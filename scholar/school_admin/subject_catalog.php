<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SUBJECT CATALOG (tick-to-adopt subjects)
|--------------------------------------------------------------------------
| Instead of typing each subject's name/code/papers by hand into
| subject_matrix.php, a school admin ticks which Uganda-curriculum
| subjects their school offers from a shared, pre-populated catalog
| (subject_catalog table, global -- not per-school). Compulsory ones
| (English/Mathematics/Biology/Physics/Chemistry/Geography/History for
| O-Level, General Paper for A-Level) arrive pre-checked -- and for
| O-Level, don't actually need a visit here at all anymore:
| scholar_ensure_compulsory_subjects() (_subject_helpers.php) auto-adopts
| them the moment a class is created or a student is registered into one,
| this page is now only needed for electives.
|
| Deliberately additive only -- unchecking an already-adopted subject
| here does nothing. subjects.id is referenced by teacher_assignments
| and student_marks (the latter ON DELETE CASCADE), so a bulk
| uncheck-and-delete could silently wipe real marks a teacher already
| entered. Removing a mistakenly-adopted subject stays a deliberate
| one-at-a-time action via subject_matrix.php's existing delete.
|
| IMPORTANT: subject_catalog.subject_code values are Scholar-internal
| reference codes, not official UNEB registration codes -- see
| _setup/subject_catalog.sql for why. Every adopted subject's code
| stays editable afterward via subject_matrix.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_subject_helpers.php';
require_once __DIR__ . '/_subject_catalog_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();

$school_type_stmt = $pdo->prepare("SELECT school_type FROM schools WHERE id = ?");
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

$message = '';
$message_type = '';

// ---- Adopt ticked catalog subjects ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adopt_subjects']) && $school_type === 'Secondary') {
    $result = admin_subjects_adopt($pdo, $school_id, $_POST['level_type'] ?? '', array_map('intval', $_POST['catalog_ids'] ?? []));
    $message = $result['message'];
    $message_type = $result['type'];
}

// ---- Adopt ticked combinations (A-Level only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adopt_combinations']) && $school_type === 'Secondary') {
    $result = admin_subjects_adopt_combinations($pdo, $school_id, array_map('intval', $_POST['combo_ids'] ?? []));
    $message = $result['message'];
    $message_type = $result['type'];
}

$sel_view = ($_GET['view'] ?? '') === 'combinations' ? 'combinations' : 'subjects';
$sel_level = in_array($_GET['level_type'] ?? '', ['O-Level', 'A-Level'], true) ? $_GET['level_type'] : 'O-Level';

// Same rows the PHP-rendered fallback below uses -- handed to the Vue
// search widget as plain data. PHP still decides exactly which subjects
// and which "adopted" flags reach the browser; Vue only filters what's
// already here, it never fetches anything of its own.
$catalog_json = admin_subjects_fetch_catalog($pdo, $school_id, $sel_level);

