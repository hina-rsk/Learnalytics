/* EduLearn LMS Main JS */
(function () {
    'use strict'

    function showActionFeedback(button, message) {
        const originalText = button.textContent;
        button.textContent = message;
        button.disabled = true;
        setTimeout(() => {
            button.textContent = originalText;
            button.disabled = false;
        }, 1800);
    }

    function sharePage(button) {
        const pageUrl = window.location.href;
        const shareData = {
            title: document.title || 'LearnAlytics',
            text: 'Check out this dashboard on LearnAlytics',
            url: pageUrl,
        };

        if (navigator.share) {
            navigator.share(shareData)
                .then(() => showActionFeedback(button, 'Shared'))
                .catch(() => fallbackShare(button, pageUrl));
            return;
        }

        fallbackShare(button, pageUrl);
    }

    function fallbackShare(button, pageUrl) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(pageUrl)
                .then(() => showActionFeedback(button, 'Copied'))
                .catch(() => {
                    window.prompt('Copy this link:', pageUrl);
                    showActionFeedback(button, 'Copied');
                });
            return;
        }

        window.prompt('Copy this link:', pageUrl);
        showActionFeedback(button, 'Copied');
    }

    function gatherExportData() {
        const rows = [];
        const cards = document.querySelectorAll('.card');

        cards.forEach(card => {
            const titleElement = card.querySelector('.card-title, .dashboard-card-title');
            const valueElement = card.querySelector('.dashboard-card-value, .card-text.display-6, .card-text');
            if (!titleElement || !valueElement) {
                return;
            }

            const title = titleElement.textContent.trim();
            const value = valueElement.textContent.trim().replace(/\s+/g, ' ');
            if (title && value) {
                rows.push([title, value]);
            }
        });

        if (rows.length === 0) {
            const heading = document.querySelector('h1.h2');
            rows.push([heading ? heading.textContent.trim() : 'Dashboard', 'No exportable summary data found']);
        }

        return rows;
    }

    function downloadCsv(filename, rows) {
        const csvContent = rows.map(row => row.map(cell => '"' + cell.replace(/"/g, '""') + '"').join(',')).join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);

        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function exportDashboard(button) {
        const data = gatherExportData();
        if (!data || data.length === 0) {
            showActionFeedback(button, 'Nothing');
            return;
        }

        downloadCsv('learnalytics-dashboard-export.csv', data);
        showActionFeedback(button, 'Exported');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const assessmentToggle = document.querySelector('[data-assessment-toggle]');
        const assessmentSubmenu = document.getElementById('assessmentSubmenu');
        if (assessmentToggle && assessmentSubmenu) {
            const storageKey = 'learnalytics-assessment-menu-open';
            const onAssessmentPage = assessmentToggle.classList.contains('active');
            const savedExpanded = window.localStorage.getItem(storageKey) === 'true';
            const expanded = onAssessmentPage || savedExpanded;
            assessmentToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            assessmentSubmenu.classList.toggle('is-open', expanded);

            assessmentToggle.addEventListener('click', function () {
                const shouldExpand = assessmentToggle.getAttribute('aria-expanded') !== 'true';
                assessmentToggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                assessmentSubmenu.classList.toggle('is-open', shouldExpand);
                window.localStorage.setItem(storageKey, shouldExpand ? 'true' : 'false');
            });
        }

        document.querySelectorAll('button[data-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                const action = button.getAttribute('data-action');
                if (action === 'share') {
                    sharePage(button);
                } else if (action === 'export') {
                    exportDashboard(button);
                }
            });
        });
    });
})();
