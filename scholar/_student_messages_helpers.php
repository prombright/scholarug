<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT MESSAGES: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of student_messages.php so the classic page and the JSON
| endpoint (api/student/messages.php) enforce identical rules -- who a
| student can message (derived entirely from teacher_assignments +
| class_teacher_id for their own class, never a school-wide directory).
|--------------------------------------------------------------------------
*/

/** Every teacher this student is allowed to message: teaches their class, or is its class_teacher. */
function student_reachable_teachers(PDO $pdo, int $schoolId, int $classId): array
{
    if ($classId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT st.staff_id, TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name
        FROM teacher_assignments ta
        JOIN staff st ON st.staff_id = ta.teacher_id AND st.school_id = ta.school_id
        WHERE ta.class_id = ? AND ta.school_id = ?
        UNION
        SELECT st.staff_id, TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name
        FROM classes c
        JOIN staff st ON st.staff_id = c.class_teacher_id AND st.school_id = c.school_id
        WHERE c.id = ? AND c.school_id = ? AND c.class_teacher_id IS NOT NULL
        ORDER BY teacher_name ASC
    ");
    $stmt->execute([$classId, $schoolId, $classId, $schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Existing threads (conversations this student has actually started), latest message first. */
function student_message_threads(PDO $pdo, int $schoolId, int $studentId): array
{
    $stmt = $pdo->prepare("
        SELECT cv.id, cv.teacher_id, TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name,
            (SELECT COUNT(*) FROM conversation_messages cm WHERE cm.conversation_id = cv.id AND cm.sender_role = 'teacher' AND cm.read_at IS NULL) AS unread,
            (SELECT MAX(created_at) FROM conversation_messages cm WHERE cm.conversation_id = cv.id) AS last_at
        FROM conversations cv
        JOIN staff st ON st.staff_id = cv.teacher_id AND st.school_id = cv.school_id
        WHERE cv.student_id = ? AND cv.school_id = ?
        ORDER BY last_at DESC
    ");
    $stmt->execute([$studentId, $schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Merges the always-messageable teacher list with actual thread data --
 * a teacher with zero messages yet still appears, ordered most-recently-
 * active first, then alphabetically among never-messaged teachers. Same
 * shape/order the classic page's sidebar and its poll_threads branch both
 * built independently; this is the one place that does it now.
 */
function student_message_rows(array $teachers, array $threads): array
{
    $out = [];
    foreach ($teachers as $t) {
        $tid = (int) $t['staff_id'];
        $unread = 0;
        $last_at = null;
        foreach ($threads as $th) {
            if ((int) $th['teacher_id'] === $tid) {
                $unread = (int) $th['unread'];
                $last_at = $th['last_at'];
            }
        }
        $out[] = ['teacher_id' => $tid, 'teacher_name' => $t['teacher_name'], 'unread' => $unread, 'last_at' => $last_at];
    }
    usort($out, function ($a, $b) {
        if ($a['last_at'] === $b['last_at']) return strcmp($a['teacher_name'], $b['teacher_name']);
        if ($a['last_at'] === null) return 1;
        if ($b['last_at'] === null) return -1;
        return strcmp($b['last_at'], $a['last_at']);
    });
    return $out;
}

/** Finds (or creates) the conversation for (student, teacher). */
function student_find_or_create_conversation(PDO $pdo, int $schoolId, int $studentId, int $teacherId): int
{
    $find = $pdo->prepare('SELECT id FROM conversations WHERE student_id = ? AND teacher_id = ? AND school_id = ?');
    $find->execute([$studentId, $teacherId, $schoolId]);
    $id = $find->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $ins = $pdo->prepare('INSERT INTO conversations (school_id, student_id, teacher_id) VALUES (?, ?, ?)');
    $ins->execute([$schoolId, $studentId, $teacherId]);
    return (int) $pdo->lastInsertId();
}

/** Marks the teacher's messages in $conversationId read, then returns every message in it. */
function student_conversation_messages(PDO $pdo, int $conversationId): array
{
    $mark = $pdo->prepare("UPDATE conversation_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'teacher' AND read_at IS NULL");
    $mark->execute([$conversationId]);

    $stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM conversation_messages WHERE conversation_id = ? ORDER BY created_at ASC');
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Same as student_conversation_messages(), but only messages after $afterId. */
function student_poll_messages(PDO $pdo, int $conversationId, int $afterId): array
{
    $mark = $pdo->prepare("UPDATE conversation_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'teacher' AND read_at IS NULL");
    $mark->execute([$conversationId]);

    $stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM conversation_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC');
    $stmt->execute([$conversationId, $afterId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
