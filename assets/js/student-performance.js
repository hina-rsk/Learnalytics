(function () {
    'use strict';

    function readJson(id) {
        var element = document.getElementById(id);
        if (!element) {
            return null;
        }
        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            console.error('Student analytics chart data is invalid.', error);
            return null;
        }
    }

    function createChart(id, config) {
        var canvas = document.getElementById(id);
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        new Chart(canvas, config);
    }

    function buildDashboardCharts(data) {
        if (!data) {
            return;
        }

        var assessmentColors = {
            Prelim: '#2563eb',
            Midterm: '#7c3aed',
            Final: '#0f766e',
            Quiz: '#0891b2',
            Assignment: '#d97706',
            Examination: '#4f46e5'
        };
        var assessmentRows = data.assessments || [];
        createChart('assessmentChart', {
            type: 'bar',
            data: {
                labels: assessmentRows.map(function (item) {
                    return item.type + ': ' + item.title + ' · ' + item.subject_title;
                }),
                datasets: [{
                    label: 'Average percentage',
                    data: assessmentRows.map(function (item) {
                        return item.average;
                    }),
                    backgroundColor: assessmentRows.map(function (item) {
                        return assessmentColors[item.type] || '#2563eb';
                    }),
                    borderRadius: 6,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return 'Average: ' + Number(context.raw).toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: function (value) { return value + '%'; } } },
                    x: { ticks: { autoSkip: true, maxRotation: 45, minRotation: 0 } }
                }
            }
        });

        var distribution = data.distribution || {};
        createChart('distributionChart', {
            type: 'doughnut',
            data: {
                labels: Object.keys(distribution),
                datasets: [{
                    data: Object.keys(distribution).map(function (key) { return distribution[key]; }),
                    backgroundColor: ['#16a34a', '#22c55e', '#2563eb', '#f59e0b', '#dc2626', '#94a3b8'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ': ' + context.raw;
                            }
                        }
                    }
                }
            }
        });

        var periodData = data.periods || {};
        createChart('periodChart', {
            type: 'line',
            data: {
                labels: Object.keys(periodData),
                datasets: [{
                    label: 'Average percentage',
                    data: Object.keys(periodData).map(function (key) { return periodData[key]; }),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, .12)',
                    pointBackgroundColor: '#2563eb',
                    pointRadius: 5,
                    fill: true,
                    tension: .3,
                    spanGaps: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: function (value) { return value + '%'; } } }
                }
            }
        });

        createComparisonChart('sectionChart', data.sections || [], 'Section average');
        createComparisonChart('subjectComparisonChart', data.subjects || [], 'Subject average');

        var studentData = readJson('spaStudentChartData');
        if (studentData) {
            buildStudentTrendChart(studentData, assessmentColors);
            buildStudentComparisonChart(studentData.comparison || {});
        }
    }

    function createComparisonChart(id, items, label) {
        if (!items.length) {
            return;
        }
        createChart(id, {
            type: 'bar',
            data: {
                labels: items.map(function (item) { return item.name; }),
                datasets: [{
                    label: label,
                    data: items.map(function (item) { return item.average; }),
                    backgroundColor: 'rgba(37, 99, 235, .78)',
                    borderRadius: 6,
                    maxBarThickness: 38
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: function (value) { return value + '%'; } } },
                    x: { ticks: { autoSkip: true, maxRotation: 35, minRotation: 0 } }
                }
            }
        });
    }

    function buildStudentTrendChart(data, colors) {
        var records = data.records || [];
        var labels = records.map(function (record, index) {
            return (record.assessed_at ? record.assessed_at.slice(0, 10) + ' · ' : '') + record.title + ' (' + (index + 1) + ')';
        });
        var types = ['Prelim', 'Midterm', 'Final', 'Quiz', 'Assignment', 'Examination'];
        var datasets = types.map(function (type) {
            var values = records.map(function (record) {
                return record.assessment_type === type ? record.percentage : null;
            });
            return {
                label: type,
                data: values,
                borderColor: colors[type] || '#2563eb',
                backgroundColor: colors[type] || '#2563eb',
                pointRadius: 4,
                spanGaps: false,
                tension: .25
            };
        }).filter(function (dataset) {
            return dataset.data.some(function (value) { return value !== null; });
        });

        createChart('studentTrendChart', {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + Number(context.raw).toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100, max: 100, ticks: { callback: function (value) { return value + '%'; } } },
                    x: { ticks: { autoSkip: true, maxRotation: 45, minRotation: 0 } }
                }
            }
        });
    }

    function buildStudentComparisonChart(comparison) {
        var labels = [];
        var values = [];
        if (comparison.student !== null && comparison.student !== undefined) {
            labels.push('Student');
            values.push(comparison.student);
        }
        if (comparison.section !== null && comparison.section !== undefined) {
            labels.push('Section average');
            values.push(comparison.section);
        }
        if (labels.length < 2) {
            return;
        }
        createChart('studentComparisonChart', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Overall average',
                    data: values,
                    backgroundColor: ['#2563eb', '#94a3b8'],
                    borderRadius: 7,
                    maxBarThickness: 72
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: function (value) { return value + '%'; } } }
                }
            }
        });
    }

    function setupStudentTable() {
        var table = document.getElementById('studentPerformanceTable');
        if (!table) {
            return;
        }
        var body = table.querySelector('tbody');
        var rows = Array.prototype.slice.call(body.querySelectorAll('[data-spa-row]'));
        var search = document.getElementById('studentSearch');
        var status = document.getElementById('statusFilter');
        var resultCount = document.getElementById('studentResultCount');
        var summary = document.getElementById('paginationSummary');
        var previous = document.getElementById('previousPage');
        var next = document.getElementById('nextPage');
        var pageSize = 10;
        var currentPage = 0;
        var sortKey = '';
        var sortDirection = 1;

        function filteredRows() {
            var term = (search ? search.value : '').trim().toLowerCase();
            var selectedStatus = status ? status.value : '';
            var filtered = rows.filter(function (row) {
                var matchesTerm = !term || row.dataset.name.indexOf(term) !== -1 || row.dataset.id.indexOf(term) !== -1;
                var matchesStatus = !selectedStatus || row.dataset.status === selectedStatus;
                return matchesTerm && matchesStatus;
            });
            if (sortKey) {
                filtered.sort(function (left, right) {
                    var leftValue = left.dataset[sortKey] || '';
                    var rightValue = right.dataset[sortKey] || '';
                    var leftNumber = parseFloat(leftValue);
                    var rightNumber = parseFloat(rightValue);
                    var comparison;
                    if (leftValue !== '' && rightValue !== '' && !Number.isNaN(leftNumber) && !Number.isNaN(rightNumber)) {
                        comparison = leftNumber - rightNumber;
                    } else {
                        comparison = leftValue.localeCompare(rightValue, undefined, { sensitivity: 'base' });
                    }
                    return comparison * sortDirection;
                });
            }
            return filtered;
        }

        function render() {
            var filtered = filteredRows();
            var pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
            currentPage = Math.min(currentPage, pageCount - 1);
            rows.forEach(function (row) { row.hidden = true; });
            filtered.slice(currentPage * pageSize, (currentPage + 1) * pageSize).forEach(function (row) {
                row.hidden = false;
                body.appendChild(row);
            });
            var start = filtered.length ? currentPage * pageSize + 1 : 0;
            var end = Math.min((currentPage + 1) * pageSize, filtered.length);
            if (summary) {
                summary.textContent = 'Showing ' + start + '–' + end + ' of ' + filtered.length + ' records';
            }
            if (resultCount) {
                resultCount.textContent = filtered.length + ' records';
            }
            if (previous) {
                previous.disabled = currentPage <= 0;
            }
            if (next) {
                next.disabled = currentPage >= pageCount - 1;
            }
        }

        if (search) {
            search.addEventListener('input', function () { currentPage = 0; render(); });
        }
        if (status) {
            status.addEventListener('change', function () { currentPage = 0; render(); });
        }
        if (previous) {
            previous.addEventListener('click', function () {
                currentPage = Math.max(0, currentPage - 1);
                render();
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                currentPage += 1;
                render();
            });
        }
        table.querySelectorAll('[data-sort]').forEach(function (button) {
            button.addEventListener('click', function () {
                var requestedKey = button.dataset.sort;
                sortDirection = sortKey === requestedKey ? -sortDirection : 1;
                sortKey = requestedKey;
                render();
            });
        });
        render();
    }

    document.addEventListener('DOMContentLoaded', function () {
        buildDashboardCharts(readJson('spaChartData'));
        setupStudentTable();
    });
}());
