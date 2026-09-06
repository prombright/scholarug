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

    $_SESSION['developer_csrf_token']
        = bin2hex(random_bytes(32));

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
| SCHOOL ID
|--------------------------------------------------------------------------
*/


$school_id =
    (int)($_GET['school_id'] ?? $_POST['school_id'] ?? 0);



if($school_id <= 0){

    header("Location: schools.php");
    exit;

}




/*
|--------------------------------------------------------------------------
| FETCH SCHOOL
|--------------------------------------------------------------------------
*/


$stmt =
$pdo->prepare(

"SELECT *
 FROM schools
 WHERE id=?
 LIMIT 1"

);


$stmt->execute([$school_id]);


$school =
$stmt->fetch(PDO::FETCH_ASSOC);



if(!$school){

    exit("School not found.");

}




/*
|--------------------------------------------------------------------------
| CREATE ADMIN
|--------------------------------------------------------------------------
*/


if($_SERVER['REQUEST_METHOD']==='POST'){


    verify_csrf();



    if(isset($_POST['create_admin'])){


        $username =
            trim($_POST['username'] ?? '');



        $email =
            trim($_POST['email'] ?? '');



        $password =
            trim($_POST['password'] ?? '');



        if(
            $username==='' ||
            $email==='' ||
            $password===''
        ){


            $msg =
            "All fields are required.";

            $msg_type='error';



        }elseif(strlen($password)<8){


            $msg =
            "Password must contain at least 8 characters.";

            $msg_type='error';



        }else{


            try{


                /*
                Check existing user
                */


                $check =
                $pdo->prepare(

                "SELECT id
                 FROM users
                 WHERE email=?
                 LIMIT 1"

                );


                $check->execute([$email]);



                if($check->fetch()){


                    throw new Exception(
                        "Email already exists."
                    );


                }



                /*
                Create user
                */


                $hashed_password =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );



                $insert =
                $pdo->prepare(

                "INSERT INTO users
                (
                    username,
                    email,
                    password,
                    role,
                    school_id
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    'school_admin',
                    ?
                )"

                );



                $insert->execute([

                    $username,
                    $email,
                    $hashed_password,
                    $school_id

                ]);



                $msg =
                "School administrator created successfully.";

                $msg_type='success';



            }catch(Throwable $e){


                $msg =
                "Unable to create administrator: "
                .$e->getMessage();


                $msg_type='error';


            }



        }



    }



}



/*
|--------------------------------------------------------------------------
| EXISTING ADMINS
|--------------------------------------------------------------------------
*/


$admins_stmt =
$pdo->prepare(

"SELECT id,username,email,role
 FROM users
 WHERE school_id=?
 ORDER BY id DESC"

);


$admins_stmt->execute([$school_id]);


$admins =
$admins_stmt->fetchAll(PDO::FETCH_ASSOC);



?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">


<title>
ScholarUg | School Administrator
</title>


<style>



:root {
    --bg: #080b11;
    --panel: #0d1118;
    --border: #1e293b;
    --text: #e2e8f0;
    --muted: #64748b;
}

body{

margin:0;

background:var(--bg);

font-family:
Inter,
Segoe UI,
sans-serif;

color:var(--text);

}


.container{

max-width:900px;

margin:auto;

padding:35px;

}



.card{

background:var(--panel);

border:1px solid var(--border);

border-radius:15px;

padding:30px;

margin-bottom:25px;

}



h1,h2{

color:var(--text);

}



.subtitle{

color:var(--muted);

}



label{

display:block;

font-size:.75rem;

color:var(--muted);

text-transform:uppercase;

margin-bottom:8px;

font-weight:bold;

}



input{

width:100%;

padding:13px;

background:var(--bg);

border:1px solid var(--border);

border-radius:8px;

color:var(--text);

margin-bottom:18px;

}



button{

background:#06b6d4;

border:none;

padding:13px 20px;

border-radius:8px;

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

background:rgba(16,185,129,.1);

color:#6ee7b7;

}


.error{

background:rgba(239,68,68,.1);

color:#fca5a5;

}



table{

width:100%;

border-collapse:collapse;

}



th{

text-align:left;

color:var(--muted);

padding:12px;

}



td{

padding:12px;

border-top:1px solid var(--border);

}



.back{

color:#06b6d4;

text-decoration:none;

}


</style>


</head>


<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">



<div class="card">


<h1>
Provision School Administrator
</h1>


<p class="subtitle">

School:
<strong>
<?=htmlspecialchars($school['school_name'])?>
</strong>

<br>

Code:
<?=htmlspecialchars($school['school_code'])?>

</p>



<?php if($msg): ?>


<div class="alert <?=$msg_type?>">

<?=htmlspecialchars($msg)?>

</div>


<?php endif; ?>



<form method="POST">


<input type="hidden"
name="csrf_token"
value="<?=$csrf_token?>">


<input type="hidden"
name="school_id"
value="<?=$school_id?>">



<label>
Username
</label>


<input
type="text"
name="username"
placeholder="School admin username"
required>



<label>
Email
</label>


<input
type="email"
name="email"
placeholder="admin@school.com"
required>



<label>
Temporary Password
</label>


<input
type="password"
name="password"
placeholder="Minimum 8 characters"
required>



<button name="create_admin">

Create Administrator

</button>


</form>


</div>





<div class="card">


<h2>
Existing School Users
</h2>



<table>


<tr>

<th>
Username
</th>

<th>
Email
</th>

<th>
Role
</th>

</tr>



<?php foreach($admins as $admin): ?>


<tr>

<td>
<?=$admin['username']?>
</td>


<td>
<?=$admin['email']?>
</td>


<td>
<?=$admin['role']?>
</td>


</tr>


<?php endforeach; ?>


</table>


</div>



<a class="back"
href="school_profile.php?id=<?=$school_id?>">

← Back to School Profile

</a>



</div>


</body>

</html>