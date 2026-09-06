<?php
// ==========================================
// 1. ENVIRONMENT CONFIGURATION & LIFECYCLE
// ==========================================
// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
require 'db.php';
require_once __DIR__ . '/auth_guard.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY FIX: this used to silently grant school_id=1 + role='admin' to
// ANY visitor with no session at all -- a full authentication bypass
// giving unauthenticated users view/add/edit/hard-delete access to School
// #1's staff records. Now requires a real school_admin login.
// HR added alongside school_admin -- staff management lives under the
// Human Resources sidebar section, reachable by either role (same
// "shared page, multiple roles" pattern as fees.php/assessments.php).
require_role(['school_admin', 'hr']);

$school_id = current_school_id();

// CSV template download for Bulk CSV Import below -- must run before any
// HTML output, same convention students.php's own template download uses.
// No "Assign Stream" guidance beyond a blank example -- streams are school-
// specific and optional (see classes.php), so there's nothing generic to
// suggest there.
if (isset($_GET['download_template']) && $_GET['download_template'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=staff_import_template.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['First Name', 'Last Name', 'Email', 'Phone', 'NIN', 'Staff Category', 'Role', 'Assign Class', 'Assign Stream']);
    fputcsv($output, ['John', 'Byaruhanga', 'john.byaruhanga@example.com', '0772000000', '', 'Teaching', 'Regular Teacher', 'S.1', '']);
    fputcsv($output, ['Grace', 'Namuli', '', '0782000000', '', 'Non-Teaching', 'Bursar', '', '']);
    fclose($output);
    exit();
}

$msg = '';
$msg_type = 'info';
$invite_link = null;
$generated_credentials = null;
$bulk_credentials = [];
$skipped_staff_rows = [];

if (!is_dir('uploads/staff')) {
    mkdir('uploads/staff', 0755, true);
}

// ==========================================
// 2. TRANSACTION PROCESSING & OPERATIONS
// ==========================================

// --- 2.1 Staff Deletion (Hard Purge) ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    $img_stmt = $pdo->prepare("SELECT photo FROM staff WHERE staff_id = ? AND school_id = ?");
    $img_stmt->execute([$delete_id, $school_id]);
    $old_photo = $img_stmt->fetchColumn();
    
    $pdo->beginTransaction();
    try {
        $del_roles = $pdo->prepare("DELETE FROM staff_responsibilities WHERE staff_id = ?");
        $del_roles->execute([$delete_id]);
        
        $del_staff = $pdo->prepare("DELETE FROM staff WHERE staff_id = ? AND school_id = ?");
        $del_staff->execute([$delete_id, $school_id]);
        
        if ($old_photo && file_exists($old_photo) && !str_contains($old_photo, 'default_avatar.png')) {
            unlink($old_photo);
        }
        
        $pdo->commit();
        header("Location: staff_manager.php?status=purged");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "EXECUTION FAILURE: " . $e->getMessage();
        $msg_type = 'error';
    }
}

// --- 2.2 Status Toggle ---
if (isset($_GET['toggle_status']) && isset($_GET['current'])) {
    $target_id = (int)$_GET['toggle_status'];
    $new_status = ($_GET['current'] === 'active') ? 'inactive' : 'active';
    
    $upd = $pdo->prepare("UPDATE staff SET status = ? WHERE staff_id = ? AND school_id = ?");
    $upd->execute([$new_status, $target_id, $school_id]);
    header("Location: staff_manager.php?status=updated");
    exit;
}

// --- 2.3 URL Message Interceptor ---
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'purged') { $msg = "SUCCESS: Profile and linked metadata purged completely."; $msg_type = 'info'; }
    if ($_GET['status'] === 'updated') { $msg = "SUCCESS: Operational status toggled cleanly."; $msg_type = 'info'; }
}

