<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RETIRED — a second, unlinked "add a teacher" page writing into a
| completely disconnected `teachers` table (no login, no
| teacher_assignments row, no relation to `staff` at all). Not reachable
| from any nav -- only ever reachable by typing this exact URL directly.
| staff_manager.php is the one real "add a teacher" flow. Kept as a
| redirect rather than deleted, same convention as dashboard.php /
| manage_teachers.php.
|--------------------------------------------------------------------------
*/

header("Location: ../staff_manager.php");
exit;
