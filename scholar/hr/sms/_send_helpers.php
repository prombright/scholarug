<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR BULK SMS: SEND HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/sms/send.php page and scholar/api/hr/sms_send.php.
| Logic ported verbatim from the original page. Two-step preview/confirm
| deliberately preserved -- same reasoning as any real-money action in
| this app (an accidental single click should never spend a school's
| wallet balance or send a real message).
*/

function hr_sms_send_groups(PDO $pdo, int $school_id): array
{
    $groups_stmt = $pdo->prepare("SELECT * FROM sms_contact_groups WHERE school_id = ? ORDER BY name");
    $groups_stmt->execute([$school_id]);
    return $groups_stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Resolves the posted group selection (a real group id, or the sentinel "whole_school") into the group array ScholarSmsContacts::resolveGroup() expects. */
function hr_sms_send_resolve_selection(PDO $pdo, int $schoolId, string $selection, array $groups): ?array
{
    if ($selection === 'whole_school') {
        return ['id' => 0, 'group_type' => 'whole_school', 'class_id' => null];
    }
    $groupId = (int) $selection;
    foreach ($groups as $g) {
        if ((int) $g['id'] === $groupId) {
            return $g;
        }
    }
    return null;
}

function hr_sms_send_preview(PDO $pdo, int $school_id, float $balance, string $selection, string $message, array $groups): array
{
    require_once __DIR__ . '/../../lib/ScholarSmsContacts.php';
    require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';

    $group = hr_sms_send_resolve_selection($pdo, $school_id, $selection, $groups);

    if ($group === null) {
        return ['ok' => false, 'message' => 'Choose who to send to.'];
    }
    if ($message === '') {
        return ['ok' => false, 'message' => 'Write a message.'];
    }

    $recipients = ScholarSmsContacts::resolveGroup($pdo, $school_id, $group);
    if (count($recipients) === 0) {
        return ['ok' => false, 'message' => 'No reachable phone numbers for that selection yet.'];
    }

    $info = ScholarSmsPricing::segmentInfo($message);
    $cost = ScholarSmsPricing::totalCost($pdo, max(1, $info['segments']), count($recipients));

    return [
        'ok' => true,
        'preview' => [
            'group_selection' => $selection,
            'group_label' => $group['group_type'] === 'whole_school' ? 'Whole School' : $group['name'],
            'message' => $message,
            'recipient_count' => count($recipients),
            'segments' => max(1, $info['segments']),
            'cost' => $cost,
            'can_afford' => $balance >= $cost,
        ],
    ];
}

function hr_sms_send_confirm(PDO $pdo, int $school_id, int $user_id, string $selection, string $message, array $groups): array
{
    require_once __DIR__ . '/../../lib/ScholarSmsContacts.php';
    require_once __DIR__ . '/../../lib/ScholarCampaignSender.php';

    $group = hr_sms_send_resolve_selection($pdo, $school_id, $selection, $groups);
    if ($group === null || $message === '') {
        return ['ok' => false, 'message' => 'Something about that send was invalid -- please try again.'];
    }

    $recipients = ScholarSmsContacts::resolveGroup($pdo, $school_id, $group);
    try {
        $sent_result = ScholarCampaignSender::send($pdo, $school_id, $user_id, $message, $recipients);
        return ['ok' => true, 'result' => $sent_result];
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
