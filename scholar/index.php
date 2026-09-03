<?php
declare(strict_types=1);

// The ScholarUg site's own landing page (one level up) is the marketing
// front door -- this just gets anyone hitting /scholar/ straight to where
// they actually need to be, logged in or not.

require_once __DIR__ . '/auth_guard.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . SCHOLAR_BASE . '/' . role_destination($_SESSION['role'] ?? ''));
} else {
    header("Location: " . SCHOLAR_BASE . '/login.php');
}
exit;
