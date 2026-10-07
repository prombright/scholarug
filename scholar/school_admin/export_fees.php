<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — EXPORT FEES LEDGER (CSV)
|--------------------------------------------------------------------------
| Streams the fees ledger for one term/year as a CSV. Reuses
| admin_fees_fetch_ledger()/admin_fees_annotate_ledger() -- the same
| balance/status formula the Fees page itself uses -- rather than
| recomputing it, so the export can never disagree with what's on screen.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_fees_helpers.php';
require_role(['school_admin', 'bursar']);

$school_id = current_school_id();
admin_fees_ensure_schema($pdo);

$term = trim($_GET['term'] ?? current_term());
$year = trim((string) ($_GET['year'] ?? current_year()));

$ledger_raw = admin_fees_fetch_ledger($pdo, $school_id, '', '', $term, $year);
$annotated = admin_fees_annotate_ledger($ledger_raw);

$school_stmt = $pdo->prepare('SELECT school_name FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_name = $school_stmt->fetchColumn() ?: 'school';
$safe_name = preg_replace('/[^a-z0-9]+/i', '_', $school_name);
$safe_term = preg_replace('/[^a-z0-9]+/i', '_', $term . '_' . $year);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $safe_name . '_fees_' . $safe_term . '_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Student', 'Class', 'Residence', 'Amount Due', 'Amount Paid', 'Bursary', 'Balance', 'Status']);
foreach ($annotated['ledger'] as $row) {
    fputcsv($output, [
        $row['full_name'] ?? '',
        $row['class_name'] ?? 'Unassigned',
        $row['active_residence'] ?? 'Day',
        number_format((float) $row['net_due'], 2, '.', ''),
        number_format((float) $row['total_paid'], 2, '.', ''),
        number_format((float) $row['total_bursary'], 2, '.', ''),
        number_format((float) $row['balance'], 2, '.', ''),
        ucfirst($row['status'] ?? ''),
    ]);
}
fclose($output);
exit;