// --- 2.4 Profile Upsert Form Handler (Add & Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['persist_staff_record'])) {
    $action         = $_POST['form_action']; 
    $staff_row_id   = !empty($_POST['staff_row_id']) ? (int)$_POST['staff_row_id'] : null;
    
    $first_name     = trim($_POST['first_name'] ?? '');
    $last_name      = trim($_POST['last_name'] ?? '');
    $email          = trim($_POST['email'] ?? '') ?: null;
    $phone          = trim($_POST['phone'] ?? '') ?: null;
    $nin            = !empty($_POST['nin']) ? strtoupper(trim($_POST['nin'])) : null;
    $staff_category = $_POST['staff_category'] ?? 'Teaching';
    $role   = $_POST['role'] ?? 'Regular Teacher';
    $responsibilities = $_POST['responsibilities'] ?? [];
    
    $assign_class   = trim($_POST['assign_class'] ?? '');
    $assign_stream  = trim($_POST['assign_stream'] ?? '');

    if (!empty($nin) && !preg_match('/^(CM|CF)[A-Z0-9]{12}$/', $nin)) {
        $msg = "VALIDATION FAULT: Provided NIN does not follow standard 14-character Ugandan Format.";
        $msg_type = 'error';
    } else {
        $pdo->beginTransaction();
        try {
            $photo_path = 'uploads/staff/default_avatar.png';

            if ($action === 'update' && $staff_row_id) {
                $exist_stmt = $pdo->prepare("SELECT photo FROM staff WHERE staff_id = ? AND school_id = ?");
                $exist_stmt->execute([$staff_row_id, $school_id]);
                $photo_path = $exist_stmt->fetchColumn() ?: 'uploads/staff/default_avatar.png';
            }

            if (isset($_FILES['staff_photo']) && $_FILES['staff_photo']['error'] === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($_FILES['staff_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    if ($action === 'update' && $photo_path && file_exists($photo_path) && !str_contains($photo_path, 'default_avatar.png')) {
                        unlink($photo_path);
                    }
                    $photo_name = 'stf_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                    $photo_path = 'uploads/staff/' . $photo_name;
                    move_uploaded_file($_FILES['staff_photo']['tmp_name'], $photo_path);
                }
            }

            if ($action === 'add') {
                // scholar_generate_staff_code() lives in auth_guard.php (already
                // required above) -- shared with the Bulk CSV Import handler
                // below, so a single add and a bulk row can never race each
                // other into the same code within one request.
                $generated_id = scholar_generate_staff_code($pdo);

                // staff_id is left off this column list entirely now -- let
                // AUTO_INCREMENT assign it, instead of trying to force a
                // formatted string into the real int PK (which silently
                // truncated to 0 and got auto-substituted anyway). The
                // formatted code goes into staff_code, its own real column.
                $ins = $pdo->prepare("INSERT INTO staff (school_id, first_name, last_name, email, phone, nin, photo, staff_category, role, staff_type, assign_class, assign_stream, staff_code, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $ins->execute([$school_id, $first_name, $last_name, $email, $phone, $nin, $photo_path, $staff_category, $role, strtolower($staff_category), $assign_class, $assign_stream, $generated_id]);
                $staff_row_id = $pdo->lastInsertId();

                $msg = "SUCCESS: Staff registered successfully -- staff code: " . $generated_id . " (internal record #" . $staff_row_id . ").";

                // Non-teaching staff get a login generated immediately at
                // registration -- one-time temporary password, shown once,
                // handed over directly. This is the same mechanism as the
                // "Generate Temporary Password" button, just automatic here
                // since these roles don't go through the teacher invite-link
                // flow.
                if ($staff_category === 'Non-Teaching') {
                    $auto_temp_password = (string) random_int(10000, 99999);
                    $auto_hash = password_hash($auto_temp_password, PASSWORD_BCRYPT);
                    $auto_staff = [
                        'email' => $email,
                        'phone' => $phone,
                        'role' => $role,
                        'first_name' => $first_name,
                    ];
                    $auto_user_id = find_or_create_staff_user($pdo, $auto_staff, (int) $staff_row_id, $school_id, $auto_hash);
                    $auto_username_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $auto_username_stmt->execute([$auto_user_id]);
                    $auto_username = $auto_username_stmt->fetchColumn();

                    $generated_credentials = ['username' => $auto_username, 'password' => $auto_temp_password];
                    $msg .= " Login generated -- copy the credentials below now, they won't be shown again.";
                } else {
                    // Teaching staff: send an activation invite automatically
                    // at registration instead -- the teacher sets their own
                    // password via the emailed link (send_staff_invite(),
                    // shared with the manual "Send Portal Invite" button
                    // below). Falls back to showing the link on-screen for
                    // the admin to relay if no email/phone is on file yet,
                    // or if the mail server can't be reached.
                    $auto_staff = [
                        'email' => $email,
                        'phone' => $phone,
                        'role' => $role,
                        'first_name' => $first_name,
                    ];
                    $invite_result = send_staff_invite($pdo, $auto_staff, (int) $staff_row_id, $school_id);

                    if (!$invite_result['ok']) {
                        $msg .= " No email or phone on file -- use \"Send Portal Invite\" or \"Generate Temporary Password\" once contact info is added.";
                    } elseif ($invite_result['emailed']) {
                        $msg .= " An activation email was sent to " . htmlspecialchars($invite_result['destination'], ENT_QUOTES, 'UTF-8') . ".";
                    } elseif ($invite_result['channel'] === 'email') {
                        $invite_link = $invite_result['invite_link'];
                        $msg .= " Could not email the activation link automatically (mail server not reachable/configured) -- copy the link below and send it directly.";
                    } else {
                        $invite_link = $invite_result['invite_link'];
                        $msg .= " No email on file -- SMS delivery isn't configured yet, copy the activation link below and send it directly.";
                    }
                }
            } else if ($action === 'update' && $staff_row_id) {
                $upd = $pdo->prepare("UPDATE staff SET first_name = ?, last_name = ?, email = ?, phone = ?, nin = ?, photo = ?, staff_category = ?, role = ?, staff_type = ?, assign_class = ?, assign_stream = ? WHERE staff_id = ? AND school_id = ?");
                $upd->execute([$first_name, $last_name, $email, $phone, $nin, $photo_path, $staff_category, $role, strtolower($staff_category), $assign_class, $assign_stream, $staff_row_id, $school_id]);
                
                $del_roles = $pdo->prepare("DELETE FROM staff_responsibilities WHERE staff_id = ?");
                $del_roles->execute([$staff_row_id]);
                
                $msg = "SUCCESS: Profile records modified systematically.";
            }

            if ($staff_row_id && !empty($responsibilities)) {
                $resp_stmt = $pdo->prepare("INSERT INTO staff_responsibilities (staff_id, responsibility) VALUES (?, ?)");
                foreach ($responsibilities as $resp) {
                    $resp_stmt->execute([$staff_row_id, $resp]);
                }
            }

            $pdo->commit();
            $msg_type = 'info';
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "DATABASE ERROR: " . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

// --- 2.5 Bulk CSV Import ---
// Mirrors school_admin/students.php's own Bulk CSV Import: same
// normalize-and-lookup convention for matching a typed class name against
// this school's real classes, same additive/skip-bad-rows tolerance.
//
// Deliberately does NOT auto-email an activation invite per row the way a
// single "Register Staff Member" submission does for Teaching staff -- N
// synchronous mail() calls in one request is exactly what times out a
// large CSV (see bulk_report_print.php's own set_time_limit() note for the
// same concern on the report-printing side). Every successfully imported
// row gets a temporary password instead, all shown together in one
// results table below -- same "hand out printed/copied credentials to a
// fresh cohort" pattern already used for newly bulk-imported students.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv' && isset($_FILES['csv_file'])) {
    @set_time_limit(120);

    $file = $_FILES['csv_file']['tmp_name'];

    if (empty($file) || !is_uploaded_file($file)) {
        $msg = "Please select a valid CSV file to upload.";
        $msg_type = 'error';
    } else {
        $handle = fopen($file, "r");
        fgetcsv($handle, 1000, ","); // header row

        $classes_lookup_stmt = $pdo->prepare("SELECT DISTINCT class_name FROM classes WHERE school_id = ?");
        $classes_lookup_stmt->execute([$school_id]);
        $class_lookup = [];
        foreach ($classes_lookup_stmt->fetchAll(PDO::FETCH_COLUMN) as $cn) {
            $class_lookup[scholar_normalize_class_name($cn)] = $cn;
        }

        $ins = $pdo->prepare("
            INSERT INTO staff (school_id, first_name, last_name, email, phone, nin, photo, staff_category, role, staff_type, assign_class, assign_stream, staff_code, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'uploads/staff/default_avatar.png', ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");

        $imported_count = 0;
        $row_num = 1; // header already consumed

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_num++;
            $csv_first    = trim($data[0] ?? '');
            $csv_last     = trim($data[1] ?? '');
            $csv_email    = trim($data[2] ?? '') ?: null;
            $csv_phone    = trim($data[3] ?? '') ?: null;
            $csv_nin      = strtoupper(trim($data[4] ?? ''));
            $csv_category = trim($data[5] ?? '') ?: 'Teaching';
            $csv_role     = trim($data[6] ?? '') ?: 'Regular Teacher';
            $csv_class    = trim($data[7] ?? '');
            $csv_stream   = trim($data[8] ?? '');

            if ($csv_first === '' || $csv_last === '') {
                $skipped_staff_rows[] = "Row {$row_num}: First Name and Last Name are both required.";
                continue;
            }

            if (!in_array($csv_category, ['Teaching', 'Non-Teaching'], true)) {
                $csv_category = 'Teaching';
            }

            // Same 14-character CM/CF format the single "Register Staff
            // Member" form validates -- a bad NIN drops just that one field
            // instead of skipping the whole row over a typo.
            if ($csv_nin !== '' && !preg_match('/^(CM|CF)[A-Z0-9]{12}$/', $csv_nin)) {
                $skipped_staff_rows[] = "Row {$row_num}: NIN \"{$csv_nin}\" for {$csv_first} {$csv_last} doesn't match the standard format -- imported without it.";
                $csv_nin = null;
            } else {
                $csv_nin = $csv_nin ?: null;
            }

            // Empty string, not null -- matches what the single "Register
            // Staff Member" form's own unselected dropdowns already send
            // (assign_class/assign_stream have no NOT NULL concern either
            // way, but staying on the exact value that path already proves
            // out avoids introducing a new one here).
            $matched_class = '';
            $matched_stream = '';
            if ($csv_class !== '') {
                $found = $class_lookup[scholar_normalize_class_name($csv_class)] ?? null;
                if ($found === null) {
                    $skipped_staff_rows[] = "Row {$row_num}: class \"{$csv_class}\" not found for {$csv_first} {$csv_last} — add it via Classes first, or check the spelling. Imported without a class assignment.";
                } else {
                    $matched_class = $found;
                    $matched_stream = $csv_stream;
                }
            }

            $staff_code = scholar_generate_staff_code($pdo);

            $ins->execute([
                $school_id, $csv_first, $csv_last, $csv_email, $csv_phone, $csv_nin,
                $csv_category, $csv_role, strtolower($csv_category),
                $matched_class, $matched_stream, $staff_code,
            ]);
            $imported_count++;
            $new_staff_id = (int) $pdo->lastInsertId();

            $temp_password = (string) random_int(10000, 99999);
            $hashed = password_hash($temp_password, PASSWORD_BCRYPT);
            $staff_for_login = ['email' => $csv_email, 'phone' => $csv_phone, 'role' => $csv_role, 'first_name' => $csv_first];
            $new_user_id = find_or_create_staff_user($pdo, $staff_for_login, $new_staff_id, $school_id, $hashed);
            $username_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $username_stmt->execute([$new_user_id]);

            $bulk_credentials[] = [
                'name'     => trim("{$csv_first} {$csv_last}"),
                'code'     => $staff_code,
                'username' => $username_stmt->fetchColumn(),
                'password' => $temp_password,
            ];
        }
        fclose($handle);

        $msg = "Successfully imported {$imported_count} staff member(s). Copy their login credentials from the table below now — passwords are shown only this once." . (!empty($skipped_staff_rows) ? ' ' . count($skipped_staff_rows) . ' row(s) had warnings, see below.' : '');
        $msg_type = 'info';
    }
}

// --- 2.x Send Portal Invite (teacher self-service account activation) ---
// Creates a `users` login for this staff member if one doesn't exist yet,
// plus a one-time token in account_verifications. The teacher opens
// teacher_verify.php?token=... and sets their own password -- no password
// is ever set by the admin. NOTE: actual email/SMS delivery isn't wired to
// a provider yet (no credentials configured) -- the link is shown here for
// the admin to relay manually in the meantime. Swap send_invite_link() out
// for a real provider call once one is chosen.
// Map the staff record's own "Primary System Role" to a real login role.
// Only roles that actually have a working dashboard today are mapped
// explicitly; everything else keeps the historical 'teacher' fallback
// rather than guessing at a login role that doesn't exist yet
// (headteacher/bursar/dos accounts still have no self-service creation
// path -- see _setup/CHANGES_AND_SETUP.md).
function staff_login_role(string $staffRole): string
{
    return match ($staffRole) {
        'Nurse' => 'nurse',
        'HR Manager' => 'HR',
        default => 'teacher',
    };
}

// A username that doesn't depend on email/phone being on file, for staff
// created without either (the common case when just testing locally).
function generate_staff_username(PDO $pdo, string $firstName, int $staffId): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName)) ?: 'staff';
    $candidate = $base . $staffId;
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$candidate]);
    if ($check->fetch()) {
        $candidate = $base . $staffId . random_int(10, 99);
    }
    return $candidate;
}

