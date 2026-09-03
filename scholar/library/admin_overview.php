<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin', 'headteacher', 'dos']);

$school_id = current_school_id();

$docs_stmt = $pdo->prepare("
    SELECT d.title, d.category, d.status, d.term, d.year, d.created_at,
           c.class_name, s.subject_name,
           CONCAT(st.first_name, ' ', st.last_name) AS teacher_name
    FROM library_documents d
    JOIN classes c ON c.id = d.class_id
    JOIN subjects s ON s.id = d.subject_id
    JOIN staff st ON st.staff_id = d.teacher_id
    WHERE d.school_id = ?
    ORDER BY d.created_at DESC
    LIMIT 200
");
$docs_stmt->execute([$school_id]);
$docs = $docs_stmt->fetchAll();

$ACTIVE_NAV = 'library';
require_once __DIR__ . '/../_admin_shell.php';
?>
    <main class="main-content">
    <div class="page-inner">
<style>
.lib-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.lib-card table{width:100%;border-collapse:collapse;font-size:0.85rem;}
.lib-card th,.lib-card td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);}
.lib-card th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;}
.badge-draft{background:rgba(100,116,139,0.2);color:var(--muted);}
.badge-published{background:rgba(16,185,129,0.15);color:var(--green);}
</style>

    <h2 style="color:var(--text);">Library Overview</h2>
    <p style="color:var(--muted);">School-wide view of notes and past papers shared by teachers (latest 200).</p>

    <div class="lib-card">
        <table>
            <thead><tr><th>Title</th><th>Type</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Term</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($docs)): ?><tr><td colspan="7" style="color:var(--muted);">No documents yet.</td></tr><?php endif; ?>
            <?php foreach ($docs as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $d['category'] === 'notes' ? 'Notes' : 'Past Paper' ?></td>
                <td><?= htmlspecialchars($d['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['teacher_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(trim(($d['term'] ?? '') . ' ' . ($d['year'] ?? '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                <td><span class="badge badge-<?= strtolower($d['status']) ?>"><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    </div><!-- /.page-inner -->
    </main>
</div><!-- /.app-shell -->
</body>
</html>
