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



$msg = '';
$msg_type = 'success';

if (!empty($_SESSION['schools_flash'])) {
    $msg = $_SESSION['schools_flash'];
    unset($_SESSION['schools_flash']);
}



/*
|--------------------------------------------------------------------------
| CSRF
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
| ACTIONS
|--------------------------------------------------------------------------
*/


if($_SERVER['REQUEST_METHOD']==='POST'){


    verify_csrf();



    /*
    Toggle payment
    */

    if(isset($_POST['toggle_payment'])){


        $id =
            (int)$_POST['school_id'];


        $stmt =
            $pdo->prepare(

            "SELECT payment_status
             FROM schools
             WHERE id=?"

            );


        $stmt->execute([$id]);


        $current =
            $stmt->fetchColumn();



        $new =
            ($current==='paid')
            ? 'pending'
            : 'paid';



        $upd =
            $pdo->prepare(

            "UPDATE schools
             SET payment_status=?
             WHERE id=?"

            );


        $upd->execute([
            $new,
            $id
        ]);



        $msg =
            "Payment status updated.";

    }





    /*
    Toggle active
    */


    if(isset($_POST['toggle_active'])){


        $id =
            (int)$_POST['school_id'];



        $stmt =
            $pdo->prepare(

            "SELECT is_active
             FROM schools
             WHERE id=?"

            );


        $stmt->execute([$id]);


        $state =
            (int)$stmt->fetchColumn();



        $new =
            $state===1 ? 0 : 1;



        $upd =
            $pdo->prepare(

            "UPDATE schools
             SET is_active=?
             WHERE id=?"

            );


        $upd->execute([
            $new,
            $id
        ]);



        $msg =
            $new
            ? "School activated."
            : "School suspended.";

    }




    /*
    Archive
    */


    if(isset($_POST['archive_school'])){


        $id =
            (int)$_POST['school_id'];



        $stmt =
            $pdo->prepare(

            "UPDATE schools
             SET is_active=0
             WHERE id=?"

            );


        $stmt->execute([$id]);



        $msg =
            "School archived.";

    }


}



/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/


$search =
    trim($_GET['search'] ?? '');



$status =
    $_GET['status'] ?? '';



$query =

"SELECT *
 FROM schools
 WHERE 1";



$params=[];



if($search!==''){


    $query .=

    " AND 
      (
        school_name LIKE ?
        OR school_code LIKE ?
      )";


    $params[] =
        "%$search%";

    $params[] =
        "%$search%";

}



if($status==='active'){


    $query .=
        " AND is_active=1";

}


if($status==='suspended'){


    $query .=
        " AND is_active=0";

}



$query .=
" ORDER BY id DESC";



$stmt =
    $pdo->prepare($query);


$stmt->execute($params);



$schools =
    $stmt->fetchAll(PDO::FETCH_ASSOC);



/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/


$total =
(int)$pdo
->query(
"SELECT COUNT(*) FROM schools"
)
->fetchColumn();



$active =
(int)$pdo
->query(
"SELECT COUNT(*)
 FROM schools
 WHERE is_active=1"
)
->fetchColumn();



$suspended =
(int)$pdo
->query(
"SELECT COUNT(*)
 FROM schools
 WHERE is_active=0"
)
->fetchColumn();



?>


<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">


<title>
ScholarUg | Schools
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

max-width:1400px;

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

color:white;

}



a{

color:#06b6d4;

text-decoration:none;

}



.cards{

display:grid;

grid-template-columns:
repeat(3,1fr);

gap:20px;

margin-bottom:30px;

}



.card{

background:#0d1118;

border:1px solid #1e293b;

padding:20px;

border-radius:12px;

}



.number{

font-size:2rem;

font-weight:bold;

color:white;

}



.toolbar{

background:#0d1118;

padding:20px;

border-radius:12px;

margin-bottom:20px;

border:1px solid #1e293b;

}



input,select{

padding:12px;

background:#080b11;

border:1px solid #1e293b;

color:white;

border-radius:7px;

}



