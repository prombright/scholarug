<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — CHAT HELPERS
|--------------------------------------------------------------------------
| Shared by student_messages.php and teacher_messages.php so the two
| sides of the same conversation UI can't drift apart -- same avatar
| initials, same "2m ago" formatting, in one place.
|--------------------------------------------------------------------------
*/

if (!function_exists('chat_initials')) {
    function chat_initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name);
        $initials = strtoupper(substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= strtoupper(substr(end($parts), 0, 1));
        }
        return htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('chat_relative_time')) {
    function chat_relative_time(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . 'm';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400) . 'd';
        }
        return date('d M', $ts);
    }
}
