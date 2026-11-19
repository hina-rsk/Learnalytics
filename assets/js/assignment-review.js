(function () {
    'use strict';

    const root = document.getElementById('assignmentReview');
    if (!root) return;

    const initial = window.assignmentReviewInitial || {};
    const readOnly = !initial.editable;
    const extension = (initial.extension || '').toLowerCase();
    const stage = document.getElementById('reviewStage');
    const viewport = document.getElementById('reviewViewport');
    const annotationCanvas = document.getElementById('reviewAnnotationCanvas');
    const annotationContext = annotationCanvas ? annotationCanvas.getContext('2d') : null;
    const baseCanvas = document.getElementById('reviewDocumentCanvas');
    const image = document.getElementById('reviewImage');
    const office = document.getElementById('reviewOffice');
    const errorBox = document.getElementById('reviewLoadError');
    const pageNumber = document.getElementById('reviewPageNumber');
    const pageCount = document.getElementById('reviewPageCount');
    const zoomLabel = document.getElementById('reviewZoomLabel');
    const commentList = document.getElementById('reviewCommentList');

    let annotations = Array.isArray(initial.annotations) ? initial.annotations : [];
    let currentPage = 1;
    let totalPages = 1;
    let zoom = 1;
    let activeTool = 'pen';
    let activeColor = '#e11d48';
    let drawing = false;
    let currentStroke = null;
    let shapeStart = null;
    let pdfDocument = null;
    let officePages = [];
    let history = [];
    let redoHistory = [];
    let saveTimer = null;
    let saveInProgress = false;
    let saveQueued = false;

    if (readOnly) stage.classList.add('is-read-only');
    pageCount.textContent = '1';

    function setError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    function setStageSize(width, height) {
        const safeWidth = Math.max(1, Math.round(width));
        const safeHeight = Math.max(1, Math.round(height));
        stage.style.width = safeWidth + 'px';
        stage.style.height = safeHeight + 'px';
        annotationCanvas.style.width = safeWidth + 'px';
        annotationCanvas.style.height = safeHeight + 'px';
        const ratio = window.devicePixelRatio || 1;
        annotationCanvas.width = Math.round(safeWidth * ratio);
        annotationCanvas.height = Math.round(safeHeight * ratio);
        annotationContext.setTransform(ratio, 0, 0, ratio, 0, 0);
        drawAnnotations();
    }

    function fitCanvas(width, height) {
        baseCanvas.width = Math.max(1, Math.round(width));
        baseCanvas.height = Math.max(1, Math.round(height));
        baseCanvas.style.width = baseCanvas.width + 'px';
        baseCanvas.style.height = baseCanvas.height + 'px';
        setStageSize(baseCanvas.width, baseCanvas.height);
    }

    async function renderPdf() {
        if (!window.pdfjsLib) throw new Error('The PDF viewer library did not load.');
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        pdfDocument = await window.pdfjsLib.getDocument(root.dataset.fileUrl).promise;
        totalPages = pdfDocument.numPages;
        pageCount.textContent = String(totalPages);
        await renderPdfPage();
    }

    async function renderPdfPage() {
        const page = await pdfDocument.getPage(currentPage);
        const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
        const availableWidth = Math.max(260, viewport.clientWidth - 40);
        const initialViewport = page.getViewport({ scale: 1 });
        const fitScale = Math.min(1, availableWidth / initialViewport.width);
        const pageViewport = page.getViewport({ scale: fitScale * zoom * pixelRatio });
        const cssWidth = pageViewport.width / pixelRatio;
        const cssHeight = pageViewport.height / pixelRatio;
        baseCanvas.width = Math.round(pageViewport.width);
        baseCanvas.height = Math.round(pageViewport.height);
        baseCanvas.style.width = cssWidth + 'px';
        baseCanvas.style.height = cssHeight + 'px';
        stage.style.width = cssWidth + 'px';
        stage.style.height = cssHeight + 'px';
        annotationCanvas.style.width = cssWidth + 'px';
        annotationCanvas.style.height = cssHeight + 'px';
        const ratio = window.devicePixelRatio || 1;
        annotationCanvas.width = Math.round(cssWidth * ratio);
        annotationCanvas.height = Math.round(cssHeight * ratio);
        annotationContext.setTransform(ratio, 0, 0, ratio, 0, 0);
        await page.render({ canvasContext: baseCanvas.getContext('2d'), viewport: pageViewport }).promise;
        drawAnnotations();
    }

    function renderImage() {
        image.hidden = false;
        image.src = root.dataset.fileUrl;
        image.onload = function () {
            const availableWidth = Math.max(260, viewport.clientWidth - 40);
            const fitScale = Math.min(1, availableWidth / image.naturalWidth);
            const width = Math.max(1, image.naturalWidth * fitScale * zoom);
            const height = Math.max(1, image.naturalHeight * fitScale * zoom);
            image.style.width = width + 'px';
            image.style.maxWidth = 'none';
            image.style.height = height + 'px';
            setStageSize(width, height);
        };
        image.onerror = function () { setError('The submitted image could not be opened.'); };
    }

    async function renderDocx() {
        if (!window.docx || typeof window.docx.renderAsync !== 'function') {
            throw new Error('The DOCX viewer library did not load.');
        }
        const response = await fetch(root.dataset.fileUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('The document could not be loaded.');
        const buffer = await response.arrayBuffer();
        office.hidden = false;
        await window.docx.renderAsync(buffer, office, null, { breakPages: true, ignoreWidth: false, ignoreHeight: false });
        officePages = Array.from(office.querySelectorAll('.docx-wrapper > section, .docx-wrapper section.docx'));
        if (!officePages.length) officePages = [office.querySelector('.docx-wrapper') || office];
        totalPages = officePages.length;
        pageCount.textContent = String(totalPages);
        await renderOfficePage();
    }

    function renderOfficePage() {
        if (officePages[0] && officePages[0].classList.contains('review-ppt-slide')) {
            const ratio = Number(officePages[0].dataset.slideRatio) || 16 / 9;
            const width = Math.min(1100, Math.max(320, viewport.clientWidth - 40)) * zoom;
            officePages.forEach(function (page) {
                page.style.width = width + 'px';
                page.style.height = (width / ratio) + 'px';
            });
        } else {
            officePages.forEach(function (page) {
                page.style.transform = 'scale(' + zoom + ')';
                page.style.transformOrigin = 'top left';
            });
        }
        officePages.forEach((page, index) => { page.style.display = index + 1 === currentPage ? 'block' : 'none'; });
        requestAnimationFrame(function () {
            const selectedPage = officePages[currentPage - 1];
            const width = Math.max(320, selectedPage.scrollWidth || selectedPage.getBoundingClientRect().width || 816);
            const height = Math.max(420, selectedPage.scrollHeight || selectedPage.getBoundingClientRect().height || 1056);
            setStageSize(width * zoom, height * zoom);
        });
    }

    function xmlText(element) {
        return Array.from(element.getElementsByTagNameNS('*', 't')).map(function (node) { return node.textContent || ''; }).join('');
    }

    function resolveZipPath(basePath, target) {
        const parts = target.startsWith('/') ? [] : basePath.split('/').slice(0, -1);
        target.replace(/^\//, '').split('/').forEach(function (part) {
            if (part === '..') parts.pop();
            else if (part && part !== '.') parts.push(part);
        });
        return parts.join('/');
    }

    function relationshipMap(xmlDocument) {
        const result = {};
        xmlDocument.getElementsByTagNameNS('*', 'Relationship').forEach(function (relationship) {
            result[relationship.getAttribute('Id')] = relationship.getAttribute('Target');
        });
        return result;
    }

    async function renderPptx() {
        if (!window.JSZip) throw new Error('The presentation viewer library did not load.');
        const response = await fetch(root.dataset.fileUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('The presentation could not be loaded.');
        const zip = await window.JSZip.loadAsync(await response.arrayBuffer());
        const parser = new DOMParser();
        const presentationFile = zip.file('ppt/presentation.xml');
        const relationshipsFile = zip.file('ppt/_rels/presentation.xml.rels');
        if (!presentationFile || !relationshipsFile) throw new Error('This presentation file is invalid.');
        const presentation = parser.parseFromString(await presentationFile.async('text'), 'application/xml');
        const relationships = parser.parseFromString(await relationshipsFile.async('text'), 'application/xml');
        const targets = relationshipMap(relationships);
        const slideSize = presentation.getElementsByTagNameNS('*', 'sldSz')[0];
        const slideWidth = Number(slideSize && slideSize.getAttribute('cx')) || 9144000;
        const slideHeight = Number(slideSize && slideSize.getAttribute('cy')) || 5143500;
        const slideRatio = slideWidth / slideHeight;
        const slideRefs = Array.from(presentation.getElementsByTagNameNS('*', 'sldId'));
        const slides = [];
        let slideIndex = 0;
        for (const slideRef of slideRefs) {
            const target = targets[slideRef.getAttribute('r:id') || slideRef.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')];
            if (!target) continue;
            const path = resolveZipPath('ppt/presentation.xml', target);
            const slideFile = zip.file(path);
            if (!slideFile) continue;
            slideIndex++;
            const slide = parser.parseFromString(await slideFile.async('text'), 'application/xml');
            const slideRelsFile = zip.file(resolveZipPath(path, '_rels/' + path.split('/').pop().replace(/\.xml$/, '.xml.rels')));
            const slideRels = slideRelsFile ? relationshipMap(parser.parseFromString(await slideRelsFile.async('text'), 'application/xml')) : {};
            const page = document.createElement('article');
            page.className = 'review-ppt-slide';
            page.dataset.page = String(slideIndex);
            page.dataset.slideRatio = String(slideRatio);

            const shapes = Array.from(slide.getElementsByTagNameNS('*', 'sp'));
            shapes.forEach(function (shape) {
                const shapeProperties = shape.getElementsByTagNameNS('*', 'spPr')[0];
                const transform = shapeProperties && shapeProperties.getElementsByTagNameNS('*', 'xfrm')[0];
                const offset = transform && transform.getElementsByTagNameNS('*', 'off')[0];
                const extent = transform && transform.getElementsByTagNameNS('*', 'ext')[0];
                if (!offset || !extent) return;
                const box = document.createElement('div');
                box.className = 'review-ppt-textbox';
                box.style.left = (Number(offset.getAttribute('x')) / slideWidth * 100) + '%';
                box.style.top = (Number(offset.getAttribute('y')) / slideHeight * 100) + '%';
                box.style.width = (Number(extent.getAttribute('cx')) / slideWidth * 100) + '%';
                box.style.height = (Number(extent.getAttribute('cy')) / slideHeight * 100) + '%';
                const textBody = shape.getElementsByTagNameNS('*', 'txBody')[0];
                const paragraphs = textBody ? Array.from(textBody.getElementsByTagNameNS('*', 'p')) : [];
                paragraphs.forEach(function (paragraph) {
                    const text = xmlText(paragraph);
                    if (!text) return;
                    const line = document.createElement('p');
                    line.textContent = text;
                    box.appendChild(line);
                });
                if (box.childNodes.length) page.appendChild(box);
            });

            const pictures = Array.from(slide.getElementsByTagNameNS('*', 'pic'));
            for (const picture of pictures) {
                const blip = picture.getElementsByTagNameNS('*', 'blip')[0];
                const embedId = blip && (blip.getAttribute('r:embed') || blip.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed'));
                const imageTarget = embedId && slideRels[embedId];
                const imageFile = imageTarget && zip.file(resolveZipPath(path, imageTarget));
                const properties = picture.getElementsByTagNameNS('*', 'spPr')[0];
                const transform = properties && properties.getElementsByTagNameNS('*', 'xfrm')[0];
                const offset = transform && transform.getElementsByTagNameNS('*', 'off')[0];
                const extent = transform && transform.getElementsByTagNameNS('*', 'ext')[0];
                if (!imageFile || !offset || !extent) continue;
                const imageExtension = imageTarget.split('.').pop().toLowerCase();
                const imageMime = imageExtension === 'jpg' || imageExtension === 'jpeg' ? 'image/jpeg' : 'image/' + imageExtension;
                const imageUrl = URL.createObjectURL(new Blob([await imageFile.async('uint8array')], { type: imageMime }));
                const pictureNode = document.createElement('img');
                pictureNode.src = imageUrl;
                pictureNode.alt = 'Slide image';
                pictureNode.style.position = 'absolute';
                pictureNode.style.left = (Number(offset.getAttribute('x')) / slideWidth * 100) + '%';
                pictureNode.style.top = (Number(offset.getAttribute('y')) / slideHeight * 100) + '%';
                pictureNode.style.width = (Number(extent.getAttribute('cx')) / slideWidth * 100) + '%';
                pictureNode.style.height = (Number(extent.getAttribute('cy')) / slideHeight * 100) + '%';
                pictureNode.style.objectFit = 'contain';
                page.appendChild(pictureNode);
            }
            slides.push(page);
        }
        if (!slides.length) throw new Error('No readable slides were found in this presentation.');
        office.hidden = false;
        office.replaceChildren();
        officePages = slides.map(function (page) {
            office.appendChild(page);
            return page;
        });
        totalPages = officePages.length;
        pageCount.textContent = String(totalPages);
        await renderOfficePage();
    }

    function pageAnnotations() {
        return annotations.filter(function (annotation) { return Number(annotation.page) === currentPage; });
    }

    function drawAnnotations() {
        if (!annotationContext || !annotationCanvas.width || !annotationCanvas.height) return;
        const ratio = window.devicePixelRatio || 1;
        const width = annotationCanvas.width / ratio;
        const height = annotationCanvas.height / ratio;
        annotationContext.clearRect(0, 0, width, height);
        pageAnnotations().forEach(function (annotation) { drawAnnotation(annotation, width, height); });
        renderCommentList();
    }

    function drawAnnotation(annotation, width, height) {
        const data = annotation.data || {};
        const color = data.color || '#e11d48';
        const x = (value) => Number(value || 0) * width;
        const y = (value) => Number(value || 0) * height;
        annotationContext.save();
        annotationContext.strokeStyle = color;
        annotationContext.fillStyle = color;
        annotationContext.lineWidth = annotation.type === 'highlighter' ? 16 : 3;
        annotationContext.lineCap = 'round';
        annotationContext.lineJoin = 'round';

        if (annotation.type === 'pen' || annotation.type === 'highlighter') {
            const points = data.points || [];
            if (points.length) {
                annotationContext.globalAlpha = annotation.type === 'highlighter' ? .28 : 1;
                annotationContext.beginPath();
                annotationContext.moveTo(x(points[0].x), y(points[0].y));
                points.slice(1).forEach(function (point) { annotationContext.lineTo(x(point.x), y(point.y)); });
                if (points.length === 1) annotationContext.lineTo(x(points[0].x) + .1, y(points[0].y) + .1);
                annotationContext.stroke();
            }
        } else if (['line', 'arrow', 'rectangle', 'circle'].includes(annotation.type)) {
            const left = x(data.x), top = y(data.y), right = x(data.x2), bottom = y(data.y2);
            annotationContext.beginPath();
            if (annotation.type === 'rectangle') {
                annotationContext.strokeRect(left, top, right - left, bottom - top);
            } else if (annotation.type === 'circle') {
                const centerX = (left + right) / 2, centerY = (top + bottom) / 2;
                annotationContext.ellipse(centerX, centerY, Math.abs(right - left) / 2, Math.abs(bottom - top) / 2, 0, 0, Math.PI * 2);
                annotationContext.stroke();
            } else {
                annotationContext.moveTo(left, top);
                annotationContext.lineTo(right, bottom);
                annotationContext.stroke();
                if (annotation.type === 'arrow') {
                    const angle = Math.atan2(bottom - top, right - left);
                    annotationContext.beginPath();
                    annotationContext.moveTo(right, bottom);
                    annotationContext.lineTo(right - 12 * Math.cos(angle - Math.PI / 6), bottom - 12 * Math.sin(angle - Math.PI / 6));
                    annotationContext.moveTo(right, bottom);
                    annotationContext.lineTo(right - 12 * Math.cos(angle + Math.PI / 6), bottom - 12 * Math.sin(angle + Math.PI / 6));
                    annotationContext.stroke();
                }
            }
        } else if (annotation.type === 'comment') {
            annotationContext.beginPath();
            annotationContext.arc(x(data.x), y(data.y), 12, 0, Math.PI * 2);
            annotationContext.fill();
            annotationContext.fillStyle = '#fff';
            annotationContext.font = 'bold 13px sans-serif';
            annotationContext.textAlign = 'center';
            annotationContext.textBaseline = 'middle';
            annotationContext.fillText('C', x(data.x), y(data.y));
        }
        annotationContext.restore();
    }

    function renderCommentList() {
        if (!commentList) return;
        commentList.replaceChildren();
        const comments = annotations.filter(function (annotation) { return annotation.type === 'comment'; });
        if (!comments.length) {
            const empty = document.createElement('div');
            empty.className = 'text-muted small';
            empty.textContent = 'No document comments.';
            commentList.appendChild(empty);
            return;
        }
        comments.forEach(function (comment) {
            const item = document.createElement('div');
            item.className = 'review-comment-item';
            item.style.setProperty('--comment-color', (comment.data && comment.data.color) || '#2563eb');
            const label = document.createElement('strong');
            const date = comment.created_at ? new Date(comment.created_at.replace(' ', 'T')).toLocaleString() : '';
            label.textContent = 'Page ' + comment.page + ' · ' + (comment.author || 'Instructor') + (date ? ' · ' + date : '');
            const text = document.createElement('span');
            text.textContent = comment.content || '';
            item.append(label, text);
            commentList.appendChild(item);
        });
    }

    function pointerPosition(event) {
        const rect = annotationCanvas.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)),
            y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height))
        };
    }

    function checkpoint() {
        history.push(JSON.stringify(annotations));
        if (history.length > 100) history.shift();
        redoHistory = [];
    }

    function addAnnotation(annotation) {
        checkpoint();
        annotations.push(annotation);
        drawAnnotations();
        scheduleSave();
    }

    function eraseAt(position) {
        let closestIndex = -1;
        let closestDistance = .045;
        pageAnnotations().forEach(function (annotation) {
            const data = annotation.data || {};
            const candidates = data.points || [{ x: data.x, y: data.y }, { x: data.x2, y: data.y2 }];
            candidates.forEach(function (point) {
                if (point.x === undefined || point.y === undefined) return;
                const distance = Math.hypot(Number(point.x) - position.x, Number(point.y) - position.y);
                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestIndex = annotations.indexOf(annotation);
                }
            });
        });
        if (closestIndex >= 0) {
            checkpoint();
            annotations.splice(closestIndex, 1);
            drawAnnotations();
            scheduleSave();
        }
    }

    function toolType() {
        if (activeTool === 'shape') return document.getElementById('reviewShape').value;
        return activeTool;
    }

    function pointDown(event) {
        if (readOnly) return;
        event.preventDefault();
        const point = pointerPosition(event);
        const type = toolType();
        if (type === 'eraser') {
            eraseAt(point);
            return;
        }
        if (type === 'comment') {
            const content = window.prompt('Write a comment for this location:');
            if (content && content.trim()) {
                addAnnotation({ page: currentPage, type: 'comment', content: content.trim(), data: { ...point, color: activeColor } });
            }
            return;
        }
        checkpoint();
        drawing = true;
        annotationCanvas.setPointerCapture(event.pointerId);
        if (['pen', 'highlighter'].includes(type)) {
            currentStroke = { page: currentPage, type: type, content: '', data: { points: [point], color: activeColor } };
            annotations.push(currentStroke);
        } else {
            shapeStart = point;
            currentStroke = { page: currentPage, type: type, content: '', data: { x: point.x, y: point.y, x2: point.x, y2: point.y, color: activeColor } };
            annotations.push(currentStroke);
        }
        drawAnnotations();
    }

    function pointMove(event) {
        if (!drawing || !currentStroke) return;
        const point = pointerPosition(event);
        if (['pen', 'highlighter'].includes(currentStroke.type)) {
            currentStroke.data.points.push(point);
        } else if (shapeStart) {
            currentStroke.data.x2 = point.x;
            currentStroke.data.y2 = point.y;
        }
        drawAnnotations();
    }

    function pointUp() {
        if (!drawing) return;
        drawing = false;
        currentStroke = null;
        shapeStart = null;
        scheduleSave();
    }

    function setTool(tool) {
        activeTool = tool;
        document.querySelectorAll('[data-tool]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.tool === tool);
        });
    }

    function setStatus(message, state) {
        const element = document.getElementById('reviewSaveState');
        if (!element) return;
        element.textContent = message;
        element.className = 'review-save-state mb-2' + (state ? ' is-' + state : '');
    }

    async function saveReview() {
        if (readOnly || saveInProgress) {
            saveQueued = !readOnly;
            return;
        }
        saveInProgress = true;
        saveQueued = false;
        setStatus('Saving review...', 'saving');
        const payload = {
            csrf: root.dataset.csrf,
            submission_id: Number(root.dataset.submissionId),
            annotations: annotations,
            comments: document.getElementById('reviewComments').value,
            score: document.getElementById('reviewScore').value,
            maximum_score: document.getElementById('reviewMaximum').value,
            status: document.getElementById('reviewStatus').value,
            allow_resubmission: document.getElementById('reviewAllowResubmission').checked
        };
        try {
            const response = await fetch(root.dataset.saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Review could not be saved.');
            setStatus('Saved at ' + new Date(result.saved_at).toLocaleTimeString(), 'saved');
        } catch (error) {
            setStatus(error.message || 'Review could not be saved.', 'error');
        } finally {
            saveInProgress = false;
            if (saveQueued) saveReview();
        }
    }

    function scheduleSave() {
        if (readOnly) return;
        setStatus('Unsaved changes', 'saving');
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveReview, 900);
    }

    function restoreHistory(from, to) {
        if (!from.length) return;
        to.push(JSON.stringify(annotations));
        annotations = JSON.parse(from.pop());
        drawAnnotations();
        scheduleSave();
    }

    function bindToolbar() {
        if (!readOnly) {
            document.querySelectorAll('[data-tool]').forEach(function (button) {
                button.addEventListener('click', function () { setTool(button.dataset.tool); });
            });
            const shapeSelect = document.getElementById('reviewShape');
            shapeSelect.addEventListener('change', function () { setTool('shape'); });
            document.getElementById('reviewColor').addEventListener('input', function (event) { activeColor = event.target.value; });
            document.getElementById('reviewUndo').addEventListener('click', function () { restoreHistory(history, redoHistory); });
            document.getElementById('reviewRedo').addEventListener('click', function () { restoreHistory(redoHistory, history); });
            annotationCanvas.addEventListener('pointerdown', pointDown);
            annotationCanvas.addEventListener('pointermove', pointMove);
            annotationCanvas.addEventListener('pointerup', pointUp);
            annotationCanvas.addEventListener('pointercancel', pointUp);
            document.getElementById('reviewSaveButton').addEventListener('click', saveReview);
            ['reviewComments', 'reviewScore', 'reviewMaximum', 'reviewStatus', 'reviewAllowResubmission'].forEach(function (id) {
                const element = document.getElementById(id);
                element.addEventListener('input', scheduleSave);
                element.addEventListener('change', scheduleSave);
            });
        }

        document.getElementById('reviewZoomIn').addEventListener('click', function () { zoom = Math.min(zoom + .15, 2.5); renderCurrentPage(); });
        document.getElementById('reviewZoomOut').addEventListener('click', function () { zoom = Math.max(zoom - .15, .55); renderCurrentPage(); });
        document.getElementById('reviewPrevPage').addEventListener('click', function () {
            if (currentPage > 1) { currentPage--; renderCurrentPage(); }
        });
        document.getElementById('reviewNextPage').addEventListener('click', function () {
            if (currentPage < totalPages) { currentPage++; renderCurrentPage(); }
        });
    }

    function updateControls() {
        pageNumber.textContent = String(currentPage);
        pageCount.textContent = String(totalPages);
        zoomLabel.textContent = Math.round(zoom * 100) + '%';
        document.getElementById('reviewPrevPage').disabled = currentPage <= 1;
        document.getElementById('reviewNextPage').disabled = currentPage >= totalPages;
    }

    function renderCurrentPage() {
        updateControls();
        if (pdfDocument) renderPdfPage().catch(function (error) { setError(error.message); });
        else if (extension === 'jpg' || extension === 'jpeg' || extension === 'png') renderImage();
        else if (officePages.length) renderOfficePage();
        else renderTextDocument();
    }

    function renderTextDocument() {
        const textDocument = stage.querySelector('.review-text-document');
        if (!textDocument) return;
        const width = Math.max(320, Math.min(viewport.clientWidth - 32, 800));
        textDocument.style.width = width + 'px';
        textDocument.style.transform = 'scale(' + zoom + ')';
        textDocument.style.transformOrigin = 'top left';
        requestAnimationFrame(function () {
            const height = Math.max(640, textDocument.scrollHeight);
            setStageSize(width * zoom, height * zoom);
        });
    }

    async function initializeDocument() {
        if (!root.dataset.fileUrl || !extension) {
            updateControls();
            renderTextDocument();
            return;
        }
        try {
            if (extension === 'pdf') await renderPdf();
            else if (['jpg', 'jpeg', 'png'].includes(extension)) renderImage();
            else if (extension === 'docx') await renderDocx();
            else if (extension === 'pptx') await renderPptx();
            else throw new Error('This submission file type cannot be previewed in the browser.');
            updateControls();
        } catch (error) {
            setError(error.message || 'The submitted document could not be opened.');
        }
    }

    bindToolbar();
    initializeDocument();
    window.addEventListener('resize', function () {
        if (pdfDocument || officePages.length || ['jpg', 'jpeg', 'png'].includes(extension)) renderCurrentPage();
    });
}());