<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="assets/img/invoxa-mark.svg" />
    <link rel="alternate icon" href="assets/img/favicon.ico" />
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png" />
    <link rel="manifest" href="manifest.webmanifest" />
    <meta name="theme-color" content="#0a0f1c" />
    <title>Invoxa<?= INSTANCE_LABEL ? ' (' . htmlspecialchars(INSTANCE_LABEL) . ')' : '' ?></title>
    <script>
        function invoxaResolveTheme() {
            const pref = localStorage.getItem('invoxa_theme') || 'system';
            if (pref === 'light' || pref === 'dark') return pref;
            return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', invoxaResolveTheme());
        document.documentElement.setAttribute('data-density', localStorage.getItem('invoxa_density') || 'comfortable');
        document.documentElement.setAttribute('data-corners', localStorage.getItem('invoxa_corners') || 'rounded');
        const savedAccent = localStorage.getItem('invoxa_accent');
        if (savedAccent) {
            document.documentElement.style.setProperty('--accent', savedAccent);
            document.documentElement.style.setProperty('--accent-hover', localStorage.getItem('invoxa_accent_hover') || savedAccent);
            document.documentElement.style.setProperty('--accent-soft', localStorage.getItem('invoxa_accent_soft') || savedAccent);
        }
    </script>
    <!--<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">-->
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/simple-datatables.css" rel="stylesheet" type="text/css">
    <script src="assets/js/simple-datatables.js" type="text/javascript"></script>
    <script src="assets/js/chart.js"></script>
    <script src="assets/js/cronstrue.min.js"></script>
    <style>
        :root {
            --bg-color: #0a0f1c;
            --surface: #131b2e;
            --surface-2: #1a2439;
            --surface-hover: #212d47;
            --text-primary: #f7f9fc;
            --text-secondary: #90a0bb;
            --accent: #4f7cff;
            --accent-hover: #3d63e0;
            --accent-soft: rgba(79, 124, 255, 0.12);
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #f5455c;
            --border: rgba(255, 255, 255, 0.08);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
            --shadow-md: 0 8px 24px -8px rgba(0, 0, 0, 0.45);
            --shadow-lg: 0 24px 48px -16px rgba(0, 0, 0, 0.55);
        }

        [data-theme="light"] {
            --bg-color: #f3f5fa;
            --surface: #ffffff;
            --surface-2: #f8f9fd;
            --surface-hover: #eef1f8;
            --text-primary: #0f172a;
            --text-secondary: #5c6b85;
            --accent: #3d63e0;
            --accent-hover: #2e4fc0;
            --accent-soft: rgba(61, 99, 224, 0.08);
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --border: rgba(15, 23, 42, 0.08);
            --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px -8px rgba(15, 23, 42, 0.12);
            --shadow-lg: 0 24px 48px -16px rgba(15, 23, 42, 0.18);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        ::selection {
            background: var(--accent);
            color: white;
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--surface-hover);
            border-radius: 8px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Roboto, 'Helvetica Neue', Arial, sans-serif;
            background:
                radial-gradient(1100px 500px at 12% -10%, var(--accent-soft), transparent 60%),
                var(--bg-color);
            color: var(--text-primary);
            display: flex;
            height: 100vh;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .sidebar {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 0 0 0;
            flex-shrink: 0;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }

        .sidebar-header h1 {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .sidebar-header h1 img {
            border-radius: 9px;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-header i {
            color: var(--accent);
        }

        .global-search-wrap {
            position: relative;
            margin: 0 1.5rem 1rem;
        }

        .global-search-wrap>i.fa-magnifying-glass {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 0.8rem;
            pointer-events: none;
        }

        #globalSearchInput {
            width: 100%;
            padding: 0.55rem 3rem 0.55rem 2.1rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.85rem;
        }

        #globalSearchInput:focus {
            outline: none;
            border-color: var(--accent);
        }

        .global-search-wrap kbd {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.65rem;
            font-family: inherit;
            color: var(--text-secondary);
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.1rem 0.35rem;
            pointer-events: none;
        }

        .global-search-results {
            display: none;
            position: fixed;
            max-height: 60vh;
            overflow-y: auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            z-index: 1300;
        }

        .global-search-results.active {
            display: block;
        }

        .global-search-group-label {
            padding: 0.5rem 0.85rem 0.25rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-secondary);
        }

        .global-search-result {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.55rem 0.85rem;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .global-search-result:hover {
            background: var(--surface-hover);
        }

        .global-search-empty {
            padding: 1rem 0.85rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-align: center;
        }

        .nav-section-label {
            padding: 0 1.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-secondary);
            opacity: 0.6;
            margin: 0.85rem 0 0.35rem;
        }

        .nav-item {
            position: relative;
            margin: 0.05rem 0.75rem;
            padding: 0.5rem 0.85rem;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            transition: background 0.15s ease, color 0.15s ease;
            font-weight: 500;
            font-size: 0.925rem;
        }

        .nav-item:hover {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--accent-soft);
            color: var(--accent);
            font-weight: 600;
        }

        .nav-item.active::before,
        .nav-item.tool-item.active::before {
            content: "";
            position: absolute;
            left: -0.75rem;
            top: 0.2rem;
            bottom: 0.2rem;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--accent);
        }

        .nav-item.tool-item {
            color: var(--text-secondary);
            margin-top: calc(0.05rem);
            margin-bottom: calc(0.05rem);
        }

        .nav-item.tool-item:hover {
            color: var(--text-primary);
            background: var(--surface-hover);
        }

        .nav-item.tool-item.active {
            background: var(--accent-soft);
            color: var(--accent);
            font-weight: 600;
        }

        .nav-item i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
        }

        .user-panel {
            margin-top: auto;
            padding: 1.25rem;
            border-top: 1px solid var(--border);
        }

        .mid-panel {
            margin: 20px 0 20px 0px;
            border-top: 1px solid var(--border);
        }

        .logout-btn {
            width: 100%;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            padding: 0.6rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: 0.15s ease;
        }

        .logout-btn:hover {
            background: rgba(245, 69, 92, 0.1);
            color: var(--danger);
            border-color: rgba(245, 69, 92, 0.25);
        }

        /* .main no longer scrolls itself — h2.page-title is fixed and never
           scrolls, so it needs no background trick to hide content behind it;
           .section-scroll scrolls independently underneath. */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 2rem 2.5rem 0;
            overflow: hidden;
            background: var(--bg-color);
        }

        .section {
            display: none;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }

        .section.active {
            display: flex;
            animation: fadeIn 0.35s ease;
        }

        .section-scroll {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 2rem;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2.page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.015em;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .alert-strip {
            background: rgba(245, 69, 92, 0.1);
            border: 1px solid rgba(245, 69, 92, 0.2);
            color: var(--danger);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        /* Statistics tab only — its inner tile grids (Dashboard's own 4-card
           .stats-grid, the .dashboard-tidbit-row below, stays plain auto-fit —
           it's always a full row of up to 4, no orphan tile to rescue). A
           couple of the Statistics tab's tile grids have 3 tiles instead,
           which auto-fit can resolve to 2 columns and leave the 3rd stranded
           with an empty cell beside it; pinning to a fixed 2 columns here
           makes that trailing odd tile reliably detectable so it can stretch
           full-width instead. */
        #sec-stats .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        #sec-stats .stats-grid > .stat-card:last-child:nth-child(odd) {
            grid-column: 1 / -1;
        }

        @media (max-width: 480px) {
            #sec-stats .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: linear-gradient(180deg, var(--surface-2), var(--surface));
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.4rem 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.15s ease;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-title {
            color: var(--text-secondary);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
        }

        .stat-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.6rem;
        }

        .stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-icon.success {
            background: color-mix(in srgb, var(--success) 15%, transparent);
            color: var(--success);
        }

        .stat-icon.warning {
            background: color-mix(in srgb, var(--warning) 15%, transparent);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: color-mix(in srgb, var(--danger) 15%, transparent);
            color: var(--danger);
        }

        .stat-value {
            font-size: 1.9rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            padding: 2.5rem 1rem;
        }

        .empty-state i {
            display: block;
            font-size: 1.6rem;
            margin-bottom: 0.6rem;
            opacity: 0.5;
        }

        td.datatable-empty {
            text-align: center;
            color: var(--text-secondary);
            padding: 2.5rem 1rem !important;
        }

        td.datatable-empty::before {
            content: "\f01c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            display: block;
            font-size: 1.6rem;
            margin-bottom: 0.6rem;
            opacity: 0.5;
        }

        .table-refreshing {
            position: relative;
        }

        .table-refreshing tbody {
            opacity: 0.35;
            transition: opacity 0.15s ease;
            pointer-events: none;
        }

        .table-refreshing::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 28px;
            height: 28px;
            margin: -14px 0 0 -14px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: table-refresh-spin 0.7s linear infinite;
            z-index: 2;
        }

        @keyframes table-refresh-spin {
            to { transform: rotate(360deg); }
        }

        .skeleton {
            display: inline-block;
            width: 70px;
            max-width: 100%;
            height: 0.85em;
            border-radius: 4px;
            vertical-align: middle;
            background: linear-gradient(90deg, var(--surface-hover) 25%, var(--border) 37%, var(--surface-hover) 63%);
            background-size: 400% 100%;
            animation: skeleton-shimmer 1.4s ease infinite;
        }

        @keyframes skeleton-shimmer {
            0% { background-position: 100% 50%; }
            100% { background-position: 0 50%; }
        }

        .stat-card.stats-loading .stat-value {
            color: transparent;
            position: relative;
        }

        .stat-card.stats-loading .stat-value::before {
            content: "";
            position: absolute;
            inset: 0.1em 20% 0.1em 0;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--surface-hover) 25%, var(--border) 37%, var(--surface-hover) 63%);
            background-size: 400% 100%;
            animation: skeleton-shimmer 1.4s ease infinite;
        }

        .client-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }

        .client-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 1.25rem;
        }

        @media (max-width: 640px) {
            .client-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            height: 350px;
            box-shadow: var(--shadow-sm);
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            min-width: 0;
        }

        #sec-settings .subnav-pane {
            gap: 0;
        }

        [data-corners="sharp"] {
            --radius-sm: 3px;
            --radius-md: 3px;
            --radius-lg: 3px;
        }

        [data-density="compact"] .card {
            margin-bottom: 1.1rem;
        }

        [data-density="compact"] .card-header {
            padding: 0.7rem 1.1rem;
        }

        [data-density="compact"] .card-body {
            padding: 1rem;
        }

        [data-density="compact"] .form-group {
            margin-bottom: 0.6rem;
        }

        [data-density="compact"] .form-control {
            padding: 0.45rem 0.65rem;
        }

        [data-density="compact"] .btn {
            padding: 0.4rem 0.8rem;
        }

        [data-density="compact"] .datatable-table th,
        [data-density="compact"] .datatable-table td {
            padding: 0.55rem 0.75rem;
        }

        .pref-item {
            padding: 1.1rem 0;
            border-bottom: 1px solid var(--border);
        }

        .pref-item:first-child {
            padding-top: 0;
        }

        .pref-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .segmented-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .segmented-btn {
            padding: 0.4rem 0.9rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text-primary);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .segmented-btn:hover {
            background: var(--surface-hover);
        }

        .segmented-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        #sec-stats .card {
            margin-bottom: 1rem;
        }

        /* Neither a shared-row grid nor CSS multi-column (both pair/balance
           card heights in ways that either leave gaps or dump everything into
           one column with a browser-chosen split) — instead two independent
           flexbox columns per "row", built and kept in sync by
           layoutStatsPane() in page_script.php. Each half-width card carries
           an explicit data-card-col (which .stats-col it belongs to), set by
           the user's drag — this is what lets a card be dropped into an empty
           column. A full-width card (data-card-width="2") renders as a direct
           child of .stats-columns instead of inside a row, and stretches full
           width for free via this container's own flex-direction: column. */
        #sec-stats .stats-columns {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        #sec-stats .stats-columns .stats-col-row {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }

        #sec-stats .stats-columns .stats-col {
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            min-width: 0;
            min-height: 3rem;
        }

        @media (max-width: 860px) {
            #sec-stats .stats-columns .stats-col-row {
                flex-direction: column;
            }
        }

        #sec-stats .stats-columns .card {
            margin-bottom: 0;
            position: relative;
        }

        #sec-stats .stats-columns .card[draggable="true"] {
            cursor: grab;
        }

        #sec-stats .stats-columns .card.dragging {
            opacity: 0.4;
        }

        /* Dashboard's own two customizable regions — deliberately separate
           containers/classes from Statistics' .stats-columns above (own
           drag-reorder logic in page_script.php, initDashboardDragDrop() /
           applyDashboardLayouts()) so nothing here can change how Statistics
           behaves. Saved per user under the 'dashboard-tidbits' and
           'dashboard-charts' panes in invoxa_stats_layout — same table
           Statistics uses, just a different pane, since the save/load
           functions are pane-agnostic. */
        .dashboard-tidbit-row .card,
        .dashboard-chart-row .card {
            position: relative;
        }

        /* Baseline span so a chart card is never smaller than half-width even
           before JS runs (or if it fails to for any reason) — applyDashboardLayouts()
           / layoutDashboardChartRow() in page_script.php override this with an inline
           style once they run, same "server render is never broken by JS" approach
           STATS_PANES's doc comment describes for Statistics. */
        .dashboard-chart-row .card {
            grid-column: span 3;
        }

        .dashboard-tidbit-row .card[draggable="true"],
        .dashboard-chart-row .card[draggable="true"] {
            cursor: grab;
        }

        .dashboard-tidbit-row .card.dragging,
        .dashboard-chart-row .card.dragging {
            opacity: 0.4;
        }

        .dashboard-tidbit-row .card[data-card-hidden="1"],
        .dashboard-chart-row .card[data-card-hidden="1"] {
            display: none;
        }

        /* Chart row: a 6-unit grid so a card can span 1/3 (2 units), 1/2 (3),
           2/3 (4), or full width (6) — width-cycle button sets
           data-card-width to the span count (see toggleDashboardChartWidth
           in page_script.php); grid auto-flow wraps combinations like
           half+half or third+two-thirds onto their own row for free. */
        .dashboard-chart-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .dashboard-chart-row {
                grid-template-columns: 1fr;
            }

            .dashboard-chart-row .card {
                grid-column: span 1 !important;
            }
        }

        .card-drag-controls {
            position: absolute;
            top: 0.6rem;
            right: 0.85rem;
            display: flex;
            gap: 0.4rem;
            align-items: center;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 1;
        }

        #sec-stats .stats-columns .card:hover .card-drag-controls,
        .dashboard-tidbit-row .card:hover .card-drag-controls,
        .dashboard-chart-row .card:hover .card-drag-controls {
            opacity: 1;
        }

        .card-drag-controls .drag-handle {
            cursor: grab;
            color: var(--text-secondary);
            padding: 0.2rem;
        }

        .card-drag-controls .card-width-toggle,
        .card-drag-controls .card-hide-toggle {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0.2rem;
            font-size: 0.85rem;
        }

        .card-drag-controls .card-width-toggle:hover,
        .card-drag-controls .card-hide-toggle:hover,
        .card-drag-controls .drag-handle:hover {
            color: var(--text-primary);
        }

        .widget-manage-menu {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 0.5rem;
            min-width: 220px;
            z-index: 10;
            font-size: 0.85rem;
            font-weight: 400;
        }

        .widget-manage-menu-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.45rem 0.6rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--text-primary);
        }

        .widget-manage-menu-heading {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
            color: var(--text-secondary);
            padding: 0.5rem 0.6rem 0.25rem;
        }

        .widget-manage-menu-item:hover {
            background: var(--surface-hover);
        }

        .widget-manage-menu-item-disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .widget-manage-menu-item-disabled:hover {
            background: none;
        }

        .widget-manage-menu-hint {
            font-size: 0.72rem;
            color: var(--text-secondary);
            padding: 0.35rem 0.6rem 0.5rem;
        }

        .card-width-toggle .width-label {
            font-size: 0.72rem;
            font-weight: 600;
            font-style: normal;
        }

        .card-header {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Mini nav (left) + one content pane at a time (right), same show/hide
           idea as the main sidebar nav nested one level deeper. Shared by
           Settings and Docs, hence generic .subnav-* names rather than
           .settings-*. Lives inside the tab's .section-scroll, so .subnav's
           sticky positioning is relative to .section-scroll, not .main. */
        .subnav-layout {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }

        .subnav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            width: 220px;
            flex-shrink: 0;
            position: sticky;
            top: 0;
        }

        .docs-nav-category summary::marker,
        .docs-nav-category summary::-webkit-details-marker {
            display: none;
        }

        .docs-nav-category summary .docs-cat-chevron {
            font-size: 0.65rem;
            transition: transform 0.15s ease;
        }

        .docs-nav-category[open] summary .docs-cat-chevron {
            transform: rotate(90deg);
        }

        .subnav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 0.9rem;
            border-radius: var(--radius-lg);
            border: 1px solid transparent;
            background: none;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .subnav-item:hover {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .subnav-item.active {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text-primary);
        }

        .subnav-item i.fa-solid:first-child,
        .subnav-item i.fa-brands:first-child {
            width: 1.1rem;
            text-align: center;
            color: var(--accent);
        }

        .subnav-item.danger {
            color: var(--danger);
        }

        .subnav-item.danger i.fa-solid:first-child {
            color: var(--danger);
        }

        .subnav-item.danger:hover {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger);
        }

        .subnav-item.danger.active {
            border-color: var(--danger);
            color: var(--danger);
        }

        .subnav-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-left: auto;
            flex-shrink: 0;
        }

        .subnav-dot.on {
            background: var(--success);
        }

        .subnav-dot.off {
            background: var(--text-secondary);
            opacity: 0.5;
        }

        .subnav-content {
            flex: 1;
            min-width: 0;
        }

        /* One card per row, full width — a pane with several cards (e.g.
           Billing) reads top-to-bottom instead of splitting into
           side-by-side columns. */
        .subnav-pane {
            display: none;
            flex-direction: column;
            gap: 1.5rem;
        }

        .subnav-pane.active {
            display: flex;
        }

        .nav-subnav-toggle {
            display: none;
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 0.75rem;
            padding: 0.25rem;
            cursor: pointer;
            flex-shrink: 0;
        }

        .nav-subnav-toggle i {
            transition: transform 0.15s ease;
        }

        .nav-subnav-toggle.expanded i {
            transform: rotate(180deg);
        }

        .nav-subnav-slot {
            display: none;
        }

        .toolbar-toggle {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
        }

        .toolbar-toggle i.toolbar-toggle-chevron {
            transition: transform 0.15s ease;
        }

        .toolbar-toggle.expanded i.toolbar-toggle-chevron {
            transform: rotate(180deg);
        }

        .toolbar-collapsible {
            display: contents;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .pill-btn {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 600;
            background: var(--surface-hover);
            color: var(--text-primary);
            border: 1px solid var(--border);
            cursor: pointer;
            width: auto;
            margin: 0;
        }

        .pill-btn:hover {
            background: var(--accent-soft);
            color: var(--accent);
            border-color: var(--accent);
        }

        .pill-btn.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            font-weight: 700;
        }

        .badge.sent {
            background: rgba(34, 197, 94, 0.12);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.25);
        }

        .badge.paid {
            background: var(--accent-soft);
            color: var(--accent);
            border: 1px solid rgba(79, 124, 255, 0.25);
        }

        .badge.partial {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .has-tooltip {
            position: relative;
            cursor: help;
            border-bottom: 1px dashed var(--text-secondary);
        }

        /* ::after tooltip suppressed — replaced by JS #globalTip below */
        .has-tooltip::after {
            display: none;
        }

        .badge.failed {
            background: rgba(245, 69, 92, 0.12);
            color: var(--danger);
            border: 1px solid rgba(245, 69, 92, 0.25);
        }

        .badge.overdue {
            background: rgba(245, 69, 92, 0.12);
            color: var(--danger);
            border: 1px solid rgba(245, 69, 92, 0.25);
            margin-left: 0.35rem;
        }

        .badge.test {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .badge.void {
            background: var(--surface-hover);
            color: var(--text-secondary);
            border: 1px solid var(--border);
            text-decoration: line-through;
        }

        .btn {
            background: var(--surface-2);
            color: var(--text-primary);
            border: 1px solid var(--border);
            padding: 0.55rem 1.05rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease, box-shadow 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            background: var(--surface-hover);
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
            box-shadow: 0 4px 14px -4px rgba(79, 124, 255, 0.5);
        }

        .btn.primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }

        .btn.success {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .btn.danger {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }

        .btn.small {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .timeline-icon {
            position: absolute;
            left: -2rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 2px solid var(--accent);
            font-size: 0.75rem;
            color: var(--text-primary);
            z-index: 1;
        }

        .timeline-content {
            background: var(--surface-2);
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .timeline-content > div:not(:first-child) {
            border-left: 1px solid var(--border);
            padding-left: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.7rem 0.85rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control option,
        select option {
            background-color: var(--bg-color);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .form-control:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .li-amount::-webkit-outer-spin-button,
        .li-amount::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .li-amount {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(5, 8, 16, 0.65);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 600px;
            max-height: 75vh;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-lg);
        }

        .modal.large {
            max-width: 900px;
        }

        .modal-header {
            padding: 1.4rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .doc-content h1, .doc-content h2, .doc-content h3, .doc-content h4 {
            color: var(--text-primary);
            margin: 1.5rem 0 0.75rem;
            line-height: 1.3;
        }

        .doc-content h1:first-child, .doc-content h2:first-child {
            margin-top: 0;
        }

        .doc-content h1 { font-size: 1.4rem; }
        .doc-content h2 { font-size: 1.15rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
        .doc-content h3 { font-size: 1rem; }

        .doc-content p, .doc-content li {
            color: var(--text-secondary);
            line-height: 1.65;
            font-size: 0.9rem;
        }

        .doc-content p { margin: 0.75rem 0; }
        .doc-content ul, .doc-content ol { margin: 0.5rem 0 0.75rem; padding-left: 1.4rem; }
        .doc-content li { margin: 0.3rem 0; }
        .doc-content strong { color: var(--text-primary); }
        .doc-content a { color: var(--accent); text-decoration: none; }
        .doc-content a:hover { text-decoration: underline; }

        .doc-content code {
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.1rem 0.4rem;
            font-size: 0.82rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            color: var(--text-primary);
        }

        .doc-content pre {
            background: var(--surface-hover);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.9rem 1rem;
            overflow-x: auto;
            margin: 0.75rem 0;
        }

        .doc-content pre code {
            background: none;
            border: none;
            padding: 0;
        }

        .doc-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.75rem 0 1.25rem;
            font-size: 0.85rem;
        }

        .doc-content th, .doc-content td {
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            text-align: left;
        }

        .doc-content th {
            background: var(--surface-hover);
            color: var(--text-primary);
        }

        .doc-content td { color: var(--text-secondary); }

        .doc-content img {
            max-width: 70%;
            height: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        .changelog-timeline {
            position: relative;
            margin: 1.25rem 0 0;
            padding-left: 1.5rem;
        }

        .changelog-entry {
            position: relative;
            padding-bottom: 1.5rem;
        }

        .changelog-entry::before {
            content: '';
            position: absolute;
            left: -1.05rem;
            top: 0.4rem;
            bottom: -1.5rem;
            width: 2px;
            background: var(--border);
        }

        .changelog-entry:last-child::before {
            display: none;
        }

        .changelog-dot {
            position: absolute;
            left: -1.32rem;
            top: 0.35rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border);
        }

        .changelog-entry.is-latest .changelog-dot {
            background: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-soft);
        }

        .changelog-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
        }

        .changelog-entry.is-latest .changelog-card {
            border-color: var(--accent);
        }

        .changelog-card-head {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.35rem;
        }

        .changelog-version {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .changelog-latest-badge {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .changelog-date {
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-left: auto;
        }

        .changelog-notes {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.65;
            margin: 0.5rem 0;
        }

        .changelog-category {
            margin-top: 0.85rem;
        }

        .changelog-category-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
        }

        .changelog-category-success .changelog-category-label {
            background: color-mix(in srgb, var(--success) 15%, transparent);
            color: var(--success);
        }

        .changelog-category-accent .changelog-category-label {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .changelog-category-warning .changelog-category-label {
            background: color-mix(in srgb, var(--warning) 15%, transparent);
            color: var(--warning);
        }

        .changelog-category-danger .changelog-category-label {
            background: color-mix(in srgb, var(--danger) 15%, transparent);
            color: var(--danger);
        }

        .changelog-category ul {
            margin: 0.5rem 0 0;
            padding-left: 1.3rem;
        }

        .changelog-category li {
            font-size: 0.87rem;
            line-height: 1.6;
            color: var(--text-secondary);
            margin: 0.3rem 0;
        }

        .changelog-older {
            display: none;
        }

        .changelog-older.show {
            display: block;
        }

        .changelog-show-more {
            margin-top: 0.5rem;
            text-align: center;
        }

        .roadmap-legend-quick, .roadmap-legend-medium, .roadmap-legend-large {
            font-weight: 600;
        }

        .roadmap-legend-quick { color: var(--success); }
        .roadmap-legend-medium { color: var(--warning); }
        .roadmap-legend-large { color: var(--danger); }

        .roadmap-timeline {
            position: relative;
            margin: 1rem 0 0;
            padding-left: 1.5rem;
        }

        .roadmap-entry {
            position: relative;
            padding-bottom: 0.6rem;
        }

        .roadmap-entry::before {
            content: '';
            position: absolute;
            left: -1.05rem;
            top: 0.4rem;
            bottom: -0.6rem;
            width: 2px;
            background: var(--border);
        }

        .roadmap-entry:last-child::before {
            display: none;
        }

        .roadmap-dot {
            position: absolute;
            left: -1.32rem;
            top: 0.35rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border);
        }

        .roadmap-entry.roadmap-quick .roadmap-dot { background: var(--success); }
        .roadmap-entry.roadmap-medium .roadmap-dot { background: var(--warning); }
        .roadmap-entry.roadmap-large .roadmap-dot { background: var(--danger); }

        .roadmap-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.65rem 1rem;
        }

        .roadmap-card-head {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.2rem;
        }

        .roadmap-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .roadmap-effort {
            margin-left: auto;
        }

        .roadmap-effort.roadmap-quick {
            background: color-mix(in srgb, var(--success) 15%, transparent);
            color: var(--success);
        }

        .roadmap-effort.roadmap-medium {
            background: color-mix(in srgb, var(--warning) 15%, transparent);
            color: var(--warning);
        }

        .roadmap-effort.roadmap-large {
            background: color-mix(in srgb, var(--danger) 15%, transparent);
            color: var(--danger);
        }

        .roadmap-desc {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0;
        }

        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            background: var(--success);
            color: white;
            padding: 0.9rem 1.4rem;
            border-radius: var(--radius-md);
            font-weight: 500;
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            visibility: hidden;
            transition: transform 0.35s cubic-bezier(.34,1.56,.64,1), opacity 0.25s ease, visibility 0.35s;
            z-index: 2000;
            box-shadow: var(--shadow-lg);
        }

        .toast.show {
            transform: translateY(0) scale(1);
            opacity: 1;
            visibility: visible;
        }

        .toast.error {
            background: var(--danger);
        }

        .toast-icon {
            font-size: 1.05rem;
            transform: scale(0);
            transition: transform 0.35s cubic-bezier(.34,1.56,.64,1) 0.08s;
        }

        .toast.show .toast-icon {
            transform: scale(1);
        }

        .brand-wordmark {
            background: linear-gradient(135deg, var(--accent) 20%, #8b5cf6 80%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .welcome-flash-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 16, 0.45);
            opacity: 0;
            visibility: hidden;
            z-index: 2999;
            pointer-events: none;
            transition: opacity 0.45s ease, visibility 0.45s;
        }

        [data-theme="light"] .welcome-flash-backdrop {
            background: rgba(15, 23, 42, 0.25);
        }

        .welcome-flash-backdrop.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            cursor: pointer;
        }

        .welcome-flash {
            position: fixed;
            top: 2.25rem;
            left: 50%;
            transform: translateX(-50%) translateY(-16px) scale(0.92);
            opacity: 0;
            visibility: hidden;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: var(--radius-lg);
            padding: 1.4rem 2.2rem;
            box-shadow: var(--shadow-lg), 0 0 0 1px rgba(79, 124, 255, 0.08);
            z-index: 3000;
            pointer-events: none;
            transition: opacity 0.45s cubic-bezier(.34,1.56,.64,1), transform 0.45s cubic-bezier(.34,1.56,.64,1), visibility 0.45s;
        }

        .welcome-flash.show {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0) scale(1);
            pointer-events: auto;
            cursor: pointer;
        }

        .welcome-flash img {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            box-shadow: 0 6px 18px -4px rgba(79, 124, 255, 0.55);
        }

        .welcome-flash-eyebrow {
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            margin-bottom: 0.2rem;
        }

        .welcome-flash-title {
            font-weight: 700;
            font-size: 1.35rem;
            letter-spacing: -0.01em;
            color: var(--text-primary);
        }

        .welcome-flash-sub {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: 0.3rem;
        }

        .datatable-wrapper.no-footer .datatable-container {
            border-bottom: 1px solid var(--border);
        }

        .datatable-table {
            border-collapse: collapse;
        }

        .datatable-table th,
        .datatable-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .datatable-table > tbody > tr > td,
        .datatable-table > tbody > tr > th {
            vertical-align: middle;
        }

        .datatable-table th {
            background: var(--surface-2);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--text-secondary);
        }

        .datatable-table tbody tr {
            transition: background 0.12s ease;
        }

        .datatable-table tbody tr:hover {
            background: var(--surface-2);
        }

        .datatable-input,
        .datatable-selector {
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
        }

        .datatable-dropdown,
        .datatable-info,
        .datatable-pagination a {
            color: var(--text-secondary);
        }

        .datatable-container {
            overflow-x: auto;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1200;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1.1rem;
            cursor: pointer;
            box-shadow: var(--shadow-md);
        }

        .mobile-brand-icon {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1200;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
        }

        .mobile-brand-icon img {
            width: 26px;
            height: 26px;
        }

        body.sidebar-open .mobile-brand-icon {
            display: none !important;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 16, 0.6);
            z-index: 1290;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        .mobile-bottom-nav {
            display: none;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1200;
            background: var(--surface);
            border-top: 1px solid var(--border);
            padding-bottom: env(safe-area-inset-bottom, 0);
            box-shadow: var(--shadow-md);
        }

        .mobile-bottom-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.2rem;
            padding: 0.5rem 0.25rem;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-family: inherit;
            font-size: 0.65rem;
            font-weight: 600;
            cursor: pointer;
        }

        .mobile-bottom-nav-item i {
            font-size: 1.15rem;
        }

        .mobile-bottom-nav-item.active {
            color: var(--accent);
        }

        @media (max-width: 860px) {
            .mobile-menu-btn {
                display: flex;
            }

            .mobile-brand-icon {
                display: flex;
            }

            .sidebar {
                position: fixed;
                top: 0;
                right: -300px;
                height: 100vh;
                z-index: 1300;
                transition: right 0.25s ease;
                box-shadow: none;
                border-right: none;
                border-left: 1px solid var(--border);
            }

            .sidebar.open {
                right: 0;
                box-shadow: 0 0 48px rgba(0, 0, 0, 0.55);
            }

            .main {
                padding: 1.25rem 1rem 0;
                padding-top: 4.5rem;
                padding-bottom: calc(4.25rem + env(safe-area-inset-bottom, 0));
            }

            .mobile-bottom-nav {
                display: flex;
            }

            .mobile-grid {
                grid-template-columns: 1fr !important;
            }

            .modal {
                width: 100vw !important;
                max-width: 100vw !important;
                height: 100vh !important;
                max-height: 100vh !important;
                border-radius: 0 !important;
                border-left: none;
                border-right: none;
            }

            .modal-overlay {
                padding: 0;
            }

            h2.page-title {
                flex-wrap: wrap;
                row-gap: 0.5rem;
            }

            .global-search-wrap kbd {
                display: none;
            }

            .nav-subnav-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .nav-subnav-slot.expanded {
                display: block;
                padding: 0.25rem 0.75rem 0.5rem;
            }

            .subnav-layout {
                flex-direction: column;
                align-items: stretch;
            }

            .subnav-layout>.subnav {
                display: none;
            }

            .subnav-content {
                width: 100%;
            }

            .nav-subnav-slot .subnav {
                width: 100%;
                position: static;
            }

            .nav-subnav-slot .subnav-item {
                font-size: 0.85rem;
                padding: 0.55rem 0.75rem;
            }

            .toolbar-toggle {
                display: flex;
            }

            .toolbar-collapsible {
                display: none;
                width: 100%;
                margin-top: 0.75rem;
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 0 0.9rem;
            }

            .toolbar-collapsible.expanded {
                display: block;
            }

            .toolbar-collapsible>div {
                width: 100%;
                flex-wrap: wrap;
                background: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0.85rem 0 !important;
                border-bottom: 1px solid var(--border);
            }

            .toolbar-collapsible>div:last-child {
                border-bottom: none;
            }

            .toolbar-collapsible select {
                flex: 1 1 auto;
                min-width: 0 !important;
            }
        }
    </style>
    <script>
        const savedCustomCss = localStorage.getItem('invoxa_custom_css');
        if (savedCustomCss) {
            const invoxaCustomCssStyle = document.createElement('style');
            invoxaCustomCssStyle.id = 'invoxaCustomCssStyle';
            invoxaCustomCssStyle.textContent = savedCustomCss;
            document.head.appendChild(invoxaCustomCssStyle);
        }
    </script>
</head>
