<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR BULK SMS: HISTORY HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/sms/history.php page and
| scholar/api/hr/sms_history.php. Logic ported verbatim from the original
| page.
*/

function hr_sms_history_campaigns(PDO $pdo, int $school_id): array
{
    $campaigns_stmt = $pdo->prepare("SELECT * FROM sms_campaigns WHERE school_id = ? ORDER BY created_at DESC LIMIT 50");
    $campaigns_stmt->execute([$school_id]);
    return $campaigns_stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hr_sms_history_recipients(PDO $pdo, int $campaign_id): array
{
    $r_stmt = $pdo->prepare("SELECT phone, channel, full_name, delivery_status FROM sms_campaign_recipients WHERE campaign_id = ? ORDER BY id");
    $r_stmt->execute([$campaign_id]);
    return $r_stmt->fetchAll(PDO::FETCH_ASSOC);
}