// ---- Data for the Combinations tab ----
$combo_catalog = [];
$adopted_combo_catalog_ids = [];
$adopted_subject_codes = [];
if ($sel_view === 'combinations') {
    $__combo_data = admin_subjects_fetch_combinations_data($pdo, $school_id);
    // Template below (unchanged) expects raw combo_catalog rows + a plain
    // id list -- reshape the helper's richer per-combo data back into that.
    $combo_catalog = array_map(static function (array $c): array {
        return [
            'id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'],
            'subject_code_1' => $c['subject_codes'][0], 'subject_code_2' => $c['subject_codes'][1], 'subject_code_3' => $c['subject_codes'][2],
        ];
    }, $__combo_data['combinations']);
    $adopted_combo_catalog_ids = array_values(array_map(static fn($c) => $c['id'], array_filter($__combo_data['combinations'], static fn($c) => $c['adopted'])));
    $adopted_subject_codes = $__combo_data['adopted_subject_codes'];
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
.level-tabs{display:flex;gap:8px;margin-bottom:20px;}
.level-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.level-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.subject-search{width:100%;max-width:360px;padding:9px 14px;border-radius:8px;border:1px solid var(--border);background:var(--panel);color:var(--text);font-size:0.85rem;margin-bottom:14px;}
.subject-search:focus{outline:none;border-color:var(--cyan);}
.subject-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin-bottom:20px;}
.subject-tile{display:flex;align-items:flex-start;gap:10px;background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.subject-tile.locked{opacity:0.6;}
.subject-tile input[type=checkbox]{width:auto;margin-top:3px;}
.subject-tile .name{font-weight:600;font-size:0.88rem;}
.subject-tile .meta{color:var(--muted);font-size:0.75rem;margin-top:2px;}
.subject-tile .compulsory-badge{display:inline-block;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.4px;color:var(--cyan);border:1px solid rgba(0,168,168,0.4);padding:1px 6px;border-radius:10px;margin-left:6px;}
.footer-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.leftover-note{color:var(--muted);font-size:0.85rem;}
.leftover-note a{color:var(--cyan);font-weight:600;}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Subject Catalog</h1>

    <?php if ($school_type !== 'Secondary'): ?>
        <div class="disclaimer">
            This catalog covers O-Level/A-Level (Secondary) subjects only. Your school is set up as Primary --
            use the "Seed Default Subjects" button on <a href="../subject_matrix.php" style="color:#00A8A8;">Subject Matrix</a> instead.
        </div>
    <?php else: ?>

    <?php if ($message): ?><div class="alert <?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="disclaimer">
        <strong>Heads up:</strong> subject codes here (e.g. <code>ENG</code>, <code>MTC</code>) are Scholar's own
        reference codes, not official UNEB registration codes -- we don't have those memorized reliably enough
        to assert as fact. Tick what your school offers below; you can edit any adopted subject's code afterward
        via Subject Matrix if it needs to match your official UNEB paperwork.
    </div>

    <div class="section">
        <div class="level-tabs">
            <a href="?level_type=O-Level" class="<?= ($sel_view === 'subjects' && $sel_level === 'O-Level') ? 'active' : '' ?>">O-Level (S.1 - S.4)</a>
            <a href="?level_type=A-Level" class="<?= ($sel_view === 'subjects' && $sel_level === 'A-Level') ? 'active' : '' ?>">A-Level (S.5 - S.6)</a>
            <a href="?view=combinations" class="<?= $sel_view === 'combinations' ? 'active' : '' ?>">A-Level Combinations</a>
        </div>

        <?php if ($sel_view === 'combinations'): ?>

        <div class="disclaimer">
            A combination can only be adopted once your school has already adopted all 3 of its subjects
            under the A-Level tab above.
        </div>

        <form method="post">
            <div class="subject-grid">
                <?php foreach ($combo_catalog as $combo): ?>
                    <?php
                        $adopted = in_array((int) $combo['id'], $adopted_combo_catalog_ids, true);
                        $codes = [$combo['subject_code_1'], $combo['subject_code_2'], $combo['subject_code_3']];
                        $missing = array_diff($codes, $adopted_subject_codes);
                        $ready = empty($missing);
                    ?>
                    <label class="subject-tile <?= ($adopted || !$ready) ? 'locked' : '' ?>">
                        <input type="checkbox" name="combo_ids[]" value="<?= (int) $combo['id'] ?>"
                               <?= $adopted ? 'checked disabled' : '' ?>
                               <?= (!$adopted && !$ready) ? 'disabled' : '' ?>>
                        <div>
                            <div class="name">
                                <?= htmlspecialchars($combo['code'], ENT_QUOTES) ?>
                                <span class="compulsory-badge"><?= htmlspecialchars($combo['name'], ENT_QUOTES) ?></span>
                            </div>
                            <div class="meta">
                                <?= htmlspecialchars(implode(' / ', $codes), ENT_QUOTES) ?>
                                <?php if ($adopted): ?>
                                    &middot; already added
                                <?php elseif (!$ready): ?>
                                    &middot; adopt <?= htmlspecialchars(implode(', ', $missing), ENT_QUOTES) ?> first
                                <?php endif; ?>
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="footer-row">
                <span class="leftover-note">Combinations are how A-Level students' subjects get assigned -- see each student's "Subjects" page.</span>
                <button type="submit" name="adopt_combinations" value="1">Adopt Ticked Combinations</button>
            </div>
        </form>

        <?php else: ?>

        <form method="post" id="subjectCatalogApp">
            <input type="hidden" name="level_type" value="<?= htmlspecialchars($sel_level, ENT_QUOTES) ?>">

            <input type="text" v-model="query" class="subject-search" placeholder="Search subjects by name or code&hellip;">

            <div class="subject-grid">
                <label v-for="cs in filtered" :key="cs.id" class="subject-tile" :class="{ locked: cs.adopted }">
                    <input type="checkbox" name="catalog_ids[]" :value="cs.id"
                           :checked="cs.adopted || cs.is_compulsory" :disabled="cs.adopted">
                    <div>
                        <div class="name">
                            {{ cs.subject_name }}
                            <span v-if="cs.is_compulsory" class="compulsory-badge">Compulsory</span>
                        </div>
                        <div class="meta">
                            {{ cs.subject_code }} &middot; {{ cs.papers_count }} paper<span v-if="cs.papers_count > 1">s</span>
                            <span v-if="cs.adopted">&middot; already added</span>
                            <a v-if="cs.adopted && !cs.is_compulsory" href="subject_enrollment.php" style="color:#00A8A8;">&middot; Assign Students &rarr;</a>
                        </div>
                    </div>
                </label>
                <p v-if="filtered.length === 0" class="leftover-note" style="padding:16px 0;">No subjects match &ldquo;{{ query }}&rdquo;.</p>
            </div>

            <div class="footer-row">
                <span class="leftover-note">Don't see a subject your school offers? <a href="../subject_matrix.php">Add a custom subject &rarr;</a></span>
                <button type="submit" name="adopt_subjects" value="1">Adopt Ticked Subjects</button>
            </div>
        </form>

        <script>const CATALOG_SUBJECTS = <?= json_encode($catalog_json, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
        <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
        <script>
        // Progressive enhancement only: filters subjects already sent by
        // PHP above (same auth/role/school-scoping as every other page --
        // nothing here talks to the server). If this script fails to load,
        // the form still works exactly as before, just without live search.
        Vue.createApp({
            data() {
                return { query: '', subjects: CATALOG_SUBJECTS };
            },
            computed: {
                filtered() {
                    const q = this.query.trim().toLowerCase();
                    if (!q) return this.subjects;
                    return this.subjects.filter(function (cs) {
                        return cs.subject_name.toLowerCase().includes(q) || cs.subject_code.toLowerCase().includes(q);
                    });
                }
            }
        }).mount('#subjectCatalogApp');
        </script>

        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
