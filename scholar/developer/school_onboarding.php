<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require '../db.php';

if(session_status() !== PHP_SESSION_ACTIVE){
    session_start();
}


/*
|--------------------------------------------------------------------------
| DEVELOPER AUTHENTICATION
|--------------------------------------------------------------------------
*/

if(
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
){

    header("Location: login.php");
    exit;

}


$msg = '';
$msg_type = 'success';



/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if(empty($_SESSION['developer_csrf_token'])){

    $_SESSION['developer_csrf_token'] =
        bin2hex(random_bytes(32));

}


$csrf_token =
    $_SESSION['developer_csrf_token'];



function verify_csrf():void
{

    if(
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['developer_csrf_token'],
            $_POST['csrf_token']
        )
    ){

        http_response_code(403);

        exit("Invalid security token.");

    }

}



/*
|--------------------------------------------------------------------------
| PROVISION SCHOOL
|--------------------------------------------------------------------------
*/


if($_SERVER['REQUEST_METHOD']==='POST'){


    verify_csrf();



    if(isset($_POST['provision_school'])){


        $school_name =
            trim($_POST['school_name'] ?? '');


        $school_type =
            $_POST['school_type'] ?? '';



        if($school_name===''){


            $msg =
                "School name is required.";

            $msg_type='error';


        }elseif(!in_array($school_type, ['Primary', 'Secondary'], true)){


            $msg =
                "Select a school type.";

            $msg_type='error';


        }else{


            try{


                /*
                Generate School Code
                */


                $code_stmt =
                    $pdo->query(

                    "SELECT school_code
                     FROM schools
                     WHERE school_code LIKE 'SC-%'
                     ORDER BY id DESC
                     LIMIT 1"

                    );


                $last_code =
                    $code_stmt->fetchColumn();



                $next_id = 1;



                if($last_code){


                    $number =
                        (int)preg_replace(
                            '/[^0-9]/',
                            '',
                            $last_code
                        );


                    $next_id =
                        $number + 1;

                }



                $school_code =
                    'SC-' .
                    str_pad(
                        (string)$next_id,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );



                /*
                Generate Setup PIN
                */


                $pin =
                    random_int(
                        10000,
                        99999
                    );



                /*
                Insert School
                */


                /*
                Note: this handler has no redirect-after-POST guard, so a
                page refresh resubmits and provisions a second school with
                a new code/PIN -- pre-existing behavior, not fixed here.
                Now that provisioning also eager-seeds classes/subjects
                below, a duplicate submission is more wasteful than before.
                */

                $stmt =
                    $pdo->prepare(

                    "INSERT INTO schools
                    (
                        school_name,
                        school_code,
                        access_pin,
                        school_type,
                        payment_status,
                        is_active
                    )

                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending',
                        1
                    )"

                    );



                $stmt->execute([

                    $school_name,
                    $school_code,
                    $pin,
                    $school_type

                ]);



                /*
                Seed the new school's class ladder + default subjects for
                its type immediately, so it's not an empty shell waiting
                on the school_admin's first visit to classes.php.
                */

                require_once __DIR__ . '/../_subject_helpers.php';

                $new_school_id =
                    (int)$pdo->lastInsertId();

                scholar_provision_school_type_defaults(
                    $pdo,
                    $new_school_id,
                    $school_type
                );



                $msg =
                    "School provisioned successfully.
                     Code: {$school_code}
                     Setup PIN: {$pin}";


                $msg_type='success';



            }catch(Throwable $e){


                $msg =
                    "Provisioning failed: ".
                    $e->getMessage();


                $msg_type='error';


            }


        }


    }


}



?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">


<title>
ScholarUg | School Provisioning
</title>


<style>

body{

margin:0;

background:#080b11;

font-family:
Inter,
Segoe UI,
sans-serif;

color:#e2e8f0;

}


.container{

max-width:800px;

margin:60px auto;

padding:30px;

}



.card{

background:#0d1118;

border:1px solid #1e293b;

border-radius:15px;

padding:30px;

}


h1{

color:white;

margin-bottom:10px;

}


p{

color:#64748b;

}



.form-group{

margin-bottom:20px;

}



label{

display:block;

font-size:.75rem;

text-transform:uppercase;

color:#64748b;

margin-bottom:8px;

font-weight:bold;

}



input,select{

width:100%;

padding:14px;

background:#080b11;

border:1px solid #1e293b;

border-radius:8px;

color:white;

}



button{

padding:14px 25px;

border:none;

border-radius:8px;

background:#06b6d4;

color:white;

font-weight:bold;

cursor:pointer;

}



.alert{

padding:15px;

border-radius:8px;

margin-bottom:20px;

}



.success{

background:
rgba(16,185,129,.1);

color:#6ee7b7;

}



.error{

background:
rgba(239,68,68,.1);

color:#fca5a5;

}


.back{

display:inline-block;

margin-top:20px;

color:#06b6d4;

text-decoration:none;

}


</style>


</head>


<body>


<div class="container">


<div class="card">


<h1>
School Provisioning
</h1>


<p>
Register a new school into the ScholarUg platform.
</p>



<?php if($msg): ?>


<div class="alert <?= $msg_type ?>">

<?= htmlspecialchars(
$msg,
ENT_QUOTES,
'UTF-8'
) ?>

</div>


<?php endif; ?>



<form method="POST">


<input type="hidden"
name="csrf_token"
value="<?= $csrf_token ?>">



<div class="form-group">


<label>
School Name
</label>


<input

type="text"

name="school_name"

placeholder="Enter school name"

required

>


</div>



<div class="form-group">


<label>
School Type
</label>


<select

name="school_type"

required

>


<option value="" disabled selected>
Select school type
</option>

<option value="Primary">
Primary (incl. Pre-Primary)
</option>

<option value="Secondary">
Secondary (O-Level / A-Level)
</option>


</select>


</div>



<button
name="provision_school">

Provision School

</button>


</form>



<a class="back"
href="developer_dashboard.php">

← Back to Dashboard

</a>


</div>


</div>


</body>

</html>