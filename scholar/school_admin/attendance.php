<?php
declare(strict_types=1);


// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV.
require '../db.php';
require_once __DIR__ . '/_attendance_helpers.php';



if(session_status() !== PHP_SESSION_ACTIVE){

    session_start();

}


$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'classes';
require_once __DIR__ . '/../_admin_shell.php';


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



$message = '';

$message_type = '';





/*
|--------------------------------------------------------------------------
| LOAD SCHOOL DETAILS
|--------------------------------------------------------------------------
*/


$school_stmt =
$pdo->prepare(

"
SELECT *

FROM schools

WHERE id=?

LIMIT 1

"

);



$school_stmt->execute([

$school_id

]);



$school =
$school_stmt->fetch(PDO::FETCH_ASSOC);



if(!$school){

    exit(
        "School not found."
    );

}





/*
|--------------------------------------------------------------------------
| LOAD CLASSES
|--------------------------------------------------------------------------
*/


$classes = admin_attendance_fetch_classes($pdo, $school_id);







/*
|--------------------------------------------------------------------------
| SELECTED CLASS
|--------------------------------------------------------------------------
*/


$class_id =
(int)($_GET['class_id'] ?? 0);



$attendance_date =
$_GET['date']
?? date('Y-m-d');







/*
|--------------------------------------------------------------------------
| LOAD STUDENTS
|--------------------------------------------------------------------------
*/


$students = admin_attendance_fetch_students($pdo, $school_id, $class_id);







/*
|--------------------------------------------------------------------------
| SAVE ATTENDANCE
|--------------------------------------------------------------------------
*/


if($_SERVER['REQUEST_METHOD']==='POST'
&& isset($_POST['save_attendance'])){

    $class_id = (int) $_POST['class_id'];
    $date = $_POST['attendance_date'];
    $result = admin_attendance_save($pdo, $school_id, $class_id, $date, $_POST['attendance'] ?? []);

    $message = $result['message'];
    $message_type = $result['ok'] ? 'success' : 'error';
}






/*
|--------------------------------------------------------------------------
| ATTENDANCE HISTORY SUMMARY
|--------------------------------------------------------------------------
*/


$today_summary = admin_attendance_today_summary($pdo, $school_id, $attendance_date);





function safe($value):string{

return htmlspecialchars(

(string)$value,

ENT_QUOTES,

'UTF-8'

);

}


?>

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
    --orange:#f59e0b;

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
HEADER
================================================
*/


.topbar{

    display:flex;

    justify-content:space-between;

    align-items:center;

    padding-bottom:25px;

    margin-bottom:30px;

    border-bottom:
    1px solid var(--border);

}



.topbar h1{

    margin:0;

    font-size:1.7rem;

    color:var(--text);

}



.topbar p{

    color:var(--muted);

}



.back-btn{

    text-decoration:none;

    color:var(--text);

    background:var(--panel);

    border:
    1px solid var(--border);

    padding:12px 18px;

    border-radius:8px;

}





/*
================================================
ALERTS
================================================
*/


.alert{

    padding:15px;

    border-radius:10px;

    margin-bottom:25px;

}



.alert.success{

    background:
    rgba(16,185,129,.1);

    border:
    1px solid var(--green);

    color:var(--green);

}



.alert.error{

    background:
    rgba(239,68,68,.1);

    border:
    1px solid var(--red);

    color:var(--red);

}





/*
================================================
PANELS
================================================
*/


.panel{

    background:var(--panel);

    border:
    1px solid var(--border);

    border-radius:15px;

    padding:25px;

    margin-bottom:25px;

}



.panel h2{

    margin-top:0;

    color:var(--muted);

    font-size:.85rem;

    text-transform:uppercase;

    letter-spacing:1px;

}






/*
================================================
FORM
================================================
*/


.form-grid{

    display:grid;

    grid-template-columns:
    repeat(2,1fr);

    gap:20px;

}



label{

    display:block;

    color:var(--muted);

    font-size:.75rem;

    text-transform:uppercase;

    margin-bottom:8px;

    font-weight:700;

}



input,
select{


    width:100%;

    padding:13px;

    background:var(--panel);

    color:var(--text);

    border:
    1px solid var(--border);

    border-radius:8px;

    outline:none;

}



input:focus,
select:focus{

    border-color:var(--cyan);

}





/*
================================================
SUMMARY CARDS
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



.stat-card h3{

    margin:0;

    color:var(--muted);

    text-transform:uppercase;

    font-size:.75rem;

}



.stat-card strong{

    display:block;

    margin-top:15px;

    font-size:2.2rem;

    color:var(--text);

}



.stat-card:nth-child(1){

    border-top:
    3px solid var(--green);

}



.stat-card:nth-child(2){

    border-top:
    3px solid var(--red);

}



.stat-card:nth-child(3){

    border-top:
    3px solid var(--orange);

}





/*
================================================
TABLE
================================================
*/


