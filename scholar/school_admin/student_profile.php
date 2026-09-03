<?php
declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| SCHOLAR STUDENT PROFILE MODULE
|--------------------------------------------------------------------------
*/


// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
require '../db.php';



if(session_status() !== PHP_SESSION_ACTIVE){

    session_start();

}




/*
|--------------------------------------------------------------------------
| SCHOOL ADMIN SECURITY
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




/*
|--------------------------------------------------------------------------
| STUDENT ID
|--------------------------------------------------------------------------
*/


$student_id =
(int)($_GET['id'] ?? 0);



if($student_id <= 0){

    exit(
        "Invalid student profile."
    );

}




/*
|--------------------------------------------------------------------------
| LOAD STUDENT PROFILE
|--------------------------------------------------------------------------
*/


$stmt =
$pdo->prepare(

"
SELECT

s.*,

c.class_name,
g.guardian_name AS parent_name,
g.phone AS parent_phone


FROM students s


LEFT JOIN classes c

ON s.class_id=c.id

LEFT JOIN guardians g
ON g.student_id = s.id


WHERE 

s.id=?

AND s.school_id=?


LIMIT 1

"

);



$stmt->execute([

    $student_id,
    $school_id

]);



$student =
$stmt->fetch(PDO::FETCH_ASSOC);



if(!$student){

    exit(
        "Student not found or access denied."
    );

}




/*
|--------------------------------------------------------------------------
| ATTENDANCE SUMMARY
|--------------------------------------------------------------------------
*/


try{


$attendance_stmt =
$pdo->prepare(

"
SELECT

COUNT(*) AS total_days,

SUM(
CASE 
WHEN status='Present'
THEN 1
ELSE 0
END
) AS present_days


FROM attendance


WHERE student_id=?

AND school_id=?

"

);



$attendance_stmt->execute([

    $student_id,
    $school_id

]);



$attendance =
$attendance_stmt->fetch(PDO::FETCH_ASSOC);



}
catch(Throwable $e){


$attendance=[

'total_days'=>0,

'present_days'=>0

];


}






/*
|--------------------------------------------------------------------------
| FEES INFORMATION
|--------------------------------------------------------------------------
*/


try{


$fees_stmt =
$pdo->prepare(

"
SELECT

SUM(amount_paid) AS paid,

SUM(amount_due) AS due


FROM fees


WHERE student_id=?

AND school_id=?

"

);



$fees_stmt->execute([

$student_id,
$school_id

]);



$fees =
$fees_stmt->fetch(PDO::FETCH_ASSOC);



}
catch(Throwable $e){


$fees=[

'paid'=>0,

'due'=>0

];


}





/*
|--------------------------------------------------------------------------
| EXAM RESULTS
|--------------------------------------------------------------------------
*/


try{


$results_stmt =
$pdo->prepare(

"
SELECT *

FROM results

WHERE student_id=?

AND school_id=?

ORDER BY id DESC

LIMIT 5

"

);



$results_stmt->execute([

$student_id,
$school_id

]);



$results =
$results_stmt->fetchAll(PDO::FETCH_ASSOC);



}
catch(Throwable $e){


$results=[];


}




function clean($value):string
{

return htmlspecialchars(

(string)$value,

ENT_QUOTES,

'UTF-8'

);

}


?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
<?=clean($student['student_name'])?>
|
Student Profile
</title>

<style>

:root{

    --bg:#080b11;
    --panel:#131b28;
    --border:#2a3a52;
    --text:#e2e8f0;
    --muted:#64748b;
    --cyan:#00A8A8;
    --purple:#a855f7;
    --green:#10b981;
    --red:#ef4444;

}


*{

    box-sizing:border-box;

}



body{

    margin:0;

    background:var(--bg);

    color:var(--text);

    font-family:
    Inter,
    "Segoe UI",
    sans-serif;

}



.container{

    max-width:1500px;

    margin:auto;

    padding:30px;

}





/*
================================================
PROFILE HEADER
================================================
*/


.profile-header{

    display:flex;

    justify-content:space-between;

    align-items:center;

    padding-bottom:25px;

    border-bottom:
    1px solid var(--border);

    margin-bottom:30px;

}



.student-title{

    display:flex;

    align-items:center;

    gap:20px;

}



.student-title h1{

    margin:0;

    font-size:1.8rem;

    color:var(--text);

}



.student-title p{

    margin-top:8px;

    color:var(--muted);

}



