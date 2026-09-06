<?php
declare(strict_types=1);

$page_title = 'Contact ScholarUg | Get in Touch About Our School Management System';
$page_description = 'Reach the ScholarUg team for a demo, access request or support. We build and support the school management system used by schools across Uganda.';
$page_keywords = 'Contact ScholarUg, ScholarUg support, School Management System Uganda demo';

$contact_message = null;
$contact_message_type = null;
$contact_values = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact_values['name'] = trim($_POST['name'] ?? '');
    $contact_values['email'] = trim($_POST['email'] ?? '');
    $contact_values['subject'] = trim($_POST['subject'] ?? '');
    $contact_values['message'] = trim($_POST['message'] ?? '');
    // Honeypot -- a real visitor never fills in a field hidden with CSS;
    // a bot filling every field on the form does. Silently drop the
    // submission (looks like success to the bot) instead of erroring.
    $honeypot = trim($_POST['website'] ?? '');

    if ($honeypot !== '') {
        $contact_message = "Thanks for reaching out — we'll get back to you shortly.";
        $contact_message_type = 'success';
    } elseif ($contact_values['name'] === '' || $contact_values['email'] === '' || $contact_values['message'] === '') {
        $contact_message = 'Please fill in your name, email and message.';
        $contact_message_type = 'error';
    } elseif (!filter_var($contact_values['email'], FILTER_VALIDATE_EMAIL)) {
        $contact_message = 'Please enter a valid email address.';
        $contact_message_type = 'error';
    } else {
        $to = 'info@abnsystems.com';
        $subject = 'ScholarUg Contact Form: ' . ($contact_values['subject'] !== '' ? $contact_values['subject'] : 'New message');
        $body = "Name: {$contact_values['name']}\n"
            . "Email: {$contact_values['email']}\n\n"
            . "{$contact_values['message']}";
        $headers = "From: ScholarUg Website <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'scholarug.com') . ">\r\n"
            . "Reply-To: " . $contact_values['email'] . "\r\n";

        // mail() has no reliable failure detection on shared hosting (it
        // reports success once handed to the local MTA, not on actual
        // delivery) -- @ only silences a PHP-level warning if the MTA
        // itself is unreachable, so a visitor still sees a clear message
        // either way instead of a raw error.
        $sent = @mail($to, $subject, $body, $headers);
        if ($sent) {
            $contact_message = "Thanks for reaching out — we'll get back to you shortly.";
            $contact_message_type = 'success';
            $contact_values = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
        } else {
            $contact_message = "Something went wrong sending your message — please email us directly at info@abnsystems.com.";
            $contact_message_type = 'error';
        }
    }
}

include "includes/head.php";
include "includes/header.php";
?>


<?php include "modules/contact_hero.php"; ?>


<?php include "modules/contact_form.php"; ?>


<?php include "includes/footer.php"; ?>