<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR BULK SMS: CONTACTS HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/sms/contacts.php page and
| scholar/api/hr/sms_contacts.php. Logic ported verbatim from the original
| page. The CSV/.xlsx import stays a classic multipart form embedded in
| the Vue page -- file transfer isn't something JSON carries any better
| (same reasoning as the students CSV import elsewhere in this app).
*/

function hr_sms_contacts_state(PDO $pdo, int $school_id): array
{
    require_once __DIR__ . '/../../lib/ScholarSmsContacts.php';

    $groups_stmt = $pdo->prepare("SELECT * FROM sms_contact_groups WHERE school_id = ? ORDER BY created_at DESC");
    $groups_stmt->execute([$school_id]);
    $groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as &$g) {
        $g['recipient_count'] = count(ScholarSmsContacts::resolveGroup($pdo, $school_id, $g));
    }
    unset($g);

    $classes_stmt = $pdo->prepare("
        SELECT id, class_name, stream_name FROM classes
        WHERE school_id = ? AND id NOT IN (SELECT class_id FROM sms_contact_groups WHERE school_id = ? AND group_type = 'class' AND class_id IS NOT NULL)
        ORDER BY class_name, stream_name
    ");
    $classes_stmt->execute([$school_id, $school_id]);
    $available_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['groups' => $groups, 'available_classes' => $available_classes];
}

/** Adds a class as a live group. Returns ['ok'=>bool,'message'=>string]. */
function hr_sms_contacts_add_class_group(PDO $pdo, int $school_id, int $class_id): array
{
    $class_stmt = $pdo->prepare("SELECT class_name, stream_name FROM classes WHERE id = ? AND school_id = ?");
    $class_stmt->execute([$class_id, $school_id]);
    $class_row = $class_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class_row) {
        return ['ok' => false, 'message' => 'Invalid class selected.'];
    }

    $dup = $pdo->prepare("SELECT id FROM sms_contact_groups WHERE school_id = ? AND group_type = 'class' AND class_id = ?");
    $dup->execute([$school_id, $class_id]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'message' => 'That class is already a group.'];
    }

    $name = $class_row['class_name'] . ($class_row['stream_name'] ? ' - ' . $class_row['stream_name'] : '');
    $pdo->prepare("INSERT INTO sms_contact_groups (school_id, name, group_type, class_id) VALUES (?, ?, 'class', ?)")
        ->execute([$school_id, $name, $class_id]);
    return ['ok' => true, 'message' => "\"$name\" added as a group."];
}

function hr_sms_contacts_delete_group(PDO $pdo, int $school_id, int $group_id): array
{
    $pdo->prepare("DELETE FROM sms_contact_groups WHERE id = ? AND school_id = ?")->execute([$group_id, $school_id]);
    return ['ok' => true, 'message' => 'Group deleted.'];
}
