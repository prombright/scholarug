<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — HARD DELETE A WHOLE SCHOOL
|--------------------------------------------------------------------------
| Permanently removes a school and every row anywhere in the database that
| belongs to it, without touching any other school's data.
|
| Most school-scoped tables already have `school_id ... ON DELETE CASCADE`
| straight to `schools(id)` (verified against information_schema before
| writing this), so a plain `DELETE FROM schools` would clean up the large
| majority of them automatically. This function does NOT rely on that --
| it explicitly deletes every row in every school-scoped table by hand,
| because a handful of tables (audit_logs, login_logs, fees, terms,
| conversations, sms_* tables, etc.) were found to have a school_id column
| but NO foreign key back to schools at all, so cascade alone would leave
| them orphaned. Being explicit here means the result is correct
| regardless of which tables do or don't have a real FK constraint today.
|
| Order matters for the handful of tables that have no school_id column of
| their own and are only reachable via a subquery against a school-scoped
| parent (e.g. election_votes -> election_candidates -> election_positions
| -> elections.school_id) -- those subqueries run BEFORE their parent rows
| are deleted, so the scoping is still correct. Everything runs inside one
| transaction with FOREIGN_KEY_CHECKS off, so the exact order of the
| direct `WHERE school_id = ?` deletes among themselves doesn't matter and
| the one RESTRICT constraint in the schema (clinic_prescriptions ->
| clinic_inventory) can't block this.
|
| Deliberately does NOT touch the separate devportal/abn_platform database
| -- that's a different product's own data, out of scope for "this
| school's data" on the Scholar side.
|--------------------------------------------------------------------------
*/

/**
 * @return array{ok:bool,error?:string,school_name?:string,school_code?:string}
 */
