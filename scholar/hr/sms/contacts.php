<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK SMS: CONTACTS
|--------------------------------------------------------------------------
| Two ways to build a sendable group: import a CSV/.xlsx file (static
| list, stored in sms_contacts), or add a class (live reference --
| resolved fresh from students/parent_students/guardians at send time via
| ScholarSmsContacts, never snapshotted here). "Whole School" doesn't
| need a stored group at all -- send.php offers it directly.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/XlsxReader.php';
require_once __DIR__ . '/../../lib/ScholarPhone.php';
require_once __DIR__ . '/../../lib/ScholarSmsContacts.php';
require_once __DIR__ . '/_contacts_helpers.php';

require_role(['school_admin', 'hr']);

$school_id = current_school_id();
$message = '';
$error = '';

// ---- Add a class as a live group ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class_group'])) {
    $result = hr_sms_contacts_add_class_group($pdo, $school_id, (int) ($_POST['class_id'] ?? 0));
    if ($result['ok']) { $message = $result['message']; } else { $error = $result['message']; }
}

// ---- Import Excel/CSV into a new static group ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    $group_name = trim($_POST['group_name'] ?? '') ?: ('Import ' . date('d M Y, H:i'));

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
        $error = 'Please choose a CSV or .xlsx file to upload.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        try {
            $rows = [];
            if ($ext === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle) {
                    while (($row = fgetcsv($handle)) !== false) {
                        $rows[] = $row;
                    }
                    fclose($handle);
                }
            } elseif ($ext === 'xlsx') {
                $rows = XlsxReader::readFirstSheet($file['tmp_name']);
            } else {
                throw new RuntimeException('Unsupported file type — please upload a .csv or .xlsx file.');
            }

            // Heuristic: if the first cell of row 1 doesn't normalize to a
            // phone number, treat row 1 as a header row and skip it.
            if (!empty($rows) && ScholarPhone::normalize((string) ($rows[0][0] ?? '')) === null) {
                array_shift($rows);
            }

            $pdo->beginTransaction();
            $group_ins = $pdo->prepare("INSERT INTO sms_contact_groups (school_id, name, group_type) VALUES (?, ?, 'imported')");
            $group_ins->execute([$school_id, $group_name]);
            $group_id = (int) $pdo->lastInsertId();

            $contact_ins = $pdo->prepare("INSERT INTO sms_contacts (school_id, group_id, full_name, phone) VALUES (?, ?, ?, ?)");
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $phone = ScholarPhone::normalize((string) ($row[0] ?? ''));
                $name = trim((string) ($row[1] ?? '')) ?: null;

                if ($phone === null) {
                    $skipped++;
                    continue;
                }

                $contact_ins->execute([$school_id, $group_id, $name, $phone]);
                $imported++;
            }
            $pdo->commit();

            $message = "Imported {$imported} contact(s) into \"{$group_name}\"." . ($skipped > 0 ? " Skipped {$skipped} row(s) with no valid phone number." : '');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Import failed: ' . $e->getMessage();
        }
    }
}

// ---- Delete a group ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    $result = hr_sms_contacts_delete_group($pdo, $school_id, (int) ($_POST['group_id'] ?? 0));
    $message = $result['message'];
}

$state = hr_sms_contacts_state($pdo, $school_id);
$groups = $state['groups'];
$available_classes = $state['available_classes'];

$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'sms_contacts' : 'hr';

if ($is_hr_role) {
    $__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
    $__school_brand->execute([$school_id]);
    $__school_brand = $__school_brand->fetch(PDO::FETCH_ASSOC) ?: [];
    $__badge_url = null;
    if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../../' . $__school_brand['school_badge'])) {
        $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
    }
    require_once __DIR__ . '/../../_hr_shell.php';
} else {
    require_once __DIR__ . '/../../_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
<style>
.sms-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.sms-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.sms-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.sms-section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.sms-alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.sms-alert.success{background:rgba(16,185,129,0.12);color:var(--green, #10b981);}
.sms-alert.error{background:rgba(239,68,68,0.12);color:var(--danger, #ef4444);}
.sms-section label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
.sms-section label:first-child{margin-top:0;}
.sms-section input,.sms-section select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;box-sizing:border-box;}
.sms-section button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
.sms-section button.danger{background:transparent;border:1px solid rgba(239,68,68,0.4);color:var(--danger, #ef4444);padding:6px 12px;margin-top:0;font-size:0.75rem;}
.sms-section table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
.sms-section th,.sms-section td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
.sms-section th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.sms-empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.sms-pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;background:rgba(0,168,168,0.1);color:var(--cyan);}
.sms-two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:700px){.sms-two-col{grid-template-columns:1fr;}}
</style>

<h1 style="margin:0 0 16px;font-size:1.4rem;">Bulk SMS</h1>
<div class="sms-tabs">
    <a href="wallet.php">Wallet</a>
    <a href="contacts.php" class="active">Contacts</a>
    <a href="send.php">Send</a>
    <a href="history.php">History</a>
    <a href="whatsapp_settings.php">WhatsApp</a>
</div>

<?php if ($message): ?><div class="sms-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sms-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sms-two-col">
    <div class="sms-section">
        <h3 style="margin:0 0 6px;font-size:1rem;">Add a Class as a Group</h3>
        <p style="color:var(--muted);font-size:0.8rem;margin:0 0 10px;">Always reflects current registered students' parent/guardian numbers -- no re-import needed when a phone changes.</p>
        <?php if (empty($available_classes)): ?>
            <div class="sms-empty">Every class already has a group.</div>
        <?php else: ?>
            <form method="POST">
                <label>Class</label>
                <select name="class_id" required>
                    <?php foreach ($available_classes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="add_class_group">Add Class Group</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="sms-section">
        <h3 style="margin:0 0 6px;font-size:1rem;">Import Contacts</h3>
        <p style="color:var(--muted);font-size:0.8rem;margin:0 0 10px;">CSV or .xlsx, phone number in column A, name (optional) in column B.</p>
        <form method="POST" enctype="multipart/form-data">
            <label>Group Name (optional)</label>
            <input type="text" name="group_name" placeholder="e.g. Alumni 2025">
            <label>File</label>
            <input type="file" name="import_file" accept=".csv,.xlsx" required>
            <button type="submit">Import</button>
        </form>
    </div>
</div>

<div class="sms-section">
    <h3 style="margin:0 0 16px;font-size:1rem;">Your Groups</h3>
    <?php if (empty($groups)): ?>
        <div class="sms-empty">No groups yet -- add a class or import a file above.</div>
    <?php else: ?>
    <table>
        <tr><th>Name</th><th>Type</th><th>Recipients</th><th></th></tr>
        <?php foreach ($groups as $g): ?>
        <tr>
            <td><?= htmlspecialchars($g['name']) ?></td>
            <td><span class="sms-pill"><?= $g['group_type'] === 'class' ? 'Class (live)' : 'Imported' ?></span></td>
            <td><?= (int) $g['recipient_count'] ?></td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete this group?');" style="display:inline;">
                    <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                    <button type="submit" name="delete_group" class="danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
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