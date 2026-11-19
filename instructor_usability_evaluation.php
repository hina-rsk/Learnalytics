<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposed System's Level of Usability – Instructor Evaluation | LearnAlytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:    #3b1f6e;
            --accent:     #7c3aed;
            --gold:       #c8962e;
            --gold-light: #f0d98a;
            --dark:       #17092e;
            --mid:        #4c2a8a;
            --light:      #f3eeff;
            --border:     #cbb8f0;
            --text:       #1e1a2e;
            --muted:      #7a6b99;
            --poor:       #d9534f;
            --marginal:   #e87e27;
            --good:       #3a9e6b;
            --excellent:  #1a6bc4;
            --row-even:   #f8f4ff;
            --row-odd:    #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #17092e 0%, #3b1f6e 50%, #17092e 100%);
            min-height: 100vh;
            padding: 30px 16px;
            color: var(--text);
        }

        .wrapper { max-width: 1060px; margin: 0 auto; }

        /* Controls */
        .controls { display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 18px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .25s ease;
        }
        .btn-print {
            background: linear-gradient(135deg, var(--gold), #e8b547);
            color: #2a1800;
            box-shadow: 0 4px 16px rgba(200,150,46,.35);
        }
        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(200,150,46,.45); }
        .btn-reset {
            background: rgba(255,255,255,.12);
            color: #fff;
            border: 1px solid rgba(255,255,255,.25);
        }
        .btn-reset:hover { background: rgba(255,255,255,.2); transform: translateY(-2px); }

        /* Card */
        .card {
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.45);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--dark) 0%, var(--primary) 60%, var(--mid) 100%);
            padding: 42px 48px 36px;
            position: relative;
            overflow: hidden;
        }
        .header::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(124,58,237,.25) 0%, transparent 70%);
            border-radius: 50%;
        }
        .header::after {
            content: '';
            position: absolute;
            bottom: -40px; left: -40px;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(200,150,46,.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .header-inner { position: relative; z-index: 2; }
        .header-top { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; }
        .logo-badge {
            width: 66px; height: 66px;
            background: linear-gradient(135deg, var(--gold), #e8b547);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 4px 20px rgba(200,150,46,.4);
            flex-shrink: 0;
        }
        .header-title h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            color: #ffffff;
        }
        .header-title .subtitle {
            color: var(--gold-light);
            font-size: 0.82rem;
            font-weight: 500;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .header-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .meta-field {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 10px;
            padding: 12px 16px;
        }
        .meta-field label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--gold-light);
            margin-bottom: 5px;
        }
        .meta-field input {
            background: transparent;
            border: none;
            border-bottom: 1px dashed rgba(255,255,255,.3);
            color: #fff;
            font-size: 0.9rem;
            width: 100%;
            font-family: 'Inter', sans-serif;
            outline: none;
            padding-bottom: 3px;
        }
        .meta-field input::placeholder { color: rgba(255,255,255,.35); }

        /* Legend Bar */
        .legend-bar { display: flex; align-items: stretch; border-bottom: 3px solid var(--border); }
        .legend-label {
            background: var(--light);
            padding: 14px 20px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
            display: flex;
            align-items: center;
            min-width: 130px;
            border-right: 2px solid var(--border);
        }
        .legend-items { display: flex; flex: 1; }
        .legend-item {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 10px;
            border-right: 1px solid var(--border);
        }
        .legend-item:last-child { border-right: none; }
        .legend-dot {
            width: 28px; height: 28px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 0.85rem; color: #fff; flex-shrink: 0;
        }
        .dot-poor     { background: var(--poor);     box-shadow: 0 2px 8px rgba(217,83,79,.4); }
        .dot-marginal { background: var(--marginal); box-shadow: 0 2px 8px rgba(232,126,39,.4); }
        .dot-good     { background: var(--good);     box-shadow: 0 2px 8px rgba(58,158,107,.4); }
        .dot-excellent{ background: var(--excellent);box-shadow: 0 2px 8px rgba(26,107,196,.4); }
        .legend-text strong { display: block; font-size: 0.8rem; font-weight: 700; color: var(--text); }
        .legend-text span   { font-size: 0.72rem; color: var(--muted); }

        /* Form Body */
        .form-body { padding: 0 48px 48px; }

        /* Direction Box */
        .direction-box {
            background: linear-gradient(135deg, #f3eeff, #e8daff);
            border: 1px solid var(--border);
            border-left: 5px solid var(--accent);
            border-radius: 12px;
            padding: 18px 24px;
            margin: 28px 0 32px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .direction-icon { font-size: 1.4rem; flex-shrink: 0; }
        .direction-box p { font-size: 0.88rem; line-height: 1.65; color: var(--text); }
        .direction-box strong { color: var(--accent); }

        /* Section */
        .section { margin-bottom: 36px; }
        .section-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--primary), var(--mid));
            border-radius: 12px 12px 0 0;
        }
        .section-icon {
            width: 38px; height: 38px;
            background: rgba(255,255,255,.15);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .section-title { color: #fff; font-size: 1rem; font-weight: 700; }
        .section-num {
            margin-left: auto;
            background: rgba(255,255,255,.15);
            color: var(--gold-light);
            font-size: 0.72rem; font-weight: 700;
            letter-spacing: .1em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 20px;
            border: 1px solid rgba(255,255,255,.2);
        }

        /* Table */
        .eval-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 12px 12px;
            overflow: hidden;
        }
        .eval-table thead tr { background: var(--light); border-bottom: 2px solid var(--border); }
        .eval-table thead th {
            padding: 11px 14px;
            font-size: 0.76rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted); text-align: center;
        }
        .eval-table thead th:first-child { text-align: left; padding-left: 22px; }
        .eval-table tbody tr:nth-child(even) { background: var(--row-even); }
        .eval-table tbody tr:nth-child(odd)  { background: var(--row-odd); }
        .eval-table tbody tr:hover { background: #e8daff; transition: background .15s; }
        .eval-table td {
            padding: 13px 14px;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--border);
            color: var(--text); vertical-align: middle;
        }
        .eval-table td:first-child { padding-left: 22px; line-height: 1.5; }
        .eval-table tbody tr:last-child td { border-bottom: none; }

        /* Radio Cells */
        .radio-cell { text-align: center; width: 70px; }
        .radio-wrap { display: flex; align-items: center; justify-content: center; }
        .radio-wrap input[type="radio"] { display: none; }
        .radio-wrap label {
            width: 32px; height: 32px;
            border-radius: 50%;
            border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-weight: 800; font-size: 0.85rem;
            color: var(--muted); transition: all .2s ease; user-select: none;
        }
        .radio-wrap label:hover { transform: scale(1.12); }
        input[type="radio"].r1:checked + label { background: var(--poor);     border-color: var(--poor);     color: #fff; box-shadow: 0 0 0 3px rgba(217,83,79,.25); }
        input[type="radio"].r2:checked + label { background: var(--marginal); border-color: var(--marginal); color: #fff; box-shadow: 0 0 0 3px rgba(232,126,39,.25); }
        input[type="radio"].r3:checked + label { background: var(--good);     border-color: var(--good);     color: #fff; box-shadow: 0 0 0 3px rgba(58,158,107,.25); }
        input[type="radio"].r4:checked + label { background: var(--excellent);border-color: var(--excellent);color: #fff; box-shadow: 0 0 0 3px rgba(26,107,196,.25); }

        .item-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px;
            background: var(--light); border: 1px solid var(--border); border-radius: 6px;
            font-size: 0.72rem; font-weight: 700; color: var(--muted);
            margin-right: 10px; flex-shrink: 0; vertical-align: middle;
        }

        /* Score Banner */
        .score-banner {
            background: linear-gradient(135deg, var(--dark), var(--primary));
            border-radius: 14px; padding: 22px 30px;
            display: flex; align-items: center; justify-content: space-between;
            margin: 28px 0; gap: 20px; flex-wrap: wrap;
        }
        .score-label { color: var(--gold-light); font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
        .score-value { font-size: 2.4rem; font-weight: 800; color: #fff; line-height: 1; }
        .score-desc  { font-size: 0.9rem; color: rgba(255,255,255,.7); margin-top: 4px; }
        .score-right { display: flex; gap: 16px; flex-wrap: wrap; }
        .score-stat {
            background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
            border-radius: 10px; padding: 12px 18px; text-align: center; min-width: 90px;
        }
        .score-stat .stat-v { font-size: 1.3rem; font-weight: 800; color: #fff; }
        .score-stat .stat-l { font-size: 0.68rem; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }

        /* Summary Table */
        .summary-section { margin-bottom: 36px; }
        .summary-table { width: 100%; border-collapse: collapse; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .summary-table thead tr { background: linear-gradient(135deg, var(--dark), var(--primary)); }
        .summary-table thead th {
            padding: 14px 18px; font-size: 0.76rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; color: var(--gold-light); text-align: center;
        }
        .summary-table thead th:first-child { text-align: left; }
        .summary-table tbody tr:nth-child(even) { background: var(--row-even); }
        .summary-table tbody tr:nth-child(odd)  { background: var(--row-odd); }
        .summary-table td { padding: 12px 18px; font-size: 0.875rem; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
        .summary-table td:not(:first-child) { text-align: center; }
        .summary-table tbody tr:last-child td { border-bottom: none; }
        .summary-table .mean-cell { font-weight: 700; font-size: 1rem; color: var(--accent); }
        .summary-table tfoot tr { background: linear-gradient(135deg, #f3eeff, #e8daff); border-top: 2px solid var(--border); }
        .summary-table tfoot td { font-weight: 700; color: var(--primary); font-size: 0.9rem; }

        /* Scale Cards */
        .scale-box-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 10px; padding-left: 4px; }
        .scale-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .scale-card { border-radius: 12px; padding: 16px; text-align: center; border: 1px solid transparent; }
        .scale-card.poor      { background: #fdf0ef; border-color: rgba(217,83,79,.25);  }
        .scale-card.marginal  { background: #fef6ee; border-color: rgba(232,126,39,.25); }
        .scale-card.good      { background: #edf7f3; border-color: rgba(58,158,107,.25); }
        .scale-card.excellent { background: #eef4fc; border-color: rgba(26,107,196,.25); }
        .scale-card .sc-num { width: 38px; height: 38px; border-radius: 50%; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1rem; color: #fff; }
        .scale-card.poor      .sc-num { background: var(--poor);     }
        .scale-card.marginal  .sc-num { background: var(--marginal); }
        .scale-card.good      .sc-num { background: var(--good);     }
        .scale-card.excellent .sc-num { background: var(--excellent);}
        .scale-card .sc-label { font-size: 0.88rem; font-weight: 700; color: var(--text); }
        .scale-card .sc-range { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
        .scale-card .sc-desc  { font-size: 0.72rem; color: var(--muted); margin-top: 6px; line-height: 1.4; }

        /* Section heading utility */
        .section-h2 { font-size: 1rem; font-weight: 700; color: var(--primary); margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .section-h2-icon { width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary), var(--accent)); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1rem; }

        /* Signature */
        .signature-section { margin-top: 40px; border-top: 2px solid var(--border); padding-top: 30px; }
        .sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
        .sig-block { padding: 20px 24px; border: 1px dashed var(--border); border-radius: 12px; }
        .sig-block label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
        .sig-line { border-top: 2px solid var(--text); margin: 30px 0 8px; }
        .sig-name { font-size: 0.85rem; font-weight: 700; color: var(--text); text-align: center; }
        .sig-title-text { font-size: 0.75rem; color: var(--muted); text-align: center; margin-top: 2px; }

        /* Footer */
        .footer { background: var(--light); border-top: 1px solid var(--border); padding: 16px 48px; display: flex; align-items: center; justify-content: space-between; }
        .footer p { font-size: 0.75rem; color: var(--muted); }
        .footer strong { color: var(--primary); }

        @media print {
            body { background: #fff !important; padding: 0; }
            .controls { display: none !important; }
            .card { box-shadow: none; border-radius: 0; }
            .header, .section-header, .summary-table thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .score-banner { border: 1px solid var(--border); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .section { page-break-inside: avoid; }
        }
        @media (max-width: 768px) {
            .header { padding: 28px 22px 24px; }
            .form-body { padding: 0 18px 36px; }
            .footer { flex-direction: column; gap: 6px; padding: 14px 18px; text-align: center; }
            .sig-grid { grid-template-columns: 1fr; }
            .scale-grid { grid-template-columns: repeat(2, 1fr); }
            .legend-bar { flex-direction: column; }
            .score-banner { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- Controls -->
    <div class="controls">
        <button class="btn btn-reset" onclick="resetForm()">&#8635; Reset Form</button>
        <button class="btn btn-print" onclick="window.print()">&#128424; Print / Save PDF</button>
    </div>

    <div class="card">

        <!-- == HEADER == -->
        <div class="header">
            <div class="header-inner">
                <div class="header-top">
                    <div class="logo-badge">&#128104;&#8205;&#127979;</div>
                    <div class="header-title">
                        <div class="subtitle">Evaluation Instrument &nbsp;&bull;&nbsp; Instructor Respondent</div>
                        <h1>Proposed System's Level of Usability</h1>
                        <div class="subtitle" style="color:rgba(255,255,255,.55);letter-spacing:.04em;text-transform:none;font-size:.85rem;margin-top:6px;">
                            LearnAlytics &mdash; Learning Management &amp; Analytics System
                        </div>
                    </div>
                </div>
                <div class="header-meta">
                    <div class="meta-field">
                        <label>Instructor's Name</label>
                        <input type="text" id="evaluatorName" placeholder="Full Name" autocomplete="off">
                    </div>
                    <div class="meta-field">
                        <label>Department / College</label>
                        <input type="text" id="position" placeholder="e.g., CITE" autocomplete="off">
                    </div>
                    <div class="meta-field">
                        <label>Subjects Handled</label>
                        <input type="text" id="department" placeholder="e.g., IT 101, CS 202" autocomplete="off">
                    </div>
                    <div class="meta-field">
                        <label>Date of Evaluation</label>
                        <input type="text" id="evalDate" placeholder="MM / DD / YYYY" autocomplete="off">
                    </div>
                </div>
            </div>
        </div>

        <!-- == RATING LEGEND == -->
        <div class="legend-bar">
            <div class="legend-label">Rating Scale</div>
            <div class="legend-items">
                <div class="legend-item">
                    <div class="legend-dot dot-poor">1</div>
                    <div class="legend-text"><strong>Poor</strong><span>1.00 &ndash; 1.74</span></div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot dot-marginal">2</div>
                    <div class="legend-text"><strong>Marginal</strong><span>1.75 &ndash; 2.49</span></div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot dot-good">3</div>
                    <div class="legend-text"><strong>Good</strong><span>2.50 &ndash; 3.24</span></div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot dot-excellent">4</div>
                    <div class="legend-text"><strong>Excellent</strong><span>3.25 &ndash; 4.00</span></div>
                </div>
            </div>
        </div>

        <!-- == FORM BODY == -->
        <div class="form-body">

            <!-- Direction -->
            <div class="direction-box">
                <div class="direction-icon">&#128203;</div>
                <p>
                    <strong>Directions:</strong> Please rate each indicator by clicking the number that best describes your experience using the
                    <strong>LearnAlytics</strong> Learning Management &amp; Analytics System as an instructor. Use the rating scale:
                    <strong>4 &ndash; Excellent &nbsp;|&nbsp; 3 &ndash; Good &nbsp;|&nbsp; 2 &ndash; Marginal &nbsp;|&nbsp; 1 &ndash; Poor</strong>.
                    Your honest and objective evaluation will help improve the system before full institutional deployment.
                </p>
            </div>

            <!-- Live Score Banner -->
            <div class="score-banner">
                <div>
                    <div class="score-label">Overall Weighted Mean</div>
                    <div class="score-value" id="overallMean">&mdash;</div>
                    <div class="score-desc" id="overallDesc">Rate the items below to compute your score</div>
                </div>
                <div class="score-right">
                    <div class="score-stat">
                        <div class="stat-v" id="totalRated">0</div>
                        <div class="stat-l">Rated</div>
                    </div>
                    <div class="score-stat">
                        <div class="stat-v">24</div>
                        <div class="stat-l">Total Items</div>
                    </div>
                    <div class="score-stat">
                        <div class="stat-v" id="pctComplete">0%</div>
                        <div class="stat-l">Complete</div>
                    </div>
                </div>
            </div>

            <!-- Sections rendered by JS -->
            <div id="sectionsContainer"></div>

            <!-- Summary Table -->
            <div class="summary-section">
                <h2 class="section-h2">
                    <span class="section-h2-icon">&#128200;</span>
                    Summary of Weighted Mean per Section
                </h2>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="text-align:left">Area / Section</th>
                            <th>No. of Items</th>
                            <th>Sum of Ratings</th>
                            <th>Weighted Mean</th>
                            <th>Interpretation</th>
                        </tr>
                    </thead>
                    <tbody id="summaryBody"></tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Overall Weighted Mean</strong></td>
                            <td style="text-align:center"><strong>24</strong></td>
                            <td style="text-align:center" id="sumTotal"><strong>&mdash;</strong></td>
                            <td style="text-align:center" id="meanTotal"><strong style="color:var(--accent);font-size:1.05rem">&mdash;</strong></td>
                            <td style="text-align:center" id="intTotal"><strong>&mdash;</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Scale Interpretation Reference -->
            <div style="margin-bottom:36px;">
                <div class="scale-box-title">Scale Interpretation Reference</div>
                <div class="scale-grid">
                    <div class="scale-card poor">
                        <div class="sc-num">1</div>
                        <div class="sc-label">Poor</div>
                        <div class="sc-range">1.00 &ndash; 1.74</div>
                        <div class="sc-desc">The feature is inadequate and needs major improvements before use.</div>
                    </div>
                    <div class="scale-card marginal">
                        <div class="sc-num">2</div>
                        <div class="sc-label">Marginal</div>
                        <div class="sc-range">1.75 &ndash; 2.49</div>
                        <div class="sc-desc">The feature partially meets expectations but requires notable revisions.</div>
                    </div>
                    <div class="scale-card good">
                        <div class="sc-num">3</div>
                        <div class="sc-label">Good</div>
                        <div class="sc-range">2.50 &ndash; 3.24</div>
                        <div class="sc-desc">The feature meets most requirements with minor areas for improvement.</div>
                    </div>
                    <div class="scale-card excellent">
                        <div class="sc-num">4</div>
                        <div class="sc-label">Excellent</div>
                        <div class="sc-range">3.25 &ndash; 4.00</div>
                        <div class="sc-desc">The feature exceeds expectations and is ready for full deployment.</div>
                    </div>
                </div>
            </div>

            <!-- Comments -->
            <div style="margin-bottom:36px;">
                <h2 class="section-h2">
                    <span class="section-h2-icon">&#128172;</span>
                    Comments, Observations &amp; Recommendations
                </h2>
                <textarea id="comments" rows="5"
                    placeholder="Please share any comments, specific observations, or suggestions to improve the LearnAlytics system for instructors..."
                    style="width:100%;border:1px solid var(--border);border-radius:12px;padding:16px;font-family:'Inter',sans-serif;font-size:0.875rem;color:var(--text);resize:vertical;outline:none;line-height:1.65;background:var(--row-even);transition:border-color .2s;"
                    onfocus="this.style.borderColor='var(--accent)'"
                    onblur="this.style.borderColor='var(--border)'"></textarea>
            </div>

            <!-- Signatures -->
            <div class="signature-section">
                <div class="sig-grid">
                    <div class="sig-block">
                        <label>Instructor's Signature Over Printed Name</label>
                        <div class="sig-line"></div>
                        <div class="sig-name" id="sigName">&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;</div>
                        <div class="sig-title-text" id="sigPos">Department / College</div>
                    </div>
                    <div class="sig-block">
                        <label>Date Accomplished</label>
                        <div class="sig-line"></div>
                        <div class="sig-name" id="sigDate">&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;&#95;</div>
                        <div class="sig-title-text">Date</div>
                    </div>
                </div>
            </div>

        </div><!-- /form-body -->

        <!-- Footer -->
        <div class="footer">
            <p><strong>LearnAlytics</strong> &mdash; Learning Management &amp; Analytics System &nbsp;|&nbsp; Instructor Usability Evaluation Form</p>
            <p>Confidential Research Instrument</p>
        </div>

    </div><!-- /card -->
</div><!-- /wrapper -->

<script>
// ── Data ──────────────────────────────────────────────────────────────────────
const SECTIONS = [
    {
        key: 'A', icon: '&#x1F4D6;',
        title: 'A. Subject &amp; Content Management',
        items: [
            'Creating a new subject and generating a class code for student enrollment is quick and easy.',
            'Posting lessons (with video content and supporting materials) is straightforward and works reliably.',
            'Creating and publishing assignments with deadlines and instructions is intuitive.',
            'Building quizzes and exams with various question types is easy and flexible.',
            'Editing or updating existing lessons, assignments, and quizzes is convenient and hassle-free.',
            'Organizing classwork content (lessons, assignments, quizzes) within a subject is well-structured.',
            'Attaching files and multimedia resources to lessons or assignments works correctly.',
            'The system clearly shows me which content items are published and visible to students.'
        ]
    },
    {
        key: 'B', icon: '&#x1F4CA;',
        title: 'B. Student Monitoring &amp; Grading',
        items: [
            'Viewing a list of students enrolled in each of my subjects is easy and up-to-date.',
            'Reviewing and grading student assignment submissions is efficient and straightforward.',
            'Checking quiz and exam results per student is clear and well-presented.',
            'The analytics dashboard provides useful insights into individual student performance.',
            'The risk assessment feature (Low, Moderate, High) helps me identify struggling students early.',
            'I can easily record and update grades for students within the system.',
            'Tracking student attendance and participation data is accessible and accurate.',
            'The system allows me to provide written feedback directly on student submissions.'
        ]
    },
    {
        key: 'C', icon: '&#x2B50;',
        title: 'C. System Usability &amp; Overall Satisfaction',
        items: [
            'The system is easy to learn and I was able to use it with minimal training.',
            'The navigation menu and layout are logically organized and easy to follow.',
            'The system performs consistently and reliably without unexpected errors or crashes.',
            'Pages and data load within an acceptable response time during regular use.',
            'The overall design and interface of the system is visually appealing and professional.',
            'The system is accessible and functions well on the devices I use (desktop, laptop, mobile).',
            'The system significantly reduces the time I spend on administrative and grading tasks.',
            'Overall, the LearnAlytics system is an effective and useful tool for managing my classes.'
        ]
    }
];

// ── Build DOM ─────────────────────────────────────────────────────────────────
(function buildSections() {
    const container   = document.getElementById('sectionsContainer');
    const summaryBody = document.getElementById('summaryBody');
    let globalItem = 0;

    SECTIONS.forEach((sec, si) => {
        const div = document.createElement('div');
        div.className = 'section';

        const hdr = document.createElement('div');
        hdr.className = 'section-header';
        hdr.innerHTML = `
            <div class="section-icon">${sec.icon}</div>
            <div class="section-title">${sec.title}</div>
            <div class="section-num">Section ${sec.key}</div>`;
        div.appendChild(hdr);

        const table = document.createElement('table');
        table.className = 'eval-table';
        table.innerHTML = `
            <thead>
                <tr>
                    <th style="width:55%">Indicator</th>
                    <th><span style="color:var(--poor)">1</span><br><span style="font-weight:400;font-size:.7rem">Poor</span></th>
                    <th><span style="color:var(--marginal)">2</span><br><span style="font-weight:400;font-size:.7rem">Marginal</span></th>
                    <th><span style="color:var(--good)">3</span><br><span style="font-weight:400;font-size:.7rem">Good</span></th>
                    <th><span style="color:var(--excellent)">4</span><br><span style="font-weight:400;font-size:.7rem">Excellent</span></th>
                </tr>
            </thead>`;
        const tbody = document.createElement('tbody');

        sec.items.forEach((item, ii) => {
            globalItem++;
            const name = `q_s${si}_i${ii}`;
            const tr   = document.createElement('tr');
            let radios = '';
            for (let r = 1; r <= 4; r++) {
                radios += `
                    <td class="radio-cell">
                        <div class="radio-wrap">
                            <input type="radio" class="r${r}" id="${name}_r${r}" name="${name}" value="${r}" onchange="updateScore()">
                            <label for="${name}_r${r}">${r}</label>
                        </div>
                    </td>`;
            }
            tr.innerHTML = `<td><span class="item-num">${globalItem}</span>${item}</td>${radios}`;
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        div.appendChild(table);
        container.appendChild(div);

        // Summary row
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${sec.title}</td>
            <td style="text-align:center">8</td>
            <td style="text-align:center" id="sum${sec.key}">&mdash;</td>
            <td style="text-align:center" class="mean-cell" id="mean${sec.key}">&mdash;</td>
            <td style="text-align:center" id="int${sec.key}">&mdash;</td>`;
        summaryBody.appendChild(tr);
    });
})();

// ── Scoring ───────────────────────────────────────────────────────────────────
function interpret(mean) {
    if (isNaN(mean)) return '&mdash;';
    if (mean < 1.75) return '&#128543; Poor';
    if (mean < 2.50) return '&#128998; Marginal';
    if (mean < 3.25) return '&#9989; Good';
    return '&#11088; Excellent';
}
function interpretText(mean) {
    if (isNaN(mean)) return 'Rate the items below to compute your score';
    if (mean < 1.75) return 'Poor — Major improvements needed';
    if (mean < 2.50) return 'Marginal — Notable revisions required';
    if (mean < 3.25) return 'Good — Meets expectations';
    return 'Excellent — Ready for full deployment';
}

function updateScore() {
    let grandSum = 0, grandRated = 0;
    const total = 24;

    SECTIONS.forEach((sec, si) => {
        let sSum = 0, sRated = 0;
        for (let ii = 0; ii < 8; ii++) {
            const name = `q_s${si}_i${ii}`;
            document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                if (r.checked) { sSum += parseInt(r.value); sRated++; }
            });
        }
        grandSum   += sSum;
        grandRated += sRated;

        const complete = sRated === 8;
        const mean = complete ? (sSum / 8).toFixed(2) : null;
        document.getElementById(`sum${sec.key}`).innerHTML     = complete ? sSum : '&mdash;';
        document.getElementById(`mean${sec.key}`).textContent  = complete ? mean : '—';
        document.getElementById(`int${sec.key}`).innerHTML     = complete ? interpret(parseFloat(mean)) : '&mdash;';
    });

    const oMean = grandRated > 0 ? (grandSum / grandRated).toFixed(2) : null;

    document.getElementById('sumTotal').innerHTML  = grandRated > 0 ? `<strong>${grandSum}</strong>` : '<strong>&mdash;</strong>';
    document.getElementById('meanTotal').innerHTML = oMean ? `<strong style="color:var(--accent);font-size:1.05rem">${oMean}</strong>` : '<strong>&mdash;</strong>';
    document.getElementById('intTotal').innerHTML  = oMean ? `<strong>${interpret(parseFloat(oMean))}</strong>` : '<strong>&mdash;</strong>';

    document.getElementById('overallMean').textContent = oMean || '—';
    document.getElementById('overallDesc').textContent = oMean ? interpretText(parseFloat(oMean)) : 'Rate the items below to compute your score';
    document.getElementById('totalRated').textContent  = grandRated;
    document.getElementById('pctComplete').textContent = Math.round((grandRated / total) * 100) + '%';
}

function resetForm() {
    if (!confirm('Reset all ratings? This cannot be undone.')) return;
    document.querySelectorAll('input[type="radio"]').forEach(r => r.checked = false);
    document.getElementById('comments').value = '';
    updateScore();
}

// ── Auto-fill signatures ──────────────────────────────────────────────────────
document.getElementById('evaluatorName').addEventListener('input', function () {
    document.getElementById('sigName').textContent = this.value || '____________________';
});
function updateSigPos() {
    const dept = document.getElementById('position').value;
    const subj = document.getElementById('department').value;
    document.getElementById('sigPos').textContent = [dept, subj].filter(Boolean).join(' — ') || 'Department / College';
}
document.getElementById('position').addEventListener('input', updateSigPos);
document.getElementById('department').addEventListener('input', updateSigPos);
document.getElementById('evalDate').addEventListener('input', function () {
    document.getElementById('sigDate').textContent = this.value || '____________________';
});

// ── Default date ──────────────────────────────────────────────────────────────
(function () {
    const d = new Date();
    const v = (d.getMonth()+1).toString().padStart(2,'0') + ' / ' +
              d.getDate().toString().padStart(2,'0') + ' / ' + d.getFullYear();
    document.getElementById('evalDate').value = v;
    document.getElementById('sigDate').textContent = v;
})();
</script>
</body>
</html>
