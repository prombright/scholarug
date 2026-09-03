<?php /* Shared CSS for assets/js/rich-toolbar.js's formatting bar --
included (inside a <style> tag) by every page that has a .rt-editable
textarea, so the small toolbar looks the same everywhere instead of each
page inventing its own. */ ?>
.rt-toolbar { display: flex; gap: 4px; margin-bottom: 6px; }
.rt-toolbar-btn {
    background: var(--panel, #111826);
    border: 1px solid var(--border, #2a3a52);
    color: var(--text, #e2e8f0);
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 0.78rem;
    cursor: pointer;
}
.rt-toolbar-btn:hover { border-color: var(--cyan, #00A8A8); color: var(--cyan, #00A8A8); }