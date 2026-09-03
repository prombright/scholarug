<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — MANAGE CLASSES: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/classes.php so the classic page and the JSON
| endpoint (api/admin/classes.php) enforce identical rules.
|--------------------------------------------------------------------------
*/

/**
 * Creates the classes row for a class+stream and keeps the reusable
 * streams catalog (other pages read class_name/stream_name pairs from it)
 * pointed at that row's id.
 */
function scholar_add_class_stream(PDO $pdo, int $schoolId, string $className, string $streamName): int
{
    $pdo->prepare('INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, ?)')
        ->execute([$schoolId, $className, $streamName]);
    $classId = (int) $pdo->lastInsertId();

    $exists = $pdo->prepare('SELECT id FROM streams WHERE school_id = ? AND class_name = ? AND stream_name = ?');
    $exists->execute([$schoolId, $className, $streamName]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE streams SET class_id = ? WHERE school_id = ? AND class_name = ? AND stream_name = ?')
            ->execute([$classId, $schoolId, $className, $streamName]);
    } else {
        $pdo->prepare('INSERT INTO streams (school_id, class_name, stream_name, class_id) VALUES (?, ?, ?, ?)')
            ->execute([$schoolId, $className, $streamName, $classId]);
    }

    return $classId;
}

/**
 * The ladder (S.1-S.6, or Primary's P.1-P.7) is fixed and known in
 * advance -- every class name with no row yet at all gets one, unstreamed,
 * automatically. Runs on every load, same as the classic page.
 */
function admin_classes_ensure_base_classes(PDO $pdo, int $schoolId, array $allClassNames): void
{
    $existing_stmt = $pdo->prepare('SELECT DISTINCT class_name FROM classes WHERE school_id = ?');
    $existing_stmt->execute([$schoolId]);
    $missing = array_diff($allClassNames, $existing_stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$missing) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO classes (school_id, class_name, stream_name) VALUES (?, ?, NULL)');
    foreach ($missing as $cn) {
        $ins->execute([$schoolId, $cn]);
        scholar_ensure_compulsory_subjects($pdo, $schoolId, $cn);
    }
}

/** @return array{classes:array,teaching_staff:array,streams_by_class:array} */
function admin_classes_fetch_all(PDO $pdo, int $schoolId, array $allClassNames): array
{
    // $allClassNames only ever comes from the fixed Primary/Secondary
    // arrays (never user input), so interpolating it into FIELD() is safe.
    $class_order_sql = "'" . implode("','", $allClassNames) . "'";

    $classes_stmt = $pdo->prepare("
        SELECT c.id, c.class_name, c.stream_name, c.class_teacher_id,
               CONCAT(t.first_name, ' ', t.last_name) AS class_teacher_name,
               (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
        FROM classes c
        LEFT JOIN staff t ON t.staff_id = c.class_teacher_id AND t.school_id = c.school_id
        WHERE c.school_id = ?
        ORDER BY FIELD(c.class_name, $class_order_sql), c.stream_name
    ");
    $classes_stmt->execute([$schoolId]);

    $teaching_staff_stmt = $pdo->prepare("
        SELECT staff_id, CONCAT(first_name, ' ', last_name) AS full_name
        FROM staff
        WHERE school_id = ? AND staff_category = 'Teaching'
        ORDER BY first_name, last_name
    ");
    $teaching_staff_stmt->execute([$schoolId]);

    $streams_stmt = $pdo->prepare("
        SELECT class_name, stream_name FROM streams
        WHERE school_id = ?
        ORDER BY FIELD(class_name, $class_order_sql), stream_name
    ");
    $streams_stmt->execute([$schoolId]);
    $streams_by_class = [];
    foreach ($streams_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $streams_by_class[$row['class_name']][] = $row['stream_name'];
    }

    return [
        'classes' => $classes_stmt->fetchAll(PDO::FETCH_ASSOC),
        'teaching_staff' => $teaching_staff_stmt->fetchAll(PDO::FETCH_ASSOC),
        'streams_by_class' => $streams_by_class,
    ];
}

/** @return array{ok:bool,message:string} */
function admin_classes_add_stream(PDO $pdo, int $schoolId, array $allClassNames, string $className, string $streamName): array
{
    if (!in_array($className, $allClassNames, true) || $streamName === '') {
        return ['ok' => false, 'message' => 'Enter a stream name.'];
    }
    $dup = $pdo->prepare('SELECT id FROM classes WHERE school_id = ? AND class_name = ? AND stream_name = ?');
    $dup->execute([$schoolId, $className, $streamName]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'message' => "{$className} {$streamName} already exists."];
    }
    scholar_add_class_stream($pdo, $schoolId, $className, $streamName);
    scholar_ensure_compulsory_subjects($pdo, $schoolId, $className);
    return ['ok' => true, 'message' => "{$className} {$streamName} added."];
}

/** @return array{ok:bool,message:string} */
function admin_classes_seed_alevel(PDO $pdo, int $schoolId, string $schoolType, array $levelClasses, string $className): array
{
    if ($schoolType !== 'Secondary' || !in_array($className, $levelClasses['A-Level'] ?? [], true)) {
        return ['ok' => false, 'message' => 'Invalid class for A-Level streams.'];
    }
    foreach (['Sciences', 'Arts'] as $default_stream) {
        $dup = $pdo->prepare('SELECT id FROM classes WHERE school_id = ? AND class_name = ? AND stream_name = ?');
        $dup->execute([$schoolId, $className, $default_stream]);
        if ($dup->fetchColumn()) continue;
        scholar_add_class_stream($pdo, $schoolId, $className, $default_stream);
    }
    return ['ok' => true, 'message' => "Sciences and Arts classes added for {$className}."];
}

function admin_classes_delete(PDO $pdo, int $schoolId, int $classId): void
{
    $pdo->prepare('DELETE FROM classes WHERE id = ? AND school_id = ?')->execute([$classId, $schoolId]);
}

/** @return array{ok:bool,message:string} */
function admin_classes_assign_teacher(PDO $pdo, int $schoolId, int $classId, int $teacherId): array
{
    if ($teacherId <= 0) {
        return ['ok' => false, 'message' => 'Pick a teacher to assign.'];
    }
    $valid = $pdo->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND school_id = ? AND staff_category = 'Teaching'");
    $valid->execute([$teacherId, $schoolId]);
    if (!$valid->fetchColumn()) {
        return ['ok' => false, 'message' => 'That teacher was not found for this school.'];
    }
    $pdo->prepare('UPDATE classes SET class_teacher_id = ? WHERE id = ? AND school_id = ?')->execute([$teacherId, $classId, $schoolId]);
    return ['ok' => true, 'message' => 'Class teacher assigned.'];
}

function admin_classes_remove_teacher(PDO $pdo, int $schoolId, int $classId): void
{
    $pdo->prepare('UPDATE classes SET class_teacher_id = NULL WHERE id = ? AND school_id = ?')->execute([$classId, $schoolId]);
}
