<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ASSIGN ELECTIVE SUBJECTS (per student, "student-first" view)
|--------------------------------------------------------------------------
| Click a student, tick which electives they take. Core/compulsory subjects
| aren't listed here -- they apply to the whole class automatically, same
| as before this feature existed. See _setup/student_subjects.sql.
|
| A-Level students additionally get a Combination picker (see
| _setup/combinations.sql) above the elective grid. Picking a combination
| just auto-ticks the 3 principal subjects' checkboxes client-side and, as
| a server-side safety net, the save handler below unions those 3 resolved
| subject ids into the ticked set regardless -- there's no separate
| "combination subjects" table, a combination is still just 3 ordinary
| student_subjects rows plus students.combination_id recording which named
| combination produced them.
|
| Subject matching against the student's class is done with dot/case
| normalization (REPLACE(UPPER(...),'.','')) rather than a strict string
| equality, because classes.class_name and subjects.class_name aren't
| consistently formatted across schools ("S1" vs "S.1") -- see
| _report_card_render.php for the same issue and the same fix.
|
| Submit is a full replace-set scoped to just this one student_id --
| student_subjects carries no cascade risk elsewhere, unlike subjects.id.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_student_subjects_helpers.php';

require_role(['school_admin']);

$school_id  = current_school_id();
$student_id = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);

$message = '';
$message_type = '';

$profile = admin_student_subjects_load($pdo, $school_id, $student_id);

if (!$profile['ok']) {
    http_response_code(404);
    require_once __DIR__ . '/../_admin_shell.php';
    echo '<main class="main-content"><div class="page-inner"><p>Student not found.</p></div></main></div></body></html>';
    exit;
}

$student = $profile['student'];
$is_a_level = $profile['is_a_level'];
$class_name = $profile['class_name'];
$electives = $profile['electives'];
$enrolled_ids = $profile['enrolled_ids'];
$combinations = $profile['combinations'];
$combo_subject_map = $profile['combo_subject_map'];

// ---- Save ticked electives (full replace-set for this student) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subjects']) && $class_name !== '') {
    $save_result = admin_student_subjects_save(
        $pdo, $school_id, $student_id,
        $_POST['subject_ids'] ?? [],
        (int) ($_POST['combination_id'] ?? 0)
    );

    if (!$save_result['ok'] && $save_result['message'] === 'A student can offer Subsidiary Mathematics or Subsidiary ICT, not both. Untick one and save again.') {
        require_once __DIR__ . '/../_admin_shell.php';
        echo '<main class="main-content"><div class="page-inner"><div style="max-width:600px;margin:60px auto;padding:20px;border:1px solid rgba(239,68,68,0.4);background:rgba(239,68,68,0.08);border-radius:8px;color:#fca5a5;">'
           . htmlspecialchars($save_result['message'], ENT_QUOTES)
           . ' <a href="javascript:history.back()" style="color:#fca5a5;text-decoration:underline;">&larr; Go back</a></div></div></main></div></body></html>';
        exit;
    }

    $message = $save_result['message'];
    $message_type = $save_result['ok'] ? 'success' : 'error';

    if ($save_result['ok']) {
        // Re-load so the page reflects the saved state (electives/enrolled_ids/combination_id).
        $profile = admin_student_subjects_load($pdo, $school_id, $student_id);
        $student = $profile['student'];
        $enrolled_ids = $profile['enrolled_ids'];
    }
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'subjects';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:#fbbf24;margin-bottom:20px;line-height:1.5;}
.subject-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:20px;}
.subject-tile{display:flex;align-items:flex-start;gap:10px;background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.subject-tile input[type=checkbox]{width:auto;margin-top:3px;}
.subject-tile .name{font-weight:600;font-size:0.88rem;}
.subject-tile .meta{color:var(--muted);font-size:0.75rem;margin-top:2px;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.back-link{color:var(--muted);text-decoration:none;font-size:0.85rem;}
.back-link:hover{color:var(--cyan);}
.empty{color:var(--muted);font-size:0.85rem;}
.combo-row{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.combo-row select{background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 12px;border-radius:8px;font-size:0.85rem;min-width:260px;}
</style>
<main class="main-content">
<div class="page-inner">
    <p><a href="student_profile.php?id=<?= (int) $student_id ?>" class="back-link">&larr; Back to <?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?>'s Profile</a></p>
    <h1 style="font-size:1.4rem;">Assign Subjects — <?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?></h1>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:-8px;">Class: <?= htmlspecialchars($class_name ?: 'Unassigned', ENT_QUOTES) ?></p>

    <?php if ($message): ?><div class="alert <?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <?php if ($class_name === ''): ?>
        <div class="disclaimer">This student isn't assigned to a class yet -- set that first on their profile.</div>
    <?php else: ?>

    <?php if ($is_a_level && empty($combinations)): ?>
        <div class="disclaimer">
            No combinations are ready for <?= htmlspecialchars($class_name, ENT_QUOTES) ?> yet -- adopt one under
            the "A-Level Combinations" tab of <a href="subject_catalog.php" style="color:#00A8A8;">Subject Catalog</a>
            (its 3 subjects need adopting for this class first).
        </div>
    <?php endif; ?>

    <div class="section">
        <?php if (empty($electives)): ?>
            <p class="empty">No elective subjects exist for <?= htmlspecialchars($class_name, ENT_QUOTES) ?> yet. Add one via <a href="subject_matrix.php" style="color:#00A8A8;">Subject Matrix</a> or the <a href="subject_catalog.php" style="color:#00A8A8;">Subject Catalog</a> first, marking it Elective.</p>
        <?php else: ?>
        <form method="post" id="subjectsForm">
            <input type="hidden" name="student_id" value="<?= (int) $student_id ?>">

            <?php if ($is_a_level && !empty($combinations)): ?>
            <div class="combo-row">
                <label style="margin:0;font-size:0.8rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Combination</label>
                <select name="combination_id" id="combinationSelect" onchange="scholarApplyCombination()">
                    <option value="">— None —</option>
                    <?php foreach ($combinations as $combo): ?>
                        <option value="<?= (int) $combo['id'] ?>" <?= ((int) ($student['combination_id'] ?? 0) === (int) $combo['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($combo['code'] . ' — ' . $combo['name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="color:var(--muted);font-size:0.78rem;">Picking a combination auto-ticks its 3 principal subjects below.</span>
            </div>
            <?php endif; ?>

            <div class="subject-grid">
                <?php foreach ($electives as $e): ?>
                    <label class="subject-tile">
                        <input type="checkbox" name="subject_ids[]" id="subj_<?= (int) $e['id'] ?>" value="<?= (int) $e['id'] ?>"
                               <?= in_array((int) $e['id'], $enrolled_ids, true) ? 'checked' : '' ?>>
                        <div>
                            <div class="name"><?= htmlspecialchars($e['subject_name'], ENT_QUOTES) ?></div>
                            <div class="meta"><?= htmlspecialchars($e['subject_code'], ENT_QUOTES) ?></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="save_subjects" value="1">Save Subjects</button>
        </form>

        <?php if ($is_a_level && !empty($combinations)): ?>
        <script>
            var SCHOLAR_COMBO_SUBJECTS = <?= json_encode($combo_subject_map, JSON_HEX_TAG) ?>;
            function scholarApplyCombination() {
                var comboId = document.getElementById('combinationSelect').value;
                var subjectIds = SCHOLAR_COMBO_SUBJECTS[comboId] || [];
                subjectIds.forEach(function (id) {
                    var box = document.getElementById('subj_' + id);
                    if (box) box.checked = true;
                });
            }
        </script>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
