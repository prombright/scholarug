<?php
/*
|--------------------------------------------------------------------------
| ABN SYSTEMS — BRANDED PRELOADER (Scholar copy)
|--------------------------------------------------------------------------
| Same markup as includes/preloader.php in the marketing site root — kept
| as its own copy here (not a cross-app include) so Scholar stays
| deployable on its own without a dependency on the parent site's files.
|--------------------------------------------------------------------------
*/

// Best-effort page-view log into the shared abn_platform.page_views table
// -- same "second PDO connection to abn_platform, same MySQL server"
// pattern sso_login.php already uses. DB_HOST/DB_USER/DB_PASS are always
// already defined by the time this file runs (every caller loads
// config.php via db.php/auth_guard.php first). Never throws.
if (!function_exists('scholar_track_visit')) {
    function scholar_track_visit(): void
    {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=abn_platform;charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
            );
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $stmt = $pdo->prepare("INSERT INTO page_views (system, path) VALUES ('scholar', ?)");
            $stmt->execute([$path]);
        } catch (Throwable $e) {
            // Analytics is never allowed to break the page.
        }
    }
}
scholar_track_visit();
?>
<div id="abn-preloader" aria-hidden="true">
    <div class="abn-preloader-word">Scholar<span>Ug</span></div>
    <div class="abn-preloader-tag">Smarter Schools, Simplified.</div>
</div>
<style>
#abn-preloader {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #071E26;
    transition: opacity .4s ease, visibility .4s ease;
}
#abn-preloader.abn-loaded {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.abn-preloader-word {
    font-family: 'Poppins', 'Segoe UI', sans-serif;
    font-weight: 800;
    font-size: clamp(28px, 6vw, 44px);
    letter-spacing: .5px;
    color: #fff;
}
.abn-preloader-tag {
    margin-top: 10px;
    font-family: 'Poppins', 'Segoe UI', sans-serif;
    font-size: clamp(11px, 2vw, 13px);
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, .55);
}
.abn-preloader-word span {
    background: linear-gradient(90deg, #0A3D62, #00A8A8, #38ada9, #0A3D62);
    background-size: 300% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: abn-sweep 1.6s linear infinite;
}
@keyframes abn-sweep {
    0% { background-position: 0% 0; }
    100% { background-position: 300% 0; }
}
@media (prefers-reduced-motion: reduce) {
    .abn-preloader-word span { animation: none; color: #00A8A8; }
}
</style>
<script>
(function () {
    var el = document.getElementById('abn-preloader');
    if (!el) return;

    // A click on _admin_shell.php's sidebar sets this right before a real
    // navigation fires. If it's here, this load is a sidebar-triggered
    // "same page" nav, not a cold visit -- skip the splash entirely so it
    // doesn't replay (and flash) on every tab switch. Direct URL entry,
    // bookmarks, and hard refreshes never set this flag, so they still get
    // the full splash exactly as before.
    if (sessionStorage.getItem('scholarInternalNav')) {
        sessionStorage.removeItem('scholarInternalNav');
        el.remove();
        return;
    }

    var hidden = false;
    function hide() {
        if (hidden) return;
        hidden = true;
        el.classList.add('abn-loaded');
        setTimeout(function () { el.remove(); }, 450);
    }
    if (document.readyState === 'complete') hide();
    else window.addEventListener('load', hide);
    setTimeout(hide, 4000);
})();
</script>
<noscript><style>#abn-preloader { display: none !important; }</style></noscript>

<?php
/*
|--------------------------------------------------------------------------
| SITE-WIDE LIGHT/DARK TOGGLE
|--------------------------------------------------------------------------
| Scholar has no shared layout file every page goes through, but nearly
| every page (dashboards, _admin_shell.php, login.php) already includes
| this preloader right after <body>. Piggybacking the toggle here gets it
| onto the whole app from one edit instead of duplicating a button into
| each dashboard.
|
| Most dashboards already share one :root{ --bg; --panel; --border; --text;
| --muted; ... } variable naming convention (Scholar is dark-by-default,
| unlike bulksms which is light-by-default) -- the [data-theme="light"]
| block below re-points those same names, so pages using that convention
| get real light mode for free. Pages with their own bespoke palette
| (login.php, nurse_dashboard.php) carry a small dedicated override in
| their own <style> block instead.
|--------------------------------------------------------------------------
*/
?>
<style>
.scholar-theme-toggle {
    position: fixed;
    top: 14px;
    right: 14px;
    z-index: 9998;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.15);
    background: rgba(13,17,24,.85);
    color: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0,0,0,.35);
    backdrop-filter: blur(6px);
    transition: background .2s ease, border-color .2s ease, transform .15s ease;
}
.scholar-theme-toggle:hover { transform: translateY(-1px); }
[data-theme="light"] .scholar-theme-toggle {
    background: rgba(255,255,255,.92);
    border-color: rgba(15,23,42,.12);
    color: #1e293b;
    box-shadow: 0 6px 18px rgba(15,23,42,.15);
}

/* ------------------------------------------------------------------
   Browser-history back/forward, as a floating pair -- bottom-left so it
   never competes with the theme toggle (top-right) or a shell's own
   mobile nav toggle (top-left on small screens). Same circular 40px
   treatment as the theme toggle above for one consistent "floating
   button" language across the app, instead of every page inventing its
   own back-link styling (a plain text link in one place, nothing at
   all in another). Real browser history, not a hardcoded "previous
   step" URL -- every step in a multi-page flow (assessment -> class ->
   roster, for example) is its own GET with its own history entry, so
   this always lands one real step back regardless of which page it's
   used from.
   ------------------------------------------------------------------ */