.avatar{

    width:75px;

    height:75px;

    border-radius:50%;

    display:flex;

    justify-content:center;

    align-items:center;

    font-size:2.5rem;

    background:

    linear-gradient(
        135deg,
        var(--cyan),
        var(--purple)
    );

}





.actions a{

    text-decoration:none;

    color:var(--text);

    background:var(--panel);

    border:
    1px solid var(--border);

    padding:12px 18px;

    border-radius:8px;

    margin-left:8px;

    font-size:.85rem;

}





.actions a:hover{

    border-color:var(--cyan);

}







/*
================================================
PROFILE INFORMATION
================================================
*/


.profile-grid{

    display:grid;

    grid-template-columns:
    repeat(3,1fr);

    gap:20px;

    margin-bottom:30px;

}



.profile-card{

    background:var(--panel);

    border:
    1px solid var(--border);

    border-radius:15px;

    padding:25px;

}



.profile-card h3{

    margin-top:0;

    margin-bottom:20px;

    color:#94a3b8;

    font-size:.85rem;

    text-transform:uppercase;

    letter-spacing:1px;

}





.info-row{

    display:flex;

    justify-content:space-between;

    padding:12px 0;

    border-bottom:
    1px solid #141b25;

}



.info-row:last-child{

    border-bottom:none;

}



.info-row label{

    color:var(--muted);

    font-size:.8rem;

}



.info-row span{

    color:var(--text);

    font-weight:600;

}



.active{

    color:#6ee7b7!important;

}








/*
================================================
STATISTICS
================================================
*/


.stats{

    display:grid;

    grid-template-columns:
    repeat(3,1fr);

    gap:20px;

    margin-bottom:30px;

}



.stat-card{

    background:var(--panel);

    border:
    1px solid var(--border);

    border-radius:15px;

    padding:25px;

    text-align:center;

}



.stat-card h4{

    margin:0;

    color:var(--muted);

    text-transform:uppercase;

    font-size:.75rem;

}



.big-number{

    margin:20px 0;

    font-size:2rem;

    font-weight:900;

    color:var(--text);

}



.big-number.danger{

    color:#f87171;

}



.stat-card p{

    color:var(--muted);

}








/*
================================================
TABLE PANEL
================================================
*/


.panel{

    background:var(--panel);

    border:
    1px solid var(--border);

    border-radius:15px;

    padding:25px;

    margin-bottom:30px;

}



.panel h2{

    margin-top:0;

    color:#94a3b8;

    font-size:.9rem;

    text-transform:uppercase;

}





.table-wrapper{

    overflow-x:auto;

}



table{

    width:100%;

    border-collapse:collapse;

    min-width:700px;

}



th{

    padding:14px;

    text-align:left;

    color:var(--muted);

    font-size:.7rem;

    text-transform:uppercase;

    border-bottom:
    1px solid var(--border);

}



td{

    padding:15px;

    border-bottom:
    1px solid var(--border);

}



tr:hover{

    background:var(--panel);

}







/*
================================================
MODULE BUTTONS
================================================
*/


.modules{

    display:grid;

    grid-template-columns:
    repeat(4,1fr);

    gap:20px;

}



.modules a{

    text-decoration:none;

    color:var(--text);

    background:var(--panel);

    border:
    1px solid var(--border);

    padding:25px;

    border-radius:15px;

    text-align:center;

    font-weight:700;

    transition:.3s;

}



.modules a:hover{

    transform:translateY(-5px);

    border-color:var(--cyan);

}

.modules .disabled{

    text-decoration:none;
    color:var(--muted);
    background:var(--panel);
    border:1px dashed var(--border);
    padding:25px;
    text-align:center;
    border-radius:15px;
    font-weight:700;
    cursor:default;

}

.modules .disabled small{

    display:block;
    font-weight:400;
    font-size:.7rem;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-top:6px;
    color:var(--muted);

}






/*
================================================
RESPONSIVE
================================================
*/


@media(max-width:1100px){


.profile-grid{

    grid-template-columns:
    1fr;

}


.stats{

    grid-template-columns:
    1fr;

}


.modules{

    grid-template-columns:
    repeat(2,1fr);

}


}




@media(max-width:650px){


.container{

    padding:15px;

}



.profile-header{

    flex-direction:column;

    align-items:flex-start;

    gap:20px;

}



.student-title{

    flex-direction:column;

    align-items:flex-start;

}



.modules{

    grid-template-columns:
    1fr;

}


}

</style>


</head>


<body>
<?php include __DIR__ . '/../preloader.php'; ?>


<div class="container">



<!-- =====================================================
PROFILE HEADER
===================================================== -->


<header class="profile-header">


