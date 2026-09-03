<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RETIRED — this was a duplicate "Faculty Onboarding" flow that inserted
| into staff via the assign_subjects VARCHAR column (a separate, unrelated
| column from staff_manager.php's own now-fixed assign_subject INT bug),
| and -- whenever the admin's picked class/stream/subject happened to match
| rows in the legacy system_subjects/streams tables -- attempted an INSERT
| into system_teacher_assignments, a table that does not exist in the
| database at all, silently rolling back the whole staff+login transaction.
| staff_manager.php is the one real "add a teacher" flow now. Kept as a
| redirect (same convention as dashboard.php) so nothing dead-ends instead
| of deleting the file outright.
|--------------------------------------------------------------------------
*/

header("Location: staff_manager.php");
exit;
