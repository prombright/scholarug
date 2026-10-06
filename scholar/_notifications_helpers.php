<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SHARED — PROACTIVE NOTIFICATIONS
|--------------------------------------------------------------------------
| The `notifications` table (id, school_id, user_id, title, message,
| is_read, created_at) already existed in the schema -- it has an index on
| school_id (performance_indexes_migration.sql) and is in the school-delete
| cascade list -- but nothing in the codebase ever read or wrote it. This
| generates rows into it instead of inventing a new table.
|
| Notifications are computed lazily (called from api/admin/notifications.php
| on each fetch) rather than via a cron job -- simpler, and "a few minutes
| stale" is fine for this. scholar_notify_once_per_day() depends on
| title alone to dedupe, so a notification's message is a snapshot from
| whenever it first fired that day, not a live figure -- the relevant page
| (Fees, Settings/subscription) always has the current number.
|--------------------------------------------------------------------------
*/

function scholar_notify_once_per_day(PDO $pdo, int $schoolId, string $title, string $message): void
{
    $exists_stmt = $pdo->prepare(
        "SELECT id FROM notifications WHERE school_id = ? AND title = ? AND DATE(created_at) = CURDATE() LIMIT 1"
    );
    $exists_stmt->execute([$schoolId, $title]);
    if ($exists_stmt->fetchColumn()) {
        return;
    }
    $pdo->prepare("INSERT INTO notifications (school_id, user_id, title, message, is_read) VALUES (?, NULL, ?, ?, 0)")
        ->execute([$schoolId, $title, $message]);
}

function scholar_notify_subscription(PDO $pdo, int $schoolId): void
{
    $stmt = $pdo->prepare("SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE school_id = ?");
    $stmt->execute([$schoolId]);
    $sub = $stmt->fetch();
    if (!$sub) {
        return;
    }

    if (in_array($sub['status'], ['expired', 'canceled'], true)) {
        scholar_notify_once_per_day(
            $pdo, $schoolId, 'Subscription Expired',
            'Your ScholarUg subscription has expired. Renew now to avoid losing access.'
        );
        return;
    }

    $deadline = $sub['status'] === 'trialing' ? $sub['trial_ends_at'] : $sub['current_period_end'];
    if ($deadline === null) {
        return;
    }

    $days_left = (int) floor((strtotime($deadline) - time()) / 86400);

    if ($days_left < 0) {
        scholar_notify_once_per_day(
            $pdo, $schoolId, 'Subscription Payment Overdue',
            'Your subscription payment is overdue. Renew soon to avoid losing access.'
        );
    } elseif ($days_left <= 7) {
        $when = $days_left === 0 ? 'today' : ($days_left === 1 ? 'in 1 day' : "in {$days_left} days");
        scholar_notify_once_per_day(
            $pdo, $schoolId, 'Subscription Expiring Soon',
            "Your subscription expires {$when}. Renew soon to avoid any interruption."
        );
    }
}

function scholar_notify_overdue_fees(PDO $pdo, int $schoolId, string $term, string $year): void
{
    require_once __DIR__ . '/school_admin/_fees_helpers.php';

    try {
        $ledger_raw = admin_fees_fetch_ledger($pdo, $schoolId, '', '', $term, $year);
    } catch (\PDOException $e) {
        return; // fee_structures/term columns not migrated on this DB yet
    }
    $annotated = admin_fees_annotate_ledger($ledger_raw);
    $unpaid_count = 0;
    foreach ($annotated['ledger'] as $row) {
        if (($row['status'] ?? '') !== 'cleared') {
            $unpaid_count++;
        }
    }

    if ($unpaid_count > 0) {
        $plural = $unpaid_count === 1 ? 'student has' : 'students have';
        scholar_notify_once_per_day(
            $pdo, $schoolId, 'Unpaid Fees',
            "{$unpaid_count} {$plural} outstanding fee balances for {$term} {$year}."
        );
    }
}

function scholar_generate_notifications(PDO $pdo, int $schoolId, string $term, string $year): void
{
    try {
        scholar_notify_subscription($pdo, $schoolId);
    } catch (\Throwable $e) {
        // Notifications must never break the page they're shown on.
    }
    try {
        scholar_notify_overdue_fees($pdo, $schoolId, $term, $year);
    } catch (\Throwable $e) {
        // Same -- isolate each check from the others.
    }
}
