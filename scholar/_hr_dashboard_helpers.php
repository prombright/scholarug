<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR DASHBOARD HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr_dashboard.php page and scholar/api/hr/dashboard.php.
| Logic ported verbatim from the original page.
*/

function hr_dashboard_stats(PDO $pdo, int $school_id): array
{
    $staff_count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ? AND status = 'active'");
    $staff_count->execute([$school_id]);
    $staff_count = (int) $staff_count->fetchColumn();

    // sms_wallets may not exist yet on a fresh install that hasn't applied
    // _setup/sms_module_migration.sql -- same defensive pattern as $pending_leave.
    try {
        require_once __DIR__ . '/lib/ScholarSmsWallet.php';
        $sms_balance = ScholarSmsWallet::balance($pdo, $school_id);
    } catch (Throwable $e) {
        $sms_balance = 0.0;
    }

    // leave_requests may not exist yet on a fresh install that hasn't applied
    // _setup/leave_management_migration.sql -- same defensive pattern used
    // elsewhere in this app (e.g. developer_dashboard.php's $total_teachers).
    try {
        $pending_leave = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE school_id = ? AND status = 'pending'");
        $pending_leave->execute([$school_id]);
        $pending_leave = (int) $pending_leave->fetchColumn();
    } catch (Throwable $e) {
        $pending_leave = 0;
    }

    // Real Teaching vs Non-Teaching split for the donut chart.
    $staff_category_stmt = $pdo->prepare("SELECT staff_category, COUNT(*) AS cnt FROM staff WHERE school_id = ? AND status = 'active' GROUP BY staff_category");
    $staff_category_stmt->execute([$school_id]);
    $teaching_count = 0;
    $non_teaching_count = 0;
    foreach ($staff_category_stmt->fetchAll() as $row) {
        if ($row['staff_category'] === 'Teaching') {
            $teaching_count = (int) $row['cnt'];
        } elseif ($row['staff_category'] === 'Non-Teaching') {
            $non_teaching_count = (int) $row['cnt'];
        }
    }
    $staff_category_total = $teaching_count + $non_teaching_count;
    $teaching_pct = $staff_category_total > 0 ? round($teaching_count / $staff_category_total * 100) : 0;

    return [
        'staff_count' => $staff_count,
        'sms_balance' => $sms_balance,
        'pending_leave' => $pending_leave,
        'teaching_count' => $teaching_count,
        'non_teaching_count' => $non_teaching_count,
        'staff_category_total' => $staff_category_total,
        'teaching_pct' => $teaching_pct,
    ];
}
