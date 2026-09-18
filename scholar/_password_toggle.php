<?php
/*
|--------------------------------------------------------------------------
| SCHOLAR — SHARED "SHOW PASSWORD" EYE TOGGLE
|--------------------------------------------------------------------------
| Every password field in the app (login, password reset, invite
| activation, the WhatsApp access-token field) gets the same eye-icon
| toggle, so this lives in one place instead of the SVG markup and JS
| being copy-pasted into every standalone auth page. Inline SVG rather
| than an icon font/CDN -- most of these pages (login.php,
| force_password_reset.php, forgot_password.php, teacher_verify.php,
| developer/*.php) don't already load one, and adding a font just for
| this would be a bigger dependency than the icon itself.
|
| Usage: wrap the <input type="password"> in a `.pw-wrap` div, put
| SCHOLAR_EYE_SVG right after it inside a
| `<button type="button" class="pw-toggle-btn" onclick="scholarTogglePassword('<id>', this)">`,
| require_once this file once per page, and echo SCHOLAR_PASSWORD_TOGGLE_CSS
| inside the page's own <style> block and SCHOLAR_PASSWORD_TOGGLE_JS
| inside a <script> block (or right before </body>).
|--------------------------------------------------------------------------
*/

// Feather icons "eye" / "eye-off" -- swapped by scholarTogglePassword() below.
define('SCHOLAR_EYE_SVG', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>');
define('SCHOLAR_EYE_OFF_SVG', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.77 21.77 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.77 21.77 0 0 1-3.22 4.53M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>');

define('SCHOLAR_PASSWORD_TOGGLE_CSS', '
.pw-wrap { position: relative; }
.pw-wrap input[type="password"], .pw-wrap input[type="text"] { padding-right: 42px !important; box-sizing: border-box; }
.pw-toggle-btn { position: absolute; top: 50%; right: 6px; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 6px; display: flex; color: #64748b; }
.pw-toggle-btn:hover { color: #00A8A8; }
.pw-toggle-btn svg { width: 18px; height: 18px; pointer-events: none; }
');

define('SCHOLAR_PASSWORD_TOGGLE_JS', '
function scholarTogglePassword(fieldId, btn) {
    var input = document.getElementById(fieldId);
    if (!input) return;
    var showing = input.type === "text";
    input.type = showing ? "password" : "text";
    btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    btn.innerHTML = showing ? ' . json_encode(SCHOLAR_EYE_SVG) . ' : ' . json_encode(SCHOLAR_EYE_OFF_SVG) . ';
}
');