button{

padding:10px 14px;

border:none;

border-radius:7px;

background:#06b6d4;

color:white;

cursor:pointer;

font-weight:bold;

}



table{

width:100%;

border-collapse:collapse;

background:#0d1118;

}



th{

text-align:left;

color:#64748b;

font-size:.75rem;

padding:15px;

}



td{

padding:15px;

border-top:1px solid #1e293b;

}



.badge{

padding:5px 10px;

border-radius:5px;

font-size:.7rem;

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



.action{

display:flex;

gap:5px;

}



.action button{

background:#111827;

border:1px solid #1e293b;

}



.alert{

padding:15px;

margin-bottom:20px;

background:#064e3b;

color:#6ee7b7;

border-radius:8px;

}


</style>


</head>



<body>


<div class="container">


<div class="header">


<div>

<h1>
School Network
</h1>

<p>
Manage ScholarUg institutions
</p>

</div>


<a href="messages.php" style="margin-right:15px;">
Messages
</a>

<a href="developer_dashboard.php">
← Dashboard
</a>


</div>



<?php if($msg): ?>

<div class="alert">

<?=htmlspecialchars($msg)?>

</div>

<?php endif; ?>



<div class="cards">


<div class="card">

Total Schools

<div class="number">
<?=$total?>
</div>

</div>


<div class="card">

Active Schools

<div class="number">
<?=$active?>
</div>

</div>


<div class="card">

Suspended Schools

<div class="number">
<?=$suspended?>
</div>

</div>


</div>





<div class="toolbar">


<form method="GET">


<input
type="text"
name="search"
placeholder="Search school..."
value="<?=htmlspecialchars($search)?>"
>


<select name="status">


<option value="">
All Status
</option>


<option value="active">
Active
</option>


<option value="suspended">
Suspended
</option>


</select>


<button>
Search
</button>


<a href="school_onboarding.php"
style="margin-left:15px">

+ New School

</a>


</form>


</div>





<table>


<tr>

<th>
School
</th>

<th>
Code
</th>

<th>
Type
</th>

<th>
Payment
</th>

<th>
Status
</th>

<th>
Registered
</th>

<th>
Actions
</th>


</tr>



<?php foreach($schools as $school): ?>


<tr>


<td>

<strong>

<?=htmlspecialchars(
$school['school_name']
)?>

</strong>

</td>


<td>

<?=htmlspecialchars(
$school['school_code']
)?>

</td>


<td>

<?php
$type_badge =
$school['school_type']==='Primary'
    ? 'yellow'
    : ($school['school_type']==='Secondary'
        ? 'green'
        : 'red');
?>

<span class="badge <?=$type_badge?>">
<?=htmlspecialchars(
$school['school_type'] ?: 'Unset'
)?>
</span>

</td>


<td>


<form method="POST">


<input type="hidden"
name="csrf_token"
value="<?=$csrf_token?>">


<input type="hidden"
name="school_id"
value="<?=$school['id']?>">


<button name="toggle_payment">


<?=$school['payment_status']?>

</button>


</form>


</td>



<td>


<?php if($school['is_active']): ?>

<span class="badge green">
ACTIVE
</span>

<?php else: ?>

<span class="badge red">
SUSPENDED
</span>

<?php endif; ?>


</td>



<td>

<?=date(
'd M Y',
strtotime($school['created_at'])
)?>

</td>



<td>


<div class="action">


<form method="POST">

<input type="hidden"
name="csrf_token"
value="<?=$csrf_token?>">

<input type="hidden"
name="school_id"
value="<?=$school['id']?>">


<button name="toggle_active">

Toggle

</button>


</form>



<a href="school_profile.php?id=<?=$school['id']?>">

<button type="button">
View
</button>

</a>


<a href="delete_school.php?id=<?=$school['id']?>">

<button type="button" style="background:#450a0a;border-color:#7f1d1d;color:#fca5a5;">
Delete
</button>

</a>



</div>


</td>



</tr>



<?php endforeach; ?>


</table>



</div>


</body>

</html>