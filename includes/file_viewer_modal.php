<?php
// Global LMS Document & Attachment Viewer Modal Component
?>
<!-- PDF.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
</script>

<style>
    #lmsFileViewerModal .modal-content {
        border-radius: 16px;
        border: none;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        background: #f8fafc;
    }
    #lmsFileViewerModal .modal-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: white;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    .lms-viewer-toolbar {
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.5rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .lms-viewer-toolbar .btn-viewer-tool {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.82rem;
        padding: 0.35rem 0.65rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }
    .lms-viewer-toolbar .btn-viewer-tool:hover {
        background: #e2e8f0;
        color: #1e3a8a;
    }
    .lms-viewer-body {
        height: 75vh;
        max-height: 800px;
        position: relative;
        overflow: auto;
        background: #525659;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .lms-viewer-body.light-bg {
        background: #f8fafc;
    }
    #lmsPdfCanvasWrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 1.5rem;
        width: 100%;
        min-height: 100%;
    }
    #lmsPdfCanvas {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        border-radius: 4px;
        background: #ffffff;
        max-width: 100%;
    }
    .lms-page-indicator {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        padding: 0.2rem 0.6rem;
        background: #f1f5f9;
        border-radius: 6px;
    }
    .lms-search-box {
        max-width: 180px;
        font-size: 0.82rem;
    }
</style>

