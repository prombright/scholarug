/*
|--------------------------------------------------------------------------
| iLEARNING — SHARED PDF VIEWER (pdf_notes + pdf_activity)
|--------------------------------------------------------------------------
| Renders a PDF page-by-page onto stacked <canvas> elements (via pdf.js,
| loaded from a CDN by the including page -- see view_topic.php) instead of
| a browser's native embedded PDF viewer, because the native viewer doesn't
| expose scroll/page position to JavaScript at all. An IntersectionObserver
| watches each rendered page and tracks the highest page number that's ever
| been at least 50% visible -- that's the real, honest "how far has this
| student actually gotten" signal, reported as percent = maxPageSeen /
| totalPages.
|
| That percent is sent to the EXACT SAME progress_ping.php endpoint written
| topics already use -- it never cared how percent was computed, only that
| it's a 0-100 value scoped to topic_id, so nothing there needed to change.
|--------------------------------------------------------------------------
*/
function scholarRenderPdf(options) {
    var containerEl = options.containerEl;
    var pdfUrl = options.pdfUrl;
    var topicId = options.topicId || null;
    var disableRightClick = !!options.disableRightClick;

    if (typeof pdfjsLib === 'undefined') {
        containerEl.innerHTML = '<p style="color:#f87171;padding:20px;">The PDF viewer failed to load (check your internet connection, then refresh). If this keeps happening, contact your school admin.</p>';
        return;
    }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

    if (disableRightClick) {
        // Best-effort deterrent only -- see serve_pdf.php's header comment
        // for why nothing server-side can fully prevent a screenshot.
        containerEl.oncontextmenu = function () { return false; };
    }

    var maxPageSeen = 0;
    var totalPages = 0;
    var pendingSeconds = 0;

    function computePercent() {
        if (totalPages <= 0) return 0;
        return Math.max(0, Math.min(100, Math.round((maxPageSeen / totalPages) * 100)));
    }

    function sendPing(useBeacon) {
        if (!topicId) return;
        var payload = JSON.stringify({ topic_id: topicId, percent: computePercent(), delta_seconds: pendingSeconds });
        pendingSeconds = 0;
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('progress_ping.php', new Blob([payload], { type: 'application/json' }));
        } else {
            fetch('progress_ping.php', { method: 'POST', body: payload, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } }).catch(function () {});
        }
    }

    if (topicId) {
        sendPing(false); // initial "opened" ping -- same convention as the written-topic viewer
        setInterval(function () {
            if (document.visibilityState === 'visible') {
                pendingSeconds += 20;
                sendPing(false);
            }
        }, 20000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') sendPing(true);
        });
        window.addEventListener('pagehide', function () { sendPing(true); });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                var pageNum = parseInt(entry.target.getAttribute('data-page-number'), 10);
                if (pageNum > maxPageSeen) maxPageSeen = pageNum;
            }
        });
    }, { threshold: 0.5 });

    pdfjsLib.getDocument(pdfUrl).promise.then(function (pdf) {
        totalPages = pdf.numPages;

        function renderPage(pageNum) {
            return pdf.getPage(pageNum).then(function (page) {
                var viewport = page.getViewport({ scale: 1.3 });
                var pageWrap = document.createElement('div');
                pageWrap.className = 'scholar-pdf-page';
                pageWrap.setAttribute('data-page-number', String(pageNum));
                pageWrap.style.margin = '0 auto 14px';
                pageWrap.style.width = viewport.width + 'px';
                pageWrap.style.maxWidth = '100%';

                var canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                canvas.style.display = 'block';
                canvas.style.width = '100%';
                canvas.style.height = 'auto';
                canvas.style.boxShadow = '0 2px 10px rgba(0,0,0,0.35)';
                canvas.style.borderRadius = '4px';
                pageWrap.appendChild(canvas);
                containerEl.appendChild(pageWrap);
                observer.observe(pageWrap);

                return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
            });
        }

        // Sequential, not parallel -- keeps memory/CPU bounded for longer
        // PDFs instead of rendering every page's canvas at once.
        var chain = Promise.resolve();
        for (var i = 1; i <= pdf.numPages; i++) {
            (function (n) { chain = chain.then(function () { return renderPage(n); }); })(i);
        }
        return chain;
    }).then(function () {
        if (typeof options.onRendered === 'function') options.onRendered();
    }).catch(function (err) {
        containerEl.innerHTML = '<p style="color:#f87171;padding:20px;">Could not load this PDF' + (err && err.message ? (': ' + err.message) : '') + '.</p>';
    });
}
