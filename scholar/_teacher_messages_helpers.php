<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER MESSAGES: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of teacher_messages.php so the classic page and the JSON
| endpoint (api/teacher/messages.php) enforce identical rules -- who a
| teacher can message (the Core/Elective roster split, and the
| class-teacher pastoral-scope exception) matters just as much here as any
| of the write-path checks in marks entry or attendance.
|--------------------------------------------------------------------------
*/

/** Every class this teacher can message students in -- teaches a subject there, or is its class_teacher. */
function teacher_reachable_classes(PDO $pdo, int $schoolId, int $staffId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.class_name, c.stream_name, (c.class_teacher_id = ?) AS is_class_teacher
        FROM classes c
        WHERE c.school_id = ? AND (
            c.id IN (SELECT class_id FROM teacher_assignments WHERE school_id = ? AND teacher_id = ?)
            OR c.class_teacher_id = ?
        )
        ORDER BY c.class_name, c.stream_name
    ");
    $stmt->execute([$staffId, $schoolId, $schoolId, $staffId, $staffId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The reachable roster for one specific class (must be a row from
 * teacher_reachable_classes(), including its is_class_teacher flag) --
 * Core subject = whole class; Elective = only students with a real
 * student_subjects enrollment row. Class teacher reaches everyone
 * regardless of subject (pastoral scope, same precedent as
 * bulk_report_print.php/teacher_class_logins.php).
 */
function teacher_class_roster(PDO $pdo, int $schoolId, int $staffId, array $class): array
{
    if ($class['is_class_teacher']) {
        $stmt = $pdo->prepare('SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name');
        $stmt->execute([$schoolId, $class['id']]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT st.id, st.full_name
            FROM students st
            JOIN subjects sub ON sub.school_id = st.school_id AND sub.class_name = st.class_name
            JOIN teacher_assignments ta ON ta.subject_id = sub.id AND ta.class_id = st.class_id AND ta.school_id = st.school_id AND ta.teacher_id = ?
            LEFT JOIN student_subjects ss ON ss.subject_id = sub.id AND ss.student_id = st.id
            WHERE st.school_id = ? AND st.class_id = ?
              AND (sub.subject_type != 'Elective' OR ss.id IS NOT NULL)
            ORDER BY st.full_name
        ");
        $stmt->execute([$staffId, $schoolId, $class['id']]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Finds (or creates) the conversation row for one student -- shared by every send path so they can't drift. */
function teacher_find_or_create_conversation(PDO $pdo, int $schoolId, int $staffId, int $studentId): int
{
    $find = $pdo->prepare('SELECT id FROM conversations WHERE student_id = ? AND teacher_id = ? AND school_id = ?');
    $find->execute([$studentId, $staffId, $schoolId]);
    $id = $find->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $ins = $pdo->prepare('INSERT INTO conversations (school_id, student_id, teacher_id) VALUES (?, ?, ?)');
    $ins->execute([$schoolId, $studentId, $staffId]);
    return (int) $pdo->lastInsertId();
}

/** Threads, latest message first, with each one's unread count. */
function teacher_message_threads(PDO $pdo, int $schoolId, int $staffId): array
{
    $stmt = $pdo->prepare("
        SELECT cv.id, cv.student_id, s.full_name AS student_name,
            (SELECT COUNT(*) FROM conversation_messages cm WHERE cm.conversation_id = cv.id AND cm.sender_role = 'student' AND cm.read_at IS NULL) AS unread,
            (SELECT MAX(created_at) FROM conversation_messages cm WHERE cm.conversation_id = cv.id) AS last_at
        FROM conversations cv
        JOIN students s ON s.id = cv.student_id AND s.school_id = cv.school_id
        WHERE cv.teacher_id = ? AND cv.school_id = ?
        ORDER BY last_at DESC
    ");
    $stmt->execute([$staffId, $schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,error:?string} */
function teacher_send_individual(PDO $pdo, int $schoolId, int $staffId, array $classRosterIds, int $targetId, string $body): array
{
    if (!in_array($targetId, $classRosterIds, true)) {
        return ['ok' => false, 'error' => 'You can only message a student you actually teach in that class.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Message cannot be empty.'];
    }
    $cid = teacher_find_or_create_conversation($pdo, $schoolId, $staffId, $targetId);
    $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'teacher', ?)")->execute([$cid, $body]);
    return ['ok' => true, 'error' => null];
}

/** @return array{ok:bool,count:int,error:?string} */
function teacher_send_broadcast(PDO $pdo, int $schoolId, int $staffId, array $classRosterIds, string $body): array
{
    if ($body === '') {
        return ['ok' => false, 'count' => 0, 'error' => 'Message cannot be empty.'];
    }
    if (empty($classRosterIds)) {
        return ['ok' => false, 'count' => 0, 'error' => 'No students to message in that class yet.'];
    }
    foreach ($classRosterIds as $sid) {
        $cid = teacher_find_or_create_conversation($pdo, $schoolId, $staffId, $sid);
        $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'teacher', ?)")->execute([$cid, $body]);
    }
    return ['ok' => true, 'count' => count($classRosterIds), 'error' => null];
}

/** Marks the student's messages in $conversationId read, then returns every message in it. */
function teacher_conversation_messages(PDO $pdo, int $conversationId): array
{
    $mark = $pdo->prepare("UPDATE conversation_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'student' AND read_at IS NULL");
    $mark->execute([$conversationId]);

    $stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Same as teacher_conversation_messages(), but only messages after $afterId -- the open thread's poll. */
function teacher_poll_messages(PDO $pdo, int $conversationId, int $afterId): array
{
    $mark = $pdo->prepare("UPDATE conversation_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'student' AND read_at IS NULL");
    $mark->execute([$conversationId]);

    $stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM conversation_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC');
    $stmt->execute([$conversationId, $afterId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