.table-wrapper{

    overflow-x:auto;

}



table{

    width:100%;

    border-collapse:collapse;

    min-width:800px;

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

    background:var(--border);

}




.attendance-select{

    max-width:180px;

}





/*
================================================
BUTTONS
================================================
*/


.btn{

    margin-top:20px;

    background:

    linear-gradient(
        135deg,
        var(--cyan),
        var(--purple)
    );

    border:none;

    color:white;

    padding:14px 25px;

    border-radius:8px;

    cursor:pointer;

    font-weight:800;

}



.btn:hover{

    opacity:.85;

}





/*
================================================
EMPTY MESSAGE
================================================
*/


.empty{

    padding:40px;

    text-align:center;

    color:var(--muted);

    border:
    1px dashed var(--border);

    border-radius:10px;

}





/*
================================================
MODULE LINKS
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

    text-align:center;

    border-radius:15px;

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



.topbar{

    flex-direction:column;

    align-items:flex-start;

    gap:15px;

}



.form-grid{

    grid-template-columns:
    1fr;

}



.modules{

    grid-template-columns:
    1fr;

}


}

</style>


<div class="container">



<!-- =====================================================
HEADER
===================================================== -->


<header class="topbar">


<div>


<h1>

Attendance Management

</h1>


<p>

<?=safe($school['school_name'])?>

|

Daily Student Attendance

</p>


</div>



<a href="school_admin_dashboard.php"
class="back-btn">

← Dashboard

</a>


</header>






<!-- =====================================================
MESSAGE
===================================================== -->


<?php if($message !== ''): ?>


<div class="alert <?=$message_type?>">

<?=safe($message)?>

</div>


<?php endif; ?>








<!-- =====================================================
FILTER PANEL
===================================================== -->


<section class="panel">


<h2>

Select Attendance Session

</h2>



<form method="GET">


<div class="form-grid">



<div>


<label>

Class

</label>



<select name="class_id"
onchange="this.form.submit()">


<option value="">

Select Class

</option>



<?php foreach($classes as $class): ?>


<option

value="<?=$class['id']?>"

<?=

$class_id == $class['id']

?

'selected'

:

''

?>

>


<?=safe(
$class['class_name']
)?>



</option>



<?php endforeach; ?>


</select>


</div>







<div>


<label>

Attendance Date

</label>


<input

type="date"

name="date"

value="<?=safe($attendance_date)?>"

onchange="this.form.submit()"

>


</div>



</div>


</form>



</section>







<!-- =====================================================
SUMMARY CARDS
===================================================== -->


<section class="stats">



<div class="stat-card">


<h3>

Present

</h3>


<strong>

<?=

$today_summary['Present']

??

0

?>

</strong>


</div>





<div class="stat-card">


<h3>

Absent

</h3>


<strong>

<?=

$today_summary['Absent']

??

0

?>

</strong>


</div>





<div class="stat-card">


<h3>

Late

</h3>


<strong>

<?=

$today_summary['Late']

??

0

?>

</strong>


</div>



</section>








<!-- =====================================================
ATTENDANCE TABLE
===================================================== -->


<section class="panel">


<h2>

Student Attendance Sheet

</h2>





<?php if($class_id<=0): ?>


<div class="empty">

Please select a class to load students.

</div>





<?php elseif(!$students): ?>


<div class="empty">

No students found in this class.

</div>






<?php else: ?>



<form method="POST">



<input

type="hidden"

name="class_id"

value="<?=$class_id?>"

>



<input

type="hidden"

name="attendance_date"

value="<?=safe($attendance_date)?>"

>




<div class="table-wrapper">



<table>



<thead>


<tr>


<th>

Admission

</th>


<th>

Student Name

</th>


<th>

Gender

</th>


<th>

Attendance Status

</th>


</tr>


</thead>





<tbody>



<?php foreach($students as $student): ?>


<tr>



<td>

#<?=$student['id']?>

</td>




<td>

<strong>

<?=safe(
$student['student_name']
)?>

</strong>


</td>




<td>

<?=safe(
$student['gender']
)?>

</td>




<td>


<select

name="attendance[<?=$student['id']?>]"

class="attendance-select"

>


<option value="Present">

Present

</option>



<option value="Absent">

Absent

</option>



<option value="Late">

Late

</option>



<option value="Excused">

Excused

</option>



</select>


</td>



</tr>





<?php endforeach; ?>



</tbody>


</table>


</div>






<button

type="submit"

name="save_attendance"

class="btn"

>

✓ Save Attendance

</button>



</form>



<?php endif; ?>



</section>








<!-- =====================================================
QUICK LINKS
===================================================== -->


<section class="modules">


<a href="students.php">

Students

</a>



<a href="student_profile.php">

Profiles

</a>




<span class="disabled">

Reports<small>Coming soon</small>

</span>




<span class="disabled">

SMS Parents<small>Coming soon</small>

</span>



</section>






</div>


</body>


</html>