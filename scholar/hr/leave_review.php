<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — HR: LEAVE REVIEW QUEUE
|--------------------------------------------------------------------------
| Approve/reject staff leave requests school-wide. current_staff_id() here
| is the REVIEWER's own staff record (for reviewed_by), not the applicant's
| -- ownership scoping on the actual request rows is by school_id, since
| any HR/admin at this school can review any staff member's request here.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_leave_review_helpers.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();
$reviewer_id = (int) ($_SESSION['user_id'] ?? 0);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $result = hr_leave_review($pdo, $school_id, $reviewer_id, (int) ($_POST['request_id'] ?? 0), $_POST['action']);
    if ($result['ok']) {
        $message = $result['message'];
    }
}

$requests = hr_leave_requests_list($pdo, $school_id);

// Same role-based shell split as hr_dashboard.php/staff_manager.php --
// 'HR' gets its own small sidebar, school_admin keeps the normal one.
$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'leave' : 'hr';

if ($is_hr_role) {
    $__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
    $__school_brand->execute([$school_id]);
    $__school_brand = $__school_brand->fetch(PDO::FETCH_ASSOC) ?: [];
    $__badge_url = null;
    if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
        $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
    }
    require_once __DIR__ . '/../_hr_shell.php';
} else {
    require_once __DIR__ . '/../_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
<style>
.lr-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.lr-section table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
.lr-section th,.lr-section td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
.lr-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.lr-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.lr-pill-pending{background:rgba(245,158,11,0.14);color:#f59e0b;}
.lr-pill-approved{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.lr-pill-rejected{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.lr-actions{display:flex;gap:6px;}
.lr-actions button{cursor:pointer;border:none;border-radius:6px;padding:6px 12px;font-weight:700;font-size:0.75rem;}
.lr-btn-approve{background:var(--green, #10b981);color:#04221a;}
.lr-btn-reject{background:transparent;border:1px solid rgba(239,68,68,0.4);color:var(--danger, #ef4444);}
.lr-empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
.lr-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
</style>

<h1 style="margin:0 0 24px;font-size:1.4rem;">Leave Requests</h1>

<?php if ($message): ?><div class="lr-alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>

<div class="lr-section">
    <table>
        <tr><th>Staff</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th></th></tr>
        <?php if (empty($requests)): ?>
            <tr><td colspan="6" class="lr-empty">No leave requests yet.</td></tr>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['staff_name']) ?></td>
                    <td><?= htmlspecialchars($r['leave_type']) ?></td>
                    <td><?= htmlspecialchars(date('d M', strtotime($r['start_date']))) ?> &ndash; <?= htmlspecialchars(date('d M Y', strtotime($r['end_date']))) ?></td>
                    <td style="max-width:200px;"><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                    <td><span class="lr-pill lr-pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <div class="lr-actions">
                                <form method="POST"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><button type="submit" name="action" value="approve" class="lr-btn-approve">Approve</button></form>
                                <form method="POST"><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><button type="submit" name="action" value="reject" class="lr-btn-reject">Reject</button></form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>

<?php if ($is_hr_role): ?>
        </div>
    </div>
</div>
</body>
</html>
<?php else: ?>
    </div><!-- /.page-inner -->
    </main>
    </div><!-- /.app-shell -->
    </body>
    </html>
<?php endif; ?>
