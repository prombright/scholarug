<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR PAYROLL HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/payroll.php page and scholar/api/hr/payroll.php.
| Logic ported verbatim from the original page. The single-payslip print
| view (?print=<id>) stays classic-only -- same "print view stays a
| classic page" rule as bulk_report_print.php/print_student_credentials.php.
*/

const HR_PAYROLL_MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function hr_payroll_staff_list(PDO $pdo, int $school_id): array
{
    $staff_stmt = $pdo->prepare("SELECT staff_id, first_name, last_name FROM staff WHERE school_id = ? AND status = 'active' ORDER BY first_name");
    $staff_stmt->execute([$school_id]);
    return $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hr_payroll_history(PDO $pdo, int $school_id): array
{
    $history_stmt = $pdo->prepare("
        SELECT pp.*, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS staff_name
        FROM payroll_payments pp
        JOIN staff s ON s.staff_id = pp.staff_id AND s.school_id = pp.school_id
        WHERE pp.school_id = ?
        ORDER BY pp.payment_date DESC, pp.id DESC
        LIMIT 100
    ");
    $history_stmt->execute([$school_id]);
    return $history_stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Records one payment. Returns ['ok'=>bool,'message'=>string]. */
function hr_payroll_record(PDO $pdo, int $school_id, int $recorded_by, int $staff_id, int $month, int $year, float $amount, string $payment_date, string $notes): array
{
    $staff_check = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ?");
    $staff_check->execute([$staff_id, $school_id]);

    if (!$staff_check->fetch() || $month < 1 || $month > 12 || $amount <= 0 || $payment_date === '') {
        return ['ok' => false, 'message' => 'Please fill in a valid staff member, month, amount, and payment date.'];
    }

    $ins = $pdo->prepare("
        INSERT INTO payroll_payments (school_id, staff_id, pay_period_month, pay_period_year, amount, payment_date, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$school_id, $staff_id, $month, $year, $amount, $payment_date, $notes, $recorded_by]);
    return ['ok' => true, 'message' => 'Payment recorded.'];
}