/**
 * Find this staff member's existing login (users row), or create one.
 * Shared by both the email/phone invite-link flow and the "generate a
 * temporary password now" flow below -- they only differ in what happens
 * to the password afterward.
 */
function find_or_create_staff_user(PDO $pdo, array $staff, int $staffId, int $schoolId, string $initialPasswordHash): int
{
    $u_stmt = $pdo->prepare("SELECT id FROM users WHERE staff_id = ? AND school_id = ?");
    $u_stmt->execute([$staffId, $schoolId]);
    $userId = $u_stmt->fetchColumn();
    if ($userId) {
        return (int) $userId;
    }

    $username = $staff['email'] ?: ($staff['phone'] ?: generate_staff_username($pdo, $staff['first_name'] ?? '', $staffId));

    $ins = $pdo->prepare("
        INSERT INTO users (username, email, password, role, staff_id, school_id, is_temp_password, account_status, phone_number)
        VALUES (?, ?, ?, ?, ?, ?, 1, 'active', ?)
    ");
    $ins->execute([
        $username,
        $staff['email'] ?: null,
        $initialPasswordHash,
        staff_login_role($staff['role'] ?? ''),
        $staffId,
        $schoolId,
        $staff['phone'] ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Create (or reuse) this staff member's login, issue a fresh
 * account_verifications token, and try to email the activation link.
 * Shared by the automatic invite fired when a Teaching staff member is
 * registered (below) and the manual "Send Portal Invite" button, so both
 * paths stay identical instead of drifting apart.
 *
 * @return array{ok:bool, emailed:bool, channel:string, destination:string, invite_link:string, staff_name:string}
 */
function send_staff_invite(PDO $pdo, array $staff, int $staffId, int $schoolId): array
{
    if (empty($staff['email']) && empty($staff['phone'])) {
        return ['ok' => false, 'emailed' => false, 'channel' => '', 'destination' => '', 'invite_link' => '', 'staff_name' => $staff['first_name'] ?? ''];
    }

    // unusable until they set their own via the link below
    $placeholder = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $user_id = find_or_create_staff_user($pdo, $staff, $staffId, $schoolId, $placeholder);

    $token = bin2hex(random_bytes(24)); // 48 hex chars, matches account_verifications.token CHAR(48)
    $channel = !empty($staff['email']) ? 'email' : 'sms';
    $destination = $channel === 'email' ? $staff['email'] : $staff['phone'];

    $tok_stmt = $pdo->prepare("
        INSERT INTO account_verifications (school_id, user_id, token, channel, destination, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR))
    ");
    $tok_stmt->execute([$schoolId, $user_id, $token, $channel, $destination]);

    // SCHOLAR_BASE is a host-relative path ("/ABNsystems/scholar") -- fine
    // for an <a href> inside this same site, but meaningless dropped into
    // an email with no page to resolve it against, which is what silently
    // produced http://<nothing-or-guessed-host>/...teacher_verify.php.
    // Needs a real scheme+host, same convention config.php's
    // DEVPORTAL_PUBLIC_URL already uses. ".php" dropped too -- the site's
    // own .htaccess redirects the literal extension to the clean URL, but
    // there's no reason to hand out the un-clean one to begin with.
    $__inviteScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__inviteHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $invite_link = "{$__inviteScheme}://{$__inviteHost}" . rtrim(SCHOLAR_BASE, '/') . '/teacher_verify?token=' . $token;
    $staff_name = htmlspecialchars($staff['first_name'] ?? '', ENT_QUOTES, 'UTF-8');

    // SMS delivery has no provider wired up yet (see generate_credentials
    // below for the no-channel-needed fallback); email is the only
    // channel that can actually be auto-sent right now.
    $emailed = false;
    if ($channel === 'email') {
        $emailed = abn_send_email(
            $destination,
            'Activate your Scholar account',
            '<p>Hello ' . $staff_name . ',</p>'
            . '<p>You have been added as staff on Scholar. Click the link below to set your password and activate your account. This link expires in 48 hours.</p>'
            . '<p><a href="' . htmlspecialchars($invite_link, ENT_QUOTES, 'UTF-8') . '">Activate your account</a></p>'
            . '<p>If you did not expect this, you can ignore this email.</p>'
        );
    }

    return ['ok' => true, 'emailed' => $emailed, 'channel' => $channel, 'destination' => $destination, 'invite_link' => $invite_link, 'staff_name' => $staff_name];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_invite') {
    $target_staff_id = (int) ($_POST['staff_id'] ?? 0);
    $stf_stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ? AND school_id = ?");
    $stf_stmt->execute([$target_staff_id, $school_id]);
    $target_staff = $stf_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_staff) {
        $msg = "Staff record not found.";
        $msg_type = 'error';
    } elseif (empty($target_staff['email']) && empty($target_staff['phone'])) {
        $msg = "Add an email or phone number for this staff member first, or use \"Generate Temporary Password\" instead -- that works without either.";
        $msg_type = 'error';
    } else {
        $result = send_staff_invite($pdo, $target_staff, $target_staff_id, $school_id);
        $invite_link = $result['invite_link'];

        if ($result['emailed']) {
            $msg = "Invite emailed to {$result['staff_name']} at " . htmlspecialchars($result['destination'], ENT_QUOTES, 'UTF-8') . ". They can also be given the link below directly if needed.";
        } elseif ($result['channel'] === 'email') {
            $msg = "Invite created for {$result['staff_name']}, but the email could not be sent (mail server not reachable/configured) -- copy the link below and send it to them directly.";
        } else {
            $msg = "Invite created for {$result['staff_name']}. SMS delivery isn't configured yet -- copy the link below and send it to them directly.";
        }
        $msg_type = 'info';
    }
}

// --- 2.x Generate Temporary Password (works with no email/phone on file) ---
// The invite-link flow above needs somewhere to deliver the link. On a dev
// box (or a school with no email/SMS set up yet) that's a dead end, so this
// gives the admin a password to read out or write down directly instead --
// same "change it on first login" safety net (is_temp_password = 1), just
// without requiring a delivery channel first.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_credentials') {
    $target_staff_id = (int) ($_POST['staff_id'] ?? 0);
    $stf_stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = ? AND school_id = ?");
    $stf_stmt->execute([$target_staff_id, $school_id]);
    $target_staff = $stf_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_staff) {
        $msg = "Staff record not found.";
        $msg_type = 'error';
    } else {
        $temp_password = (string) random_int(10000, 99999);
        $hashed = password_hash($temp_password, PASSWORD_BCRYPT);

        $user_id = find_or_create_staff_user($pdo, $target_staff, $target_staff_id, $school_id, $hashed);

        // If a users row already existed (e.g. left over from an unused
        // invite link), overwrite its password with this freshly generated
        // one rather than leaving the old unusable placeholder in place.
        $pdo->prepare("UPDATE users SET password = ?, is_temp_password = 1, account_status = 'active' WHERE id = ?")
            ->execute([$hashed, $user_id]);

        $final_username = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $final_username->execute([$user_id]);
        $final_username = $final_username->fetchColumn();

        $generated_credentials = ['username' => $final_username, 'password' => $temp_password];
        $msg = "Login credentials generated for " . htmlspecialchars($target_staff['first_name'] ?? '', ENT_QUOTES, 'UTF-8') . ". Copy them now and hand them over directly -- the password is shown only this once, and they'll be asked to change it on first login.";
        $msg_type = 'info';
    }
}

$staff_collection = $pdo->prepare("SELECT * FROM staff WHERE school_id = ? ORDER BY staff_id DESC");
$staff_collection->execute([$school_id]);
$staff_collection = $staff_collection->fetchAll(PDO::FETCH_ASSOC);

// --- Classes & subjects for the Academic Assignment dropdowns ---
// Pulled from the real classes/subjects tables instead of free-typed text,
// so a staff member's assigned class/subject always matches something
// that actually exists (no more "Senior 1" here and "S.1" on the roster).
$classes_rows = $pdo->prepare("SELECT class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name, stream_name");
$classes_rows->execute([$school_id]);
$classes_rows = $classes_rows->fetchAll(PDO::FETCH_ASSOC);

$class_names = [];
$class_streams_map = [];
foreach ($classes_rows as $cr) {
    $cn = $cr['class_name'];
    if (!in_array($cn, $class_names, true)) $class_names[] = $cn;
    if (!empty($cr['stream_name'])) {
        $class_streams_map[$cn][] = $cr['stream_name'];
    }
}

$responsibilities_map = [];
try {
    // Scoped to this school's own staff IDs -- previously had no WHERE at
    // all, pulling every school's responsibility rows on every load.
    $staff_ids_for_resp = array_column($staff_collection, 'staff_id');
    if (!empty($staff_ids_for_resp)) {
        $resp_placeholders = implode(',', array_fill(0, count($staff_ids_for_resp), '?'));
        $resp_q = $pdo->prepare("SELECT staff_id, responsibility FROM staff_responsibilities WHERE staff_id IN ($resp_placeholders)");
        $resp_q->execute($staff_ids_for_resp);
        while ($row = $resp_q->fetch(PDO::FETCH_ASSOC)) {
            $responsibilities_map[$row['staff_id']][] = $row['responsibility'];
        }
    }
} catch (Exception $e) {}

// Real teaching assignments, replacing the old free-typed
// staff.assign_subject column -- that was actually an INT(11) column
// silently truncating every typed subject name to 0, so it never held
// anything real. teacher_assignments (already the source of truth for
// iLearning and subject_matrix.php) is what's actually correct. Bulk
// query + GROUP_CONCAT, same convention subject_matrix.php already uses,
// to avoid an N+1 per staff row.
$assignments_map = [];
try {
    $ta_stmt = $pdo->prepare("
        SELECT
            ta.teacher_id,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    sub.subject_name,
                    IF(sub.papers_count > 1, CONCAT(' P', ta.paper_number), ''),
                    ' (', c.class_name, IF(c.stream_name IS NOT NULL AND c.stream_name <> '', CONCAT(' ', c.stream_name), ''), ')'
                )
                ORDER BY sub.subject_name SEPARATOR ', '
            ) AS assignment_list
        FROM teacher_assignments ta
        JOIN subjects sub ON sub.id = ta.subject_id AND sub.school_id = ta.school_id
        JOIN classes  c   ON c.id  = ta.class_id  AND c.school_id  = ta.school_id
        WHERE ta.school_id = ?
        GROUP BY ta.teacher_id
    ");
    $ta_stmt->execute([$school_id]);
    while ($row = $ta_stmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments_map[(int) $row['teacher_id']] = $row['assignment_list'];
    }
} catch (Exception $e) {
    // Roster falls back to "no assignments yet" per row.
}