.scholar-nav-fab {
    position: fixed;
    left: 16px;
    bottom: 16px;
    z-index: 9998;
    display: flex;
    gap: 10px;
}
.scholar-nav-fab button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    box-sizing: border-box;
    height: 46px;
    padding: 0 18px;
    border-radius: 23px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(13,17,24,.92);
    color: #e2e8f0;
    font-size: 0.85rem;
    font-weight: 700;
    font-family: inherit;
    white-space: nowrap;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(0,0,0,.4);
    backdrop-filter: blur(6px);
    transition: background .2s ease, border-color .2s ease, transform .15s ease;
}
.scholar-nav-fab button svg { flex-shrink: 0; }
.scholar-nav-fab button:hover { transform: translateY(-1px); background: rgba(0,168,168,.22); border-color: rgba(0,168,168,.55); }
[data-theme="light"] .scholar-nav-fab button {
    background: rgba(255,255,255,.95);
    border-color: rgba(15,23,42,.15);
    color: #1e293b;
    box-shadow: 0 6px 18px rgba(15,23,42,.18);
}
[data-theme="light"] .scholar-nav-fab button:hover { background: rgba(0,168,168,.12); border-color: rgba(0,168,168,.4); }
@media (max-width: 480px) {
    .scholar-nav-fab button span.label { display: none; }
    .scholar-nav-fab button { width: 46px; padding: 0; justify-content: center; }
}
@media print {
    .scholar-nav-fab { display: none !important; }
}

:root[data-theme="light"] {
    --bg: #F1F5F9;
    --panel: #FFFFFF;
    --border: rgba(15, 23, 42, .10);
    --text: #1E293B;
    --muted: #64748B;
}

/* This button is fixed-position and floats on top of everything --
   including, with no rule to stop it, the printed/PDF'd report card,
   which is the one document in the app that regularly gets printed. */
@media print {
    .scholar-theme-toggle { display: none !important; }
}

/* ------------------------------------------------------------------
   ONE shared "Log Out" button design for the whole app.
   Before this, every shell/dashboard had its own bespoke logout
   markup -- five different visual treatments (plain sidebar text
   link, red-outline pill, uppercase chip, translucent white pill,
   etc.), and school_admin's own sidebar version was an unstyled text
   link with a ~19px tap target, well under the ~40px a comfortable
   tap target needs. Defined once here (same reasoning as the theme
   toggle above: no shared layout file every page goes through, but
   nearly every page already includes this file) so every shell/
   dashboard can just drop in the same class instead of reinventing
   it -- and never drift apart again.
   ------------------------------------------------------------------ */
.scholar-logout-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    box-sizing: border-box;
    min-height: 40px;
    padding: 9px 16px;
    background: rgba(239,68,68,0.12);
    border: 1px solid rgba(239,68,68,0.35);
    color: #ef4444;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.82rem;
    white-space: nowrap;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
}
.scholar-logout-btn:hover {
    background: rgba(239,68,68,0.22);
    border-color: rgba(239,68,68,0.55);
    transform: translateY(-1px);
}
.scholar-logout-btn svg { flex-shrink: 0; }
/* Icon-only variant for tight spaces (mobile topbars) -- same tap
   target size, no text, so it never forces an overflow/wrap on a
   narrow screen. */
.scholar-logout-btn.icon-only { padding: 9px; width: 40px; justify-content: center; }
[data-theme="light"] .scholar-logout-btn { background: rgba(239,68,68,0.08); }
</style>
<button type="button" class="scholar-theme-toggle" id="scholarThemeToggle" aria-label="Toggle dark mode">
    <span id="scholarThemeIcon">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
    </span>
</button>
<div class="scholar-nav-fab">
    <button type="button" onclick="history.back()" aria-label="Go back" title="Back">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        <span class="label">Back</span>
    </button>
    <button type="button" onclick="history.forward()" aria-label="Go forward" title="Forward">
        <span class="label">Next</span>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </button>
</div>
<script>
(function () {
    "use strict";

    var STORAGE_KEY = "scholar-theme";
    var root = document.documentElement;

    function applyTheme(theme) {
        // "dark" is left as an absent attribute rather than an explicit
        // value on purpose: most Scholar pages are dark by default with
        // no [data-theme] CSS at all, and a handful (e.g. nurse_dashboard.php)
        // are already light-native. Leaving dark == absent means a
        // first-time visitor with no saved preference always sees each
        // page's existing, already-shipped look untouched -- only the
        // explicit "light" opt-in changes anything.
        if (theme === "light") {
            root.setAttribute("data-theme", "light");
        } else {
            root.removeAttribute("data-theme");
        }
        var icon = document.getElementById("scholarThemeIcon");
        if (icon) {
            icon.innerHTML = theme === "light"
                ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1020.354 15.354z"/></svg>'
                : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
        }
    }

    var saved = localStorage.getItem(STORAGE_KEY) || "dark";
    applyTheme(saved);

    var toggle = document.getElementById("scholarThemeToggle");
    if (toggle) {
        toggle.addEventListener("click", function () {
            var next = root.getAttribute("data-theme") === "light" ? "dark" : "light";
            localStorage.setItem(STORAGE_KEY, next);
            applyTheme(next);
        });
    }
})();
</script>
