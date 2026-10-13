<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED — AUDIT LOGGING
|--------------------------------------------------------------------------
| Writes one row per sensitive action (delete, role change, money/grade
| edit) into the existing `audit_logs` table (school_id, user_id, action,
| table_name, record_id, description, created_at) so "who did this" is
| answerable. The table already existed in the schema before this feature
| but nothing in the current codebase was writing to it.
|
| Same philosophy as _visit_tracking.php: logging a sensitive action must
| never be the reason that action fails, so every call is wrapped and
| swallows its own errors.
|
| $userId is deliberately untyped (not ?int) -- every call site passes
| $_SESSION['user_id'], but a school_admin who logged in via the School
| Code + PIN path (no `users` row at all, see login.php) has
| $_SESSION['user_id'] set to a STRING like "school_admin_42", not a
| real numeric id. A strict ?int type hint means PHP validates/rejects
| that at the CALL SITE, before this function's own body -- and its own
| try/catch -- ever runs, so the "never breaks the caller" promise above
| didn't actually hold: every delete/role-change/edit this file audits
| was a hard 500 for any PIN-logged-in admin. Normalizing inside the
| function body instead (string input -> NULL, not a crash) is what
| actually delivers on that promise.
|--------------------------------------------------------------------------
*/

function scholar_audit_log(
    PDO $pdo,
    ?int $schoolId,
    mixed $userId,
    string $action,
    string $tableName,
    ?int $recordId,
    string $description
): void {
    try {
        $userId = is_numeric($userId) ? (int) $userId : null;
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (school_id, user_id, action, table_name, record_id, description)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$schoolId, $userId, $action, $tableName, $recordId, substr($description, 0, 65535)]);
    } catch (\Throwable $e) {
        // Audit logging must never break the action it's auditing.
    }
}
