<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SCHOOL SETTINGS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of settings.php so the classic page and the JSON endpoint
| (api/admin/settings.php) run Close Term / Close Year identically.
| DESTRUCTIVE, IRREVERSIBLE operations -- preserved verbatim from the
| classic page, not simplified, not "improved" -- every comment below is
| load-bearing context for exactly why each step exists.
|--------------------------------------------------------------------------
*/

/** @return array{ok:bool,message:string,school_badge?:?string} */
function admin_settings_save(PDO $pdo, int $schoolId, array $post, array $files): array
{
    $school_name = trim($post['school_name'] ?? '');
    $phone       = trim($post['phone_contact'] ?? '');
    $email       = trim($post['email_contact'] ?? '');
    $location    = trim($post['address'] ?? '');
    $academic_yr = trim($post['current_academic_year'] ?? '2026');
    $curr_term   = trim($post['current_term'] ?? 'Term 1');

    $logo_destination = $post['existing_logo_path'] ?? 'assets/img/default-logo.png';

    if (isset($files['school_logo']) && $files['school_logo']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $files['school_logo']['tmp_name'];
        $file_name     = $files['school_logo']['name'];
        $file_ext      = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($file_ext, $allowed_extensions, true)) {
            if (!is_dir('assets/uploads')) {
                mkdir('assets/uploads', 0755, true);
            }
            $new_file_name    = 'badge_school_' . $schoolId . '_' . time() . '.' . $file_ext;
            $upload_file_path = 'assets/uploads/' . $new_file_name;

            if (move_uploaded_file($file_tmp_path, $upload_file_path)) {
                $logo_destination = $upload_file_path;
            } else {
                return ['ok' => false, 'message' => 'FILE SYSTEM NOTICE: Failed to migrate uploaded asset to destination storage.'];
            }
        } else {
            return ['ok' => false, 'message' => 'VALIDATION ERROR: Unsupported file type. Please use WebP, PNG, JPG, or JPEG.'];
        }
    }

    try {
        $update_stmt = $pdo->prepare('
            UPDATE schools
            SET school_name = ?,
                phone_contact = ?,
                email_contact = ?,
                address = ?,
                school_badge = ?,
                current_term = ?,
                current_year = ?
            WHERE id = ?
        ');
        $update_stmt->execute([$school_name, $phone, $email, $location, $logo_destination, $curr_term, $academic_yr, $schoolId]);

        return ['ok' => true, 'message' => 'SUCCESS: Core institutional matrix profiles updated. Logo changed successfully!', 'school_badge' => $logo_destination];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'DATABASE ERROR: ' . $e->getMessage()];
    }
}

function admin_settings_fetch_school(PDO $pdo, int $schoolId): array
{
    $school_profile = $pdo->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
    $school_profile->execute([$schoolId]);
    $school = $school_profile->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        $insert_init = $pdo->prepare("INSERT INTO schools (id, school_name) VALUES (?, 'My New High School')");
        $insert_init->execute([$schoolId]);

        $school_profile->execute([$schoolId]);
        $school = $school_profile->fetch(PDO::FETCH_ASSOC);
    }
    return $school;
}

/**
 * 'Closed' used to only hide an assessment from the teacher marks-entry
 * dropdown -- the actual save handlers never checked it, so a closed
 * assessment could still silently receive new marks. This bulk-closes
 * every assessment for the term, and that status is server-enforced on
 * both teacher marks-entry write paths, so this actually locks marks entry.
 *
 * @return array{ok:bool,message:string,new_term?:string}
 */
function admin_settings_close_term(PDO $pdo, int $schoolId, string $termToClose, string $yearToClose): array
{
    try {
        $pdo->beginTransaction();

        $close_stmt = $pdo->prepare("
            UPDATE assessments SET status = 'Closed'
            WHERE school_id = ? AND term = ? AND year = ? AND status != 'Closed'
        ");
        $close_stmt->execute([$schoolId, $termToClose, $yearToClose]);
        $affected = $close_stmt->rowCount();

        // Closing Term 3 does NOT auto-roll into next year's Term 1 -- that
        // stays Close Year's own deliberate action, so nobody promotes a
        // whole school's students by clicking through term-closes on
        // autopilot.
        $next_term_map = ['Term 1' => 'Term 2', 'Term 2' => 'Term 3', 'Term 3' => 'Term 3'];
        $next_term = $next_term_map[$termToClose] ?? 'Term 1';

        $pdo->prepare('UPDATE schools SET current_term = ? WHERE id = ?')->execute([$next_term, $schoolId]);
        $pdo->commit();

        $message = $termToClose === 'Term 3'
            ? "Term 3 {$yearToClose} closed -- {$affected} assessment(s) locked. This was the school's final term for {$yearToClose}; use Close Year below when ready to promote students and start " . ((int) $yearToClose + 1) . '.'
            : "{$termToClose} {$yearToClose} closed -- {$affected} assessment(s) locked. Now on {$next_term}.";

        return ['ok' => true, 'message' => $message, 'new_term' => $next_term];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not close the term: ' . $e->getMessage()];
    }
}

/**
 * Resolves a promotion's target classes.id, reusing whatever spelling of
 * that class already exists rather than assuming the canonical dotted
 * form -- classes.class_name isn't consistently formatted across schools
 * ("S1" vs "S.1"), and creating a fresh "S.2" for a school that already
 * has "S2" would permanently fork it into two parallel spellings of the
 * same class. Only creates a new row (canonical dotted form, matching
 * classes.php's manual "Add Class" form) if truly nothing matches.
 */
function scholar_resolve_or_create_class(PDO $pdo, int $school_id, string $class_name, ?string $stream_name): int
{
    $norm = scholar_normalize_class_name($class_name);
    $all_stmt = $pdo->prepare('SELECT id, class_name, stream_name FROM classes WHERE school_id = ?');
    $all_stmt->execute([$school_id]);
    $rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $row_stream = $row['stream_name'] ?? null;
        if (scholar_normalize_class_name($row['class_name']) === $norm && $row_stream === $stream_name) {
            return (int) $row['id'];
        }
    }

    foreach ($rows as $row) {
        if (scholar_normalize_class_name($row['class_name']) === $norm) {
            return (int) $row['id'];
        }
    }

    $ins = $pdo->prepare('INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, NULL)');
    $ins->execute([$school_id, $class_name]);
    return (int) $pdo->lastInsertId();
}

