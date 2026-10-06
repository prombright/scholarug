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
|--------------------------------------------------------------------------
*/

function scholar_audit_log(
    PDO $pdo,
    ?int $schoolId,
    ?int $userId,
    string $action,
    string $tableName,
    ?int $recordId,
    string $description
): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (school_id, user_id, action, table_name, record_id, description)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$schoolId, $userId, $action, $tableName, $recordId, substr($description, 0, 65535)]);
    } catch (\Throwable $e) {
        // Audit logging must never break the action it's auditing.
    }
}
