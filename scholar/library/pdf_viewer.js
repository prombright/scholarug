/*
|--------------------------------------------------------------------------
| LIBRARY — PDF VIEWER (read-only)
|--------------------------------------------------------------------------
| Renders a PDF page-by-page onto stacked <canvas> elements (via pdf.js,
| loaded from a CDN by the including page) instead of a browser's native
| embedded PDF viewer -- a canvas has no selectable text layer and no
| built-in save/print/download UI, which is the whole point here. See
| serve_pdf.php's header comment for what this can and can't actually
| prevent.
|--------------------------------------------------------------------------
*/
function scholarRenderPdf(options) {
    var containerEl = options.containerEl;
    var pdfUrl = options.pdfUrl;

    if (typeof pdfjsLib === 'undefined') {
        containerEl.innerHTML = '<p style="color:#f87171;padding:20px;">The PDF viewer failed to load (check your internet connection, then refresh). If this keeps happening, contact your school admin.</p>';
        return;
    }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

    // Best-effort deterrent only -- see serve_pdf.php's header comment for
    // why nothing server-side/client-side can fully prevent a screenshot.
    containerEl.oncontextmenu = function () { return false; };

    pdfjsLib.getDocument(pdfUrl).promise.then(function (pdf) {
        function renderPage(pageNum) {
            return pdf.getPage(pageNum).then(function (page) {
                var viewport = page.getViewport({ scale: 1.3 });
                var pageWrap = document.createElement('div');
                pageWrap.className = 'scholar-pdf-page';
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