function developer_delete_school(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT school_name, school_code, school_badge FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();

    if (!$school) {
        return ['ok' => false, 'error' => 'School not found.'];
    }

    // Library PDFs live outside the DB (uploads/library_private/) -- collect
    // their paths before the rows describing them are deleted.
    $fileStmt = $pdo->prepare('SELECT file_path FROM library_documents WHERE school_id = ?');
    $fileStmt->execute([$schoolId]);
    $filesToDelete = $fileStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($school['school_badge'])) {
        $filesToDelete[] = $school['school_badge'];
    }

    // Tables with no school_id column of their own, reachable only through
    // a school-scoped parent -- must run before that parent is deleted.
    $indirectDeletes = [
        "DELETE FROM election_votes WHERE candidate_id IN (SELECT ec.id FROM election_candidates ec JOIN election_positions ep ON ep.id = ec.position_id JOIN elections e ON e.id = ep.election_id WHERE e.school_id = ?)",
        "DELETE FROM election_ballots_cast WHERE position_id IN (SELECT ep.id FROM election_positions ep JOIN elections e ON e.id = ep.election_id WHERE e.school_id = ?)",
        "DELETE FROM election_candidates WHERE position_id IN (SELECT ep.id FROM election_positions ep JOIN elections e ON e.id = ep.election_id WHERE e.school_id = ?)",
        "DELETE FROM clinic_dispense_log WHERE prescription_id IN (SELECT id FROM clinic_prescriptions WHERE school_id = ?)",
        "DELETE FROM clinic_invoice_items WHERE invoice_id IN (SELECT id FROM clinic_invoices WHERE school_id = ?)",
        "DELETE FROM ilearning_attempt_answers WHERE attempt_id IN (SELECT id FROM ilearning_attempts WHERE school_id = ?)",
        "DELETE FROM ilearning_question_options WHERE question_id IN (SELECT id FROM ilearning_questions WHERE school_id = ?)",
        "DELETE FROM project_stage_evidence WHERE stage_id IN (SELECT ps.id FROM project_stages ps JOIN projects p ON p.id = ps.project_id WHERE p.school_id = ?)",
        "DELETE FROM election_positions WHERE election_id IN (SELECT id FROM elections WHERE school_id = ?)",
        "DELETE FROM project_stages WHERE project_id IN (SELECT id FROM projects WHERE school_id = ?)",
        "DELETE FROM ilearning_attachments WHERE topic_id IN (SELECT id FROM ilearning_topics WHERE school_id = ?)",
        "DELETE FROM ilearning_live_attendance WHERE session_id IN (SELECT id FROM ilearning_live_sessions WHERE school_id = ?)",
        "DELETE FROM ilearning_live_questions WHERE session_id IN (SELECT id FROM ilearning_live_sessions WHERE school_id = ?)",
        "DELETE FROM ilearning_addon_charges WHERE addon_id IN (SELECT id FROM ilearning_addons WHERE school_id = ?)",
        "DELETE FROM sms_campaign_recipients WHERE campaign_id IN (SELECT id FROM sms_campaigns WHERE school_id = ?)",
        "DELETE FROM subscription_charges WHERE subscription_id IN (SELECT id FROM subscriptions WHERE school_id = ?)",
        "DELETE FROM conversation_messages WHERE conversation_id IN (SELECT id FROM conversations WHERE school_id = ?)",
        "DELETE FROM staff_responsibilities WHERE staff_id IN (SELECT staff_id FROM staff WHERE school_id = ?)",
        "DELETE FROM project_teacher_assignments WHERE project_id IN (SELECT id FROM projects WHERE school_id = ?)",
    ];

    // Every table in the schema with a direct school_id column (from
    // information_schema.COLUMNS, WHERE COLUMN_NAME = 'school_id') --
    // whether or not it also has a cascading FK, since several don't.
    $directTables = [
        'academic_years', 'account_verifications', 'announcements', 'assessments', 'attendance',
        'audit_logs', 'classes', 'clinic_activity_exemptions', 'clinic_dorm_care_logs', 'clinic_inventory',
        'clinic_invoices', 'clinic_leave_passes', 'clinic_outbreak_alerts', 'clinic_patients', 'clinic_prescriptions',
        'clinic_visits', 'combinations', 'conversations', 'departments', 'elections', 'events', 'exams',
        'exam_types', 'fees', 'fee_payments', 'fee_structures', 'generic_skills', 'grading_scales', 'guardians',
        'ilearning_addons', 'ilearning_attempts', 'ilearning_live_sessions', 'ilearning_pdf_submissions',
        'ilearning_progress', 'ilearning_questions', 'ilearning_text_annotations', 'ilearning_topics',
        'leave_requests', 'levels', 'library_documents', 'login_logs', 'notifications', 'parent_feedback',
        'parent_students', 'payroll_payments', 'projects', 'report_card_remarks', 'school_settings',
        'sms_campaigns', 'sms_contacts', 'sms_contact_groups', 'sms_topup_requests', 'sms_wallets',
        'sms_wallet_transactions', 'sms_whatsapp_settings', 'staff', 'staff_departments', 'streams',
        'students', 'student_marks', 'student_skill_ratings', 'student_subjects', 'subjects', 'subscriptions',
        'system_subjects', 'teacher_assignments', 'teacher_comments', 'terms', 'timetable_entries',
        'timetable_periods', 'users',
    ];

    // developer_conversations/developer_messages -- this platform's own
    // school<->developer chat (see _developer_messages_migration.sql) --
    // deleted the same way if that migration has been applied.
    try {
        $hasDevThreads = (bool) $pdo->query("SHOW TABLES LIKE 'developer_conversations'")->fetchColumn();
    } catch (\Throwable $e) {
        $hasDevThreads = false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($indirectDeletes as $sql) {
            $pdo->prepare($sql)->execute([$schoolId]);
        }

        if ($hasDevThreads) {
            $pdo->prepare('DELETE FROM developer_messages WHERE conversation_id IN (SELECT id FROM developer_conversations WHERE school_id = ?)')->execute([$schoolId]);
            $pdo->prepare('DELETE FROM developer_conversations WHERE school_id = ?')->execute([$schoolId]);
        }

        foreach ($directTables as $table) {
            $pdo->prepare("DELETE FROM `{$table}` WHERE school_id = ?")->execute([$schoolId]);
        }

        $pdo->prepare('DELETE FROM schools WHERE id = ?')->execute([$schoolId]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable $ignored) {}
        return ['ok' => false, 'error' => 'Delete failed, nothing was changed: ' . $e->getMessage()];
    }

    // Best-effort file cleanup -- a leftover file on disk is a much smaller
    // problem than a half-deleted database, so this runs after the
    // transaction has already committed and never rolls anything back.
    foreach ($filesToDelete as $relativePath) {
        if (!$relativePath) {
            continue;
        }
        $fullPath = realpath(__DIR__ . '/../' . $relativePath);
        $allowedRoot = realpath(__DIR__ . '/..');
        if ($fullPath !== false && $allowedRoot !== false && strpos($fullPath, $allowedRoot) === 0 && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    return ['ok' => true, 'school_name' => $school['school_name'], 'school_code' => $school['school_code']];
}
