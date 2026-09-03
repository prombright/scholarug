<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RETIRED — this used to be a second, older school admin dashboard that
| duplicated classes/streams setup, staff registration, and student
| enrollment already handled by school_admin/school_admin_dashboard.php
| (+ classes.php, students.php, manage_teachers.php). login.php never
| pointed here — only direct/bookmarked visits did. Kept as a redirect
| so nothing dead-ends instead of deleting the file outright.
|--------------------------------------------------------------------------
*/

header("Location: school_admin/school_admin_dashboard.php");
exit;