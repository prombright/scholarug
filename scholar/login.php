<?php
declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| SCHOLAR CENTRAL LOGIN GATEWAY
|--------------------------------------------------------------------------
| ScholarUg
| Multi-Tenant School Management Platform
|--------------------------------------------------------------------------
*/


session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);


require_once 'db.php';
require_once __DIR__ . '/auth_guard.php'; // for role_destination() below



$error = '';
$success = isset($_GET['reset'])
    ? 'Password updated -- log in with your new password.'
    : (isset($_GET['timeout']) ? 'You were signed out after 30 minutes of inactivity. Please log in again.' : '');



/*
|--------------------------------------------------------------------------
| RESET OLD SESSION SAFELY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    // Previously preserved school_id across this reset so the login page
    // could show the last-logged-in school's badge/name. That's what was
    // leaking one school's branding into the next visitor's login screen
    // on a shared device -- the login panel should always be neutral
    // ScholarUg branding, so nothing from the old session survives.
    session_unset();

}



/*
|--------------------------------------------------------------------------
| LOGIN PROCESS
|--------------------------------------------------------------------------
*/


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $username =
        trim($_POST['username'] ?? '');


    $password =
        trim($_POST['password'] ?? '');



    if ($username === '' || $password === '') {


        $error =
        "Please provide username and password.";


    } else {


        try {


            $authenticated = false;



            /*
            |--------------------------------------------------------------------------
            | METHOD 1
            | SCHOOL CODE LOGIN
            |
            | Example:
            |
            | SC-001
            | 12345
            |
            |--------------------------------------------------------------------------
            */


            if(
                preg_match(
                    '/^SC-\d+$/i',
                    $username
                )
            ){


                $school_stmt =
                $pdo->prepare(

                "SELECT
                    id,
                    school_name,
                    school_badge,
                    location,
                    access_pin,
                    is_active,
                    current_term,
                    current_year

                 FROM schools

                 WHERE UPPER(school_code)
                 =
                 UPPER(?)

                 LIMIT 1"

                );



                $school_stmt->execute([
                    $username
                ]);



                $school =
                $school_stmt->fetch(PDO::FETCH_ASSOC);



                if($school){



                    if(
                        (int)$school['is_active'] !== 1
                    ){


                        $error =
                        "School account is currently inactive.";


                    }



                    elseif(
                        $password ===
                        $school['access_pin']
                    ){



                        /*
                        |
                        | SCHOOL ADMIN SESSION
                        |
                        */

                        // Regenerate the session ID on every successful
                        // login (same as developer_login.php) -- without
                        // this, a session ID an attacker planted before
                        // authentication would still be valid afterward
                        // (session fixation).
                        session_regenerate_id(true);


                        $_SESSION['user_id'] =
                            "school_admin_".$school['id'];


                        $_SESSION['username'] =
                            $username;


                        $_SESSION['role'] =
                            "school_admin";


                        $_SESSION['school_id'] =
                            $school['id'];



                        $_SESSION['school_name'] =
                            $school['school_name'];



                        $_SESSION['school_badge'] =
                            $school['school_badge']
                            ??
                            'assets/img/default-logo.png';



                        $_SESSION['school_location'] =
                            $school['location']
                            ??
                            'Uganda';



                        $_SESSION['current_term'] =
                            $school['current_term']
                            ??
                            'Term 1';



                        $_SESSION['current_year'] =
                            $school['current_year']
                            ??
                            (string) date('Y');



                        header("Location: school_admin/school_admin_dashboard.php");


                        exit;


                    }



                    else {
                        // Not the shared school access PIN. Staff and
                        // students don't authenticate through the school
                        // code field at all -- they use their own
                        // username + password below (Method 2), which
                        // is the single place that checks credentials
                        // against `users` and redirects by role, so
                        // there is only one routing table to keep in
                        // sync, not two.
                        $error = "Invalid school code or access PIN. If you're staff or a student, log in with your username and password instead.";
                    }

            } // closes if($school)

            } // closes if(preg_match('/^SC-\d+$/i', $username))




            /*
            |--------------------------------------------------------------------------
            | METHOD 2
            | NORMAL USER LOGIN
            |
            | developer
            | school_admin
            | teacher
            | student
            |
            |--------------------------------------------------------------------------
            */


            // Skip this entirely if the school-code branch above already
            // produced a specific error (wrong PIN for a real school code) --
            // otherwise this always ran a second time (since $authenticated
            // is never actually set true above) and silently overwrote that
            // helpful message with a generic "invalid username or password".
            if(!$authenticated && $error === ''){



                $stmt =
                $pdo->prepare(

                "SELECT

                    u.id,
                    u.username,
                    u.email,
                    u.password,
                    u.role,
                    u.school_id,
                    u.is_temp_password,
                    u.staff_id,
                    u.student_id,

                    s.school_name,
                    s.school_badge,
                    s.location AS school_location,
                    s.current_term,
                    s.current_year


                 FROM users u


                 LEFT JOIN schools s

                 ON u.school_id=s.id


                 WHERE

                    u.username=?

                    OR

                    u.email=?"

                );



                $stmt->execute([
                    $username,
                    $username
                ]);



                // A teacher assigned to more than one school gets a
                // separate `users` row per school, and (since the
                // multi-school login migration) the same email/phone can
                // now recur once per school -- so more than one row here
                // is expected, not an error. Whichever row's password
                // actually matches is the school this login opens.
                $candidates =
                $stmt->fetchAll(PDO::FETCH_ASSOC);

                $user = null;

                foreach ($candidates as $candidate) {
                    if (
                        $password === $candidate['password']
                        ||
                        password_verify($password, $candidate['password'])
                    ) {
                        $user = $candidate;
                        break;
                    }
                }

                if($user){



                    /*
                    |--------------------------------------------------------------------------
                    | CREATE SESSION
                    |--------------------------------------------------------------------------
                    */

                    // See the matching comment in the school-code/PIN
                    // branch above -- same session-fixation fix.
                    session_regenerate_id(true);


                    $_SESSION['user_id'] =
                        $user['id'];



                    $_SESSION['username'] =
                        $user['username'];



                    $_SESSION['role'] =
                        strtolower(
                            $user['role']
                        );



                    $_SESSION['school_id'] =
                        $user['school_id'];



                    $_SESSION['staff_id'] =
                        $user['staff_id'];

                    $_SESSION['student_id'] =
                        $user['student_id'];



                    $_SESSION['school_name'] =
                        $user['school_name']
                        ??
                        'Scholar Portal';



                    $_SESSION['school_badge'] =
                        $user['school_badge']
                        ??
                        'assets/img/default-logo.png';



                    $_SESSION['school_location'] =
                        $user['school_location']
                        ??
                        'Uganda';



                    $_SESSION['current_term'] =
                        $user['current_term']
                        ??
                        'Term 1';



                    $_SESSION['current_year'] =
                        $user['current_year']
                        ??
                        (string) date('Y');



                    if ((int) $user['is_temp_password'] === 1) {
                        header("Location: force_password_reset.php");
                        exit;
                    }



                    /*
                    |--------------------------------------------------------------------------
                    | ROLE REDIRECTION
                    |--------------------------------------------------------------------------
                    | role_destination() lives in auth_guard.php and is the one place this
                    | role -> page mapping is defined. force_password_reset.php uses the
                    | same function, so the two can no longer drift apart.
                    */

                    header("Location: " . role_destination($_SESSION['role']));



                    exit;



                }


                else{


                    $error =
                    "Invalid username or password.";


                }


            }



        }

        catch(Throwable $e){


            $error =
            "System error: ".$e->getMessage();


        }


    }


}