<div class="student-title">


<div class="avatar">

<?= clean(strtoupper(substr($student['student_name'], 0, 1))) ?>

</div>



<div>


<h1>

<?=clean(
$student['student_name']
)?>

</h1>


<p>

Student Profile

|

<?=clean(
$student['class_name'] ?? 'No Class'
)?>

</p>


</div>


</div>




<div class="actions">


<a href="students.php">

← Students

</a>



<a href="edit_student.php?id=<?=$student['id']?>">

Edit Profile

</a>

<a href="student_subjects.php?student_id=<?=$student['id']?>">

Subjects

</a>


</div>



</header>







<!-- =====================================================
STUDENT OVERVIEW
===================================================== -->


<section class="profile-grid">



<div class="profile-card">


<h3>

Personal Information

</h3>


<div class="info-row">

<label>
Admission ID
</label>


<span>

#<?=$student['id']?>

</span>


</div>



<div class="info-row">

<label>
Gender
</label>


<span>

<?=clean(
$student['gender']
)?>

</span>


</div>




<div class="info-row">

<label>
Date Registered
</label>


<span>

<?=clean(
$student['created_at'] ?? 'N/A'
)?>

</span>


</div>



</div>








<div class="profile-card">


<h3>

Parent / Guardian

</h3>


<div class="info-row">

<label>
Name
</label>


<span>

<?=clean(
$student['parent_name'] ?? 'Not Provided'
)?>

</span>


</div>




<div class="info-row">

<label>
Phone
</label>


<span>

<?=clean(
$student['parent_phone'] ?? 'Not Provided'
)?>

</span>


</div>


<div class="info-row">

<label>
Relationship
</label>


<span>

Guardian

</span>


</div>



</div>







<div class="profile-card">


<h3>

Academic Information

</h3>



<div class="info-row">

<label>
Class

</label>


<span>

<?=clean(
$student['class_name'] ?? 'Unassigned'
)?>

</span>


</div>



<div class="info-row">

<label>
Status

</label>


<span class="active">

Active

</span>


</div>



<div class="info-row">

<label>
School

</label>


<span>

<?=clean(
$_SESSION['school_name'] ?? ''
)?>

</span>


</div>



</div>




</section>








<!-- =====================================================
PERFORMANCE CARDS
===================================================== -->


<section class="stats">



<div class="stat-card">


<h4>

Attendance

</h4>


<div class="big-number">

<?=$attendance['present_days'] ?? 0?>

/

<?=$attendance['total_days'] ?? 0?>

</div>


<p>

Days Present

</p>


</div>







<div class="stat-card">


<h4>

Fees Paid

</h4>


<div class="big-number">

<?=number_format(
$fees['paid'] ?? 0
)?>

</div>


<p>

Total Paid

</p>


</div>







<div class="stat-card">


<h4>

Outstanding Fees

</h4>


<div class="big-number danger">

<?=number_format(
$fees['due'] ?? 0
)?>

</div>


<p>

Balance

</p>


</div>




</section>







<!-- =====================================================
EXAM RESULTS
===================================================== -->


<section class="panel">


<h2>

Recent Examination Results

</h2>



<div class="table-wrapper">


<table>


<thead>


<tr>


<th>
Exam
</th>


<th>
Subject
</th>


<th>
Marks
</th>


<th>
Grade
</th>


<th>
Position
</th>



</tr>


</thead>



<tbody>



<?php if(!$results): ?>


<tr>

<td colspan="5">

No examination records available.

</td>

</tr>




<?php else: ?>



<?php foreach($results as $result): ?>


<tr>


<td>

<?=clean(
$result['exam_name'] ?? ''
)?>

</td>


<td>

<?=clean(
$result['subject'] ?? ''
)?>

</td>


<td>

<?=clean(
$result['marks'] ?? ''
)?>

</td>


<td>

<?=clean(
$result['grade'] ?? ''
)?>

</td>


<td>

<?=clean(
$result['position'] ?? ''
)?>

</td>



</tr>



<?php endforeach; ?>



<?php endif; ?>



</tbody>


</table>


</div>


</section>








<!-- =====================================================
QUICK MODULES
===================================================== -->


<section class="modules">


<a href="student_attendance_history.php?id=<?= (int) $student_id ?>">

Attendance History

</a>



<span class="disabled">

Fee Statement<small>Coming soon</small>

</span>



<span class="disabled">

Documents<small>Coming soon</small>

</span>



<span class="disabled">

SMS History<small>Coming soon</small>

</span>


</section>





</div>


</body>


</html>