<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR REPORT SETTINGS HELPERS
|--------------------------------------------------------------------------
| Shared by the classic school_admin/report_settings.php page and
| scholar/api/admin/report_settings.php. Logic ported verbatim from the
| original page.
*/

function admin_report_settings_save(PDO $pdo, int $school_id, bool $show_photos, bool $allow_download, ?string $no_data_color): void
{
    $pdo->prepare("
        INSERT INTO school_settings (school_id, show_student_photos, allow_student_download, no_data_color)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            show_student_photos = VALUES(show_student_photos),
            allow_student_download = VALUES(allow_student_download),
            no_data_color = VALUES(no_data_color)
    ")->execute([$school_id, $show_photos ? 1 : 0, $allow_download ? 1 : 0, $no_data_color]);
}

/** Validates/normalizes the "no data" color the same way the classic page's POST handler does. */
function admin_report_settings_clean_color(bool $use_color, string $raw_color): ?string
{
    $no_data_color = trim($raw_color);
    return ($use_color && preg_match('/^#[0-9a-fA-F]{6}$/', $no_data_color)) ? $no_data_color : null;
}