<!-- LMS File Viewer Modal -->
<div class="modal fade" id="lmsFileViewerModal" tabindex="-1" aria-labelledby="lmsFileViewerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <!-- Header -->
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2 text-truncate me-3">
                    <i id="lmsViewerFileIcon" class="fas fa-file-alt fa-lg text-warning"></i>
                    <h6 class="modal-title mb-0 fw-bold text-truncate" id="lmsViewerFileName">Document Viewer</h6>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <a id="lmsViewerDownloadBtn" href="#" download class="btn btn-sm btn-light text-primary fw-semibold rounded-pill px-3">
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <!-- PDF Toolbar (Visible for PDFs) -->
            <div id="lmsPdfToolbar" class="lms-viewer-toolbar" style="display: none;">
                <div class="d-flex align-items-center gap-1">
                    <button type="button" id="lmsPdfPrev" class="btn btn-viewer-tool" title="Previous Page">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="lms-page-indicator">
                        <span id="lmsPdfNum">1</span> / <span id="lmsPdfCount">1</span>
                    </span>
                    <button type="button" id="lmsPdfNext" class="btn btn-viewer-tool" title="Next Page">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <div class="vr mx-1"></div>

                <div class="d-flex align-items-center gap-1">
                    <button type="button" id="lmsPdfZoomOut" class="btn btn-viewer-tool" title="Zoom Out">
                        <i class="fas fa-search-minus"></i>
                    </button>
                    <span id="lmsPdfZoomVal" class="lms-page-indicator">100%</span>
                    <button type="button" id="lmsPdfZoomIn" class="btn btn-viewer-tool" title="Zoom In">
                        <i class="fas fa-search-plus"></i>
                    </button>
                    <button type="button" id="lmsPdfFitWidth" class="btn btn-viewer-tool" title="Fit to Screen">
                        <i class="fas fa-expand-arrows-alt"></i> Fit
                    </button>
                </div>

                <div class="vr mx-1 d-none d-md-block"></div>

                <div class="d-none d-md-flex align-items-center gap-1 me-auto">
                    <input type="text" id="lmsPdfSearchInput" class="form-control form-control-sm lms-search-box" placeholder="Find text...">
                    <button type="button" id="lmsPdfSearchBtn" class="btn btn-viewer-tool" title="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

                <button type="button" id="lmsPdfFullscreen" class="btn btn-viewer-tool ms-auto" title="Full Screen">
                    <i class="fas fa-expand"></i> Fullscreen
                </button>
            </div>

            <!-- Modal Body Container -->
            <div id="lmsViewerBody" class="lms-viewer-body">
                <!-- PDF Render Area -->
                <div id="lmsPdfArea" style="display: none; width: 100%; height: 100%; overflow: auto;">
                    <div id="lmsPdfCanvasWrapper">
                        <canvas id="lmsPdfCanvas"></canvas>
                    </div>
                </div>

                <!-- PDF Iframe Fallback -->
                <iframe id="lmsIframeArea" style="display: none; width: 100%; height: 100%; border: none;" src="about:blank"></iframe>

                <!-- Image Area -->
                <div id="lmsImageArea" style="display: none; padding: 1.5rem; text-align: center;">
                    <img id="lmsViewerImage" src="" alt="Preview" style="max-width: 100%; max-height: 68vh; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2);">
                </div>

                <!-- Text / Code Area -->
                <div id="lmsTextArea" style="display: none; width: 100%; height: 100%; padding: 1.5rem;">
                    <pre id="lmsViewerText" style="width: 100%; height: 100%; background: #ffffff; color: #1e293b; padding: 1.25rem; border-radius: 8px; font-family: monospace; font-size: 0.9rem; overflow: auto; border: 1px solid #cbd5e1;"></pre>
                </div>

                <!-- Office / Google Docs Iframe Area -->
                <div id="lmsOfficeArea" style="display: none; width: 100%; height: 100%;">
                    <iframe id="lmsOfficeIframe" style="width: 100%; height: 100%; border: none;" src="about:blank"></iframe>
                </div>

                <!-- Unsupported / General Area -->
                <div id="lmsUnsupportedArea" style="display: none; text-align: center; color: #334155; padding: 2rem;">
                    <div class="card p-4 border-0 shadow-sm mx-auto" style="max-width: 450px; border-radius: 14px;">
                        <i id="lmsUnsupIcon" class="fas fa-file-download fa-4x text-primary mb-3"></i>
                        <h5 class="fw-bold mb-2">Direct Preview Not Supported</h5>
                        <p class="text-muted small mb-4">This file format cannot be rendered directly inside the viewer. You can download the file to check or view it on your device.</p>
                        <a id="lmsUnsupDownloadBtn" href="#" download class="btn btn-primary fw-semibold rounded-pill py-2">
                            <i class="fas fa-download me-1"></i> Download File Now
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let pdfDoc = null;
    let pageNum = 1;
    let pageRendering = false;
    let pageNumPending = null;
    let scale = 1.2;
    let currentFileUrl = '';
    let currentPdfCanvas = null;
    let currentPdfCtx = null;

    document.addEventListener('DOMContentLoaded', function() {
        currentPdfCanvas = document.getElementById('lmsPdfCanvas');
        if (currentPdfCanvas) {
            currentPdfCtx = currentPdfCanvas.getContext('2d');
        }

        // PDF Navigation Handlers
        document.getElementById('lmsPdfPrev').addEventListener('click', function() {
            if (pageNum <= 1) return;
            pageNum--;
            queueRenderPage(pageNum);
        });

        document.getElementById('lmsPdfNext').addEventListener('click', function() {
            if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
            pageNum++;
            queueRenderPage(pageNum);
        });

        document.getElementById('lmsPdfZoomIn').addEventListener('click', function() {
            scale = Math.min(scale + 0.25, 3.0);
            updateZoomLabel();
            if (pdfDoc) renderPage(pageNum);
        });

        document.getElementById('lmsPdfZoomOut').addEventListener('click', function() {
            scale = Math.max(scale - 0.25, 0.5);
            updateZoomLabel();
            if (pdfDoc) renderPage(pageNum);
        });

        document.getElementById('lmsPdfFitWidth').addEventListener('click', function() {
            scale = 1.0;
            updateZoomLabel();
            if (pdfDoc) renderPage(pageNum);
        });

        document.getElementById('lmsPdfFullscreen').addEventListener('click', function() {
            const body = document.getElementById('lmsViewerBody');
            if (!document.fullscreenElement) {
                if (body.requestFullscreen) body.requestFullscreen();
                else if (body.webkitRequestFullscreen) body.webkitRequestFullscreen();
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
            }
        });
    });

    function updateZoomLabel() {
        document.getElementById('lmsPdfZoomVal').textContent = Math.round(scale * 100) + '%';
    }

    function renderPage(num) {
        pageRendering = true;
        pdfDoc.getPage(num).then(function(page) {
            const viewport = page.getViewport({ scale: scale });
            currentPdfCanvas.height = viewport.height;
            currentPdfCanvas.width = viewport.width;

            const renderContext = {
                canvasContext: currentPdfCtx,
                viewport: viewport
            };
            const renderTask = page.render(renderContext);

            renderTask.promise.then(function() {
                pageRendering = false;
                if (pageNumPending !== null) {
                    renderPage(pageNumPending);
                    pageNumPending = null;
                }
            });
        });

        document.getElementById('lmsPdfNum').textContent = num;
    }

    function queueRenderPage(num) {
        if (pageRendering) {
            pageNumPending = num;
        } else {
            renderPage(num);
        }
    }

    // Global LMS File Viewer trigger function
    window.openLmsFileViewer = function(fileUrl, fileName, ext) {
        if (!fileUrl) return false;

        // Extract extension if not provided
        if (!ext && fileName) {
            ext = fileName.split('.').pop().toLowerCase();
        } else if (!ext) {
            ext = fileUrl.split('.').pop().split('?')[0].toLowerCase();
        } else {
            ext = ext.toLowerCase();
        }

        fileName = fileName || fileUrl.split('/').pop().split('?')[0] || 'Document';

        // Convert path to serve_file.php proxy if relative path
        let proxyUrl = fileUrl;
        if (!fileUrl.startsWith('http://') && !fileUrl.startsWith('https://') && !fileUrl.startsWith('/tools/serve_file.php') && !fileUrl.includes('serve_file.php')) {
            // Remove leading slashes or ../
            let cleanPath = fileUrl.replace(/^(\.\.\/)+/, '').replace(/^\//, '');
            proxyUrl = '../tools/serve_file.php?file=' + encodeURIComponent(cleanPath);
        }

        // Set Header details
        document.getElementById('lmsViewerFileName').textContent = fileName;
        document.getElementById('lmsViewerDownloadBtn').href = proxyUrl + (proxyUrl.includes('?') ? '&download=1' : '?download=1');

        // Set Icon based on extension
        const iconEl = document.getElementById('lmsViewerFileIcon');
        iconEl.className = 'fas fa-file-alt fa-lg text-warning';
        if (['pdf'].includes(ext)) iconEl.className = 'fas fa-file-pdf fa-lg text-danger';
        else if (['doc', 'docx'].includes(ext)) iconEl.className = 'fas fa-file-word fa-lg text-primary';
        else if (['ppt', 'pptx'].includes(ext)) iconEl.className = 'fas fa-file-powerpoint fa-lg text-warning';
        else if (['xls', 'xlsx'].includes(ext)) iconEl.className = 'fas fa-file-excel fa-lg text-success';
        else if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(ext)) iconEl.className = 'fas fa-file-image fa-lg text-info';
        else if (['txt', 'csv'].includes(ext)) iconEl.className = 'fas fa-file-alt fa-lg text-secondary';
        else if (['mp4', 'webm', 'mp3', 'wav'].includes(ext)) iconEl.className = 'fas fa-file-video fa-lg text-danger';

        // Hide all areas first
        document.getElementById('lmsPdfToolbar').style.display = 'none';
        document.getElementById('lmsPdfArea').style.display = 'none';
        document.getElementById('lmsIframeArea').style.display = 'none';
        document.getElementById('lmsImageArea').style.display = 'none';
        document.getElementById('lmsTextArea').style.display = 'none';
        document.getElementById('lmsOfficeArea').style.display = 'none';
        document.getElementById('lmsUnsupportedArea').style.display = 'none';

        const viewerBody = document.getElementById('lmsViewerBody');
        viewerBody.classList.remove('light-bg');

        // Open Modal via Bootstrap 5
        const modalEl = document.getElementById('lmsFileViewerModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // 1. PDF Rendering
        if (ext === 'pdf') {
            document.getElementById('lmsPdfToolbar').style.display = 'flex';
            document.getElementById('lmsPdfArea').style.display = 'block';

            if (typeof pdfjsLib !== 'undefined') {
                pdfjsLib.getDocument(proxyUrl).promise.then(function(pdfDoc_) {
                    pdfDoc = pdfDoc_;
                    document.getElementById('lmsPdfCount').textContent = pdfDoc.numPages;
                    pageNum = 1;
                    scale = 1.2;
                    updateZoomLabel();
                    renderPage(pageNum);
                }).catch(function(err) {
                    console.warn('PDF.js failed, falling back to iframe:', err);
                    // Fallback to browser inline PDF iframe
                    document.getElementById('lmsPdfArea').style.display = 'none';
                    document.getElementById('lmsIframeArea').style.display = 'block';
                    document.getElementById('lmsIframeArea').src = proxyUrl;
                });
            } else {
                document.getElementById('lmsPdfArea').style.display = 'none';
                document.getElementById('lmsIframeArea').style.display = 'block';
                document.getElementById('lmsIframeArea').src = proxyUrl;
            }
        }
        // 2. Images
        else if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(ext)) {
            viewerBody.classList.add('light-bg');
            document.getElementById('lmsImageArea').style.display = 'block';
            document.getElementById('lmsViewerImage').src = proxyUrl;
        }
        // 3. Text / CSV
        else if (['txt', 'csv'].includes(ext)) {
            viewerBody.classList.add('light-bg');
            document.getElementById('lmsTextArea').style.display = 'block';
            fetch(proxyUrl)
                .then(res => res.text())
                .then(text => {
                    document.getElementById('lmsViewerText').textContent = text;
                })
                .catch(() => {
                    document.getElementById('lmsViewerText').textContent = 'Unable to load text content.';
                });
        }
        // 4. Office Docs (DOC, DOCX, PPT, PPTX, XLS, XLSX)
        else if (['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'].includes(ext)) {
            document.getElementById('lmsOfficeArea').style.display = 'block';
            // Google Docs Viewer URL (works if site is public, else iframe displays fallback message)
            const absoluteUrl = window.location.origin + '/' + proxyUrl.replace(/^\.\.\//, '');
            const gdriveUrl = 'https://docs.google.com/gview?url=' + encodeURIComponent(absoluteUrl) + '&embedded=true';
            document.getElementById('lmsOfficeIframe').src = gdriveUrl;
        }
        // 5. Unsupported formats
        else {
            viewerBody.classList.add('light-bg');
            document.getElementById('lmsUnsupportedArea').style.display = 'block';
            document.getElementById('lmsUnsupDownloadBtn').href = proxyUrl + (proxyUrl.includes('?') ? '&download=1' : '?download=1');
        }

        return false;
    };
})();
</script>
