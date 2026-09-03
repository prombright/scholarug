<?php /* Shared report-card CSS -- included (inside a <style> tag) by both
generate_report.php (single student) and school_admin/bulk_report_print.php
(whole class). Previously hand-copy-pasted between the two, already
slightly drifted (bulk_report_print.php's copy had extra rules
generate_report.php's didn't) -- one shared file means the two can no
longer silently diverge. Each host page keeps its own page-chrome CSS
(toolbar/print button/etc) and its own @media print block; only the
report-content rules that must look identical either way live here. */ ?>
.report-card-wrapper { max-width: 850px; border: 4px double #0f172a; padding: 35px; border-radius: 4px; box-sizing: border-box; position: relative; overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }

/* Watermark -- the school's own uploaded logo, faint and centered behind
   every section (header through signatures). Only rendered when a logo is
   on file (render_report_card_html() guards this), so a school with no
   logo yet gets a plain white card instead of an empty broken image. */
.rc-watermark { position: absolute; top: 50%; left: 50%; width: 62%; transform: translate(-50%, -50%); background-repeat: no-repeat; background-position: center; background-size: contain; opacity: 0.06; z-index: 0; pointer-events: none; aspect-ratio: 1 / 1; }

/* Everything else needs to sit above the watermark layer. */
.rc-header, .rc-title-bar, .bio-infomatrix, .matrix-table, .summary-box, .grade-legend, .rc-signatures, .rc-footer, .rc-photo-corner { position: relative; z-index: 1; }

/* Header -- logo on the left, school identity block centered in the
   remaining space. A matching invisible spacer on the right (same width as
   the logo) keeps the centered text block truly centered on the page
   rather than just centered within the space right of the logo. */
.rc-header { display: flex; align-items: center; gap: 18px; margin-bottom: 20px; }
.rc-header-spacer { width: 84px; flex-shrink: 0; }
.rc-logo-ring { width: 84px; height: 84px; border-radius: 50%; object-fit: cover; border: 3px solid #0f172a; padding: 3px; display: block; background: #fff; flex-shrink: 0; }
.rc-logo-fallback { display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 800; color: #fff; background: #0f172a; }
.rc-header-text { flex: 1; text-align: center; }
.school-title { font-size: 24px; font-weight: 900; color: #0f172a; text-transform: uppercase; margin: 0; letter-spacing: 0.5px; }
.school-meta { font-size: 11px; color: #475569; margin: 3px 0; font-family: monospace; }
.school-address { color: #0284c7; font-weight: bold; font-family: inherit; }
.rc-photo-corner { position: absolute; top: 30px; right: 30px; width: 74px; height: 74px; object-fit: cover; border-radius: 6px; border: 2px solid #cbd5e1; }

/* Title bar -- "Term X Report Card" plus an O-Level/A-Level badge, the
   dividing line between the letterhead and the actual academic record. */
.rc-title-bar { display: flex; align-items: center; justify-content: center; gap: 10px; text-transform: uppercase; font-weight: 800; font-size: 13px; letter-spacing: 1px; color: #0f172a; border-top: 2px solid #0f172a; border-bottom: 2px solid #0f172a; padding: 8px 0; margin-bottom: 22px; }
.rc-level-badge { background: #0f172a; color: #fff; padding: 2px 10px; border-radius: 20px; font-size: 10.5px; letter-spacing: 1px; }

/* Infobox Elements */
.bio-infomatrix { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 18px; border-radius: 6px; margin-bottom: 26px; }
.bio-item { font-size: 13px; color: #334155; margin-bottom: 4px; }
.bio-label { font-weight: bold; color: #64748b; text-transform: uppercase; font-size: 11px; display: inline-block; width: 110px; }

/* Tabulation Matrix Styles */
.matrix-table { width: 100%; border-collapse: collapse; text-align: left; margin-bottom: 30px; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
.matrix-table th { background: #0f172a; color: #fff; text-transform: uppercase; font-size: 10.5px; padding: 10px 8px; font-weight: 700; border: 1px solid #0f172a; letter-spacing: 0.3px; text-align: center; }
.matrix-table th:first-child { text-align: left; }
.matrix-table td { padding: 9px 8px; border: 1px solid #cbd5e1; font-size: 12px; text-align: center; }
.matrix-table td.rc-subject-cell { text-align: left; }
.rc-subject-name { font-weight: 700; color: #0f172a; }
.rc-score-cell { font-weight: bold; font-family: monospace; }
.rc-final-cell { font-weight: 900; font-family: monospace; font-size: 13px; }
.rc-grade-cell { min-width: 50px; }
.rc-grade-letter { font-weight: 900; color: #0f172a; font-size: 14px; }
.rc-tr-cell { font-weight: bold; font-family: monospace; color: #6d28d9; }
.assessment-breakdown { font-size: 10px; color: #64748b; font-style: italic; margin-top: 3px; }

/* Summary Blocks */
.summary-box { border: 1px solid #0f172a; padding: 18px; border-radius: 6px; margin-top: 25px; background: #fafafa; }

/* Grade legend -- printed grading key, same idea as the band table every
   school already keeps on its wall, so a parent reading a printed report
   doesn't need to ask what "C" means. Only rendered when the school has
   grading_scales configured (always true in practice, but guarded). */
.grade-legend { margin-top: 22px; }
.grade-legend-title { font-weight: bold; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.grade-legend table { width: 100%; border-collapse: collapse; font-size: 11px; }
.grade-legend th { background: #f1f5f9; color: #475569; text-transform: uppercase; font-size: 9.5px; padding: 6px 8px; text-align: left; border: 1px solid #e2e8f0; }
.grade-legend td { padding: 5px 8px; border: 1px solid #e2e8f0; color: #334155; }
.grade-legend-letter { font-weight: 900; text-align: center; width: 40px; color: #0f172a; }

.rc-footer { text-align: center; font-size: 9.5px; color: #94a3b8; margin-top: 20px; letter-spacing: 0.3px; }

/* Human-written remarks -- distinct from the auto-computed narrative
   above them, so a reader can tell an actual teacher's words apart from
   the system's own summary sentence. */
.rc-remark-row { margin-bottom: 10px; }
.rc-remark-row:last-child { margin-bottom: 0; }
.rc-remark-label { font-weight: bold; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 3px; }
.rc-remark-text { font-size: 12.5px; color: #1e293b; font-style: italic; line-height: 1.5; }
.rc-remark-empty { color: #94a3b8; }

/* Verification QR -- rendered client-side by assets/js/qrcode.js into
   this target div (see the init script each host page includes once).
   Sized to stay legible at typical phone-camera scan distance even at
   this card's print size. */
.rc-qr-target { display: inline-block; line-height: 0; }
.rc-qr-target svg { width: 78px; height: 78px; }
.rc-qr-caption { font-size: 9px; color: #64748b; margin-top: 4px; line-height: 1.3; }

/* Phones: the bio grid drops to one column, the photo corner returns to
   the document flow instead of floating over the centered header, and the
   matrix scrolls horizontally at a smaller font rather than being forced
   to stack -- with one column per assessment now, stacking would sprawl
   across many screen-heights instead of just scrolling sideways. */
@media (max-width: 600px) {
    .report-card-wrapper { padding: 14px; border-width: 2px; }
    .rc-photo-corner { position: static; margin: 0 auto 10px; display: block; }
    .rc-header { flex-direction: column; text-align: center; }
    .rc-header-spacer { display: none; }
    .rc-header-text { text-align: center; }
    .bio-infomatrix { grid-template-columns: 1fr; }
    .bio-label { display: block; width: auto; }
    .matrix-table { display: block; overflow-x: auto; white-space: nowrap; }
    .matrix-table th, .matrix-table td { font-size: 11px; padding: 8px 6px; }
    .summary-box table { display: block; }
    .summary-box table td { display: block; width: 100% !important; border-left: none !important; padding-left: 0 !important; }
}

/* Print fit -- a subject-heavy student (A-Level with a full combination,
   or any O-Level student with the full compulsory + elective load) was
   overflowing one printed page: default browser @page margins alone eat
   ~19mm top+bottom, and the on-screen sizing (picked for readability on a
   monitor) is taller than one A4 sheet can hold once every section is
   present -- header, bio grid, a matrix row per subject, summary,
   grade legend, remarks, QR, footer. This block only tightens spacing/
   type size for print output; the on-screen preview is untouched. Lives
   here (not each host's own @media print) because it must produce the
   IDENTICAL page for both generate_report.php and bulk_report_print.php
   -- same reason the rest of this file is shared. */
@media print {
    /* Both host pages already zero .report-card-wrapper's border/padding/
       margin for print in their own @media print blocks (later in the
       cascade, so they win) -- not repeated here. */
    @page { size: A4; margin: 8mm; }
    .rc-header { margin-bottom: 12px; }
    .rc-header-spacer { width: 64px; }
    .rc-logo-ring { width: 64px; height: 64px; }
    .rc-logo-fallback { font-size: 24px; }
    .school-title { font-size: 19px; }
    .school-meta { font-size: 9.5px; margin: 2px 0; }
    .rc-photo-corner { top: 18px; right: 18px; width: 58px; height: 58px; }
    .rc-title-bar { font-size: 11px; padding: 5px 0; margin-bottom: 14px; }
    .bio-infomatrix { padding: 12px; gap: 10px 20px; margin-bottom: 16px; }
    .bio-item { font-size: 11px; margin-bottom: 2px; }
    .matrix-table { margin-bottom: 16px; }
    .matrix-table th { font-size: 9px; padding: 6px 6px; }
    .matrix-table td { font-size: 10.5px; padding: 5px 6px; }
    .assessment-breakdown { font-size: 8.5px; margin-top: 1px; }
    .summary-box { padding: 12px; margin-top: 14px; }
    .grade-legend { margin-top: 12px; }
    .grade-legend-title { font-size: 9.5px; margin-bottom: 5px; }
    .grade-legend th { font-size: 8.5px; padding: 4px 6px; }
    .grade-legend td { padding: 3px 6px; font-size: 9.5px; }
    .rc-remark-row { margin-bottom: 6px; }
    .rc-remark-label { font-size: 9.5px; margin-bottom: 2px; }
    .rc-remark-text { font-size: 11px; line-height: 1.35; }
    .rc-qr-target svg { width: 58px; height: 58px; }
    .rc-qr-caption { font-size: 7.5px; }
    .rc-footer { font-size: 8px; margin-top: 12px; }
}
