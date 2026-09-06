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
| AUTHENTICATION
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
| SCHOOL ID
|--------------------------------------------------------------------------
*/

$school_id =
    (int)($_GET['id'] ?? $_POST['school_id'] ?? 0);



if($school_id <= 0){

    header("Location: schools.php");
    exit;

}



$type_msg = '';
$type_msg_type = 'success';



/*
|--------------------------------------------------------------------------
| UPDATE SCHOOL TYPE
|--------------------------------------------------------------------------
*/

if(
    $_SERVER['REQUEST_METHOD']==='POST' &&
    isset($_POST['update_school_type'])
){

    verify_csrf();


    $new_school_type =
        $_POST['school_type'] ?? '';


    if(!in_array($new_school_type, ['Primary', 'Secondary'], true)){


        $type_msg =
            "Select a valid school type.";

        $type_msg_type = 'error';


    }else{


        $upd =
        $pdo->prepare(

        "UPDATE schools
         SET school_type=?
         WHERE id=?"

        );


        $upd->execute([
            $new_school_type,
            $school_id
        ]);


        require_once __DIR__ . '/../_subject_helpers.php';

        scholar_provision_school_type_defaults(
            $pdo,
            $school_id,
            $new_school_type
        );


        $type_msg =
            "School type set to {$new_school_type}. Classes and default subjects for this type have been added.";

        $type_msg_type = 'success';


    }

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
 WHERE id = ?
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
| SCHOOL STATISTICS
|--------------------------------------------------------------------------
*/


function count_records(
    PDO $pdo,
    string $table,
    int $school_id
):int{


    try{


        $stmt =
        $pdo->prepare(

        "SELECT COUNT(*)
         FROM {$table}
         WHERE school_id = ?"

        );


        $stmt->execute([$school_id]);


        return (int)$stmt->fetchColumn();


    }
    catch(Throwable $e){

        return 0;

    }

}



$total_users =
count_records(
    $pdo,
    'users',
    $school_id
);



$total_students =
count_records(
    $pdo,
    'students',
    $school_id
);



$total_teachers =
count_records(
    $pdo,
    'teachers',
    $school_id
);



$total_staff =
count_records(
    $pdo,
    'staff',
    $school_id
);



/*
|--------------------------------------------------------------------------
| SCHOOL ADMINS
|--------------------------------------------------------------------------
*/


try{


$stmt =
$pdo->prepare(

"SELECT username,email,role
 FROM users
 WHERE school_id=?
 AND role LIKE '%admin%'"

);


$stmt->execute([$school_id]);


$admins =
$stmt->fetchAll(PDO::FETCH_ASSOC);


}
catch(Throwable $e){

    $admins=[];

}



?>


<!DOCTYPE html>

<html lang="en">

<head>


<meta charset="UTF-8">


<meta name="viewport"
content="width=device-width, initial-scale=1.0">


<title>
ScholarUg | School Profile
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

max-width:1300px;

margin:auto;

padding:30px;

}



.header{

display:flex;

justify-content:space-between;

align-items:center;

margin-bottom:30px;

}



h1{

color:var(--text);

margin:0;

}



a{

text-decoration:none;

color:#06b6d4;

}




.profile-card{

background:var(--panel);

border:1px solid var(--border);

border-radius:15px;

padding:30px;

margin-bottom:25px;

}




.school-title{

font-size:2rem;

font-weight:800;

color:var(--text);

}




.code{

color:#06b6d4;

font-family:monospace;

font-size:1rem;

margin-top:10px;

}




.grid{

display:grid;

grid-template-columns:
repeat(4,1fr);

gap:20px;

margin-bottom:25px;

}




.card{

background:var(--panel);

border:1px solid var(--border);

padding:20px;

border-radius:12px;

}



.label{

font-size:.7rem;

text-transform:uppercase;

color:var(--muted);

font-weight:bold;

}



.value{

font-size:1.7rem;

color:var(--text);

font-weight:800;

margin-top:10px;

}



.info-grid{

display:grid;

grid-template-columns:
repeat(2,1fr);

gap:20px;

}




.info{

background:var(--bg);

border:1px solid var(--border);

padding:15px;

border-radius:8px;

}



.badge{

display:inline-block;

padding:6px 12px;

border-radius:5px;

font-size:.7rem;

font-weight:bold;

}



.green{

background:#064e3b;

color:#6ee7b7;

}



.red{

background:#450a0a;

color:#fca5a5;

}



.yellow{

background:#451a03;

color:#fdba74;

}



.alert{

padding:15px;

border-radius:8px;

margin-bottom:20px;

}



.alert.success{

background:rgba(16,185,129,.1);

color:#6ee7b7;

}



.alert.error{

background:rgba(239,68,68,.1);

color:#fca5a5;

}



.type-form select{

padding:12px;

background:var(--bg);

border:1px solid var(--border);

color:var(--text);

border-radius:7px;

margin-right:10px;

}



