<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR LEAVE REVIEW HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/leave_review.php page and
| scholar/api/hr/leave.php. Logic ported verbatim from the original page.
*/

function hr_leave_requests_list(PDO $pdo, int $school_id): array
{
    $requests_stmt = $pdo->prepare("
        SELECT lr.*, TRIM(CONCAT(s.first_name, ' ', s.last_name)) AS staff_name
        FROM leave_requests lr
        JOIN staff s ON s.staff_id = lr.staff_id AND s.school_id = lr.school_id
        WHERE lr.school_id = ?
        ORDER BY (lr.status = 'pending') DESC, lr.applied_at DESC
    ");
    $requests_stmt->execute([$school_id]);
    return $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Approve/reject one pending leave request. Returns ['ok'=>bool,'message'=>string]. */
function hr_leave_review(PDO $pdo, int $school_id, int $reviewer_id, int $request_id, string $action): array
{
    $new_status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : null);
    if ($new_status === null) {
        return ['ok' => false, 'message' => 'Invalid action.'];
    }

    $upd = $pdo->prepare("
        UPDATE leave_requests
        SET status = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ? AND school_id = ? AND status = 'pending'
    ");
    $upd->execute([$new_status, $reviewer_id, $request_id, $school_id]);
    return ['ok' => true, 'message' => 'Leave request ' . $new_status . '.'];
}
