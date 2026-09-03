<?php
// This partial used to open its own full <!DOCTYPE html><html><head>...
// block -- fine on the pages that included ONLY header.php (about.php,
// contact.php), but index.php and products.php include includes/head.php
// FIRST, so those two pages ended up with two nested <html>/<head>/<body>
// sets. head.php is now the single place that owns the document shell
// (and per-page <title>/meta); every page includes head.php then this
// file, so this is just the <header><nav> markup.
?>
<header>


<div class="container">


<nav class="navbar">


<!-- LOGO -->

<a href="index.php" class="logo">

Scholar<span>Ug</span>

</a>



<!-- NAVIGATION -->

<div class="nav-links">


<a href="index.php">
Home
</a>


<a href="about.php">
About
</a>


<a href="products.php">
Solutions
</a>


<a href="contact.php">
Contact
</a>


</div>




<!-- ACTION BUTTON -->

<div class="header-cta">

    <a href="scholar/login" class="btn btn-primary">

    Login

    </a>

</div>



<!-- MOBILE MENU ICON -->


<div class="mobile-menu" id="mobileMenuToggle" role="button" tabindex="0" aria-label="Toggle menu" aria-expanded="false">

<i class="bi bi-list"></i>

</div>



</nav>


</div>


</header>


<!-- FLOATING ACTION BUTTONS -->
<!-- Fixed-position, independent of header.php's mobile breakpoint (which
     hides .header-cta on small screens) so Login stays reachable on
     mobile too. Simple matching circular icon buttons in opposite bottom
     corners -- Login left, WhatsApp right -- so both are immediately
     visible/recognizable rather than blending into page content.
     WhatsApp opens a pre-filled chat via wa.me, no JS SDK. -->
<a href="scholar/login" class="floating-btn floating-login" aria-label="Login to Scholar">
    <i class="bi bi-box-arrow-in-right"></i>
</a>

<a href="https://wa.me/256759815047?text=Hi%20ScholarUg%2C%20I%27d%20like%20to%20find%20out%20more."
   class="floating-btn floating-whatsapp" aria-label="Chat with ScholarUg on WhatsApp" target="_blank" rel="noopener">
    <i class="bi bi-whatsapp"></i>
</a>

<script>
// Mobile nav had a hamburger icon with nothing wired to it -- .nav-links
// was just permanently display:none below 768px, so mobile visitors could
// not reach Home/About/Solutions/Contact at all. Toggles a .mobile-open
// class the CSS shows/hides against.
(function () {
    var toggle = document.getElementById('mobileMenuToggle');
    var links = document.querySelector('.nav-links');
    if (!toggle || !links) return;
    function toggleMenu() {
        var open = links.classList.toggle('mobile-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var icon = toggle.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-list');
            icon.classList.toggle('bi-x-lg');
        }
    }
    toggle.addEventListener('click', toggleMenu);
    // A div with role="button" isn't natively keyboard-activatable like a
    // real <button> would be -- Enter/Space have to be wired up by hand.
    toggle.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleMenu();
        }
    });
})();
</script>