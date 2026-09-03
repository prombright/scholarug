<?php
// Single source of truth for the <head> block on every marketing page --
// previously includes/header.php ALSO opened its own competing
// <!DOCTYPE html><html><head>, so index.php/products.php (which include
// this file first) shipped two nested <head>s, and about.php/contact.php
// (which included only header.php) got a different, generic title with
// no keywords/OG/canonical at all. Now every page includes this file
// first, optionally setting $page_title / $page_description /
// $page_keywords / $page_canonical beforehand for per-page SEO instead of
// one identical tag set everywhere.
$page_title = $page_title ?? 'ScholarUg | School Management Platform For Ugandan Schools';
$page_description = $page_description ?? "ScholarUg is a school management system for Uganda that helps schools manage students, academics, finance and communication in one platform.";
$page_keywords = $page_keywords ?? 'ScholarUg, Scholar, School Management System, School Management System Uganda, School Software Uganda, Student Information System, Bulk SMS';
$__site_origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'scholarug.com');
$page_canonical = $page_canonical ?? ($__site_origin . ($_SERVER['REQUEST_URI'] ?? '/'));
?>
<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">



<title>
<?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?>
</title>



<meta name="description"
content="<?= htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') ?>">



<meta name="keywords"
content="<?= htmlspecialchars($page_keywords, ENT_QUOTES, 'UTF-8') ?>">



<meta name="author"
content="ScholarUg">


<meta name="robots" content="index, follow">


<meta name="google-site-verification" content="2bzvDsb-I0x_zeBiOsKltTKtuPOrQ53_iuz6JN4bv0g">


<link rel="canonical" href="<?= htmlspecialchars($page_canonical, ENT_QUOTES, 'UTF-8') ?>">



<!-- OPEN GRAPH / SOCIAL SHARING -->

<meta property="og:type" content="website">
<meta property="og:site_name" content="ScholarUg">
<meta property="og:title" content="<?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($page_canonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($__site_origin, ENT_QUOTES, 'UTF-8') ?>/assets/images/og-image.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="ScholarUg -- Smarter Schools, Simplified">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($page_description, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($__site_origin, ENT_QUOTES, 'UTF-8') ?>/assets/images/og-image.png">



<!-- FAVICON -->

<link rel="icon" href="assets/images/favicon.png" type="image/png">





<!-- GOOGLE FONT, BOOTSTRAP ICONS, AOS -->
<!-- All three used to be plain synchronous <link rel="stylesheet"> tags --
     three render-blocking round-trips to three different CDNs before the
     browser could paint anything (Lighthouse: "Eliminate render-blocking
     resources", ~2s). None of the three is needed for first paint (the
     font has a system-font fallback via &display=swap already, icons and
     scroll animations are secondary), so each loads via the standard
     preload-then-swap pattern: fetch without blocking render, then
     promote to an active stylesheet once it arrives. <noscript> keeps
     them working with JS disabled. -->

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="preconnect" href="https://unpkg.com">

<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<link rel="preload" as="style" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" onload="this.onload=null;this.rel='stylesheet'">
<link rel="preload" as="style" href="https://unpkg.com/aos@2.3.1/dist/aos.css" onload="this.onload=null;this.rel='stylesheet'">

<noscript>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
</noscript>





<!-- MAIN CSS -->
<?php
// Cache-busting: .htaccess sets long browser-cache headers for static
// assets, and this file's own filename never changes, so returning
// visitors (and anyone testing on a phone) can keep being served a
// stale cached copy after every edit here. filemtime() as the version
// query string forces a fresh fetch exactly when the file actually
// changes, no manual bump needed.
$__style_path = __DIR__ . '/../assets/css/style.css';
$__style_ver = file_exists($__style_path) ? filemtime($__style_path) : time();
?>
<link rel="stylesheet" href="assets/css/style.css?v=<?= $__style_ver ?>">


<!-- MAIN JS -->


</head>



<body>