/**
 * Promotes every active student to their next class, graduates whoever's
 * at the top of the ladder (flagged via graduated_year, not deleted), and
 * advances the school to Term 1 of the next year. Requires Term 3 of the
 * closing year to already be fully closed (checked by the caller / here).
 *
 * @return array{ok:bool,message:string,new_year?:string}
 */
function admin_settings_close_year(PDO $pdo, int $schoolId, string $yearToClose, string $schoolType): array
{
    $open_check = $pdo->prepare("
        SELECT COUNT(*) FROM assessments
        WHERE school_id = ? AND term = 'Term 3' AND year = ? AND status != 'Closed'
    ");
    $open_check->execute([$schoolId, $yearToClose]);

    if ((int) $open_check->fetchColumn() > 0) {
        return ['ok' => false, 'message' => "Term 3 {$yearToClose} still has open assessments -- close Term 3 first."];
    }

    $ladder = array_merge(...array_values(scholar_class_ladder($schoolType)));

    try {
        $pdo->beginTransaction();

        $promoted_total = 0;
        $graduated_total = 0;

        // Top-down, one pass: classes.id rows are shared forever (no year
        // column), so promoting bottom-up would have the very next step
        // immediately re-sweep students who just arrived a moment earlier
        // in the same run -- top-down guarantees each source class is only
        // ever read once.
        for ($i = count($ladder) - 1; $i >= 0; $i--) {
            $current_name = $ladder[$i];
            $norm_current = scholar_normalize_class_name($current_name);

            if ($i === count($ladder) - 1) {
                $grad_stmt = $pdo->prepare("
                    UPDATE students SET graduated_year = ?, class_id = NULL
                    WHERE school_id = ? AND graduated_year IS NULL
                      AND REPLACE(UPPER(class_name), '.', '') = ?
                ");
                $grad_stmt->execute([$yearToClose, $schoolId, $norm_current]);
                $graduated_total += $grad_stmt->rowCount();
                continue;
            }

            $next_name = $ladder[$i + 1];
            // level_type only flips at the O-Level -> A-Level boundary
            $level_override = ($current_name === 'S.4' && $schoolType === 'Secondary') ? 'A-Level' : null;

            $find_stmt = $pdo->prepare("
                SELECT st.id, c.stream_name
                FROM students st
                LEFT JOIN classes c ON st.class_id = c.id
                WHERE st.school_id = ? AND st.graduated_year IS NULL
                  AND REPLACE(UPPER(st.class_name), '.', '') = ?
            ");
            $find_stmt->execute([$schoolId, $norm_current]);
            $matched = $find_stmt->fetchAll(PDO::FETCH_ASSOC);

            $class_cache = []; // stream key => [class_id, class_name]
            $class_name_stmt = $pdo->prepare('SELECT class_name FROM classes WHERE id = ?');
            $upd_student = $pdo->prepare('
                UPDATE students SET class_id = ?, class_name = ?, level_type = COALESCE(?, level_type)
                WHERE id = ?
            ');
            foreach ($matched as $stu) {
                $stream_key = $stu['stream_name'] ?? '';
                if (!array_key_exists($stream_key, $class_cache)) {
                    $target_id = scholar_resolve_or_create_class($pdo, $schoolId, $next_name, $stu['stream_name'] ?: null);
                    $class_name_stmt->execute([$target_id]);
                    $resolved_name = $class_name_stmt->fetchColumn() ?: $next_name;
                    $class_cache[$stream_key] = [$target_id, $resolved_name];
                }
                [$target_class_id, $target_class_name] = $class_cache[$stream_key];
                $upd_student->execute([$target_class_id, $target_class_name, $level_override, $stu['id']]);
                $promoted_total++;
            }
        }

        $new_year = (string) ((int) $yearToClose + 1);
        $pdo->prepare("UPDATE schools SET current_year = ?, current_term = 'Term 1' WHERE id = ?")
            ->execute([$new_year, $schoolId]);
        $pdo->commit();

        return [
            'ok' => true,
            'message' => "Year closed -- {$promoted_total} student(s) promoted, {$graduated_total} graduated. Now on Term 1 {$new_year}.",
            'new_year' => $new_year,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not close the year: ' . $e->getMessage()];
    }
}
