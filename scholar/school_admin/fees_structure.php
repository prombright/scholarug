<?php
declare(strict_types=1);

// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
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
    $_SESSION['role'] !== 'school_admin' ||
    !isset($_SESSION['school_id'])
){

    header(
        "Location: ../login.php"
    );

    exit;

}



$school_id =
(int)$_SESSION['school_id'];



$message='';
$message_type='';





/*
|--------------------------------------------------------------------------
| SCHOOL DETAILS
|--------------------------------------------------------------------------
*/


$stmt =
$pdo->prepare(

"
SELECT *

FROM schools

WHERE id=?

LIMIT 1

"

);


$stmt->execute([
    $school_id
]);


$school =
$stmt->fetch(PDO::FETCH_ASSOC);






/*
|--------------------------------------------------------------------------
| LOAD CLASSES
|--------------------------------------------------------------------------
*/


$class_stmt =
$pdo->prepare(

"
SELECT *

FROM classes

WHERE school_id=?

ORDER BY class_name

"

);


$class_stmt->execute([
    $school_id
]);


$classes =
$class_stmt->fetchAll(PDO::FETCH_ASSOC);








/*
|--------------------------------------------------------------------------
| CREATE FEE STRUCTURE
|--------------------------------------------------------------------------
*/


if(
$_SERVER['REQUEST_METHOD']==='POST'
&&
isset($_POST['create_fee'])
){


$class_id =
(int)$_POST['class_id'];



$year =
trim($_POST['academic_year']);



$term =
trim($_POST['term']);



$tuition =
(float)$_POST['tuition'];


$lunch =
(float)$_POST['lunch'];


$transport =
(float)$_POST['transport'];


$examination =
(float)$_POST['examination'];


$other =
(float)$_POST['other_charges'];



$total =
$tuition+
$lunch+
$transport+
$examination+
$other;



try{


$insert =
$pdo->prepare(

"
INSERT INTO fee_structures

(
school_id,
class_id,
academic_year,
term,
tuition,
lunch,
transport,
examination,
other_charges,
total_amount

)

VALUES

(?,?,?,?,?,?,?,?,?,?)

"

);



$insert->execute([


$school_id,

$class_id,

$year,

$term,

$tuition,

$lunch,

$transport,

$examination,

$other,

$total


]);



$message=
"Fee structure created successfully.";


$message_type=
"success";



}
catch(Throwable $e){


$message=
"Error: ".$e->getMessage();


$message_type=
"error";


}



}






/*
|--------------------------------------------------------------------------
| EXISTING STRUCTURES
|--------------------------------------------------------------------------
*/


$list =
$pdo->prepare(

"
SELECT

f.*,

c.class_name


FROM fee_structures f


LEFT JOIN classes c

ON f.class_id=c.id


WHERE f.school_id=?


ORDER BY f.id DESC

"

);



$list->execute([
$school_id
]);


$fee_structures =
$list->fetchAll(PDO::FETCH_ASSOC);





function safe($value):string{

return htmlspecialchars(
(string)$value,
ENT_QUOTES,
'UTF-8'
);

}


$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'classes';
require_once __DIR__ . '/../_admin_shell.php';

?>
