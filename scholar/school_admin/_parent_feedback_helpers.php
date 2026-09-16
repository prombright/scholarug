<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PARENT FEEDBACK INBOX: SHARED LOGIC
|--------------------------------------------------------------------------
| parent_feedback has been written to by parent_portal.php since it was
| built, and read back by that same page (a parent sees their own past
| messages + any response) -- but nothing on the school_admin side has
| ever read or written it. The dashboard's "New Parent Messages" stat
| counted rows nobody could actually open. This is that missing inbox.
|--------------------------------------------------------------------------
*/

/** @return array<int,array{id:int,student_name:string,parent_username:string,subject:string,message:string,status:string,response:?string,created_at:string,responded_at:?string}> */
function admin_feedback_fetch_list(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare("
        SELECT f.id, f.subject, f.message, f.status, f.response, f.created_at, f.responded_at,
               s.full_name AS student_name, u.username AS parent_username
        FROM parent_feedback f
        LEFT JOIN students s ON s.id = f.student_id
        LEFT JOIN users u ON u.id = f.parent_user_id
        WHERE f.school_id = ?
        ORDER BY (f.status = 'new') DESC, f.created_at DESC
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A 'new' message becomes 'read' the moment an admin opens it -- 'responded' stays 'responded' even if reopened. */
function admin_feedback_mark_read(PDO $pdo, int $schoolId, int $feedbackId): void
{
    $pdo->prepare("UPDATE parent_feedback SET status = 'read' WHERE id = ? AND school_id = ? AND status = 'new'")
        ->execute([$feedbackId, $schoolId]);
}

/** @return array{ok:bool,message:string} */
function admin_feedback_respond(PDO $pdo, int $schoolId, int $feedbackId, string $response): array
{
    $response = trim($response);
    if ($response === '') {
        return ['ok' => false, 'message' => 'Write a response before sending.'];
    }
    $stmt = $pdo->prepare("
        UPDATE parent_feedback
        SET response = ?, status = 'responded', responded_at = NOW()
        WHERE id = ? AND school_id = ?
    ");
    $stmt->execute([$response, $feedbackId, $schoolId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Message not found.'];
    }
    return ['ok' => true, 'message' => 'Response sent.'];
}