// The dedicated 'HR' role gets its own small sidebar (_hr_shell.php) --
// school_admin keeps the normal full admin sidebar, same as every other
// admin page. _admin_shell.php fetches its own badge internally;
// _hr_shell.php expects the caller to provide it (same contract as
// _teacher_shell.php), so that fetch only happens on the HR-role branch.
$is_hr_role = ($_SESSION['role'] ?? '') === 'hr';
$ACTIVE_NAV = $is_hr_role ? 'staff' : 'hr';

if ($is_hr_role) {
    $__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
    $__school_brand->execute([$school_id]);
    $__school_brand = $__school_brand->fetch(PDO::FETCH_ASSOC) ?: [];
    $__badge_url = null;
    if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
        $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
    }
    require_once __DIR__ . '/_hr_shell.php';
} else {
    require_once __DIR__ . '/_admin_shell.php';
}
?>
<?php if (!$is_hr_role): ?><main class="main-content"><div class="page-inner"><?php endif; ?>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

        .btn-primary { background: #10b981; color: #fff; border: none; padding: 10px 18px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s; text-transform: uppercase; letter-spacing: 0.5px;}
        .btn-primary:hover { opacity: 0.9; }

        .action-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; background: var(--panel); border: 1px solid var(--border); color: var(--muted); text-decoration: none; cursor: pointer; transition: all 0.15s; font-size: 0.9rem; }
        .action-btn.edit:hover { background: rgba(59, 130, 246, 0.1); border-color: #3b82f6; color: #3b82f6; }
        .action-btn.delete:hover { background: rgba(239, 68, 68, 0.1); border-color: #ef4444; color: #ef4444; }

        .status-badge { text-decoration: none; font-size: 0.7rem; padding: 5px 10px; border-radius: 20px; font-weight: 700; font-family: monospace; display: inline-flex; align-items: center; gap: 5px; text-transform: uppercase; transition: all 0.15s; }
        .status-badge.active { background: rgba(16, 185, 129, 0.08); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .status-badge.active:hover { background: rgba(16, 185, 129, 0.2); }
        .status-badge.inactive { background: rgba(239, 68, 68, 0.08); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); }
        .status-badge.inactive:hover { background: rgba(239, 68, 68, 0.2); }

        .form-control { width: 100%; background: var(--panel); border: 1px solid var(--border); padding: 10px 14px; border-radius: 6px; color: var(--text); font-size: 0.875rem; box-sizing: border-box; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: #10b981; }

        /* Same horizontal-tab pattern as the Report Cards pages -- Staff /
           Leave Management / Payroll are one sidebar entry (Human
           Resources) now, connected by this bar instead of three separate
           dropdown links. */
        .hr-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
        .hr-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
        .hr-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}

        /* .action-btn/.form-control/scrollbar above predate the shared
           --bg/--panel/--border vocabulary _admin_shell.php's
           [data-theme="light"] override targets (unlike .hr-tabs just
           above, which already uses it) -- so the shell/sidebar goes light
           but these stayed dark. Targeted override, same approach used
           elsewhere in Scholar for this exact gap. */
        [data-theme="light"] ::-webkit-scrollbar-track { background: #F1F5F9; }
        [data-theme="light"] ::-webkit-scrollbar-thumb { background: #CBD5E1; }
        [data-theme="light"] .action-btn { background: #F1F5F9; border-color: rgba(15,23,42,.14); color: #64748B; }
        [data-theme="light"] .form-control { background: #FFFFFF; border-color: rgba(15,23,42,.16); color: #1E293B; }
    </style>

    <div class="hr-tabs">
        <a href="staff_manager.php" class="active">Staff</a>
        <a href="hr/leave_review.php">Leave Management</a>
        <a href="hr/payroll.php">Payroll</a>
        <a href="hr/sms/wallet.php">Bulk SMS</a>
    </div>

        <?php if(!empty($msg)): ?>
            <div style="background: <?= $msg_type === 'error' ? 'rgba(239,68,68,0.06)' : 'rgba(16,185,129,0.06)' ?>; border-left: 4px solid <?= $msg_type === 'error' ? '#ef4444' : '#10b981' ?>; padding: 16px; border-radius: 4px; font-size: 0.85rem; margin-bottom: 24px; color: var(--text); font-family: monospace;">
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($invite_link): ?>
                    <div style="margin-top:10px;">
                        <input type="text" readonly value="<?= htmlspecialchars($invite_link, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px;background:var(--bg);border:1px solid var(--border);color:#00A8A8;border-radius:6px;font-family:monospace;font-size:0.8rem;" onclick="this.select();">
                        <span style="color:var(--muted);font-size:0.75rem;">Link expires in 48 hours.</span>
                    </div>
                <?php endif; ?>
                <?php if ($generated_credentials): ?>
                    <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:180px;">
                            <label style="display:block; font-size:0.65rem; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Username</label>
                            <input type="text" readonly value="<?= htmlspecialchars($generated_credentials['username'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px;background:var(--bg);border:1px solid var(--border);color:#00A8A8;border-radius:6px;font-family:monospace;font-size:0.85rem;" onclick="this.select();">
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <label style="display:block; font-size:0.65rem; color:var(--muted); text-transform:uppercase; margin-bottom:4px;">Temporary Password</label>
                            <input type="text" readonly value="<?= htmlspecialchars($generated_credentials['password'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px;background:var(--bg);border:1px solid var(--border);color:#f59e0b;border-radius:6px;font-family:monospace;font-size:0.85rem;" onclick="this.select();">
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($skipped_staff_rows)): ?>
            <div style="background: rgba(245,158,11,0.06); border-left: 4px solid #f59e0b; padding: 16px; border-radius: 4px; font-size: 0.85rem; margin-bottom: 24px; color: var(--text);">
                <strong>Some rows had warnings:</strong>
                <ul style="margin:8px 0 0; padding-left:20px;">
                    <?php foreach ($skipped_staff_rows as $row_msg): ?>
                        <li><?= htmlspecialchars($row_msg, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($bulk_credentials)): ?>
            <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; overflow-x: auto; margin-bottom: 24px;">
                <div style="padding:14px 16px; border-bottom:1px solid var(--border); background:var(--panel);">
                    <strong style="font-size:0.85rem; color:var(--text);">Bulk-Imported Login Credentials</strong>
                    <span style="display:block; font-size:0.75rem; color:var(--muted); margin-top:2px;">Copy these now and hand them out directly — passwords are shown only this once. Each person can change theirs after first login.</span>
                </div>
                <table style="width:100%; border-collapse:collapse; text-align:left; min-width:600px; font-size:0.85rem;">
                    <thead>
                        <tr style="background: var(--panel); border-bottom:1px solid var(--border);">
                            <th style="padding:10px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase;">Name</th>
                            <th style="padding:10px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase;">Staff Code</th>
                            <th style="padding:10px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase;">Username</th>
                            <th style="padding:10px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase;">Temporary Password</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulk_credentials as $cred): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:10px 16px; color:var(--text); font-weight:500;"><?= htmlspecialchars($cred['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding:10px 16px; font-family:monospace; color:#3b82f6;"><?= htmlspecialchars($cred['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding:10px 16px; font-family:monospace; color:#00A8A8;"><?= htmlspecialchars($cred['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding:10px 16px; font-family:monospace; color:#f59e0b;"><?= htmlspecialchars($cred['password'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="margin: 0 0 6px 0; font-size: 1.35rem; color:var(--text); font-weight: 700; letter-spacing: -0.5px;">Staff Management Portal</h3>
                <p style="color:var(--muted); font-size:0.85rem; margin:0;">Organize school human resource records, system access tracking tags, and academic class distribution tables.</p>
            </div>
           <button type="button" onclick="openStaffModal('add')" class="btn-primary">
                <span style="font-size: 1.1rem; line-height: 0;">+</span> Register Staff Member
           </button>
        </div>

        <details style="background:var(--panel); border:1px solid var(--border); border-radius:10px; padding:16px 20px; margin-bottom:24px;">
            <summary style="cursor:pointer; font-weight:700; font-size:0.85rem; color:var(--text); text-transform:uppercase; letter-spacing:0.5px;">Bulk CSV Import</summary>
            <div style="margin-top:16px; display:flex; flex-direction:column; gap:12px;">
                <p style="margin:0; font-size:0.82rem; color:var(--muted); line-height:1.5;">Upload a CSV to register multiple staff at once. Columns: <strong>First Name, Last Name, Email, Phone, NIN, Staff Category, Role, Assign Class, Assign Stream</strong> — Email, Phone, NIN, Assign Class and Assign Stream may be left blank. Staff Category must be "Teaching" or "Non-Teaching" (defaults to Teaching if blank/invalid). Assign Class must match a class name exactly as it appears in Manage Classes (e.g. "S.1"), or that row imports without a class assignment. Every imported row gets a temporary login password, shown once in a table above after import.</p>
                <div>
                    <a href="staff_manager.php?download_template=csv" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:6px; background:var(--bg); border:1px solid var(--border); color:var(--text); text-decoration:none; font-size:0.8rem; font-weight:600;"><i class="bi bi-download"></i> Download CSV Template</a>
                </div>
                <form method="POST" action="staff_manager.php" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="import_csv">
                    <input type="file" name="csv_file" accept=".csv" required class="form-control" style="max-width:320px;">
                    <button type="submit" style="background:#10b981; color:#fff; border:none; padding:10px 18px; border-radius:6px; font-weight:600; font-size:0.85rem; cursor:pointer;">Upload &amp; Import</button>
                </form>
            </div>
        </details>

        <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; overflow-x: auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);">
            <table style="width:100%; border-collapse:collapse; text-align:left; min-width:950px; font-size: 0.9rem;">
                <thead>
                    <tr style="background: var(--panel); border-bottom:1px solid var(--border);">
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700; width: 80px;">Actions</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700; width: 60px; text-align:center;">Photo</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700;">Staff ID</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700;">FName</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700;">Role</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700;">Department</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700;"> Roles</th>
                        <th style="padding:14px 16px; color:var(--muted); font-size:0.7rem; text-transform:uppercase; font-weight:700; width: 110px; text-align: center;">Status</th>
                    </tr>
                </thead>
                <tbody style="background: var(--bg);">
                    <?php if(empty($staff_collection)): ?>
    <tr><td colspan="8" style="padding:40px; text-align:center; color:var(--muted); font-size:0.85rem; font-family:monospace;">[No records matching database criteria found]</td></tr>
<?php else: foreach($staff_collection as $st): 
    $extra_roles = $responsibilities_map[$st['staff_id']] ?? [];
    $roles_str = !empty($extra_roles) ? ' • ' . implode(', ', $extra_roles) : '';
    
    // `staff` stores first_name/last_name separately -- there is no full_name
    // column (a prior version of this code assumed one, which meant every
    // name in this table and the edit modal silently rendered blank).
    $full_name = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
    $first_name = $st['first_name'] ?? '';
    $last_name = $st['last_name'] ?? '';

    // FIX: Using null coalescing (?? '') on every single key to prevent PHP warnings inside the HTML
    $js_payload = json_encode([
        'staff_id'               => (int)($st['staff_id'] ?? 0),
        'first_name'       => $first_name,
        'last_name'        => $last_name,
        'nin'              => $st['nin'] ?? '',
        'category'         => $st['staff_category'] ?? 'Teaching',
        'role'             => $st['role'] ?? 'Regular Teacher',
        'class'            => $st['assign_class'] ?? '',
        'stream'           => $st['assign_stream'] ?? '',
        'assignments_display' => $assignments_map[(int) ($st['staff_id'] ?? 0)] ?? '',
        'responsibilities' => $extra_roles
    ]);
?>
                        <tr style="border-bottom:1px solid var(--border); transition: background 0.1s;" onmouseover="this.style.background='var(--panel)'" onmouseout="this.style.background='transparent'">
                          <td style="padding:12px 16px; white-space:nowrap;">
    <button type="button"
            onclick="openStaffModal('update', <?= htmlspecialchars($js_payload, ENT_QUOTES, 'UTF-8') ?>)"
            class="action-btn edit"
            title="Edit Profile">
        <i class="bi bi-pencil-square"></i>
    </button>

    <a href="staff_manager.php?delete_id=<?= (int)($st['staff_id'] ?? 0) ?>"
       onclick="return confirm('Purge personnel data completely? Action is non-reversible.');"
       class="action-btn delete"
       title="Delete Profile">
        <i class="bi bi-trash3"></i>
    </a>
    <form method="POST" action="staff_manager.php" style="display:inline;" onsubmit="return confirm('Send a portal login invite to this staff member?');">
        <input type="hidden" name="action" value="send_invite">
        <input type="hidden" name="staff_id" value="<?= (int)($st['staff_id'] ?? 0) ?>">
        <button type="submit" class="action-btn" title="Send Portal Invite (needs email or phone; staff sets their own password)"><i class="bi bi-send"></i></button>
    </form>
    <form method="POST" action="staff_manager.php" style="display:inline;" onsubmit="return confirm('Generate a temporary password for this staff member now?');">
        <input type="hidden" name="action" value="generate_credentials">
        <input type="hidden" name="staff_id" value="<?= (int)($st['staff_id'] ?? 0) ?>">
        <button type="submit" class="action-btn" title="Generate Temporary Password (works without email/phone -- hand it over directly)"><i class="bi bi-key"></i></button>
    </form>
</td>
                            <td style="padding:12px 16px; text-align:center;">
                                <?php if (!empty($st['photo']) && file_exists($st['photo'])): ?>
                                    <img src="<?= htmlspecialchars($st['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:34px; height:34px; object-fit:cover; border-radius:50%; border:1px solid var(--border); background:var(--panel); display: block; margin: 0 auto;">
                                <?php else: ?>
                                    <div style="width:34px; height:34px; border-radius:50%; border:1px solid var(--border); background:var(--panel); color:var(--muted); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem; margin:0 auto;"><?= htmlspecialchars(strtoupper(substr($st['first_name'] ?? '?', 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; font-family:monospace; color:#3b82f6; font-weight:600; font-size:0.85rem;"><?= htmlspecialchars($st['staff_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:12px 16px;">
                                <div style="font-weight:600; color:var(--text); font-size:0.9rem;"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if(!empty($st['nin'])): ?>
                                    <span style="font-size:0.65rem; color:var(--muted); font-family:monospace; display:block; margin-top:2px;">NIN: <?= htmlspecialchars($st['nin'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; color:var(--muted); font-size:0.85rem;"><?= htmlspecialchars($st['staff_category'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:12px 16px; font-size:0.85rem;">
                                <span style="color:var(--text); font-weight:500;"><?= htmlspecialchars($st['role'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                <span style="color:#a855f7; font-size:0.75rem; font-weight:500; display:block; margin-top:1px;"><?= htmlspecialchars($roles_str, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="padding:12px 16px; font-size:0.85rem; color:var(--muted);">
                                <?php if(($st['staff_category'] ?? 'Teaching') === 'Teaching' && !empty($st['assign_class'])): ?>
                                    <span style="color:var(--text); font-weight:500;"><?= htmlspecialchars($st['assign_class'], ENT_QUOTES, 'UTF-8') ?></span> 
                                    <span style="color: var(--muted);">(<?= htmlspecialchars($st['assign_stream'] ?? 'Universal', ENT_QUOTES, 'UTF-8') ?>)</span> 
                                    <?php $assignment_str = $assignments_map[(int) ($st['staff_id'] ?? 0)] ?? ''; ?>
                                    <?php if ($assignment_str !== ''): ?>
                                        <div style="color:#00A8A8; font-size:0.75rem; margin-top:2px; font-weight:500;"><?= htmlspecialchars($assignment_str, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php else: ?>
                                        <div style="margin-top:2px;"><a href="school_admin/assign_teacher.php?staff_id=<?= (int) ($st['staff_id'] ?? 0) ?>" style="color:var(--muted); font-style:italic; font-size:0.75rem;">No teaching assignments yet — assign →</a></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--muted); font-style:italic; font-size:0.8rem;">Operations Control</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; text-align: center;">
                                <a href="staff_manager.php?toggle_status=<?= $st['staff_id'] ?>&current=<?= $st['status'] ?>" class="status-badge <?= $st['status'] === 'active' ? 'active' : 'inactive' ?>">
                                    ● <?= $st['status'] ?? 'INACTIVE' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    <div id="staffCreationModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(3,4,6,0.8); backdrop-filter:blur(4px); z-index:9999; justify-content:center; align-items:center; box-sizing:border-box; padding:20px;">
        <div style="background:var(--bg); border:1px solid var(--border); width:100%; max-width:640px; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.5); overflow:hidden; animation: modalSlide 0.15s ease-out;">
            <style>@keyframes modalSlide { from { transform:scale(0.97); opacity:0; } to { transform:scale(1); opacity:1; } }</style>
            
            <div style="padding:18px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--panel);">
                <h4 id="staffModalTitle" style="margin:0; font-size:0.9rem; text-transform:uppercase; color:var(--text); letter-spacing:0.5px; font-weight: 700;">Setup Workspace Profile</h4>
                <button type="button" onclick="closeStaffModal()" style="background:transparent; border:none; color:var(--muted); font-size:1.1rem; cursor:pointer; padding:4px; transition: color 0.2s;" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">✕</button>
            </div>
            
            <form action="staff_manager.php" method="POST" enctype="multipart/form-data" style="padding:24px; margin:0; display:flex; flex-direction:column; gap:16px; max-height:80vh; overflow-y:auto;">
                <input type="hidden" name="form_action" id="staffFormAction" value="add">
                <input type="hidden" name="staff_row_id" id="staffFormRowId" value="">

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">First Name *</label>
                        <input type="text" name="first_name" id="stf_f_name" required class="form-control">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Last Name / Surname *</label>
                        <input type="text" name="last_name" id="stf_l_name" required class="form-control">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Email</label>
                        <input type="email" name="email" id="stf_email" class="form-control" placeholder="Needed to send their portal invite">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Phone</label>
                        <input type="text" name="phone" id="stf_phone" class="form-control" placeholder="e.g. 0772000000">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Uganda National ID (NIN)</label>
                        <input type="text" name="nin" id="stf_nin" placeholder="e.g., CM85023100XXXX" class="form-control" style="font-family:monospace;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Staff Group</label>
                        <select name="staff_category" id="stf_category" onchange="evaluateCategoryFields(this.value)" class="form-control" style="cursor: pointer;">
                            <option value="Teaching">Teaching / Academic Staff</option>
                            <option value="Non-Teaching">Non-Teaching Operations Team</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Primary System Role</label>
                        <select name="role" id="stf_primary_role" class="form-control" style="cursor: pointer;">
                            <option value="Regular Teacher">Regular Teacher</option>
                            <option value="Head Teacher">Head Teacher</option>
                            <option value="Deputy Head Teacher">Deputy Head Teacher</option>
                            <option value="Director of Studies (DOS)">Director of Studies (DOS)</option>
                            <option value="Bursar">Bursar</option>
                            <option value="Accountant">Accountant</option>
                            <option value="Secretary">Secretary</option>
                            <option value="Warden">Warden / Matron</option>
                            <option value="Nurse">Nurse (School Clinic)</option>
                            <option value="HR Manager">HR Manager</option>
                            <option value="System Administrator">System Administrator</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:var(--muted); font-weight:700; margin-bottom:6px; letter-spacing: 0.5px;">Upload Profile Picture</label>
                        <input type="file" name="staff_photo" accept="image/*" class="form-control" style="padding: 6px 12px; color: var(--muted);">
                    </div>
                </div>

                <div style="background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:16px;">
                    <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:#a855f7; font-weight:700; margin-bottom:12px; letter-spacing: 0.5px;">Secondary Overlapping Responsibilities</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <label style="font-size:0.85rem; color:var(--text); display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" name="responsibilities[]" value="DOS" class="stf_resp_check"> Director of Studies (DOS)</label>
                        <label style="font-size:0.85rem; color:var(--text); display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" name="responsibilities[]" value="Deputy DOS" class="stf_resp_check"> Deputy DOS</label>
                        <label style="font-size:0.85rem; color:var(--text); display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" name="responsibilities[]" value="Class Teacher" class="stf_resp_check"> Class Teacher</label>
                        <label style="font-size:0.85rem; color:var(--text); display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" name="responsibilities[]" value="Head of Department" class="stf_resp_check"> Head of Department (HOD)</label>
                        <label style="font-size:0.85rem; color:var(--text); display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" name="responsibilities[]" value="House Master" class="stf_resp_check"> House Master / Mistress</label>
                    </div>
                </div>

                <div id="academicAllocationBox" style="background:var(--panel); border:1px solid var(--border); border-radius:8px; padding:16px; transition: opacity 0.2s;">
                    <label style="display:block; font-size:0.65rem; text-transform:uppercase; color:#00A8A8; font-weight:700; margin-bottom:12px; letter-spacing: 0.5px;">Academic Assignment Vectors</label>
                    <?php if (empty($class_names)): ?>
                        <div style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.3); border-radius:6px; padding:10px 12px; font-size:0.8rem; color:#fbbf24; margin-bottom:12px;">
                            No classes set up yet, so there's nothing to pick from here.
                            <a href="school_admin/classes.php" style="color:#00A8A8; font-weight:600;">Set up classes first &rarr;</a>
                        </div>
                    <?php endif; ?>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div>
                            <label style="font-size:0.65rem; text-transform:uppercase; color:var(--muted); display:block; margin-bottom:5px; font-weight: 600;">Class</label>
                            <select name="assign_class" id="stf_assign_class" class="form-control" style="background: var(--bg); padding: 8px 12px;" onchange="onStaffClassChange()">
                                <option value="">— Select Class —</option>
                                <?php foreach ($class_names as $cn): ?>
                                    <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.65rem; text-transform:uppercase; color:var(--muted); display:block; margin-bottom:5px; font-weight: 600;">Stream</label>
                            <select name="assign_stream" id="stf_assign_stream" class="form-control" style="background: var(--bg); padding: 8px 12px;">
                                <option value="">— Select Class First —</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.65rem; text-transform:uppercase; color:var(--muted); display:block; margin-bottom:5px; font-weight: 600;">Subjects Taught</label>
                            <div id="stf_assignments_display" style="background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:8px 12px; font-size:0.8rem; color:var(--muted); min-height:38px; display:flex; align-items:center;">
                                Save this staff member first, then assign subjects.
                            </div>
                            <a id="stf_manage_assignments_link" href="#" style="display:none; font-size:0.75rem; color:#00A8A8; margin-top:4px;">Manage teaching assignments &rarr;</a>
                        </div>
                    </div>
                </div>

                <button type="submit" name="persist_staff_record" style="background:#10b981; color:#fff; border:none; padding:12px; border-radius:6px; font-weight:600; font-size:0.85rem; cursor:pointer; text-transform:uppercase; margin-top:4px; letter-spacing:0.5px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">Save Records to Database</button>
            </form>
        </div>
    </div>

    </div><!-- /.page-inner -->
    <?php if (!$is_hr_role): ?></main><?php endif; ?>
</div><!-- /.app-shell / .main-col -->
<?php if ($is_hr_role): ?></div><?php endif; ?>

    <script>
        const CLASS_STREAMS_MAP  = <?= json_encode($class_streams_map, JSON_HEX_TAG) ?>;

        function evaluateCategoryFields(category) {
            var allocationBox = document.getElementById('academicAllocationBox');
            var inputs = allocationBox.querySelectorAll('input, select');
            if (category === 'Non-Teaching') {
                allocationBox.style.opacity = '0.35';
                inputs.forEach(el => el.disabled = true);
            } else {
                allocationBox.style.opacity = '1';
                inputs.forEach(el => el.disabled = false);
            }
        }

        // Rebuilds the Stream dropdown to only show options that actually
        // belong to the selected class. If editing a staff record whose
        // stored stream isn't in that list anymore (e.g. the class was
        // renamed since), that stored value is kept as an extra option
        // instead of being silently dropped. Subject is no longer handled
        // here at all -- see openStaffModal(), it's now a read-only display
        // of real teacher_assignments rows, not a free-typed field on this
        // form.
        function onStaffClassChange(presetStream) {
            var className = document.getElementById('stf_assign_class').value;
            var streamSel = document.getElementById('stf_assign_stream');

            var streams = CLASS_STREAMS_MAP[className] || [];

            streamSel.innerHTML = '<option value="">— No Stream —</option>';
            streams.forEach(function(s) {
                var opt = document.createElement('option');
                opt.value = s; opt.textContent = s;
                streamSel.appendChild(opt);
            });
            if (presetStream && !streams.includes(presetStream)) {
                var opt = document.createElement('option');
                opt.value = presetStream; opt.textContent = presetStream + ' (not in class list)';
                streamSel.appendChild(opt);
            }
            streamSel.value = presetStream || "";
        }

        function openStaffModal(actionType, data = null) {
            document.getElementById('staffFormAction').value = actionType;
            document.querySelectorAll('.stf_resp_check').forEach(cb => cb.checked = false);
            
            if (actionType === 'add') {
                document.getElementById('staffModalTitle').innerText = "Register New Staff Member";
                document.getElementById('staffFormRowId').value = "";
                document.getElementById('stf_f_name').value = "";
                document.getElementById('stf_l_name').value = "";
                document.getElementById('stf_email').value = "";
                document.getElementById('stf_phone').value = "";
                document.getElementById('stf_nin').value = "";
                document.getElementById('stf_category').value = "Teaching";
                document.getElementById('stf_primary_role').value = "Regular Teacher";
                document.getElementById('stf_assign_class').value = "";
                onStaffClassChange();
                evaluateCategoryFields("Teaching");
                document.getElementById('stf_assignments_display').textContent = "Save this staff member first, then assign subjects.";
                document.getElementById('stf_manage_assignments_link').style.display = 'none';
            } else if (actionType === 'update' && data) {
                document.getElementById('staffModalTitle').innerText = "Modify Personnel Profile Credentials";
                // NOTE: was previously "data.id", which doesn't exist on this
                // payload (the key is "staff_id") -- so this always set the
                // hidden row-id field to the literal string "undefined",
                // meaning $_POST['staff_row_id'] never matched a real row and
                // the whole update handler silently did nothing. Editing a
                // staff member has been a no-op until this fix.
                document.getElementById('staffFormRowId').value = data.staff_id;
                document.getElementById('stf_f_name').value = data.first_name;
                document.getElementById('stf_l_name').value = data.last_name;
                document.getElementById('stf_email').value = data.email || "";
                document.getElementById('stf_phone').value = data.phone || "";
                document.getElementById('stf_nin').value = data.nin;
                document.getElementById('stf_category').value = data.category;
                document.getElementById('stf_primary_role').value = data.role;
                document.getElementById('stf_assign_class').value = data.class;
                onStaffClassChange(data.stream);

                var assignDisplay = document.getElementById('stf_assignments_display');
                var assignLink = document.getElementById('stf_manage_assignments_link');
                assignDisplay.textContent = data.assignments_display || 'No teaching assignments yet.';
                assignLink.href = 'school_admin/assign_teacher.php?staff_id=' + data.staff_id;
                assignLink.style.display = 'inline-block';

                evaluateCategoryFields(data.category);
                
                if (data.responsibilities && Array.isArray(data.responsibilities)) {
                    document.querySelectorAll('.stf_resp_check').forEach(cb => {
                        if (data.responsibilities.includes(cb.value)) {
                            cb.checked = true;
                        }
                    });
                }
            }
            document.getElementById('staffCreationModal').style.display = 'flex';
        }

        function closeStaffModal() {
            document.getElementById('staffCreationModal').style.display = 'none';
        }
    </script>
</body>
</html>