.type-form button{

padding:12px 18px;

border:none;

border-radius:8px;

background:#06b6d4;

color:white;

font-weight:bold;

cursor:pointer;

}



table{

width:100%;

border-collapse:collapse;

}



th{

text-align:left;

padding:12px;

color:var(--muted);

}



td{

padding:12px;

border-top:1px solid var(--border);

}



.actions a{

display:inline-block;

padding:12px 18px;

background:#06b6d4;

color:white;

border-radius:8px;

font-weight:bold;

margin-right:10px;

}



@media(max-width:900px){

.grid{

grid-template-columns:1fr 1fr;

}

}


</style>


</head>



<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">



<div class="header">


<div>

<h1>
School Profile
</h1>

<p>
ScholarUg Institution Management
</p>

</div>


<a href="schools.php">
← Back to Schools
</a>


</div>


<?php if($type_msg): ?>

<div class="alert <?=$type_msg_type?>">

<?=htmlspecialchars($type_msg)?>

</div>

<?php endif; ?>



<div class="profile-card">


<div class="school-title">

<?=htmlspecialchars(
$school['school_name']
)?>

</div>


<div class="code">

<?=htmlspecialchars(
$school['school_code']
)?>

</div>



<br>



Payment:


<?php if(
$school['payment_status']==='paid'
): ?>


<span class="badge green">
PAID
</span>


<?php else: ?>


<span class="badge yellow">
PENDING
</span>


<?php endif; ?>




&nbsp;&nbsp;


Type:


<span class="badge <?=
$school['school_type']==='Primary'
    ? 'yellow'
    : ($school['school_type']==='Secondary'
        ? 'green'
        : 'red')
?>">
<?=htmlspecialchars($school['school_type'] ?: 'Unset')?>
</span>



&nbsp;&nbsp;


Status:


<?php if(
$school['is_active']
): ?>


<span class="badge green">
ACTIVE
</span>


<?php else: ?>


<span class="badge red">
SUSPENDED
</span>


<?php endif; ?>



</div>





<div class="grid">


<div class="card">

<div class="label">
Users
</div>

<div class="value">
<?=$total_users?>
</div>

</div>



<div class="card">

<div class="label">
Students
</div>

<div class="value">
<?=$total_students?>
</div>

</div>



<div class="card">

<div class="label">
Teachers
</div>

<div class="value">
<?=$total_teachers?>
</div>

</div>



<div class="card">

<div class="label">
Staff
</div>

<div class="value">
<?=$total_staff?>
</div>

</div>



</div>






<div class="profile-card">


<h2>
School Information
</h2>


<div class="info-grid">


<div class="info">

<strong>
School Code
</strong>

<br>

<?=$school['school_code']?>


</div>



<div class="info">

<strong>
Setup PIN
</strong>

<br>

<?=$school['access_pin']?>


</div>




<div class="info">

<strong>
Registration Date
</strong>

<br>


<?=date(
'd M Y',
strtotime(
$school['created_at']
)
)?>



</div>



<div class="info">

<strong>
School ID
</strong>

<br>

<?=$school['id']?>


</div>


</div>


</div>





<div class="profile-card">


<h2>
School Administrators
</h2>



<?php if(!$admins): ?>


<p>
No administrators registered yet.
</p>



<?php else: ?>


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


<?php endif; ?>



</div>






<div class="profile-card actions">


<a href="school_admin_provision.php?school_id=<?=$school['id']?>">
Create School Admin
</a>


<a href="#">
Activity Logs
</a>


</div>



<div class="profile-card">


<h2>
School Type
</h2>


<p class="subtitle">
Sets the class ladder (Pre-Primary/Primary or O-Level/A-Level) and
default subjects this school starts with. Changing this only adds
the new type's classes/subjects -- it never removes existing ones.
</p>


<form
class="type-form"
method="POST"
onsubmit="var original='<?=htmlspecialchars($school['school_type'], ENT_QUOTES)?>';var chosen=this.school_type.value;if(original && chosen!==original){return confirm('This school already has classes/subjects for its current type. Changing type will add the new type\'s classes/subjects but will NOT remove the old ones -- continue?');}return true;"
>


<input type="hidden"
name="csrf_token"
value="<?=$csrf_token?>">


<input type="hidden"
name="school_id"
value="<?=$school['id']?>">


<select name="school_type" required>

<option value="" disabled <?=$school['school_type'] ? '' : 'selected'?>>
Select school type
</option>

<option value="Primary" <?=$school['school_type']==='Primary' ? 'selected' : ''?>>
Primary (incl. Pre-Primary)
</option>

<option value="Secondary" <?=$school['school_type']==='Secondary' ? 'selected' : ''?>>
Secondary (O-Level / A-Level)
</option>

</select>


<button name="update_school_type">
Save School Type
</button>


</form>


</div>





</div>



</body>

</html>