/*
|--------------------------------------------------------------------------
| LOGIN PAGE BRANDING
|--------------------------------------------------------------------------
| Always the plain ScholarUg word logo -- this used to look up the last
| session's school and show its badge/name instead, which is exactly what
| leaked one school's branding onto the next visitor's login screen on a
| shared device. This is a shared multi-tenant login gateway, not any one
| school's page, so it never shows per-school branding.
|--------------------------------------------------------------------------
*/

$login_school_name = 'ScholarUg';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($login_school_name, ENT_QUOTES, 'UTF-8') ?> | Login</title>
    <style>
        /* Fixed brand-identity split, not the app's usual light/dark toggle
           theme -- this layout's two tones (white brand panel, dark navy
           form panel) are the design, not a preference, so the floating
           theme toggle from preloader.php is suppressed on this page. */
        :root {
            --panel-dark: #080b11;
            --panel-dark-raised: #131b28;
            --border-dark: #2a3a52;
            --text-dark: #e2e8f0;
            --muted-dark: #64748b;
            --cyan: #00A8A8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; font-family: 'Segoe UI', system-ui, sans-serif;
            /* Soft cyan + navy glow behind the card so the page reads as
               one continuous design instead of "grey page, card dropped
               on top" -- same two colors as the card itself, just faded
               out into the backdrop rather than introduced fresh. */
            background:
                radial-gradient(640px circle at 12% 8%, rgba(0,168,168,0.12), transparent 60%),
                radial-gradient(720px circle at 92% 95%, rgba(8,11,17,0.10), transparent 60%),
                #eef2f6;
            display: flex; align-items: center; justify-content: center;
            padding: 24px 16px;
        }
        .scholar-theme-toggle { display: none !important; }

        /* One card -- white brand half + dark form half -- floating on the
           page background, not stretched edge-to-edge. */
        .split {
            display: flex; flex-direction: column;
            width: 100%; max-width: 940px;
            border-radius: 20px; overflow: hidden;
            box-shadow: 0 25px 60px -15px rgba(15,23,42,0.35);
        }

        .panel-brand {
            background: #fff; color: #0f172a;
            display: grid; grid-template-rows: 1fr auto;
            padding: 32px 24px;
        }
        .brand-center { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; padding: 16px 0; }
        .wordmark { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; }
        .wordmark .accent { color: var(--cyan); }
        .tagline { color: #64748b; font-size: 0.8rem; letter-spacing: 0.02em; }

        /* Emphasized, not an afterthought -- a bordered block of its own
           rather than small muted text tucked under the form. */
        .contacts {
            border-top: 1px solid #e2e8f0; padding-top: 18px; margin-top: 24px;
            display: flex; flex-direction: column; gap: 6px;
            font-size: 0.85rem;
        }
        .contacts-heading { font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; margin-bottom: 4px; }
        .contacts a { color: #0f172a; font-weight: 600; text-decoration: none; }
        .contacts a:hover { color: var(--cyan); }

        .panel-form {
            background: var(--panel-dark); color: var(--text-dark);
            display: flex; align-items: center; justify-content: center;
            padding: 32px 24px;
        }
        .form-wrap { width: 100%; max-width: 360px; }
        .form-eyebrow { color: var(--muted-dark); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 6px; }
        .form-title { font-size: 1.4rem; font-weight: 700; margin-bottom: 28px; }
        .form-control { width: 100%; background: var(--panel-dark-raised); border: 1px solid var(--border-dark); padding: 12px 14px; border-radius: 6px; color: #fff; font-size: 0.875rem; transition: border-color 0.15s; }
        .form-control:focus { outline: none; border-color: var(--cyan); }
        .btn-access { width: 100%; background: var(--cyan); color: #04222a; border: none; padding: 14px; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; border-radius: 6px; cursor: pointer; letter-spacing: 0.5px; margin-top: 10px; }
        .btn-access:hover { filter: brightness(1.08); }
        .hint { color: var(--muted-dark); font-size: 0.7rem; text-align: center; margin-top: 18px; line-height: 1.5; }
        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 0.65rem; text-transform: uppercase; color: var(--muted-dark); font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px; }

        @media (min-width: 860px) {
            .split { flex-direction: row; min-height: 500px; }
            .panel-brand { flex: 0 0 44%; padding: 56px; }
            .panel-form { flex: 1; padding: 56px; }
            .wordmark { font-size: 40px; }
            /* Diagonal seam between the two panels, echoing the reference
               design -- clip-path on both edges so they interlock with no
               gap or overlap seam showing through. */
            .panel-brand { clip-path: polygon(0 0, 100% 0, 84% 100%, 0 100%); }
            .panel-form { margin-left: -12%; padding-left: calc(12% + 56px); clip-path: polygon(16% 0, 100% 0, 100% 100%, 0 100%); }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>

<div class="split">
    <div class="panel-brand">
        <div class="brand-center">
            <div class="wordmark">
                Scholar<span class="accent">Ug</span>
            </div>
            <div class="tagline">Multi-Tenant School Management Platform</div>
        </div>

        <div class="contacts">
            <div class="contacts-heading">Need help signing in?</div>
            <a href="mailto:info@scholarug.com">&#9993; info@scholarug.com</a>
            <a href="tel:+256759815047">&#9742; 0759 815 047</a>
            <a href="tel:+256788643794">&#9742; 0788 643 794</a>
        </div>
    </div>

    <div class="panel-form">
        <div class="form-wrap">
            <div class="form-eyebrow">Welcome back</div>
            <div class="form-title">Sign in to Scholar</div>

            <?php if (!empty($error)): ?>
                <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-left: 4px solid #ef4444; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #fca5a5; margin-bottom: 20px;">
                    &#9888; <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-left: 4px solid #10b981; padding: 12px; border-radius: 6px; font-size: 0.8rem; color: #6ee7b7; margin-bottom: 20px;">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" autocomplete="off">
                <div class="field">
                    <label>Username / Email / School Code</label>
                    <input type="text" name="username" required class="form-control" placeholder="e.g. jdoe or SC-001">
                </div>

                <div class="field">
                    <label>Password / Access PIN</label>
                    <input type="password" name="password" required class="form-control" placeholder="********">
                    <div style="text-align:right;margin-top:6px;">
                        <a href="forgot_password.php" style="color:var(--muted-dark);font-size:0.7rem;">Forgot password?</a>
                    </div>
                </div>

                <button type="submit" class="btn-access">Log In</button>
            </form>

            <div class="hint">
                School admins can also log in with their School Code (e.g. SC-001) and Access PIN instead of a username/password.
            </div>
        </div>
    </div>
</div>

</body>
</html>