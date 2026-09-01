        <script src="assets/js/simple-datatables.js"></script>
        <script src="assets/js/qrcode.min.js"></script>
        <script>
            const APP_CURRENCY = <?= json_encode($settings['currency'] ?? 'USD') ?>;
            let chartInstance = null, pieChartInstance = null, chartAllData = null, chartRange = '12';
            const CLIENT_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#f97316', '#84cc16', '#a855f7', '#ec4899', '#14b8a6', '#f43f5e'];
            // Declared here (not just above their only use in updateDashboardCardWidthLabel
            // further down) because applyDashboardLayouts() is called at top-level well
            // before that point in the script — a `const` declared after the call site is
            // still in its temporal dead zone when the call runs, so a saved dashboard-charts
            // layout (which routes through updateDashboardCardWidthLabel) would throw a
            // ReferenceError and abort the rest of this script's top-level execution.
            const DASH_WIDTH_CYCLE = { '3': '2', '2': '4', '4': '6', '6': '3' };
            const DASH_WIDTH_LABEL = { '2': '1/3', '3': '1/2', '4': '2/3', '6': 'Full' };
            const justLoggedIn = new URLSearchParams(window.location.search).has('login');
            const justSignedUp = new URLSearchParams(window.location.search).has('welcome');
            const defaultLandingTab = localStorage.getItem('invoxa_default_tab') || 'dashboard';
            const storedTab = justLoggedIn ? defaultLandingTab : (localStorage.getItem('activeTab') || 'dashboard');
            if (justLoggedIn) {
                localStorage.setItem('activeTab', defaultLandingTab);
                history.replaceState(null, '', window.location.pathname);
                if (justSignedUp) {
                    document.getElementById('onboardingModal')?.classList.add('active');
                } else {
                    const flash = document.getElementById('welcomeFlash');
                    const flashBackdrop = document.getElementById('welcomeFlashBackdrop');
                    if (flash && localStorage.getItem('invoxa_show_welcome') !== '0') {
                        const dismiss = () => { flash.classList.remove('show'); flashBackdrop?.classList.remove('show'); };
                        requestAnimationFrame(() => requestAnimationFrame(() => {
                            flash.classList.add('show');
                            flashBackdrop?.classList.add('show');
                        }));
                        setTimeout(dismiss, 4200);
                        flash.addEventListener('click', dismiss);
                        flashBackdrop?.addEventListener('click', dismiss);
                    }
                }
            }
            const emailVerifyParam = new URLSearchParams(window.location.search).has('email_verified') ? 'ok' : (new URLSearchParams(window.location.search).has('email_verify_failed') ? 'failed' : null);
            if (emailVerifyParam) {
                history.replaceState(null, '', window.location.pathname);
                showToast(emailVerifyParam === 'ok' ? 'Email confirmed — account recovery will reach you at that address.' : 'That confirmation link is invalid or has expired.', emailVerifyParam === 'failed');
            }

            let __chartResizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(__chartResizeTimeout);
                __chartResizeTimeout = setTimeout(() => {
                    Object.values(Chart.instances).forEach(c => c.resize());
                }, 150);
            });

            function toggleOtherTables(section, showAll) {
                const selector = section === 'stats' ? '.stat-table-item.other-table' : '.backup-table-item.other-table';
                document.querySelectorAll(selector).forEach(el => {
                    el.style.display = showAll ? 'flex' : 'none';
                    if (section === 'backup' && !showAll) {
                        const cb = el.querySelector('input[type="checkbox"]');
                        if (cb) cb.checked = false;
                    }
                });
            }

            function toggleSidebar() {
                document.querySelector('.sidebar').classList.toggle('open');
                document.getElementById('sidebarBackdrop').classList.toggle('active');
                document.body.classList.toggle('sidebar-open');
            }

            // ── Global quick search ───────────────────────────────────────
            // Jumps to a record by re-using the target tab's own DataTable search
            // box (see filterTableSearch) rather than fetching/rendering the full
            // row itself, so it stays in sync with whatever that tab already shows.
            let __globalSearchDebounce = null;
            function handleGlobalSearch() {
                clearTimeout(__globalSearchDebounce);
                const q = document.getElementById('globalSearchInput').value.trim();
                const resultsEl = document.getElementById('globalSearchResults');
                if (q.length < 2) {
                    resultsEl.classList.remove('active');
                    resultsEl.innerHTML = '';
                    return;
                }
                __globalSearchDebounce = setTimeout(async () => {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'global_search', q }) });
                    const json = await res.json();
                    if (json.success) renderGlobalSearchResults(json);
                }, 250);
            }
            function positionGlobalSearchResults() {
                const input = document.getElementById('globalSearchInput');
                const resultsEl = document.getElementById('globalSearchResults');
                const rect = input.getBoundingClientRect();
                resultsEl.style.left = rect.left + 'px';
                resultsEl.style.top = (rect.bottom + 6) + 'px';
                resultsEl.style.width = rect.width + 'px';
            }
            function _escHtml(s) {
                return (s || '').toString().replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            }
            function renderGlobalSearchResults(json) {
                const resultsEl = document.getElementById('globalSearchResults');
                const groups = [];
                if (json.invoices.length) {
                    groups.push('<div class="global-search-group-label">Invoices &amp; Quotes</div>' + json.invoices.map(inv => `
                        <div class="global-search-result" data-jump="invoice" data-value="${_escHtml(inv.invoice_number)}" data-quote="${inv.is_quote}">
                            <span><strong>${_escHtml(inv.invoice_number)}</strong> — ${_escHtml(inv.client_name)}</span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">$${parseFloat(inv.amount).toFixed(2)}</span>
                        </div>
                    `).join(''));
                }
                if (json.clients.length) {
                    groups.push('<div class="global-search-group-label">Clients</div>' + json.clients.map(c => `
                        <div class="global-search-result" data-jump="client" data-value="${_escHtml(c.client_name)}">
                            <span><strong>${_escHtml(c.client_name)}</strong></span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">${_escHtml(c.email)}</span>
                        </div>
                    `).join(''));
                }
                if (json.expenses.length) {
                    groups.push('<div class="global-search-group-label">Expenses</div>' + json.expenses.map(e => `
                        <div class="global-search-result" data-jump="expense" data-value="${_escHtml(e.vendor)}">
                            <span><strong>${_escHtml(e.vendor)}</strong> — ${_escHtml((e.expense_date || '').substring(0, 10))}</span>
                            <span style="color:var(--text-secondary); font-size:0.8rem;">$${parseFloat(e.amount).toFixed(2)}</span>
                        </div>
                    `).join(''));
                }
                resultsEl.innerHTML = groups.length ? groups.join('') : '<div class="global-search-empty">No matches</div>';
                positionGlobalSearchResults();
                resultsEl.classList.add('active');
            }
            function closeGlobalSearch() {
                document.getElementById('globalSearchResults').classList.remove('active');
            }
            function filterTableSearch(which, value) {
                const wrapper = document.querySelector('#sec-' + which + ' .datatable-wrapper');
                const input = wrapper && wrapper.querySelector('input.datatable-input');
                if (!input) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            // nav(which, true) kicks off an async refreshTable() in the background
            // (destroys and recreates the DataTable, wiping any search box value set
            // before it lands) — waits for that tbody swap to actually happen before
            // filtering, instead of guessing at a fixed delay that could land either
            // side of it.
            // simple-datatables rebuilds the table's <tbody> for its own pagination on
            // construction, so a tbody id baked into the server-rendered HTML doesn't
            // survive — look it up live under the table each time instead.
            function tabTbody(which) {
                const table = document.getElementById(which + 'Table');
                return table ? table.querySelector('tbody') : null;
            }
            function waitForTableRefresh(which, maxWaitMs = 1500) {
                return new Promise(resolve => {
                    const tbody = tabTbody(which);
                    if (!tbody) return resolve();
                    let done = false;
                    const finish = () => { if (!done) { done = true; observer.disconnect(); resolve(); } };
                    const observer = new MutationObserver(finish);
                    observer.observe(tbody, { childList: true });
                    setTimeout(finish, maxWaitMs);
                });
            }
            document.getElementById('globalSearchResults').addEventListener('click', (e) => {
                const item = e.target.closest('.global-search-result');
                if (!item) return;
                const type = item.dataset.jump;
                const value = item.dataset.value;
                closeGlobalSearch();
                document.getElementById('globalSearchInput').value = '';
                if (type === 'invoice') {
                    const which = item.dataset.quote === '1' ? 'quotes' : 'invoices';
                    nav(which, true);
                    waitForTableRefresh(which).then(() => filterTableSearch(which, value));
                } else if (type === 'client') {
                    nav('clients', true);
                    waitForTableRefresh('clients').then(() => filterTableSearch('clients', value));
                } else if (type === 'expense') {
                    nav('expenses', true);
                    waitForTableRefresh('expenses').then(() => filterTableSearch('expenses', value));
                }
            });
            function handleGlobalSearchKeydown(event) {
                if (event.key === 'Escape') { closeGlobalSearch(); event.target.blur(); }
            }
            document.addEventListener('click', (e) => {
                const wrap = document.querySelector('.global-search-wrap');
                if (wrap && !wrap.contains(e.target)) closeGlobalSearch();
            });
            window.addEventListener('resize', () => {
                if (document.getElementById('globalSearchResults').classList.contains('active')) positionGlobalSearchResults();
            });
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    const input = document.getElementById('globalSearchInput');
                    input.focus();
                    input.select();
                }
            });

            function nav(section, fromClick = false) {
                if (!document.getElementById('sec-' + section)) section = 'dashboard';
                if (fromClick) {
                    document.querySelector('.sidebar').classList.remove('open');
                    document.getElementById('sidebarBackdrop').classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                    document.querySelectorAll('.modal-overlay.active').forEach(el => el.classList.remove('active'));
                }
                document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
                document.querySelector('.nav-item[data-target="' + section + '"]').classList.add('active');
                document.querySelectorAll('.mobile-bottom-nav-item').forEach(el => el.classList.toggle('active', el.dataset.target === section));
                document.querySelectorAll('.section').forEach(el => el.classList.remove('active'));
                document.getElementById('sec-' + section).classList.add('active');
                localStorage.setItem('activeTab', section);
                // The automatic nav(storedTab) call at page load just draws the chart
                // from server-rendered data; an actual click triggers a full refresh below.
                if (section === 'dashboard' && !fromClick) { initChart(); animateStatCards(); }
                if (section === 'backup') loadBackupList();
                // Re-fetch the tab's content in the background, but only on an actual
                // click — the page-load nav(storedTab) call already has fresh data.
                if (fromClick && (section === 'invoices' || section === 'clients' || section === 'quotes' || section === 'expenses')) refreshTable(section);
                if (fromClick && section === 'dashboard') refreshDashboard();
                if (fromClick && section === 'stats') refreshStatsSection();
                if (fromClick && section === 'audit') refreshAuditSection();
                // simple-datatables miscalculates column widths for any table built or
                // resized while its tab was hidden (display:none gives a zero-width
                // container). Firing resize right after the tab becomes visible makes
                // it recompute against the real size and self-heal the layout.
                requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
            }
            nav(storedTab);
            if (storedTab === 'dashboard') requestAnimationFrame(() => setTimeout(initChart, 50));
            if (storedTab === 'backup') loadBackupList();

            // Settings and Docs each get their own mini nav (mirrors the main sidebar
            // nav()/`.section` pattern, nested one level deeper).
            function navSettings(target) {
                document.querySelectorAll('#sec-settings .subnav-item, .nav-subnav-slot[data-for="settings"] .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.settingsTarget === target));
                document.querySelectorAll('#sec-settings .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'settings-pane-' + target));
                localStorage.setItem('settingsSubTab', target);
            }
            const storedSettingsTab = localStorage.getItem('settingsSubTab');
            if (storedSettingsTab && document.getElementById('settings-pane-' + storedSettingsTab)) navSettings(storedSettingsTab);

            function navDocs(target) {
                document.querySelectorAll('#sec-docs .subnav-item, .nav-subnav-slot[data-for="docs"] .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.docsTarget === target));
                document.querySelectorAll('#sec-docs .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'docs-pane-' + target));
                document.querySelectorAll(`.docs-nav-page[data-docs-target="${target}"]`).forEach(el => {
                    const details = el.closest('details.docs-nav-category');
                    if (details) details.open = true;
                });
                localStorage.setItem('docsSubTab', target);
            }
            const storedDocsTab = localStorage.getItem('docsSubTab');
            if (storedDocsTab && document.getElementById('docs-pane-' + storedDocsTab)) navDocs(storedDocsTab);

            // "Fuzzy" means word-order-independent AND matching, not typo-tolerance:
            // each search term must appear somewhere in the page's title+content,
            // in any order. Reads the already-rendered (hidden) page markup directly
            // rather than maintaining a separate search index.
            function filterDocsNav() {
                const terms = document.getElementById('docsSearchInput').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
                let anyVisible = false;
                document.querySelectorAll('#docsNav .docs-nav-category').forEach(catEl => {
                    let catHasVisible = false;
                    catEl.querySelectorAll('.docs-nav-page').forEach(pageEl => {
                        const pageId = pageEl.dataset.docsTarget;
                        const title = pageEl.dataset.title || '';
                        const paneEl = document.getElementById('docs-pane-' + pageId);
                        const content = paneEl ? paneEl.textContent.toLowerCase() : '';
                        const haystack = title + ' ' + content;
                        const match = terms.length === 0 || terms.every(term => haystack.includes(term));
                        pageEl.style.display = match ? '' : 'none';
                        if (match) { catHasVisible = true; anyVisible = true; }
                    });
                    catEl.style.display = catHasVisible ? '' : 'none';
                    if (terms.length > 0 && catHasVisible) catEl.open = true;
                });
                document.getElementById('docsNoResults').style.display = anyVisible ? 'none' : '';
            }

            function toggleChangelogOlder(btn) {
                const older = document.querySelectorAll('.changelog-older');
                const willShow = !older[0]?.classList.contains('show');
                older.forEach(el => el.classList.toggle('show', willShow));
                btn.textContent = willShow ? 'Show fewer releases' : btn.dataset.showLabel;
            }

            function navBackup(target) {
                document.querySelectorAll('#sec-backup .subnav-item, .nav-subnav-slot[data-for="backup"] .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.backupTarget === target));
                document.querySelectorAll('#sec-backup .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'backup-pane-' + target));
                localStorage.setItem('backupSubTab', target);
                if (target === 'sync') refreshSync();
            }
            const storedBackupTab = localStorage.getItem('backupSubTab');
            if (storedBackupTab && document.getElementById('backup-pane-' + storedBackupTab)) navBackup(storedBackupTab);

            // Statistics rebuilds its whole tab body from scratch on every visit (see
            // refreshStatsSection() below), so this also gets called after each of
            // those refreshes, unlike Settings/Backup/Docs which only restore their
            // sub-tab once at page load.
            let __statsChartsInit = {};
            function initStatsChartsFor(target) {
                // Lazy, per-tab, and only once — a Chart.js canvas sizes itself to zero
                // while its pane is still display:none, so each chart is only created
                // the first time its tab actually becomes visible.
                if (__statsChartsInit[target] || typeof Chart === 'undefined') return;
                if (target === 'revenue') {
                    __statsChartsInit.revenue = true;
                    if (window.__revenueBreakdownData && document.getElementById('revenueBreakdownChart')) {
                        const d = window.__revenueBreakdownData;
                        new Chart(document.getElementById('revenueBreakdownChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: ['Invoiced', 'Paid', 'Outstanding'], datasets: [{ data: [d.invoiced, d.paid, d.outstanding], backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'] }] },
                            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                        });
                    }
                    if (window.__invoiceStatusData && document.getElementById('invoiceStatusChart')) {
                        const rows = window.__invoiceStatusData;
                        new Chart(document.getElementById('invoiceStatusChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.amount), backgroundColor: rows.map(r => r.color) }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
                        });
                    }
                    if (window.__revenueTrendData && document.getElementById('revenueTrendChart')) {
                        const rows = window.__revenueTrendData;
                        new Chart(document.getElementById('revenueTrendChart').getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: rows.map(r => r.month),
                                datasets: [
                                    { label: 'Invoiced', data: rows.map(r => r.total_invoiced), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true },
                                    { label: 'Paid', data: rows.map(r => r.total_paid), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', tension: 0.3, fill: true }
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'forecasting' && window.__arAgingData && document.getElementById('arAgingChart')) {
                    __statsChartsInit.forecasting = true;
                    const rows = window.__arAgingData;
                    new Chart(document.getElementById('arAgingChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.amount), backgroundColor: rows.map(r => r.color) }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                    });
                }
                if (target === 'expenses') {
                    __statsChartsInit.expenses = true;
                    if (window.__expenseCategoryData && document.getElementById('expenseCategoryChart')) {
                        const rows = window.__expenseCategoryData;
                        new Chart(document.getElementById('expenseCategoryChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.total), backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#f97316', '#06b6d4', '#ec4899', '#84cc16', '#6b7280'] }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
                        });
                    }
                    if (window.__expenseTrendData && document.getElementById('expenseTrendChart')) {
                        const rows = window.__expenseTrendData;
                        new Chart(document.getElementById('expenseTrendChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: rows.map(r => r.month), datasets: [{ label: 'Expenses', data: rows.map(r => r.total), backgroundColor: '#ef4444' }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'tax' && window.__taxMonthlyData && document.getElementById('taxMonthlyChart')) {
                    __statsChartsInit.tax = true;
                    const rows = window.__taxMonthlyData;
                    if (rows.length) {
                        new Chart(document.getElementById('taxMonthlyChart').getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: rows.map(r => r.month),
                                datasets: [
                                    { label: 'Invoiced', data: rows.map(r => r.total_invoiced), backgroundColor: '#3b82f6' },
                                    { label: 'Paid', data: rows.map(r => r.total_paid), backgroundColor: '#10b981' }
                                ]
                            },
                            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
                        });
                    }
                }
                if (target === 'clients' && window.__topClientsData && document.getElementById('topClientsChart')) {
                    __statsChartsInit.clients = true;
                    const rows = window.__topClientsData;
                    new Chart(document.getElementById('topClientsChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.name), datasets: [{ label: 'Paid Revenue', data: rows.map(r => r.revenue), backgroundColor: '#10b981' }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                    });
                }
                if (target === 'activity' && window.__activeClientsData && document.getElementById('activeClientsChart')) {
                    __statsChartsInit.activity = true;
                    const rows = window.__activeClientsData;
                    new Chart(document.getElementById('activeClientsChart').getContext('2d'), {
                        type: 'bar',
                        data: { labels: rows.map(r => r.name), datasets: [{ label: 'Invoices', data: rows.map(r => r.count), backgroundColor: '#3b82f6' }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });
                }
                if (target === 'system') {
                    __statsChartsInit.system = true;
                    if (window.__emailHealthData && document.getElementById('emailHealthChart')) {
                        const d = window.__emailHealthData;
                        new Chart(document.getElementById('emailHealthChart').getContext('2d'), {
                            type: 'doughnut',
                            data: { labels: ['Sent', 'Failed'], datasets: [{ data: [d.sent, d.failed], backgroundColor: ['#10b981', '#ef4444'] }] },
                            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '65%' }
                        });
                    }
                    if (window.__storageFootprintData && document.getElementById('storageFootprintChart')) {
                        const d = window.__storageFootprintData;
                        new Chart(document.getElementById('storageFootprintChart').getContext('2d'), {
                            type: 'bar',
                            data: { labels: [d.labels.db, d.labels.invoices, d.labels.backups], datasets: [{ data: [d.db, d.invoices, d.backups], backgroundColor: ['#3b82f6', '#10b981', '#f59e0b'] }] },
                            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                        });
                    }
                }
            }
            function navStats(target) {
                document.querySelectorAll('#sec-stats .subnav-item, .nav-subnav-slot[data-for="stats"] .subnav-item').forEach(el => el.classList.toggle('active', el.dataset.statsTarget === target));
                document.querySelectorAll('#sec-stats .subnav-pane').forEach(el => el.classList.toggle('active', el.id === 'stats-pane-' + target));
                localStorage.setItem('statsSubTab', target);
                initStatsChartsFor(target);
            }
            // Card order/width (applyStatsLayouts) is applied before the pane is
            // first shown, so there's no flash of the default order/width.
            applyStatsLayouts();
            initStatsDragDrop();
            const storedStatsTab = localStorage.getItem('statsSubTab');
            if (storedStatsTab && document.getElementById('stats-pane-' + storedStatsTab)) navStats(storedStatsTab);
            else initStatsChartsFor('revenue');

            // Dashboard's own cards, already in the DOM at this point — see the
            // comment above applyDashboardLayouts() further down.
            applyDashboardLayouts();
            initDashboardDragDrop();

            // Statistics cards: drag-to-reorder (including between columns, or
            // into an empty one) and half/full width, saved per-user to
            // invoxa_stats_layout (see save_stats_layout / renderStatsSection()).
            //
            // Server markup for a pane (`.stats-columns[data-stats-pane]`) is a
            // flat list of cards. layoutStatsPane() turns that into real layout:
            // it walks the cards in document order and groups consecutive
            // half-width ones into a `.stats-col-row` of two independent
            // `.stats-col` flex columns (so one short card never gets stretched
            // to match a tall neighbor — the gap bug a shared-row grid or
            // CSS-multicolumn balance both caused); a full-width card ends any
            // run and renders as its own direct child of .stats-columns, which
            // stretches it full-width for free via that container's own
            // flex-direction: column. Every half-width card carries an explicit
            // data-card-col ("0" or "1") recording which .stats-col it's in —
            // set from the saved layout, from a drag, or (first time) by
            // alternating within its run — so layoutStatsPane can be re-run at
            // any time (after a drag, a width toggle, or a fresh load) and
            // reproduce the same structure.
            function layoutStatsPane(container) {
                const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                container.querySelectorAll(':scope > .stats-col-row').forEach(row => row.remove());
                cards.forEach(c => c.remove());
                let cols = null, runIndex = 0;
                cards.forEach(card => {
                    if (card.dataset.cardWidth === '2') {
                        cols = null;
                        runIndex = 0;
                        container.appendChild(card);
                        return;
                    }
                    if (!cols) {
                        const row = document.createElement('div');
                        row.className = 'stats-col-row';
                        cols = [0, 1].map(i => {
                            const col = document.createElement('div');
                            col.className = 'stats-col';
                            col.dataset.col = String(i);
                            row.appendChild(col);
                            return col;
                        });
                        container.appendChild(row);
                    }
                    const colIdx = card.dataset.cardCol === '0' || card.dataset.cardCol === '1'
                        ? Number(card.dataset.cardCol)
                        : runIndex % 2;
                    card.dataset.cardCol = String(colIdx);
                    cols[colIdx].appendChild(card);
                    runIndex++;
                });
            }

            function applyStatsLayouts() {
                const dataEl = document.getElementById('statsLayoutData');
                let layouts = {};
                if (dataEl) {
                    try { layouts = JSON.parse(dataEl.dataset.layouts || '{}'); } catch (e) { layouts = {}; }
                }
                document.querySelectorAll('#sec-stats .stats-columns[data-stats-pane]').forEach(container => {
                    const pane = container.dataset.statsPane;
                    const saved = layouts[pane] || [];
                    const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                    const byId = new Map(cards.map(c => [c.dataset.cardId, c]));
                    const seen = new Set();
                    const order = [];
                    saved.forEach(entry => {
                        const el = byId.get(entry.id);
                        if (el && !seen.has(entry.id)) {
                            el.dataset.cardWidth = entry.width === 2 ? '2' : '1';
                            if (entry.col === 0 || entry.col === 1) el.dataset.cardCol = String(entry.col);
                            updateCardWidthIcon(el);
                            order.push(el);
                            seen.add(entry.id);
                        }
                    });
                    cards.forEach(c => { if (!seen.has(c.dataset.cardId)) order.push(c); });
                    order.forEach(el => container.appendChild(el));
                    layoutStatsPane(container);
                });
            }

            function updateCardWidthIcon(cardEl) {
                const icon = cardEl.querySelector(':scope > .card-drag-controls .card-width-toggle i');
                if (!icon) return;
                icon.className = cardEl.dataset.cardWidth === '2' ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
            }

            function toggleStatsCardWidth(btn) {
                const cardEl = btn.closest('.card[data-card-id]');
                if (!cardEl) return;
                const container = cardEl.closest('.stats-columns[data-stats-pane]');
                cardEl.dataset.cardWidth = cardEl.dataset.cardWidth === '2' ? '1' : '2';
                updateCardWidthIcon(cardEl);
                layoutStatsPane(container);
                saveStatsLayout(container);
            }

            function saveStatsLayout(container) {
                if (!container) return;
                const pane = container.dataset.statsPane;
                const layout = Array.from(container.querySelectorAll('.card[data-card-id]')).map(c => ({
                    id: c.dataset.cardId,
                    width: c.dataset.cardWidth === '2' ? 2 : 1,
                    col: c.dataset.cardCol === '1' ? 1 : 0
                }));
                fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_stats_layout', pane, layout: JSON.stringify(layout) }) }).catch(() => { });
            }

            // ── Dashboard's own two customizable regions ───────────────────────
            // The "flash card" stat tidbits (.dashboard-tidbit-row) and the chart
            // row (.dashboard-chart-row) each get simple drag-to-reorder, saved
            // per-user under the 'dashboard-tidbits'/'dashboard-charts' panes in
            // invoxa_stats_layout (see save_stats_layout / renderDashboardStats()).
            // Deliberately its own code, not a rename of Statistics' layoutStatsPane/
            // initStatsDragDrop/etc above — the layout rules differ (a flat 4-wide
            // grid vs. a 6-unit span grid, vs. Statistics' 2-column pairing), and
            // keeping them separate means nothing here can change how Statistics
            // behaves.
            function applyDashboardLayouts() {
                const dataEl = document.getElementById('dashboardLayoutData');
                let layouts = {};
                if (dataEl) {
                    try { layouts = JSON.parse(dataEl.dataset.layouts || '{}'); } catch (e) { layouts = {}; }
                }
                document.querySelectorAll('[data-dash-pane]').forEach(container => {
                    const pane = container.dataset.dashPane;
                    const saved = layouts[pane] || [];
                    const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                    const byId = new Map(cards.map(c => [c.dataset.cardId, c]));
                    const seen = new Set();
                    const order = [];
                    saved.forEach(entry => {
                        const el = byId.get(entry.id);
                        if (el && !seen.has(entry.id)) {
                            if (el.dataset.cardWidth !== undefined && entry.width) el.dataset.cardWidth = String(entry.width);
                            el.dataset.cardHidden = entry.hidden ? '1' : '0';
                            updateDashboardCardWidthLabel(el);
                            order.push(el);
                            seen.add(entry.id);
                        }
                    });
                    cards.forEach(c => { if (!seen.has(c.dataset.cardId)) order.push(c); });
                    order.forEach(el => container.appendChild(el));
                    layoutDashboardChartRow(container);
                });
            }

            // Chart cards carry their column span (2=1/3, 3=1/2, 4=2/3, 6=full) directly
            // in data-card-width and grid-column, so laying out the row after a
            // reorder/resize is just re-applying that to each card in its new order
            // — the CSS grid (see .dashboard-chart-row in page_head.php) handles
            // wrapping combinations onto their own row on its own.
            function layoutDashboardChartRow(container) {
                if (!container.classList.contains('dashboard-chart-row')) return;
                container.querySelectorAll('.card[data-card-id]').forEach(c => {
                    c.style.gridColumn = 'span ' + (c.dataset.cardWidth || '3');
                });
            }

            function updateDashboardCardWidthLabel(cardEl) {
                const label = cardEl.querySelector(':scope > .card-drag-controls .card-width-toggle .width-label');
                if (label) label.textContent = DASH_WIDTH_LABEL[cardEl.dataset.cardWidth] || '1/2';
            }

            function toggleDashboardChartWidth(btn) {
                const cardEl = btn.closest('.card[data-card-id]');
                if (!cardEl) return;
                const container = cardEl.closest('[data-dash-pane]');
                cardEl.dataset.cardWidth = DASH_WIDTH_CYCLE[cardEl.dataset.cardWidth] || '3';
                updateDashboardCardWidthLabel(cardEl);
                layoutDashboardChartRow(container);
                saveDashboardLayout(container);
            }

            // Hides a card in place (kept in the DOM, just display:none via
            // [data-card-hidden="1"], see page_head.php) — re-shown from the
            // Customize menu (renderDashboardWidgetMenu below), not the card itself.
            function hideDashboardCard(btn) {
                const cardEl = btn.closest('.card[data-card-id]');
                if (!cardEl) return;
                const container = cardEl.closest('[data-dash-pane]');
                cardEl.dataset.cardHidden = '1';
                saveDashboardLayout(container);
                refreshDashboardWidgetMenuIfOpen();
            }

            function saveDashboardLayout(container) {
                if (!container) return;
                const pane = container.dataset.dashPane;
                const layout = Array.from(container.querySelectorAll('.card[data-card-id]')).map(c => ({
                    id: c.dataset.cardId,
                    width: parseInt(c.dataset.cardWidth || '0', 10) || 0,
                    hidden: c.dataset.cardHidden === '1'
                }));
                fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_stats_layout', pane, layout: JSON.stringify(layout) }) }).catch(() => { });
            }

            // "Customize" popover — lists every card in both dashboard regions
            // (built from the DOM, not hardcoded, so it can never drift from
            // renderDashboardStats()), grouped under a heading per region, each
            // with a checkbox toggling data-card-hidden.
            function toggleDashboardWidgetMenu() {
                const menu = document.getElementById('dashboardWidgetMenu');
                if (!menu) return;
                if (menu.hidden) {
                    renderDashboardWidgetMenu();
                    menu.hidden = false;
                } else {
                    menu.hidden = true;
                }
            }

            // A pane can cap how many of its cards may be visible at once (see
            // data-max-visible on .dashboard-tidbit-row — DASHBOARD_TIDBIT_VISIBLE_MAX
            // in stats.php) — once that many are checked, the remaining unchecked
            // boxes disable until one is unchecked to free a slot, so the fixed-width
            // tidbit row never has to grow.
            function renderDashboardWidgetMenu() {
                const menu = document.getElementById('dashboardWidgetMenu');
                if (!menu) return;
                menu.innerHTML = '';
                [
                    { label: 'Stat Cards', pane: 'dashboard-tidbits' },
                    { label: 'Charts', pane: 'dashboard-charts' },
                ].forEach(g => {
                    const container = document.querySelector('#sec-dashboard [data-dash-pane="' + g.pane + '"]');
                    if (!container) return;
                    const heading = document.createElement('div');
                    heading.className = 'widget-manage-menu-heading';
                    heading.textContent = g.label;
                    menu.appendChild(heading);
                    const maxVisible = parseInt(container.dataset.maxVisible || '0', 10) || 0;
                    const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                    const visibleCount = cards.filter(c => c.dataset.cardHidden !== '1').length;
                    cards.forEach(c => {
                        const isVisible = c.dataset.cardHidden !== '1';
                        const label = document.createElement('label');
                        label.className = 'widget-manage-menu-item';
                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.checked = isVisible;
                        if (maxVisible > 0 && !isVisible && visibleCount >= maxVisible) {
                            cb.disabled = true;
                            label.classList.add('widget-manage-menu-item-disabled');
                        }
                        cb.addEventListener('change', () => {
                            c.dataset.cardHidden = cb.checked ? '0' : '1';
                            saveDashboardLayout(container);
                            renderDashboardWidgetMenu();
                        });
                        label.appendChild(cb);
                        label.appendChild(document.createTextNode(c.dataset.cardLabel || c.dataset.cardId));
                        menu.appendChild(label);
                    });
                    if (maxVisible > 0) {
                        const hint = document.createElement('div');
                        hint.className = 'widget-manage-menu-hint';
                        hint.textContent = 'Up to ' + maxVisible + ' at a time — uncheck one to swap in another.';
                        menu.appendChild(hint);
                    }
                });
            }

            function refreshDashboardWidgetMenuIfOpen() {
                const menu = document.getElementById('dashboardWidgetMenu');
                if (menu && !menu.hidden) renderDashboardWidgetMenu();
            }

            document.addEventListener('click', e => {
                const menu = document.getElementById('dashboardWidgetMenu');
                if (!menu || menu.hidden) return;
                if (!e.target.closest('.widget-manage')) menu.hidden = true;
            });

            // Same FLIP technique as Statistics' flipStatsMove below, duplicated
            // here rather than shared so Dashboard's drag code has no dependency
            // on Statistics' — see the comment above applyDashboardLayouts.
            function flipDashboardMove(container, mutate) {
                const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                const before = new Map(cards.map(c => [c, c.getBoundingClientRect()]));
                mutate();
                cards.forEach(c => {
                    const a = before.get(c);
                    const b = c.getBoundingClientRect();
                    const dx = a.left - b.left, dy = a.top - b.top;
                    if (!dx && !dy) return;
                    c.style.transition = 'none';
                    c.style.transform = `translate(${dx}px, ${dy}px)`;
                    requestAnimationFrame(() => {
                        c.style.transition = 'transform 0.16s ease';
                        c.style.transform = '';
                    });
                });
            }

            let __dashDragCard = null;
            let __dashDragRaf = null;
            function initDashboardDragDrop() {
                document.querySelectorAll('[data-dash-pane]').forEach(container => {
                    if (container.dataset.dragBound) return;
                    container.dataset.dragBound = '1';
                    container.addEventListener('dragstart', e => {
                        const cardEl = e.target.closest('.card[data-card-id]');
                        if (!cardEl) return;
                        __dashDragCard = cardEl;
                        cardEl.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    container.addEventListener('dragover', e => {
                        if (!__dashDragCard) return;
                        e.preventDefault();
                        if (__dashDragRaf) return;
                        const targetEl = e.target, clientX = e.clientX, clientY = e.clientY;
                        __dashDragRaf = requestAnimationFrame(() => {
                            __dashDragRaf = null;
                            positionDashboardDragCard(container, targetEl, clientX, clientY);
                        });
                    });
                    container.addEventListener('dragend', () => {
                        if (!__dashDragCard) return;
                        if (__dashDragRaf) { cancelAnimationFrame(__dashDragRaf); __dashDragRaf = null; }
                        __dashDragCard.classList.remove('dragging');
                        saveDashboardLayout(container);
                        __dashDragCard = null;
                    });
                });
            }

            // Both dashboard rows can have multiple cards side by side on the same
            // line (the tidbit row always does; the chart row does whenever two
            // cards' spans sum to <= 6), so before/after has to be decided on
            // whichever axis the pointer is actually off-center on, not just Y like
            // Statistics' single-column-flow positionStatsDragCard.
            function positionDashboardDragCard(container, targetEl, clientX, clientY) {
                if (!__dashDragCard) return;
                const overCard = targetEl.closest('.card[data-card-id]');
                if (!overCard || overCard === __dashDragCard) return;
                const rect = overCard.getBoundingClientRect();
                const relX = (clientX - rect.left) / rect.width;
                const relY = (clientY - rect.top) / rect.height;
                const rel = Math.abs(relX - 0.5) >= Math.abs(relY - 0.5) ? relX : relY;
                if (rel >= 0.4 && rel <= 0.6) return;
                const before = rel < 0.4;
                flipDashboardMove(container, () => {
                    overCard.parentNode.insertBefore(__dashDragCard, before ? overCard : overCard.nextSibling);
                    layoutDashboardChartRow(container);
                });
            }

            // FLIP (First-Last-Invert-Play): snapshot every card's position,
            // run the DOM mutation, then for anything that moved, jump it back
            // to where it visually was via a transform and animate that
            // transform away — turns an instant DOM reorder into a glide.
            function flipStatsMove(container, mutate) {
                const cards = Array.from(container.querySelectorAll('.card[data-card-id]'));
                const before = new Map(cards.map(c => [c, c.getBoundingClientRect()]));
                mutate();
                cards.forEach(c => {
                    const a = before.get(c);
                    const b = c.getBoundingClientRect();
                    const dx = a.left - b.left, dy = a.top - b.top;
                    if (!dx && !dy) return;
                    c.style.transition = 'none';
                    c.style.transform = `translate(${dx}px, ${dy}px)`;
                    requestAnimationFrame(() => {
                        c.style.transition = 'transform 0.16s ease';
                        c.style.transform = '';
                    });
                });
            }

            let __statsDragCard = null;
            let __statsDragRaf = null;
            function initStatsDragDrop() {
                document.querySelectorAll('#sec-stats .stats-columns[data-stats-pane]').forEach(container => {
                    if (container.dataset.dragBound) return;
                    container.dataset.dragBound = '1';
                    container.addEventListener('dragstart', e => {
                        const cardEl = e.target.closest('.card[data-card-id]');
                        if (!cardEl) return;
                        __statsDragCard = cardEl;
                        cardEl.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                    });
                    container.addEventListener('dragover', e => {
                        if (!__statsDragCard) return;
                        e.preventDefault();
                        // Coalesce to at most one reorder per animation frame —
                        // dragover can fire far faster than that, and doing a
                        // DOM move on every single event is what made this feel
                        // jerky rather than smooth.
                        if (__statsDragRaf) return;
                        const targetEl = e.target, clientY = e.clientY;
                        __statsDragRaf = requestAnimationFrame(() => {
                            __statsDragRaf = null;
                            positionStatsDragCard(container, targetEl, clientY);
                        });
                    });
                    container.addEventListener('dragend', () => {
                        if (!__statsDragCard) return;
                        if (__statsDragRaf) { cancelAnimationFrame(__statsDragRaf); __statsDragRaf = null; }
                        __statsDragCard.classList.remove('dragging');
                        flipStatsMove(container, () => layoutStatsPane(container));
                        saveStatsLayout(container);
                        __statsDragCard = null;
                    });
                });
            }

            function positionStatsDragCard(container, targetEl, clientY) {
                if (!__statsDragCard) return;
                const overCard = targetEl.closest('.card[data-card-id]');
                if (overCard && overCard !== __statsDragCard) {
                    // Columns flow top-to-bottom, so "before/after" is decided by
                    // which part of the hovered card the pointer is over — a
                    // dead zone around the middle (rather than a hard 50/50
                    // split) stops the decision flip-flopping (and the card
                    // jittering back and forth) when the pointer sits right on
                    // the boundary between two cards.
                    const rect = overCard.getBoundingClientRect();
                    const rel = (clientY - rect.top) / rect.height;
                    if (rel >= 0.4 && rel <= 0.6) return;
                    const before = rel < 0.4;
                    const col = overCard.closest('.stats-col');
                    flipStatsMove(container, () => {
                        if (__statsDragCard.dataset.cardWidth !== '2' && col) __statsDragCard.dataset.cardCol = col.dataset.col;
                        overCard.parentNode.insertBefore(__statsDragCard, before ? overCard : overCard.nextSibling);
                    });
                    return;
                }
                // Hovering an empty .stats-col (e.g. the other column when it has
                // no cards of its own) rather than a card — drop straight into
                // it, which is what lets a card move to an otherwise-empty
                // column.
                const col = targetEl.closest('.stats-col');
                if (col && __statsDragCard.dataset.cardWidth !== '2' && __statsDragCard.parentElement !== col) {
                    flipStatsMove(container, () => {
                        __statsDragCard.dataset.cardCol = col.dataset.col;
                        col.appendChild(__statsDragCard);
                    });
                    return;
                }
                if (!overCard && !col && __statsDragCard.parentElement !== container) {
                    flipStatsMove(container, () => container.appendChild(__statsDragCard));
                }
            }

            function toggleToolbar(name) {
                const wrap = document.getElementById(name + 'ToolbarGroups');
                const btn = document.getElementById(name + 'ToolbarToggle');
                if (!wrap) return;
                const isExpanded = wrap.classList.toggle('expanded');
                if (btn) btn.classList.toggle('expanded', isExpanded);
            }

            const subnavSections = ['stats', 'docs', 'backup', 'settings'];
            const mobileMq = window.matchMedia('(max-width: 860px)');
            function placeSubnavs(isMobile) {
                subnavSections.forEach(name => {
                    const subnavEl = document.querySelector('#sec-' + name + ' .subnav, .nav-subnav-slot[data-for="' + name + '"] .subnav');
                    const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                    const layoutEl = document.querySelector('#sec-' + name + ' .subnav-layout');
                    if (!subnavEl || !slotEl || !layoutEl) return;
                    if (isMobile) {
                        slotEl.replaceChildren(subnavEl);
                    } else {
                        layoutEl.insertBefore(subnavEl, layoutEl.firstChild);
                        slotEl.classList.remove('expanded');
                        const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                        if (toggleEl) toggleEl.classList.remove('expanded');
                    }
                });
            }
            placeSubnavs(mobileMq.matches);
            mobileMq.addEventListener('change', e => placeSubnavs(e.matches));

            function toggleNavSubnav(name) {
                const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                const isExpanded = slotEl.classList.toggle('expanded');
                if (toggleEl) toggleEl.classList.toggle('expanded', isExpanded);
            }

            subnavSections.forEach(name => {
                const slotEl = document.querySelector('.nav-subnav-slot[data-for="' + name + '"]');
                if (!slotEl) return;
                slotEl.addEventListener('click', e => {
                    if (e.target.closest('.subnav-item')) {
                        nav(name, true);
                        slotEl.classList.remove('expanded');
                        const toggleEl = document.querySelector('.nav-item[data-target="' + name + '"] .nav-subnav-toggle');
                        if (toggleEl) toggleEl.classList.remove('expanded');
                    }
                }, true);
            });

            // A function, not a cached value — re-reads localStorage on every table
            // (re)build so a changed Default Page Size setting applies on the next
            // tab visit instead of requiring a hard refresh.
            const tblEmptyMessages = {
                invoices: 'No invoices yet — create one to get started.',
                clients: 'No clients yet — add your first client to get started.',
                quotes: 'No quotes yet — save one as a quote instead of sending it.',
                expenses: 'No expenses logged yet.',
            };
            function getTblOpts(which) {
                const preferredPageSize = parseInt(localStorage.getItem('invoxa_table_page_size'), 10) || 12;
                return { searchable: true, fixedHeight: false, destroyable: true, perPage: preferredPageSize, perPageSelect: [12, 30, 50, 99999], labels: { noRows: tblEmptyMessages[which] || 'No entries found', searchLabel: '' } };
            }
            const dataTables = {};
            if (document.getElementById("invoicesTable")) dataTables.invoices = new simpleDatatables.DataTable("#invoicesTable", getTblOpts('invoices'));
            if (document.getElementById("clientsTable")) dataTables.clients = new simpleDatatables.DataTable("#clientsTable", getTblOpts('clients'));
            if (document.getElementById("quotesTable")) dataTables.quotes = new simpleDatatables.DataTable("#quotesTable", getTblOpts('quotes'));
            if (document.getElementById("expensesTable")) dataTables.expenses = new simpleDatatables.DataTable("#expensesTable", getTblOpts('expenses'));
            setTimeout(() => { document.querySelectorAll('.datatable-selector option').forEach(opt => { if (opt.value == "99999") opt.textContent = "All"; }); }, 100);

            // Background refresh for the Invoices/Clients/Quotes tabs (see nav() above) —
            // fetches the tab's <tr> rows from ?api=table_html, swaps them in, and
            // reinitializes the DataTable plugin (destroy+recreate, same as first init).
            async function refreshTable(which) {
                const tbody = tabTbody(which);
                if (!tbody) return;
                const cardEl = tbody.closest('.card');
                if (cardEl) cardEl.classList.add('table-refreshing');
                try {
                    const res = await fetch('?api=table_html&which=' + which);
                    const html = await res.text();
                    if (dataTables[which]) dataTables[which].destroy();
                    tabTbody(which).innerHTML = html;
                    dataTables[which] = new simpleDatatables.DataTable('#' + which + 'Table', getTblOpts(which));
                    document.querySelectorAll('#sec-' + which + ' .datatable-selector option').forEach(opt => { if (opt.value == "99999") opt.textContent = "All"; });
                } catch (e) {
                    // Silent by design — a failed background refresh leaves the existing,
                    // still-valid (if slightly stale) table in place rather than surfacing
                    // an error for a refresh the user didn't explicitly wait on.
                } finally {
                    if (cardEl) cardEl.classList.remove('table-refreshing');
                }
            }

            // Refreshes the alert strips, top stat cards, charts, and Recent Activity
            // list, and forces the chart to refetch (bypassing initChart's cache via
            // `force`). The stat/chart cards' canvases are part of the innerHTML swap
            // (they're drag-reorderable alongside the stat cards, see
            // renderDashboardStats()), so their Chart.js instances are orphaned —
            // renderChart() already destroys the previous instance before creating a
            // new one against the fresh canvas, so nothing extra is needed here.
            async function refreshDashboard() {
                document.querySelectorAll('#dashboardStatsWrap .stat-card').forEach(el => el.classList.add('stats-loading'));
                try {
                    const [statsHtml, activityHtml] = await Promise.all([
                        fetch('?api=table_html&which=dashboard_stats').then(r => r.text()),
                        fetch('?api=table_html&which=activity').then(r => r.text()),
                    ]);
                    document.getElementById('dashboardStatsWrap').innerHTML = statsHtml;
                    document.getElementById('activityTbody').innerHTML = activityHtml;
                    applyDashboardLayouts();
                    initDashboardDragDrop();
                    const menu = document.getElementById('dashboardWidgetMenu');
                    if (menu) menu.hidden = true;
                    initChart(true);
                    animateStatCards();
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }

            // Statistics and Sync tabs are read-only content with no DataTable-managed
            // tables, so refreshing just swaps the tab body's innerHTML wholesale.
            async function refreshStatsSection() {
                try {
                    const html = await fetch('?api=table_html&which=stats_section').then(r => r.text());
                    document.getElementById('sec-stats').innerHTML = html;
                    // Fresh canvases means old Chart.js instances are orphaned. The
                    // <script> tags in the fetched HTML don't execute via innerHTML, so
                    // window.__*Data isn't refreshed — charts re-created here show stale
                    // data, which is fine for a background poll no one is watching live.
                    __statsChartsInit = {};
                    // The fresh markup defaults to its first sub-tab — reapply the last-selected one.
                    const stored = localStorage.getItem('statsSubTab');
                    if (stored && document.getElementById('stats-pane-' + stored)) navStats(stored);
                    else initStatsChartsFor('revenue');
                    placeSubnavs(mobileMq.matches);
                    applyStatsLayouts();
                    initStatsDragDrop();
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }
            async function refreshSync() {
                const paneEl = document.getElementById('backup-pane-sync');
                paneEl.classList.add('table-refreshing');
                try {
                    const html = await fetch('?api=table_html&which=sync_section').then(r => r.text());
                    paneEl.innerHTML = html;
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                } finally {
                    paneEl.classList.remove('table-refreshing');
                }
            }
            async function refreshAuditSection() {
                try {
                    const html = await fetch('?api=table_html&which=audit_section').then(r => r.text());
                    document.getElementById('sec-audit').innerHTML = html;
                } catch (e) {
                    // Silent by design, same reasoning as refreshTable() above.
                }
            }
            // Client-side show/hide over whichever timeline items are currently loaded
            // (100 per page — see loadMoreAuditRows()). data-search is a pre-lowercased
            // blob (client name + invoice # + type + notes) baked in server-side per
            // item; data-action-type backs the dropdown since "Overdue" etc. aren't
            // literal stored values, same as the Invoices status filter. Search/filter
            // only ever covers what's been loaded, not the whole audit log.
            function filterAuditLog() {
                const q = document.getElementById('auditSearchInput').value.trim().toLowerCase();
                const type = document.getElementById('auditTypeFilter').value;
                const items = document.querySelectorAll('#auditTimelineBody .timeline-item');
                let visible = 0;
                items.forEach(item => {
                    const show = (!type || item.dataset.actionType === type) && (!q || item.dataset.search.includes(q));
                    item.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                const noResults = document.getElementById('auditNoResults');
                if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
            }
            async function loadMoreAuditRows() {
                const container = document.getElementById('auditTimelineBody');
                const offset = parseInt(container.dataset.nextOffset || '0', 10);
                const btn = document.getElementById('auditLoadMoreBtn');
                const wrap = document.getElementById('auditLoadMoreWrap');
                const originalLabel = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
                try {
                    const json = await fetch('?api=table_html&which=audit_items&offset=' + offset).then(r => r.json());
                    document.getElementById('auditNoResults').insertAdjacentHTML('beforebegin', json.html);
                    container.dataset.nextOffset = json.nextOffset;
                    container.dataset.hasMore = json.hasMore ? '1' : '0';
                    wrap.style.display = json.hasMore ? '' : 'none';
                    filterAuditLog();
                } catch (e) {
                    showToast('Failed to load more audit entries', true);
                }
                btn.disabled = false; btn.innerHTML = originalLabel;
            }
            // Exports whatever's currently loaded and passing the search/type filter —
            // same "export what's visible" convention as bulkExportExpensesCsv() etc.,
            // just reading .timeline-item cells instead of table rows/checkboxes.
            function exportAuditLogCsv() {
                const items = Array.from(document.querySelectorAll('#auditTimelineBody .timeline-item')).filter(item => item.style.display !== 'none');
                if (!items.length) { showToast('No audit rows to export', true); return; }
                const rows = [['Time', 'Type', 'Action', 'Notes', 'Performed By', 'Client']];
                items.forEach(item => {
                    const cells = item.querySelectorAll('.timeline-content > div');
                    const detailsEl = cells[2];
                    const badge = detailsEl.querySelector('span');
                    const action = (item.dataset.actionType || '').replace(/_/g, ' ');
                    const notes = (detailsEl.innerText || '').replace(badge ? badge.innerText : '', '').trim();
                    rows.push([
                        (cells[0].innerText || '').trim(),
                        (cells[1].innerText || '').trim(),
                        action,
                        notes,
                        (cells[3].innerText || '').trim(),
                        (cells[4].innerText || '').trim(),
                    ]);
                });
                const csv = rows.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'audit_log.csv'; a.click();
                URL.revokeObjectURL(url);
            }

            function closeModal(id) { document.getElementById(id).classList.remove('active'); if (id === 'noteModal' && window._notePageNeedsReload) { window._notePageNeedsReload = false; window.location.reload(); } requestAnimationFrame(() => window.dispatchEvent(new Event('resize'))); }
            // Close any modal when clicking the backdrop (outside .modal-body)
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
                    closeModal(e.target.id);
                }
            });
            function showToast(msg, isError = false) {
                const t = document.getElementById('toast');
                document.getElementById('toastMsg').textContent = msg;
                document.getElementById('toastIcon').className = 'fa-solid toast-icon ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check');
                t.className = 'toast show' + (isError ? ' error' : '');
                setTimeout(() => t.classList.remove('show'), 3000);
            }

            function animateCountUp(el) {
                const finalText = el.textContent;
                const matches = [...finalText.matchAll(/[\d,]+\.?\d*/g)];
                if (!matches.length) return;
                const duration = 1800;
                const start = performance.now();
                function frame(now) {
                    const t = Math.min(1, (now - start) / duration);
                    const eased = 1 - Math.pow(1 - t, 3);
                    let result = '', lastIndex = 0;
                    matches.forEach(m => {
                        const numStr = m[0];
                        const decimals = numStr.includes('.') ? numStr.split('.')[1].length : 0;
                        const current = parseFloat(numStr.replace(/,/g, '')) * eased;
                        result += finalText.slice(lastIndex, m.index) + current.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
                        lastIndex = m.index + numStr.length;
                    });
                    el.textContent = result + finalText.slice(lastIndex);
                    if (t < 1) requestAnimationFrame(frame); else el.textContent = finalText;
                }
                requestAnimationFrame(frame);
            }

            function animateStatCards() {
                document.querySelectorAll('#dashboardStatsWrap .stat-value').forEach(animateCountUp);
            }

            // Client CRUD
            function openClientModal(c = null) {
                document.getElementById('clientModalTitle').textContent = c ? 'Edit Client' : 'Add Client';
                document.getElementById('clientId').value = c ? c.id : '';
                document.getElementById('clientName').value = c ? c.client_name : '';
                document.getElementById('clientEmail').value = c ? c.email : '';
                document.getElementById('clientPhone').value = c ? (c.phone || '') : '';
                document.getElementById('clientAddress').value = c ? (c.address || '') : '';
                document.getElementById('clientRate').value = c ? c.monthly_rate : '0.00';
                document.getElementById('clientCurrency').value = c ? (c.currency || '') : '';
                document.getElementById('clientBillingFrequency').value = c ? c.billing_frequency : 'monthly';
                document.getElementById('clientPaymentTerms').value = c ? c.payment_terms_days : '21';
                document.getElementById('clientDiscountPct').value = c ? c.discount_pct : '0';
                document.getElementById('clientTaxRate').value = c ? c.tax_rate : '0';
                document.getElementById('clientAccName').value = c ? c.account_name : '';
                document.getElementById('clientAccNum').value = c ? c.account_number : '';
                document.getElementById('clientActive').checked = c ? c.is_active == 1 : true;
                document.getElementById('clientTest').checked = c ? c.is_test == 1 : false;
                // Portal section only makes sense once the client actually exists (a
                // token needs a client id to attach to) — hidden entirely on Add Client.
                document.getElementById('clientPortalSection').style.display = c ? '' : 'none';
                if (c && c.portal_token) {
                    document.getElementById('clientPortalUrl').value = window.location.origin + '/?portal=' + c.portal_token;
                    document.getElementById('clientPortalExpiryNote').textContent = c.portal_token_expires_at
                        ? 'Expires ' + new Date(c.portal_token_expires_at.replace(' ', 'T')).toLocaleDateString() : 'Never expires';
                    document.getElementById('clientPortalNoLinkWrap').style.display = 'none';
                    document.getElementById('clientPortalLinkWrap').style.display = '';
                } else {
                    document.getElementById('clientPortalNoLinkWrap').style.display = '';
                    document.getElementById('clientPortalLinkWrap').style.display = 'none';
                }
                document.getElementById('clientModal').classList.add('active');
            }
            async function generatePortalLink() {
                const id = document.getElementById('clientId').value;
                if (!id) return;
                const hasLink = document.getElementById('clientPortalLinkWrap').style.display !== 'none';
                const expiry = document.getElementById(hasLink ? 'clientPortalRegenExpiry' : 'clientPortalExpiry').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'generate_portal_token', id, expiry }) });
                const json = await res.json();
                if (!json.success) return showToast(json.error || 'Failed to generate link', true);
                document.getElementById('clientPortalUrl').value = window.location.origin + '/?portal=' + json.token;
                const labels = { never: 'Never expires', '30': 'Expires in 30 days', '90': 'Expires in 90 days', '365': 'Expires in 1 year' };
                document.getElementById('clientPortalExpiryNote').textContent = labels[expiry] || '';
                document.getElementById('clientPortalNoLinkWrap').style.display = 'none';
                document.getElementById('clientPortalLinkWrap').style.display = '';
                showToast('Portal link generated!');
            }
            async function revokePortalLink() {
                const id = document.getElementById('clientId').value;
                if (!id) return;
                if (!confirm('Revoke this client\'s portal link? The old link will stop working immediately.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'revoke_portal_token', id }) });
                const json = await res.json();
                if (!json.success) return showToast(json.error || 'Failed to revoke link', true);
                document.getElementById('clientPortalNoLinkWrap').style.display = '';
                document.getElementById('clientPortalLinkWrap').style.display = 'none';
                showToast('Portal link revoked.');
            }
            function copyPortalLink() {
                const input = document.getElementById('clientPortalUrl');
                input.select();
                navigator.clipboard ? navigator.clipboard.writeText(input.value).then(() => showToast('Link copied!')) : document.execCommand('copy');
            }
            async function saveClient() {
                if (!document.getElementById('clientName').value.trim()) return showToast('Client name is required', true);
                const btn = document.getElementById('saveClientBtn'); btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_client', id: document.getElementById('clientId').value, client_name: document.getElementById('clientName').value,
                    email: document.getElementById('clientEmail').value, phone: document.getElementById('clientPhone').value,
                    address: document.getElementById('clientAddress').value, monthly_rate: document.getElementById('clientRate').value,
                    currency: document.getElementById('clientCurrency').value,
                    billing_frequency: document.getElementById('clientBillingFrequency').value,
                    payment_terms_days: document.getElementById('clientPaymentTerms').value,
                    discount_pct: document.getElementById('clientDiscountPct').value || '0',
                    tax_rate: document.getElementById('clientTaxRate').value || '0',
                    account_name: document.getElementById('clientAccName').value, account_number: document.getElementById('clientAccNum').value,
                    is_active: document.getElementById('clientActive').checked ? 1 : 0, is_test: document.getElementById('clientTest').checked ? 1 : 0
                });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Client saved!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; }
            }
            async function deleteClient(id) {
                if (!confirm("Are you sure you want to delete this client?")) return;
                const data = new URLSearchParams({ action: 'delete_client', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Client deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }
            function openExpenseModal(e = null) {
                document.getElementById('expenseModalTitle').textContent = e ? 'Edit Expense' : 'Add Expense';
                document.getElementById('expenseId').value = e ? e.id : '';
                document.getElementById('expenseDate').value = e ? e.expense_date.substring(0, 10) : new Date().toISOString().substring(0, 10);
                document.getElementById('expenseDateIso').textContent = document.getElementById('expenseDate').value;
                document.getElementById('expenseVendor').value = e ? e.vendor : '';
                document.getElementById('expenseCategory').value = e ? e.category : 'other';
                document.getElementById('expenseAmount').value = e ? e.amount : '0.00';
                document.getElementById('expenseDescription').value = e ? (e.description || '') : '';
                document.getElementById('expenseInvoiceFiles').value = '';
                document.getElementById('expenseInvoiceFilesList').innerHTML = '';
                document.getElementById('expenseReceiptFiles').value = '';
                document.getElementById('expenseReceiptsList').innerHTML = '';
                document.getElementById('expenseOcrStatus').style.display = 'none';
                document.getElementById('expenseModal').classList.add('active');
                if (e && e.id) loadExpenseReceipts(e.id);
            }
            function _renderExpenseFileList(files, expenseId) {
                if (!files.length) return '';
                return files.map(r => {
                    const target = r.doc_type === 'invoice' ? 'receipt' : 'invoice';
                    return `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.4rem 0; border-bottom:1px solid var(--border);">
                        <a href="${r.url}" target="_blank" style="color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:0.85rem;"><i class="fa-solid fa-paperclip"></i> ${r.filename}</a>
                        <div style="display:flex; align-items:center; gap:0.5rem; white-space:nowrap;">
                            <span style="color:var(--text-secondary); font-size:0.75rem;">${_formatFileSize(r.file_size)}</span>
                            <button type="button" class="btn small" title="Move to ${target === 'invoice' ? 'Invoice' : 'Receipt'}" onclick="moveExpenseReceipt(${r.id}, ${expenseId}, '${target}')"><i class="fa-solid fa-right-left"></i></button>
                            <button type="button" class="btn small danger" onclick="deleteExpenseReceipt(${r.id}, ${expenseId})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `;
                }).join('');
            }
            async function loadExpenseReceipts(expenseId) {
                const invoiceList = document.getElementById('expenseInvoiceFilesList');
                const receiptList = document.getElementById('expenseReceiptsList');
                receiptList.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; margin:0;">Loading…</p>';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_expense_receipts', expense_id: expenseId }) });
                const json = await res.json();
                if (!json.success) { invoiceList.innerHTML = ''; receiptList.innerHTML = ''; return; }
                invoiceList.innerHTML = _renderExpenseFileList(json.receipts.filter(r => r.doc_type === 'invoice'), expenseId);
                receiptList.innerHTML = _renderExpenseFileList(json.receipts.filter(r => r.doc_type !== 'invoice'), expenseId);
            }
            async function deleteExpenseReceipt(id, expenseId) {
                if (!confirm('Delete this receipt?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_expense_receipt', id: id }) });
                const json = await res.json();
                if (json.success) { showToast('Receipt deleted!'); await loadExpenseReceipts(expenseId); refreshTable('expenses'); }
                else showToast(json.error || 'Failed to delete', true);
            }
            async function moveExpenseReceipt(id, expenseId, docType) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'move_expense_receipt', id: id, doc_type: docType }) });
                const json = await res.json();
                if (json.success) { showToast(`Moved to ${docType === 'invoice' ? 'Invoice' : 'Receipt'}!`); await loadExpenseReceipts(expenseId); }
                else showToast(json.error || 'Failed to move', true);
            }
            async function handleExpenseReceiptFilesChange() {
                const files = Array.from(document.getElementById('expenseReceiptFiles').files).filter(f => /^image\//.test(f.type));
                const statusEl = document.getElementById('expenseOcrStatus');
                if (!files.length) { statusEl.style.display = 'none'; return; }
                const vendorField = document.getElementById('expenseVendor');
                const amountField = document.getElementById('expenseAmount');
                const vendorEmpty = vendorField.value.trim() === '';
                const amountEmpty = amountField.value.trim() === '' || parseFloat(amountField.value) === 0;
                if (!vendorEmpty && !amountEmpty) { statusEl.style.display = 'none'; return; }
                statusEl.textContent = 'Reading receipt' + (files.length > 1 ? 's' : '') + '…';
                statusEl.style.display = '';
                try {
                    const results = await Promise.all(files.map(async file => {
                        const formData = new FormData();
                        formData.append('action', 'ocr_expense_receipt');
                        formData.append('file', file);
                        const res = await fetch('', { method: 'POST', body: formData });
                        return res.json();
                    }));
                    // With more than one file attached (e.g. a vendor invoice plus the
                    // actual payment receipt), prefer whichever result found a line
                    // genuinely labeled TOTAL over one that just guessed the largest
                    // number — that's the one more likely to be the real receipt.
                    const usable = results.filter(r => r.success && (r.vendor || r.amount));
                    const best = usable.find(r => r.confident) || usable[0];
                    if (best) {
                        if (vendorEmpty && best.vendor) vendorField.value = best.vendor;
                        if (amountEmpty && best.amount) amountField.value = best.amount.toFixed(2);
                        statusEl.textContent = 'Prefilled from the receipt — double-check before saving.';
                    } else {
                        const firstError = results.find(r => !r.success && r.error);
                        statusEl.textContent = firstError ? firstError.error : "Couldn't read a vendor/amount from these receipts.";
                    }
                } catch (e) {
                    statusEl.style.display = 'none';
                }
            }
            async function saveExpense() {
                if (!document.getElementById('expenseVendor').value.trim()) return showToast('Vendor is required', true);
                if (!(parseFloat(document.getElementById('expenseAmount').value) > 0)) return showToast('Amount must be greater than 0', true);
                const btn = document.getElementById('saveExpenseBtn'); btn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'save_expense');
                formData.append('id', document.getElementById('expenseId').value);
                formData.append('expense_date', document.getElementById('expenseDate').value);
                formData.append('vendor', document.getElementById('expenseVendor').value);
                formData.append('category', document.getElementById('expenseCategory').value);
                formData.append('amount', document.getElementById('expenseAmount').value);
                formData.append('description', document.getElementById('expenseDescription').value);
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (!json.success) { showToast(json.error || 'Failed to save', true); btn.disabled = false; return; }
                const filesToUpload = [
                    ...Array.from(document.getElementById('expenseInvoiceFiles').files).map(file => ({ file, docType: 'invoice' })),
                    ...Array.from(document.getElementById('expenseReceiptFiles').files).map(file => ({ file, docType: 'receipt' })),
                ];
                for (const { file, docType } of filesToUpload) {
                    const rFormData = new FormData();
                    rFormData.append('action', 'upload_expense_receipt');
                    rFormData.append('expense_id', json.id);
                    rFormData.append('doc_type', docType);
                    rFormData.append('file', file);
                    await fetch('', { method: 'POST', body: rFormData });
                }
                showToast('Expense saved!');
                setTimeout(() => window.location.reload(), 1000);
            }
            async function deleteExpense(id) {
                if (!confirm("Are you sure you want to delete this expense?")) return;
                const data = new URLSearchParams({ action: 'delete_expense', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Expense deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }

            // ── Recurring expense templates ───────────────────────────────
            function openRecurringExpenseModal(re = null) {
                document.getElementById('recurringExpenseModalTitle').textContent = re ? 'Edit Recurring Expense' : 'Add Recurring Expense';
                document.getElementById('recurringExpenseId').value = re ? re.id : '';
                document.getElementById('recurringExpenseVendor').value = re ? re.vendor : '';
                document.getElementById('recurringExpenseCategory').value = re ? re.category : 'other';
                document.getElementById('recurringExpenseAmount').value = re ? re.amount : '0.00';
                document.getElementById('recurringExpenseFrequency').value = re ? re.frequency : 'monthly';
                document.getElementById('recurringExpenseDescription').value = re ? (re.description || '') : '';
                document.getElementById('recurringExpenseModal').classList.add('active');
            }
            async function saveRecurringExpense() {
                if (!document.getElementById('recurringExpenseVendor').value.trim()) return showToast('Vendor is required', true);
                if (!(parseFloat(document.getElementById('recurringExpenseAmount').value) > 0)) return showToast('Amount must be greater than 0', true);
                const btn = document.getElementById('saveRecurringExpenseBtn'); btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_recurring_expense',
                    id: document.getElementById('recurringExpenseId').value,
                    vendor: document.getElementById('recurringExpenseVendor').value,
                    category: document.getElementById('recurringExpenseCategory').value,
                    amount: document.getElementById('recurringExpenseAmount').value,
                    frequency: document.getElementById('recurringExpenseFrequency').value,
                    description: document.getElementById('recurringExpenseDescription').value,
                });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Recurring expense saved!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to save', true); btn.disabled = false; }
            }
            async function toggleRecurringExpenseActive(id, active) {
                const data = new URLSearchParams({ action: 'toggle_recurring_expense', id: id, is_active: active ? '1' : '0' });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) showToast(active ? 'Resumed!' : 'Paused!');
                else { showToast(json.error || 'Failed to update', true); setTimeout(() => window.location.reload(), 1000); }
            }
            async function deleteRecurringExpense(id) {
                if (!confirm('Delete this recurring expense? Past expenses it already logged are not affected.')) return;
                const data = new URLSearchParams({ action: 'delete_recurring_expense', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Recurring expense deleted!'); setTimeout(() => window.location.reload(), 1000); } else showToast(json.error, true);
            }
            async function importClientsCsv(file) {
                if (!file) return;
                const input = document.getElementById('importClientsFile');
                const fd = new FormData();
                fd.append('action', 'import_clients_csv');
                fd.append('clients_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        let msg = `Imported ${json.imported} client(s)`;
                        if (json.skipped > 0) msg += `, skipped ${json.skipped}`;
                        showToast(msg + '!');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                } finally {
                    input.value = '';
                }
            }
            async function importInvoicesCsv(file) {
                if (!file) return;
                const input = document.getElementById('importInvoicesFile');
                const fd = new FormData();
                fd.append('action', 'import_invoices_csv');
                fd.append('invoices_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        let msg = `Imported ${json.imported} invoice(s)`;
                        if (json.skipped > 0) msg += `, skipped ${json.skipped}`;
                        showToast(msg + '!');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                } finally {
                    input.value = '';
                }
            }
            async function importExpensesCsv(file) {
                if (!file) return;
                const input = document.getElementById('importExpensesFile');
                const fd = new FormData();
                fd.append('action', 'import_expenses_csv');
                fd.append('expenses_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        let msg = `Imported ${json.imported} expense(s)`;
                        if (json.skipped > 0) msg += `, skipped ${json.skipped}`;
                        showToast(msg + '!');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                } finally {
                    input.value = '';
                }
            }

            // Adhoc & Recurring Billing
            const LINE_ITEM_ROW_HTML = `
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text" class="form-control li-code" placeholder="WEB01" style="font-size:0.85rem;"></td>
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="text" class="form-control li-desc" placeholder="Description" style="font-size:0.85rem;"></td>
                    <td style="padding:0 0.5rem 0.5rem 0;"><input type="number" class="form-control li-amount" step="0.01" placeholder="0.00" style="font-size:0.85rem; text-align:right;"></td>
                    <td style="padding:0 0 0.5rem 0;"><button type="button" class="btn small danger" onclick="this.closest('tr').remove()" style="padding:0.2rem 0.4rem;"><i class="fa-solid fa-xmark"></i></button></td>`;
            function addLineItem() {
                const tbody = document.getElementById('lineItemsBody');
                const tr = document.createElement('tr');
                tr.className = 'line-item-row';
                tr.innerHTML = LINE_ITEM_ROW_HTML;
                tbody.appendChild(tr);
            }
            function getLineItems() {
                const rows = document.querySelectorAll('#lineItemsBody .line-item-row');
                const items = [];
                for (const row of rows) {
                    const code = row.querySelector('.li-code').value.trim();
                    const desc = row.querySelector('.li-desc').value.trim();
                    const amount = parseFloat(row.querySelector('.li-amount').value);
                    if (!desc || isNaN(amount) || amount <= 0) continue;
                    items.push({ code: code || 'WEB01', desc, amount });
                }
                return items;
            }
            // One discount % and one tax % for the whole invoice, not per line item
            // — matches computeInvoiceTotals() server-side. Discount comes off the
            // line-item subtotal first, tax applies to what's left.
            function getInvoiceAdjustments() {
                return {
                    discount_pct: Math.min(100, Math.max(0, parseFloat(document.getElementById('adhocDiscountPct').value) || 0)),
                    tax_rate: Math.min(100, Math.max(0, parseFloat(document.getElementById('adhocTaxRate').value) || 0)),
                };
            }
            function resetLineItems() {
                const tbody = document.getElementById('lineItemsBody');
                tbody.innerHTML = `<tr class="line-item-row">${LINE_ITEM_ROW_HTML}</tr>`;
                document.getElementById('adhocDueDate').value = '';
                document.getElementById('adhocDueDateHint').textContent = '';
                document.getElementById('adhocMemo').value = '';
                document.getElementById('adhocDiscountPct').value = '0';
                document.getElementById('adhocTaxRate').value = '0';
                updateAdhocTotal();
            }
            // Recomputes the Subtotal/Discount/Tax/Total breakdown live as line
            // items or the discount/tax fields change, so there's an accurate total
            // before hitting Preview.
            function updateAdhocTotal() {
                const rows = document.querySelectorAll('#lineItemsBody .li-amount');
                let subtotal = 0;
                rows.forEach(el => { const v = parseFloat(el.value); if (!isNaN(v)) subtotal += v; });
                const { discount_pct, tax_rate } = getInvoiceAdjustments();
                const discountAmt = subtotal * discount_pct / 100;
                const net = subtotal - discountAmt;
                const taxAmt = net * tax_rate / 100;
                const total = net + taxAmt;
                const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                document.getElementById('adhocSubtotal').textContent = fmt(subtotal);
                document.getElementById('adhocDiscountAmt').textContent = fmt(discountAmt);
                document.getElementById('adhocTaxAmt').textContent = fmt(taxAmt);
                document.getElementById('adhocRunningTotal').textContent = fmt(total);
            }
            document.getElementById('lineItemsBody').addEventListener('input', (e) => { if (e.target.classList.contains('li-amount')) updateAdhocTotal(); });
            document.getElementById('lineItemsBody').addEventListener('click', (e) => { if (e.target.closest('button')) setTimeout(updateAdhocTotal, 0); });
            document.getElementById('adhocDiscountPct').addEventListener('input', updateAdhocTotal);
            document.getElementById('adhocTaxRate').addEventListener('input', updateAdhocTotal);
            // Shows the selected client's current outstanding balance and hints at
            // what due date their default payment terms would produce, since the
            // Due Date field below is left blank (falls back to those terms) unless
            // explicitly overridden.
            function updateAdhocClientInfo() {
                const sel = document.getElementById('adhocClient');
                const opt = sel.options[sel.selectedIndex];
                const balanceEl = document.getElementById('adhocClientBalance');
                const hintEl = document.getElementById('adhocDueDateHint');
                if (!opt || !opt.value) {
                    balanceEl.style.display = 'none';
                    hintEl.textContent = '';
                    document.getElementById('adhocAmountCcy').textContent = APP_CURRENCY;
                    return;
                }
                const outstanding = parseFloat(opt.dataset.outstanding || '0');
                if (outstanding > 0) {
                    balanceEl.textContent = `Outstanding balance: ${outstanding.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
                    balanceEl.style.display = '';
                } else {
                    balanceEl.style.display = 'none';
                }
                const terms = parseInt(opt.dataset.terms || '21', 10);
                const defaultDue = new Date(Date.now() + terms * 86400000);
                hintEl.textContent = `Leave blank to use this client's terms (${terms} days — ${defaultDue.toLocaleDateString()})`;
                document.getElementById('adhocAmountCcy').textContent = opt.dataset.currency || APP_CURRENCY;
            }
            async function previewAdhocInvoice() {
                const cid = document.getElementById('adhocClient').value;
                const items = getLineItems();
                if (!cid) return showToast('Please select a client', true);
                if (!items.length) return showToast('Please add at least one line item with a description and amount', true);
                const btn = document.getElementById('previewAdhocBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>...'; btn.disabled = true;
                const params = { action: 'preview_adhoc', client_id: cid, line_items: JSON.stringify(items), due_date: document.getElementById('adhocDueDate').value, ...getInvoiceAdjustments() };
                const data = new URLSearchParams(params);
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-eye"></i> Preview'; btn.disabled = false;
                if (json.success) {
                    // Not saved yet, so there's no invoxa_invoices row for the PDF
                    // button's usual GET export — stash the same inputs so it can
                    // re-render straight to PDF via preview_adhoc_pdf instead.
                    _lastAdhocPreviewParams = params;
                    viewInvoice({ invoice_number: json.invoice_number, html_content: json.html });
                }
                else { showToast(json.error || 'Failed to preview', true); }
            }
            async function sendAdhocInvoice(isQuote = false) {
                const cid = document.getElementById('adhocClient').value;
                const items = getLineItems();
                if (!cid) return showToast('Please select a client', true);
                if (!items.length) return showToast('Please add at least one line item with a description and amount', true);
                const dueDate = document.getElementById('adhocDueDate').value;
                const memo = document.getElementById('adhocMemo').value;
                if (isQuote) {
                    const btn = document.getElementById('saveQuoteBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                    const quoteExpiresAt = document.getElementById('adhocQuoteExpiry').value;
                    const data = new URLSearchParams({ action: 'save_quote', client_id: cid, line_items: JSON.stringify(items), due_date: dueDate, quote_expires_at: quoteExpiresAt, memo: memo, ...getInvoiceAdjustments() });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast(`Quote ${json.quoteNum} saved!`); setTimeout(() => window.location.reload(), 2000); }
                    else { showToast(json.error || 'Failed to save quote', true); btn.innerHTML = '<i class="fa-solid fa-file-pen"></i> Save as Quote'; btn.disabled = false; }
                } else {
                    const btn = document.getElementById('sendAdhocBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...'; btn.disabled = true;
                    const data = new URLSearchParams({ action: 'generate_adhoc', client_id: cid, line_items: JSON.stringify(items), due_date: dueDate, memo: memo, ...getInvoiceAdjustments() });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast(`Invoice ${json.invNum} sent!`); setTimeout(() => window.location.reload(), 2000); }
                    else { showToast(json.error || 'Failed to send', true); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Generate & Send'; btn.disabled = false; }
                }
            }
            async function runRecurringBilling() {
                if (!confirm("This will instantly generate and email invoices to ALL active clients with a monthly rate. Proceed?")) return;
                const btn = document.getElementById('runRecurringBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'run_recurring' });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Sent ${json.sent} invoices. Errors: ${json.errors}. Reminders sent: ${json.reminders_sent}. Late fees charged: ${json.late_fees_charged}. Recurring expenses logged: ${json.recurring_expenses_logged}.`); setTimeout(() => window.location.reload(), 2000); }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Run Monthly Billing'; btn.disabled = false; }
            }

            // Invoices / general
            // Single Export button for the invoiceExportType dropdown above — the two
            // tax-year options open their existing preview modal (which itself has a
            // download button once loaded); everything else is a direct file download.
            function runInvoiceExport() {
                const type = document.getElementById('invoiceExportType').value;
                if (type === 'tax_year') { openTaxYearPreview(); return; }
                if (type === 'tax_year_monthly') { openMonthlySummaryPreview(); return; }
                window.location.href = '?export=' + type;
            }
            function filterInvoicesByStatus(value) {
                filterTableSearch('invoices', value);
            }

            // Saved Filtered Views — a named preset is just the current contents of a
            // table's search box, stored per-browser in localStorage like other display
            // preferences (theme, default tab, page size). Not persisted server-side.
            const FILTER_VIEW_TABLES = {
                invoices: { viewSelectId: 'invoicesViewSelect', statusSelectId: 'invoiceStatusFilter', storageKey: 'invoxa_views_invoices' },
                clients: { viewSelectId: 'clientsViewSelect', statusSelectId: null, storageKey: 'invoxa_views_clients' },
            };
            function getFilterViews(table) {
                try { return JSON.parse(localStorage.getItem(FILTER_VIEW_TABLES[table].storageKey) || '[]'); } catch (e) { return []; }
            }
            function setFilterViews(table, views) {
                localStorage.setItem(FILTER_VIEW_TABLES[table].storageKey, JSON.stringify(views));
            }
            function populateFilterViewSelect(table) {
                const cfg = FILTER_VIEW_TABLES[table];
                const select = document.getElementById(cfg.viewSelectId);
                if (!select) return;
                const current = select.value;
                const views = getFilterViews(table);
                select.innerHTML = '<option value="">Saved Views…</option>' +
                    views.map(v => `<option value="${encodeURIComponent(v.name)}">${v.name.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</option>`).join('');
                if (views.some(v => encodeURIComponent(v.name) === current)) select.value = current;
            }
            function tableSearchInput(table) {
                const wrapper = document.querySelector('#sec-' + table + ' .datatable-wrapper');
                return wrapper && wrapper.querySelector('input.datatable-input');
            }
            function saveFilterView(table) {
                const name = (prompt('Name this view:') || '').trim();
                if (!name) return;
                const input = tableSearchInput(table);
                const view = { name, search: input ? input.value : '' };
                const views = getFilterViews(table).filter(v => v.name !== name);
                views.push(view);
                setFilterViews(table, views);
                populateFilterViewSelect(table);
                document.getElementById(FILTER_VIEW_TABLES[table].viewSelectId).value = encodeURIComponent(name);
                showToast(`View "${name}" saved`);
            }
            function applyFilterView(table, encodedName) {
                if (!encodedName) return;
                const name = decodeURIComponent(encodedName);
                const view = getFilterViews(table).find(v => v.name === name);
                const input = tableSearchInput(table);
                if (!view || !input) return;
                input.value = view.search || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                // Keep the Status dropdown (invoices only) in sync when the saved search
                // happens to be one of its option values, so it doesn't show a stale choice.
                const cfg = FILTER_VIEW_TABLES[table];
                if (cfg.statusSelectId) {
                    const statusEl = document.getElementById(cfg.statusSelectId);
                    if (statusEl) statusEl.value = [...statusEl.options].some(o => o.value === view.search) ? view.search : '';
                }
            }
            function deleteFilterView(table) {
                const select = document.getElementById(FILTER_VIEW_TABLES[table].viewSelectId);
                if (!select || !select.value) return showToast('Select a view to delete first', true);
                const name = decodeURIComponent(select.value);
                setFilterViews(table, getFilterViews(table).filter(v => v.name !== name));
                populateFilterViewSelect(table);
                showToast(`View "${name}" deleted`);
            }
            Object.keys(FILTER_VIEW_TABLES).forEach(populateFilterViewSelect);
            // file_path is stored in the DB as "invoices/<folder>/<file>.html", but the
            // actual served URL (see INVOICES_URL in invoxa.php) is under /invoxa-invoices/
            // — mirror that mapping here rather than using file_path as a URL directly.
            function invoiceFileUrl(filePath) {
                return '/invoxa-invoices/' + filePath.replace(/^invoices\//, '');
            }
            let _currentViewFilePath = null;
            let _currentViewInvoiceId = null;
            let _currentViewInvoiceNumber = null;
            // Set by previewAdhocInvoice() for an unsaved preview (no DB id/file yet),
            // so the PDF button can re-render from the same inputs. Cleared when a
            // real, saved invoice is opened so it can't be reused stale.
            let _lastAdhocPreviewParams = null;
            async function viewInvoice(inv) {
                document.getElementById('viewModalTitle').textContent = 'Invoice ' + inv.invoice_number;
                const iframe = document.getElementById('invoicePreview');
                const warning = document.getElementById('invoiceMissingWarning');
                _currentViewFilePath = inv.file_path || null;
                _currentViewInvoiceId = inv.id ?? null;
                _currentViewInvoiceNumber = inv.invoice_number ?? null;
                if (_currentViewInvoiceId) _lastAdhocPreviewParams = null;
                document.getElementById('copyInvoiceLinkBtn').style.display = inv.file_path ? '' : 'none';
                document.getElementById('downloadPdfBtn').style.display = (_currentViewInvoiceId || _lastAdhocPreviewParams) ? '' : 'none';
                // Attachments need a real invoice_id, so hidden for an unsaved adhoc
                // preview, same condition as the other buttons tied to a persisted row.
                document.getElementById('attachmentsBtn').style.display = _currentViewInvoiceId ? '' : 'none';
                warning.style.display = 'none';
                iframe.style.display = '';
                document.getElementById('viewModal').classList.add('active');
                if (inv.file_path) {
                    // Check the file actually exists before pointing the iframe at it — the
                    // DB record and disk file can drift apart (e.g. a restored backup whose
                    // files never came along), which would otherwise be a blank 404.
                    const url = invoiceFileUrl(inv.file_path);
                    try {
                        const check = await fetch(url, { method: 'HEAD' });
                        if (!check.ok) throw new Error('missing');
                        iframe.src = url;
                    } catch (e) {
                        iframe.style.display = 'none';
                        warning.style.display = 'flex';
                    }
                } else if (inv.html_content) {
                    // Fallback: write html_content, replacing the email cid: logo reference with the real URL
                    let html = inv.html_content;
                    html = html.replace(/src=["']cid:logo_cid["']/g, 'src="/invoxa-invoices/invoxa_logo.jpg"');
                    const doc = iframe.contentWindow.document;
                    doc.open(); doc.write(html); doc.close();
                } else {
                    iframe.style.display = 'none';
                    warning.style.display = 'flex';
                }
            }
            function copyInvoiceLink() {
                if (!_currentViewFilePath) { showToast('No direct link available for this invoice', true); return; }
                const url = window.location.origin + invoiceFileUrl(_currentViewFilePath);
                // navigator.clipboard only exists in secure contexts (HTTPS or localhost) —
                // plain HTTP throws before .catch() runs, so fall back to the older
                // execCommand('copy') approach, which works everywhere.
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(url)
                        .then(() => showToast('Link copied to clipboard'))
                        .catch(() => showToast('Failed to copy link', true));
                    return;
                }
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try {
                    document.execCommand('copy');
                    showToast('Link copied to clipboard');
                } catch (e) {
                    showToast('Failed to copy link — copy manually: ' + url, true);
                }
                document.body.removeChild(ta);
            }
            function _formatFileSize(bytes) {
                bytes = parseInt(bytes, 10) || 0;
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }
            async function openAttachmentsModal() {
                if (!_currentViewInvoiceId) return;
                document.getElementById('attachmentsModalTitle').textContent = 'Attachments — Invoice ' + (_currentViewInvoiceNumber || '');
                document.getElementById('attachmentFile').value = '';
                document.getElementById('attachmentsList').innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">Loading…</p>';
                document.getElementById('attachmentsModal').classList.add('active');
                await loadAttachments();
            }
            async function loadAttachments() {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_invoice_attachments', invoice_id: _currentViewInvoiceId }) });
                const json = await res.json();
                const list = document.getElementById('attachmentsList');
                if (!json.success || !json.attachments.length) {
                    list.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">No attachments yet.</p>';
                    return;
                }
                list.innerHTML = json.attachments.map(a => `
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.5rem 0; border-bottom:1px solid var(--border);">
                        <a href="${a.url}" target="_blank" style="color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><i class="fa-solid fa-paperclip"></i> ${a.filename}</a>
                        <div style="display:flex; align-items:center; gap:0.75rem; white-space:nowrap;">
                            <span style="color:var(--text-secondary); font-size:0.8rem;">${_formatFileSize(a.file_size)}</span>
                            <button class="btn small danger" onclick="deleteAttachment(${a.id})"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `).join('');
            }
            async function uploadAttachment() {
                const file = document.getElementById('attachmentFile').files[0];
                if (!file) return showToast('Choose a file first', true);
                const btn = document.getElementById('uploadAttachmentBtn'); btn.disabled = true;
                const formData = new FormData();
                formData.append('action', 'upload_invoice_attachment');
                formData.append('invoice_id', _currentViewInvoiceId);
                formData.append('file', file);
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) { showToast('Attachment uploaded!'); document.getElementById('attachmentFile').value = ''; await loadAttachments(); }
                else showToast(json.error || 'Upload failed', true);
                btn.disabled = false;
            }
            async function deleteAttachment(id) {
                if (!confirm('Delete this attachment?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice_attachment', id: id }) });
                const json = await res.json();
                if (json.success) { showToast('Attachment deleted!'); await loadAttachments(); }
                else showToast(json.error || 'Failed to delete', true);
            }
            async function openMarkPaid(inv) {
                document.getElementById('paidInvoiceId').value = inv.id;
                document.getElementById('paidInvoiceNum').value = inv.invoice_number;
                const remaining = Math.max(0, parseFloat(inv.amount) - parseFloat(inv.paid_amount || 0));
                document.getElementById('paidAmount').value = remaining.toFixed(2);
                document.getElementById('paidAmountCcy').textContent = inv.currency || APP_CURRENCY;
                document.getElementById('paidNote').value = '';
                document.getElementById('paidHistoryWrap').style.display = 'none';
                document.getElementById('paidHistoryList').innerHTML = '';
                document.getElementById('paidModal').classList.add('active');
                await loadPaymentHistory(inv.id);
            }
            async function loadPaymentHistory(invoiceId) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_invoice_payments', invoice_id: invoiceId }) });
                const json = await res.json();
                if (!json.success || !json.payments.length) return;
                const wrap = document.getElementById('paidHistoryWrap');
                wrap.style.display = '';
                document.getElementById('paidHistoryList').innerHTML = json.payments.map(p => `
                    <div style="display:flex; justify-content:space-between; gap:0.75rem; padding:0.25rem 0;">
                        <span style="color:var(--text-secondary);">${new Date(p.paid_at).toLocaleDateString()}${p.note ? ' — ' + p.note.replace(/</g, '&lt;') : ''}</span>
                        <span>$${parseFloat(p.amount).toFixed(2)}</span>
                    </div>
                `).join('');
            }
            async function openNoteModal(id, num) {
                document.getElementById('noteInvoiceId').value = id;
                document.getElementById('noteInvoiceNum').textContent = num;
                document.getElementById('noteText').value = '';
                document.getElementById('existingNotesList').innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">Loading notes...</p>';
                document.getElementById('noteModal').classList.add('active');
                await renderNotesList(num);
            }
            async function renderNotesList(num) {
                const invNum = num || document.getElementById('noteInvoiceNum').textContent;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_notes', invoice_number: invNum }) });
                const json = await res.json();
                const container = document.getElementById('existingNotesList');
                if (!json.success || json.notes.length === 0) {
                    container.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem; font-style:italic;">No notes yet.</p>';
                    return;
                }
                container.innerHTML = json.notes.map(n => `
                    <div style="background:rgba(255,255,255,0.04); border:1px solid var(--border); border-radius:6px; padding:0.6rem 0.8rem; margin-bottom:0.5rem; display:flex; align-items:flex-start; gap:0.75rem;">
                        <div style="flex:1;">
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.25rem;">${n.performed_at}</div>
                            <div style="font-size:0.875rem; white-space:pre-wrap;">${n.notes.replace(/</g, '&lt;')}</div>
                        </div>
                        <button class="btn small danger" style="flex-shrink:0; padding:0.2rem 0.4rem;" onclick="deleteNote(${n.id}, '${invNum}')" title="Delete note"><i class="fa-solid fa-trash"></i></button>
                    </div>`).join('');
            }
            async function deleteNote(noteId, invNum) {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_note', note_id: noteId }) });
                const json = await res.json();
                if (json.success) {
                    await renderNotesList(invNum);
                    // Reload page in background to update note count badge
                    window._notePageNeedsReload = true;
                } else { showToast(json.error || 'Failed to delete', true); }
            }
            async function markPaid() { const btn = document.getElementById('markPaidBtn'); btn.disabled = true; const data = new URLSearchParams({ action: 'mark_paid', id: document.getElementById('paidInvoiceId').value, amount: document.getElementById('paidAmount').value, note: document.getElementById('paidNote').value }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Payment recorded!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; } }
            async function markUnpaid(id) { if (!confirm('Mark this invoice as unpaid?')) return; const data = new URLSearchParams({ action: 'mark_unpaid', id: id }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Marked as unpaid!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); } }
            async function resendInvoiceEmail(id) {
                if (!confirm('Resend this invoice email to the client?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_invoice_email', id }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice email resent!'); } else { showToast(json.error || 'Resend failed', true); }
            }
            async function voidInvoice(id, invNum) {
                const reason = prompt(`Void invoice ${invNum}? It stays on record but is excluded from outstanding/overdue totals. This can be undone.\n\nOptional reason:`);
                if (reason === null) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'void_invoice', id, reason }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice voided'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to void', true); }
            }
            async function unvoidInvoice(id) {
                if (!confirm('Restore this invoice from void?')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'unvoid_invoice', id }) });
                const json = await res.json();
                if (json.success) { showToast('Invoice restored'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error || 'Failed to restore', true); }
            }
            async function fixPaidDates() {
                if (!confirm('This will reset paid_at to the last day of each invoice\'s month for ALL paid invoices. Continue?')) return;
                const btn = document.getElementById('fixPaidDatesBtn');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'fix_paid_dates' }) });
                const json = await res.json();
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-calendar-xmark"></i> Reset paid_at to End-of-Month';
                if (json.success) { showToast('Fixed ' + json.fixed + ' invoices. Reload to see updated Payment Velocity.'); }
                else { showToast('Error: ' + (json.error || 'Unknown'), true); }
            }
            async function backfillClientNames() {
                if (!confirm('This will fill in any blank invoice client-name snapshots from the current client record. Continue?')) return;
                const btn = document.getElementById('backfillClientNamesBtn');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'backfill_client_names' }) });
                const json = await res.json();
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-user-pen"></i> Backfill Missing Client Names';
                if (json.success) { showToast('Backfilled ' + json.fixed + ' invoice(s).'); }
                else { showToast('Error: ' + (json.error || 'Unknown'), true); }
            }
            async function dedupePayments() {
                if (!confirm('This will permanently delete exact-duplicate payment ledger rows (same invoice, provider, amount, note, and date), keeping only the earliest of each. Continue?')) return;
                const btn = document.getElementById('dedupePaymentsBtn');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'dedupe_payments' }) });
                const json = await res.json();
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-copy"></i> Dedupe Payment Ledger';
                if (json.success) { showToast('Removed ' + json.fixed + ' duplicate payment row(s).'); }
                else { showToast('Error: ' + (json.error || 'Unknown'), true); }
            }
            async function reconcilePaymentTotals() {
                if (!confirm('This will recalculate every invoice\'s cached paid amount from its payment ledger, and mark fully-paid invoices as paid. Continue?')) return;
                const btn = document.getElementById('reconcilePaymentTotalsBtn');
                btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'reconcile_payment_totals' }) });
                const json = await res.json();
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-scale-balanced"></i> Reconcile Payment Totals';
                if (json.success) { showToast('Reconciled ' + json.fixed + ' invoice(s).'); }
                else { showToast('Error: ' + (json.error || 'Unknown'), true); }
            }
            async function addNote() { const btn = document.getElementById('addNoteBtn'); btn.disabled = true; const data = new URLSearchParams({ action: 'add_note', id: document.getElementById('noteInvoiceId').value, note: document.getElementById('noteText').value }); const res = await fetch('', { method: 'POST', body: data }); const json = await res.json(); if (json.success) { showToast('Note added!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); btn.disabled = false; } }
            async function deleteInvoice(id) {
                if (!confirm('Are you sure you want to delete this invoice? This will remove it from the database and delete the HTML file. This action cannot be undone.')) return;
                const data = new URLSearchParams({ action: 'delete_invoice', id: id });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('Invoice deleted!'); setTimeout(() => window.location.reload(), 1000); } else { showToast(json.error, true); }
            }

            // ── Invoice bulk actions ──────────────────────────────────────
            function toggleSelectAllInvoices(masterCb) {
                document.querySelectorAll('.invoice-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateInvoiceBulkBar();
            }
            function getSelectedInvoiceCbs() {
                return Array.from(document.querySelectorAll('.invoice-select-cb:checked'));
            }
            function updateInvoiceBulkBar() {
                const count = getSelectedInvoiceCbs().length;
                const bar = document.getElementById('invoiceBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('invoiceBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.invoice-select-cb');
                document.getElementById('invoicesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkMarkPaidInvoices() {
                const cbs = getSelectedInvoiceCbs().filter(cb => cb.dataset.status !== 'paid' && cb.dataset.status !== 'void');
                if (!cbs.length) return showToast('No eligible invoices selected (already paid or void)', true);
                if (!confirm(`Mark ${cbs.length} invoice(s) as fully paid?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'mark_paid', id: cb.value, amount: cb.dataset.amount, note: 'Bulk mark paid' }) });
                }
                showToast(`Marked ${cbs.length} invoice(s) as paid!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkResendInvoiceEmails() {
                const cbs = getSelectedInvoiceCbs().filter(cb => cb.dataset.status !== 'void' && cb.dataset.status !== 'draft');
                if (!cbs.length) return showToast('No eligible invoices selected (void/draft can\'t be resent)', true);
                if (!confirm(`Resend ${cbs.length} invoice email(s)?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_invoice_email', id: cb.value }) });
                }
                showToast(`Resent ${cbs.length} invoice email(s)!`);
            }
            async function bulkDeleteInvoices() {
                const cbs = getSelectedInvoiceCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} invoice(s)? This removes them from the database and deletes their HTML files. This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} invoice(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportInvoicesCsv() {
                const cbs = getSelectedInvoiceCbs();
                if (!cbs.length) return;
                const rows = [['Invoice #', 'Date', 'Due Date', 'Client', 'Amount', 'Status']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5, 6].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'invoices_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }

            // ── Client bulk actions ───────────────────────────────────────
            function toggleSelectAllClients(masterCb) {
                document.querySelectorAll('.client-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateClientBulkBar();
            }
            function getSelectedClientCbs() {
                return Array.from(document.querySelectorAll('.client-select-cb:checked'));
            }
            function updateClientBulkBar() {
                const count = getSelectedClientCbs().length;
                const bar = document.getElementById('clientBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('clientBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.client-select-cb');
                document.getElementById('clientsSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkSetClientFlag(field, value, label) {
                const cbs = getSelectedClientCbs();
                if (!cbs.length) return;
                if (!confirm(`${label}: ${cbs.length} client(s)?`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'update_client_flags', id: cb.value, field: field, value: value }) });
                }
                showToast(`${label} for ${cbs.length} client(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkDeleteClients() {
                const cbs = getSelectedClientCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} client(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_client', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} client(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }

            // ── Expense bulk actions ──────────────────────────────────────
            function toggleSelectAllExpenses(masterCb) {
                document.querySelectorAll('.expense-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateExpenseBulkBar();
            }
            function getSelectedExpenseCbs() {
                return Array.from(document.querySelectorAll('.expense-select-cb:checked'));
            }
            function updateExpenseBulkBar() {
                const count = getSelectedExpenseCbs().length;
                const bar = document.getElementById('expenseBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('expenseBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.expense-select-cb');
                document.getElementById('expensesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkDeleteExpenses() {
                const cbs = getSelectedExpenseCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} expense(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_expense', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} expense(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportExpensesCsv() {
                const cbs = getSelectedExpenseCbs();
                if (!cbs.length) return;
                const rows = [['Date', 'Vendor', 'Category', 'Amount', 'Description']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'expenses_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }

            // ── Quote bulk actions ────────────────────────────────────────
            function toggleSelectAllQuotes(masterCb) {
                document.querySelectorAll('.quote-select-cb').forEach(cb => { cb.checked = masterCb.checked; });
                updateQuoteBulkBar();
            }
            function getSelectedQuoteCbs() {
                return Array.from(document.querySelectorAll('.quote-select-cb:checked'));
            }
            function updateQuoteBulkBar() {
                const count = getSelectedQuoteCbs().length;
                const bar = document.getElementById('quoteBulkBar');
                bar.style.display = count > 0 ? 'flex' : 'none';
                document.getElementById('quoteBulkCount').textContent = count + ' selected';
                const allCbs = document.querySelectorAll('.quote-select-cb');
                document.getElementById('quotesSelectAll').checked = count > 0 && count === allCbs.length;
            }
            async function bulkConvertQuotes() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                const expiredCount = cbs.filter(cb => cb.dataset.expired === '1').length;
                const warning = expiredCount
                    ? `Convert ${cbs.length} quote(s) to invoices? ${expiredCount} of them have expired. This cannot be undone.`
                    : `Convert ${cbs.length} quote(s) to invoices? This cannot be undone.`;
                if (!confirm(warning)) return;
                let failed = 0;
                for (const cb of cbs) {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'convert_quote', id: cb.value }) });
                    const json = await res.json();
                    if (!json.success) failed++;
                }
                showToast(failed ? `Converted ${cbs.length - failed} quote(s), ${failed} failed` : `Converted ${cbs.length} quote(s)!`, failed > 0);
                setTimeout(() => window.location.reload(), 1000);
            }
            async function bulkDeleteQuotes() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                if (!confirm(`Delete ${cbs.length} quote(s)? This cannot be undone.`)) return;
                for (const cb of cbs) {
                    await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_invoice', id: cb.value }) });
                }
                showToast(`Deleted ${cbs.length} quote(s)!`);
                setTimeout(() => window.location.reload(), 1000);
            }
            function bulkExportQuotesCsv() {
                const cbs = getSelectedQuoteCbs();
                if (!cbs.length) return;
                const rows = [['Quote #', 'Client', 'Date', 'Amount', 'Status', 'Expires']];
                cbs.forEach(cb => {
                    const cells = cb.closest('tr').querySelectorAll('td');
                    rows.push([1, 2, 3, 4, 5, 6].map(i => (cells[i].innerText || '').trim()));
                });
                const csv = rows.map(r => r.map(v => '"' + v.replace(/"/g, '""') + '"').join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = 'quotes_selected.csv'; a.click();
                URL.revokeObjectURL(url);
            }
            async function toggleTestClients(hide) {
                const data = new URLSearchParams({ action: 'toggle_test_clients', hide: hide ? '1' : '0' });
                try {
                    const res = await fetch('', { method: 'POST', body: data });
                    const json = await res.json();
                    if (!json.success) {
                        showToast(json.error || 'Failed to update — see Settings > License if this keeps happening', true);
                        document.getElementById('hideTestToggle').checked = !hide;
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    document.getElementById('hideTestToggle').checked = !hide;
                }
            }
            async function toggleShowTestOnly(show) {
                const data = new URLSearchParams({ action: 'toggle_show_test_only', show: show ? '1' : '0' });
                try {
                    const res = await fetch('', { method: 'POST', body: data });
                    const json = await res.json();
                    if (!json.success) {
                        showToast(json.error || 'Failed to update — see Settings > License if this keeps happening', true);
                        document.getElementById('showTestOnlyToggle').checked = !show;
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    document.getElementById('showTestOnlyToggle').checked = !show;
                }
            }
            function applyResolvedTheme() {
                document.documentElement.setAttribute('data-theme', invoxaResolveTheme());
                if (chartAllData) renderChart();
            }
            function setThemeMode(mode) {
                localStorage.setItem('invoxa_theme', mode);
                applyResolvedTheme();
                markActiveSegment('themeModeGroup', mode);
            }
            if (window.matchMedia) {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                    if ((localStorage.getItem('invoxa_theme') || 'system') === 'system') applyResolvedTheme();
                });
            }
            function setDensityMode(mode) {
                localStorage.setItem('invoxa_density', mode);
                document.documentElement.setAttribute('data-density', mode);
                markActiveSegment('densityModeGroup', mode);
            }
            function setCornerStyle(mode) {
                localStorage.setItem('invoxa_corners', mode);
                document.documentElement.setAttribute('data-corners', mode);
                markActiveSegment('cornerStyleGroup', mode);
            }
            function markActiveSegment(groupId, value) {
                document.querySelectorAll('#' + groupId + ' .segmented-btn').forEach((btn) => {
                    btn.classList.toggle('active', btn.dataset.value === value);
                });
            }
            function injectCustomCss(css) {
                let styleEl = document.getElementById('invoxaCustomCssStyle');
                if (!styleEl) {
                    styleEl = document.createElement('style');
                    styleEl.id = 'invoxaCustomCssStyle';
                    document.head.appendChild(styleEl);
                }
                styleEl.textContent = css;
            }
            function applyCustomCss() {
                const css = document.getElementById('customCssInput').value;
                localStorage.setItem('invoxa_custom_css', css);
                injectCustomCss(css);
                showToast('Custom CSS applied');
            }
            function clearCustomCss() {
                document.getElementById('customCssInput').value = '';
                localStorage.removeItem('invoxa_custom_css');
                injectCustomCss('');
                showToast('Custom CSS cleared');
            }

            markActiveSegment('themeModeGroup', localStorage.getItem('invoxa_theme') || 'system');
            markActiveSegment('densityModeGroup', localStorage.getItem('invoxa_density') || 'comfortable');
            markActiveSegment('cornerStyleGroup', localStorage.getItem('invoxa_corners') || 'rounded');
            markActiveAccentSwatch(localStorage.getItem('invoxa_accent'));
            const _brandColorInput = document.getElementById('brandColor');
            if (_brandColorInput) updateBrandPreview(_brandColorInput.value);

            // ── Branding & Accent color helpers ───────────────────────────
            function shadeColor(hex, percent) {
                hex = hex.replace('#', '');
                const num = parseInt(hex, 16);
                const adjust = (c) => {
                    const delta = percent < 0 ? c : (255 - c);
                    return Math.max(0, Math.min(255, Math.round(c + delta * percent)));
                };
                const r = adjust((num >> 16) & 0xff), g = adjust((num >> 8) & 0xff), b = adjust(num & 0xff);
                return '#' + [r, g, b].map((c) => c.toString(16).padStart(2, '0')).join('');
            }
            function hexToRgba(hex, alpha) {
                hex = hex.replace('#', '');
                const num = parseInt(hex, 16);
                return `rgba(${(num >> 16) & 0xff}, ${(num >> 8) & 0xff}, ${num & 0xff}, ${alpha})`;
            }
            function selectBrandPreset(hex) {
                document.getElementById('brandColor').value = hex;
                updateBrandPreview(hex);
            }
            function updateBrandPreview(hex) {
                document.getElementById('brandColorHex').textContent = hex;
                document.getElementById('brandPreviewHeader').style.borderBottomColor = hex;
                document.getElementById('brandPreviewBtn').style.background = hex;
                document.querySelectorAll('.brand-preset-swatch').forEach((sw) => {
                    sw.style.outline = sw.dataset.color.toLowerCase() === hex.toLowerCase() ? '2px solid var(--accent)' : 'none';
                    sw.style.outlineOffset = '2px';
                });
            }
            function applyAccentColor(hex) {
                const hover = shadeColor(hex, -0.15);
                const soft = hexToRgba(hex, 0.12);
                document.documentElement.style.setProperty('--accent', hex);
                document.documentElement.style.setProperty('--accent-hover', hover);
                document.documentElement.style.setProperty('--accent-soft', soft);
                localStorage.setItem('invoxa_accent', hex);
                localStorage.setItem('invoxa_accent_hover', hover);
                localStorage.setItem('invoxa_accent_soft', soft);
                markActiveAccentSwatch(hex);
                if (chartAllData) renderChart();
            }
            function resetAccentColor() {
                document.documentElement.style.removeProperty('--accent');
                document.documentElement.style.removeProperty('--accent-hover');
                document.documentElement.style.removeProperty('--accent-soft');
                localStorage.removeItem('invoxa_accent');
                localStorage.removeItem('invoxa_accent_hover');
                localStorage.removeItem('invoxa_accent_soft');
                markActiveAccentSwatch(null);
                if (chartAllData) renderChart();
            }
            function markActiveAccentSwatch(hex) {
                document.querySelectorAll('.accent-preset-swatch').forEach((sw) => {
                    sw.style.outline = (hex && sw.dataset.color.toLowerCase() === hex.toLowerCase()) ? '2px solid var(--text-primary)' : 'none';
                    sw.style.outlineOffset = '2px';
                });
            }
            async function saveCron() {
                const btn = document.getElementById('saveCronBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; btn.disabled = true;
                try {
                    const data = new URLSearchParams({ action: 'update_cron', cron: document.getElementById('cronInput').value });
                    const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                    if (json.success) { showToast('Cron updated!'); updateCronHuman(); } else showToast(json.error, true);
                } catch (e) { showToast('Error: ' + e.message, true); }
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Save'; btn.disabled = false;
            }

            function updateCronHuman() {
                const val = document.getElementById('cronInput').value.trim();
                const el = document.getElementById('cronHuman');
                const dashEl = document.getElementById('nextCronRunDashboard');
                const toggle = document.getElementById('cronEnabledToggle');
                const isEnabled = !toggle || toggle.checked;
                if (!val) {
                    if (el) el.textContent = '';
                    if (dashEl) dashEl.textContent = 'Not set';
                    return;
                }
                try {
                    const desc = window.cronstrue.toString(val);
                    const pausedPrefix = isEnabled ? '' : '<i class="fa-solid fa-pause"></i> Paused — would run: ';
                    if (el) {
                        el.innerHTML = isEnabled ? `<strong>Schedule:</strong> ${desc}` : `${pausedPrefix}${desc}`;
                        el.style.color = isEnabled ? "var(--success)" : "var(--text-secondary)";
                    }
                    if (dashEl) {
                        dashEl.textContent = isEnabled ? desc : 'Paused (' + desc + ')';
                    }
                } catch (e) {
                    if (el) {
                        el.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Invalid cron expression';
                        el.style.color = "var(--danger)";
                    }
                    if (dashEl) dashEl.textContent = 'Invalid';
                }
            }
            async function toggleCronEnabled(enabled) {
                const toggle = document.getElementById('cronEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_cron', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Recurring billing enabled' : 'Recurring billing paused');
                        updateCronHuman();
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function toggleRecurringBypassGuard(enabled) {
                const toggle = document.getElementById('recurringBypassGuardToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_recurring_bypass_guard', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Double-billing guard bypassed — every run will re-bill active clients' : 'Double-billing guard restored');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function saveAuditRetention() {
                const btn = document.getElementById('saveAuditRetentionBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const days = document.getElementById('auditRetentionSelect').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_audit_retention', audit_log_retention_days: days }) });
                const json = await res.json();
                if (json.success) { showToast('Audit log retention saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function saveBackupRetention() {
                const btn = document.getElementById('saveBackupRetentionBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const count = document.getElementById('localBackupRetentionCount').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_backup_retention', local_backup_retention_count: count }) });
                const json = await res.json();
                if (json.success) { showToast('Backup retention saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function saveOffsiteBackup() {
                const btn = document.getElementById('saveOffsiteBackupBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'save_offsite_backup',
                    offsite_backup_enabled: document.getElementById('offsiteBackupEnabled').checked ? '1' : '0',
                    offsite_remote_name: document.getElementById('offsiteRemoteName').value,
                    offsite_remote_path: document.getElementById('offsiteRemotePath').value,
                    offsite_retention_count: document.getElementById('offsiteRetentionCount').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Offsite push settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save'; btn.disabled = false;
            }
            async function toggleReminders(enabled) {
                const toggle = document.getElementById('remindersEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_reminders', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Payment reminders enabled' : 'Payment reminders paused');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function toggleLateFees(enabled) {
                const toggle = document.getElementById('lateFeesEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_late_fees', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Late fees enabled' : 'Late fees paused');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function toggleAutoBackup(enabled) {
                const toggle = document.getElementById('autoBackupEnabledToggle');
                toggle.disabled = true;
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'toggle_auto_backup', enabled: enabled ? '1' : '0' }) });
                    const json = await res.json();
                    if (json.success) {
                        showToast(enabled ? 'Automatic backups enabled' : 'Automatic backups paused');
                    } else {
                        showToast(json.error || 'Failed to update', true);
                        toggle.checked = !enabled;
                    }
                } catch (e) {
                    showToast('Failed to update (network error)', true);
                    toggle.checked = !enabled;
                }
                toggle.disabled = false;
            }
            async function saveLateFeeSettings() {
                const btn = document.getElementById('saveLateFeeSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('lateFeeSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_late_fee_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Late fee settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Late Fee Settings'; btn.disabled = false;
            }
            function updateNotificationChannelUI() {
                const channel = document.getElementById('notificationChannel').value;
                document.getElementById('telegramFields').style.display = channel === 'telegram' ? '' : 'none';
                document.getElementById('slackFields').style.display = channel === 'slack' ? '' : 'none';
                document.getElementById('webhookFields').style.display = channel === 'webhook' ? '' : 'none';
            }
            updateNotificationChannelUI();
            function setAllNotifyEvents(checked) {
                document.querySelectorAll('#notificationSettingsForm .notify-event-cb').forEach(cb => { cb.checked = checked; });
            }
            async function saveNotificationSettings() {
                const btn = document.getElementById('saveNotificationSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('notificationSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_notification_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Notification settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Notification Settings'; btn.disabled = false;
            }
            async function sendTestNotification() {
                const btn = document.getElementById('sendTestNotificationBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'test_notification',
                    notification_channel: document.getElementById('notificationChannel').value,
                    telegram_bot_token: document.getElementById('telegramBotToken').value,
                    telegram_chat_id: document.getElementById('telegramChatId').value,
                    slack_webhook_url: document.getElementById('slackWebhookUrl').value,
                    webhook_url: document.getElementById('webhookUrl').value,
                    webhook_format: document.getElementById('webhookFormat').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Test message sent!'); } else { showToast(json.error || 'Failed to send', true); }
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Message'; btn.disabled = false;
            }
            async function savePaymentSettings() {
                const btn = document.getElementById('savePaymentSettingsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('paymentSettingsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_payment_settings');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Payment settings saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Payment Settings'; btn.disabled = false;
            }
            async function testStripeConnection() {
                const btn = document.getElementById('testStripeBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing...'; btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'test_stripe_connection', stripe_secret_key: document.getElementById('stripeSecretKey').value }) });
                const json = await res.json();
                if (json.success) { showToast('Stripe connected — account: ' + (json.account || 'OK')); } else { showToast(json.error || 'Stripe connection failed', true); }
                btn.innerHTML = '<i class="fa-solid fa-plug"></i> Test Connection'; btn.disabled = false;
            }
            async function testPaypalConnection() {
                const btn = document.getElementById('testPaypalBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing...'; btn.disabled = true;
                const data = new URLSearchParams({
                    action: 'test_paypal_connection',
                    paypal_environment: document.getElementById('paypalEnvironment').value,
                    paypal_client_id: document.getElementById('paypalClientId').value,
                    paypal_client_secret: document.getElementById('paypalClientSecret').value,
                });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('PayPal connected!'); } else { showToast(json.error || 'PayPal connection failed', true); }
                btn.innerHTML = '<i class="fa-solid fa-plug"></i> Test Connection'; btn.disabled = false;
            }
            async function createApiToken() {
                const label = document.getElementById('apiTokenLabel').value.trim();
                if (!label) return showToast('Give this token a label first', true);
                const expiry = document.getElementById('apiTokenExpiry').value;
                const btn = document.getElementById('createApiTokenBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'create_api_token', label, expiry }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to create token', true);
                document.getElementById('apiTokenNewValue').value = json.token;
                document.getElementById('apiTokenNewWrap').style.display = '';
                document.getElementById('apiTokenLabel').value = '';
                showToast('Token created!');
                setTimeout(() => window.location.reload(), 4000);
            }
            function copyApiToken() {
                const input = document.getElementById('apiTokenNewValue');
                input.select();
                navigator.clipboard ? navigator.clipboard.writeText(input.value).then(() => showToast('Token copied!')) : document.execCommand('copy');
            }
            function copyApiExample(id) {
                const text = document.getElementById(id).textContent;
                navigator.clipboard ? navigator.clipboard.writeText(text).then(() => showToast('Copied!')) : (() => {
                    const range = document.createRange(); range.selectNode(document.getElementById(id));
                    window.getSelection().removeAllRanges(); window.getSelection().addRange(range);
                    document.execCommand('copy'); window.getSelection().removeAllRanges();
                })();
            }
            async function renewApiToken(id) {
                const select = document.getElementById('apiTokenRenewSelect' + id);
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'renew_api_token', id, expiry: select.value }) });
                const json = await res.json();
                if (json.success) { showToast('Token renewed!'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to renew token', true);
            }
            async function revokeApiToken(id) {
                if (!confirm('Revoke this API token? Anything using it will stop working immediately.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'revoke_api_token', id }) });
                const json = await res.json();
                if (json.success) { showToast('Token revoked.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to revoke token', true);
            }
            async function deleteApiToken(id) {
                if (!confirm('Permanently delete this token? This removes it from the list entirely — unlike Revoke, this can\'t be undone.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_api_token', id }) });
                const json = await res.json();
                if (json.success) { showToast('Token deleted.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to delete token', true);
            }
            async function createUser() {
                const username = document.getElementById('newUserUsername').value.trim();
                const email = document.getElementById('newUserEmail').value.trim();
                const password = document.getElementById('newUserPassword').value;
                const role = document.getElementById('newUserRole').value;
                if (!username || !email || !password) return showToast('Username, email, and password are all required', true);
                const btn = document.getElementById('createUserBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'create_user', username, email, password, role }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to create user', true);
                showToast('User created!');
                setTimeout(() => window.location.reload(), 800);
            }
            async function updateUserRole(id) {
                const role = document.getElementById('userRoleSelect' + id).value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'update_user', id, role }) });
                const json = await res.json();
                if (json.success) { showToast('User updated!'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to update user', true);
            }
            async function deleteUser(id) {
                if (!confirm('Delete this user account? This can\'t be undone.')) return;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'delete_user', id }) });
                const json = await res.json();
                if (json.success) { showToast('User deleted.'); setTimeout(() => window.location.reload(), 800); }
                else showToast(json.error || 'Failed to delete user', true);
            }
            async function saveInvoiceNumbering() {
                const btn = document.getElementById('saveInvoiceNumberingBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceNumberingForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_numbering');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice numbering format saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Numbering Format'; btn.disabled = false;
            }
            document.getElementById('cronInput').addEventListener('input', updateCronHuman);
            // Init on load
            document.addEventListener('DOMContentLoaded', updateCronHuman);

            // Sidebar badge counts are baked in at initial render only, so background
            // changes (e.g. the cron container firing recurring billing) would leave
            // them stale without polling. Also runs on tab focus, so switching back
            // after stepping away feels current too.
            async function refreshNavCounts() {
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_nav_counts' }) });
                    const json = await res.json();
                    if (!json.success) return;
                    document.getElementById('navInvoiceCountBadge').textContent = json.invoice_count;
                    const unpaidEl = document.getElementById('navUnpaidCountBadge');
                    unpaidEl.textContent = json.unpaid_count;
                    unpaidEl.style.display = json.unpaid_count > 0 ? '' : 'none';
                    document.getElementById('navQuoteCountBadge').textContent = json.quote_count;
                    document.getElementById('navClientCountBadge').textContent = json.client_count;
                    document.getElementById('navExpenseCountBadge').textContent = json.expense_count;
                } catch (e) { /* silent — next poll retries */ }
            }
            setInterval(refreshNavCounts, 60000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshNavCounts(); });
            async function sendTestEmail() {
                const email = document.getElementById('testEmailInput').value;
                if (!email) return showToast('Enter an email', true);
                const btn = document.getElementById('sendTestEmailBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'test_email', email: email });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Test email sent!`); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Email'; btn.disabled = false; document.getElementById('testEmailInput').value = ''; }
                else { showToast(json.error || 'Failed to send', true); btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Test Email'; btn.disabled = false; }
            }

            const missingFiles = <?= json_encode(array_values($missingFiles)) ?>;
            async function syncFiles() {
                const btn = document.getElementById('syncBtn');
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing...';
                btn.disabled = true;
                const data = new URLSearchParams({ action: 'sync_missing', files: JSON.stringify(missingFiles) });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    const skipped = (json.failures ? json.failures.length : 0) + (json.mismatches ? json.mismatches.length : 0);
                    if (json.failures && json.failures.length > 0) {
                        showToast(`${json.imported} imported, ${json.failures.length} skipped — client needs to be created first`, true);
                    } else if (json.mismatches && json.mismatches.length > 0) {
                        showToast(`${json.imported} imported, ${json.mismatches.length} skipped — invoice number mismatch`, true);
                    } else {
                        showToast(`Imported ${json.imported} files!`);
                    }
                    setTimeout(() => window.location.reload(), skipped > 0 ? 3000 : 1500);
                } else {
                    showToast(json.error, true);
                    btn.innerHTML = '<i class="fa-solid fa-download"></i> Import All';
                    btn.disabled = false;
                }
            }

            async function deleteUntrackedFile(filePath) {
                if (!confirm(`This will permanently delete '${filePath}' from disk. This cannot be undone! Proceed?`)) return;
                const data = new URLSearchParams({ action: 'delete_untracked_file', file: filePath });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast('File deleted!'); setTimeout(() => window.location.reload(), 1500); }
                else { showToast(json.error, true); }
            }

            async function deleteAllUntrackedFiles() {
                if (!confirm(`This will permanently delete all ${missingFiles.length} untracked file${missingFiles.length === 1 ? '' : 's'} from disk. This cannot be undone! Proceed?`)) return;
                const btn = document.getElementById('deleteAllUntrackedBtn');
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';
                btn.disabled = true;
                const data = new URLSearchParams({ action: 'delete_all_untracked_files', files: JSON.stringify(missingFiles) });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) {
                    let msg = `Deleted ${json.deleted} file${json.deleted === 1 ? '' : 's'}.`;
                    if (json.errors > 0) msg += ` ${json.errors} failed to delete.`;
                    showToast(msg, json.deleted === 0 && json.errors > 0);
                    setTimeout(() => window.location.reload(), json.errors > 0 ? 3000 : 1500);
                } else {
                    showToast(json.error, true);
                    btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete All';
                    btn.disabled = false;
                }
            }

            const missingDiskIds = <?= json_encode(array_column($missingDiskData, 'id')) ?>;
            async function restoreMissingFiles() {
                if (!confirm('This will rebuild the HTML files for all ' + missingDiskIds.length + ' missing invoices using the data saved in the database. Proceed?')) return;
                const btn = document.getElementById('restoreBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rebuilding...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'restore_missing', ids: JSON.stringify(missingDiskIds) });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) {
                    let msg = `Rebuilt ${json.restored} file${json.restored === 1 ? '' : 's'}.`;
                    if (json.no_content > 0) {
                        msg += ` ${json.no_content} had no stored content to rebuild from — likely historical records imported without an original invoice file. Their database records (client, amount, dates, paid status) are still intact for reporting; delete them below only if you don't need that history.`;
                    }
                    if (json.errors > 0) {
                        msg += ` ${json.errors} failed to write to disk — check file permissions on invoxa-invoices/.`;
                    }
                    const hasIssue = json.no_content > 0 || json.errors > 0;
                    showToast(msg, json.restored === 0 && hasIssue);
                    setTimeout(() => window.location.reload(), hasIssue ? 4000 : 1500);
                }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-file-export"></i> Rebuild HTML Files'; btn.disabled = false; }
            }
            async function deleteMissingDb() {
                if (!confirm('WARNING: This will permanently DELETE ' + missingDiskIds.length + ' invoice records from the database that do not have matching HTML files. This cannot be undone! Proceed?')) return;
                const btn = document.getElementById('delDbBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'delete_missing_db', ids: JSON.stringify(missingDiskIds) });
                const res = await fetch('', { method: 'POST', body: data }); const json = await res.json();
                if (json.success) { showToast(`Deleted ${json.deleted} records!`); setTimeout(() => window.location.reload(), 1500); }
                else { showToast(json.error, true); btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete All'; btn.disabled = false; }
            }

            async function initChart(force = false) {
                if (chartAllData && !force) { renderChart(); return; }
                const res = await fetch('?api=chart');
                chartAllData = await res.json();
                renderChart();
            }

            function setChartRange(range) {
                chartRange = range;
                document.getElementById('chartRange12').className = 'btn small' + (range === '12' ? ' primary' : '');
                document.getElementById('chartRangeAll').className = 'btn small' + (range === 'all' ? ' primary' : '');
                renderChart();
            }

            function renderChart() {
                if (!chartAllData) return;
                const { clients, data: allData } = chartAllData;
                const displayData = chartRange === '12' ? allData.slice(-12) : allData;
                const labels = displayData.map(d => d.month);
                const clientKeys = Object.keys(clients);
                const datasets = [];
                clientKeys.forEach((ck, i) => {
                    datasets.push({
                        label: clients[ck],
                        data: displayData.map(d => d[ck] ?? 0),
                        borderColor: CLIENT_COLORS[i % CLIENT_COLORS.length],
                        backgroundColor: CLIENT_COLORS[i % CLIENT_COLORS.length] + '20',
                        borderWidth: 2, pointRadius: 2, pointHoverRadius: 5, tension: 0.3, fill: false
                    });
                });
                datasets.push({
                    label: 'Total (All Clients)',
                    data: displayData.map(d => d.total ?? 0),
                    borderColor: '#ffffff',
                    backgroundColor: (context) => {
                        const { ctx, chartArea } = context.chart;
                        if (!chartArea) return null;
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(79, 124, 255, 0.35)');
                        gradient.addColorStop(1, 'rgba(79, 124, 255, 0)');
                        return gradient;
                    },
                    borderWidth: 2.5, borderDash: [6, 3], pointRadius: 2, pointHoverRadius: 5, tension: 0.3, fill: true
                });
                if (chartInstance) chartInstance.destroy();
                chartInstance = new Chart(document.getElementById('revenueChart').getContext('2d'), {
                    type: 'line',
                    data: { labels, datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: true, labels: { color: '#94a3b8', usePointStyle: true, pointStyleWidth: 10, boxHeight: 6 } },
                            tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${APP_CURRENCY} $${ctx.raw.toLocaleString()}` } }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8', callback: v => '$' + v.toLocaleString() } },
                            x: { grid: { display: false }, ticks: { color: '#94a3b8', maxRotation: 45 } }
                        }
                    }
                });

                const lastRow = allData.length > 0 ? allData[allData.length - 1] : null;
                const pieLabels = [];
                const pieValues = [];
                const pieBg = [];
                const pieBorder = [];

                if (lastRow) {
                    clientKeys.forEach((ck, i) => {
                        if (lastRow[ck] && lastRow[ck] > 0) {
                            pieLabels.push(clients[ck]);
                            pieValues.push(lastRow[ck]);
                            pieBg.push(CLIENT_COLORS[i % CLIENT_COLORS.length] + '80');
                            pieBorder.push(CLIENT_COLORS[i % CLIENT_COLORS.length]);
                        }
                    });
                }

                if (pieChartInstance) pieChartInstance.destroy();
                pieChartInstance = new Chart(document.getElementById('pieChart').getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: pieLabels, datasets: [{ data: pieValues, backgroundColor: pieBg, borderColor: pieBorder, borderWidth: 1 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { color: '#94a3b8', usePointStyle: true, padding: 20 } },
                            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${APP_CURRENCY} $${ctx.raw.toLocaleString()}` } }
                        }
                    }
                });
            }

            // ── Brand Settings ─────────────────────────────────────────────
            async function saveProfile() {
                const newUsername = document.getElementById('newUsername').value.trim();
                const newEmail = document.getElementById('newEmail').value.trim();
                const currentPassword = document.getElementById('currentPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                if (!currentPassword) return showToast('Current password is required', true);
                const btn = document.getElementById('saveProfileBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const data = new URLSearchParams({ action: 'update_profile', new_username: newUsername, new_email: newEmail, current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Profile'; btn.disabled = false;
                if (json.success) {
                    showToast('Profile saved! Logging out for changes to take effect...');
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                    setTimeout(() => { document.querySelector('form [name="auth_action"]') && document.querySelector('form [name="auth_action"]').closest('form').submit(); }, 2000);
                } else { showToast(json.error || 'Failed to save', true); }
            }
            async function startTotpSetup() {
                const btn = document.getElementById('totpStartBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_setup_init' }) });
                const json = await res.json();
                btn.disabled = false;
                if (!json.success) return showToast(json.error || 'Failed to start setup', true);
                const qr = qrcode(0, 'M');
                qr.addData(json.otpauth_uri);
                qr.make();
                document.getElementById('totpQrCode').innerHTML = qr.createSvgTag(4);
                document.getElementById('totpSecretDisplay').value = json.secret;
                document.getElementById('totpAccountLabel').textContent = json.account_label;
                document.getElementById('totpConfirmCode').value = '';
                document.getElementById('totpSetupWrap').style.display = '';
                btn.style.display = 'none';
            }
            function cancelTotpSetup() {
                document.getElementById('totpSetupWrap').style.display = 'none';
                document.getElementById('totpStartBtn').style.display = '';
            }
            async function confirmTotpSetup() {
                const btn = document.getElementById('totpConfirmBtn'); btn.disabled = true;
                const code = document.getElementById('totpConfirmCode').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_setup_confirm', code }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) {
                    document.getElementById('totpDisabledView').style.display = 'none';
                    document.getElementById('totpBackupCodesList').textContent = (json.backup_codes || []).join('\n');
                    document.getElementById('totpBackupCodesWrap').style.display = '';
                    document.getElementById('totpEnabledView').style.display = '';
                    showToast('Two-factor authentication enabled!');
                } else showToast(json.error || 'Invalid code', true);
            }
            async function regenerateBackupCodes() {
                if (!confirm('Regenerate backup codes? Any codes you saved previously will stop working.')) return;
                const password = document.getElementById('totpDisablePassword').value;
                const btn = document.getElementById('totpRegenBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_regenerate_backup_codes', current_password: password }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) {
                    document.getElementById('totpBackupCodesList').textContent = (json.backup_codes || []).join('\n');
                    document.getElementById('totpBackupCodesWrap').style.display = '';
                    document.getElementById('totpDisablePassword').value = '';
                    showToast('Backup codes regenerated!');
                } else showToast(json.error || 'Failed to regenerate', true);
            }
            async function disableTotp() {
                if (!confirm('Disable two-factor authentication for this account?')) return;
                const password = document.getElementById('totpDisablePassword').value;
                const btn = document.getElementById('totpDisableBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'totp_disable', current_password: password }) });
                const json = await res.json();
                btn.disabled = false;
                if (json.success) { showToast('Two-factor authentication disabled.'); setTimeout(() => window.location.reload(), 1000); }
                else showToast(json.error || 'Failed to disable', true);
            }
            async function saveLicenseKey() {
                const key = document.getElementById('licenseKey').value.trim();
                const btn = document.getElementById('saveLicenseBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Activating...'; btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_license_key', license_key: key }) });
                const json = await res.json();
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Activate License'; btn.disabled = false;
                if (json.success && json.valid) {
                    showToast('License activated!');
                    setTimeout(() => window.location.reload(), 1000);
                } else if (json.success) {
                    const reasons = {
                        malformed: 'That license key doesn\'t look valid — check you copied the whole string with nothing missing.',
                        bad_signature: 'That license key failed verification — check you copied it exactly, with nothing missing or altered.',
                        no_profile_email: 'Your admin account has no email set. Add the email your license was issued to under Authentication, then try again.',
                        email_mismatch: 'This license was issued to a different email than your admin account\'s. Update your email under Authentication to match, or contact your seller for a new key.',
                        domain_mismatch: 'This license is issued for a different domain than the one you\'re accessing this instance on. Contact your seller for a new key if you\'ve moved domains.',
                    };
                    showToast(reasons[json.reason] || 'Saved, but this key is not valid for this domain/install.', true);
                    // The key was saved server-side even though it doesn't validate, so any
                    // previously-active license is now deactivated — reload to reflect that.
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(json.error || 'Failed to save', true);
                }
            }
            async function clearLicenseKey() {
                if (!confirm('Deactivate your license? The seven paid features (payment collection, recurring billing, Client Portal, external API, Reporting & Statistics, adding teammates, and Powered-by removal) will lock again until you activate a key.')) return;
                const btn = document.getElementById('clearLicenseBtn'); btn.disabled = true;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'save_license_key', license_key: '' }) });
                const json = await res.json();
                if (json.success) {
                    document.getElementById('licenseKey').value = '';
                    showToast('License deactivated.');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    btn.disabled = false;
                    showToast(json.error || 'Failed to deactivate', true);
                }
            }
            async function loadDefaultInvoiceTemplate() {
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_default_invoice_template' }) });
                const json = await res.json();
                if (json.success) { document.getElementById('customInvoiceTemplate').value = json.template; }
                else { showToast(json.error || 'Failed to load default template', true); }
            }
            async function previewInvoiceTemplate() {
                const template = document.getElementById('invoiceTemplate').value;
                const params = { action: 'preview_invoice_template', template };
                if (template === 'custom') params.custom_html = document.getElementById('customInvoiceTemplate').value;
                const res = await fetch('', { method: 'POST', body: new URLSearchParams(params) });
                const json = await res.json();
                if (json.success) { _lastAdhocPreviewParams = null; viewInvoice({ invoice_number: 'INV-SAMPLE-001 (preview)', html_content: json.html }); }
                else { showToast(json.error || 'Failed to render preview', true); }
            }
            async function saveInvoiceTemplate() {
                const btn = document.getElementById('saveInvoiceTemplateBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceTemplateForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_template');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice template saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Invoice Template'; btn.disabled = false;
            }
            async function saveBusinessIdentity() {
                const btn = document.getElementById('saveBusinessIdentityBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('businessIdentityForm');
                const formData = new FormData(form);
                formData.append('action', 'save_business_identity');
                const res = await fetch('', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    showToast('Business identity saved! This only affects invoices sent to your clients — the Invoxa app itself keeps its own identity.');
                } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Business Identity'; btn.disabled = false;
            }
            async function saveInvoiceDefaults() {
                const btn = document.getElementById('saveInvoiceDefaultsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('invoiceDefaultsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_invoice_defaults');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Invoice defaults saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Invoice Defaults'; btn.disabled = false;
            }
            async function savePaymentDetails() {
                const btn = document.getElementById('savePaymentDetailsBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('paymentDetailsForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_payment_details');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Default payment details saved!'); } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Payment Details'; btn.disabled = false;
            }
            async function saveEmailTemplates() {
                const btn = document.getElementById('saveEmailTemplatesBtn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
                const form = document.getElementById('emailTemplatesForm');
                const data = new URLSearchParams(new FormData(form));
                data.append('action', 'save_email_templates');
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    showToast('Email templates saved!');
                } else { showToast(json.error || 'Failed to save', true); }
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Email Templates'; btn.disabled = false;
            }

            // ── PDF Download ───────────────────────────────────────────────
            // Server-side render (dompdf, see ?export=invoice_pdf in invoxa.php) —
            // a plain navigation rather than fetch/blob so the browser handles the
            // Content-Disposition download itself.
            async function downloadInvoicePdf() {
                if (_currentViewInvoiceId) {
                    window.location.href = '?export=invoice_pdf&id=' + encodeURIComponent(_currentViewInvoiceId);
                    return;
                }
                if (!_lastAdhocPreviewParams) { showToast('Nothing to download yet', true); return; }
                // Unsaved preview: no GET URL to navigate to, so fetch the PDF as a
                // blob and trigger the download via a throwaway link instead.
                const btn = document.getElementById('downloadPdfBtn'); const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; btn.disabled = true;
                try {
                    const data = new URLSearchParams({ ..._lastAdhocPreviewParams, action: 'preview_adhoc_pdf' });
                    const res = await fetch('', { method: 'POST', body: data });
                    if (!res.ok) throw new Error(await res.text());
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url; a.download = 'Invoice-preview.pdf';
                    document.body.appendChild(a); a.click(); a.remove();
                    URL.revokeObjectURL(url);
                } catch (e) {
                    showToast('Failed to generate PDF', true);
                } finally {
                    btn.innerHTML = originalHtml; btn.disabled = false;
                }
            }

            // ── CRM Drawer ─────────────────────────────────────────────────
            let _crmClientId = null;
            function openCrm(c) {
                _crmClientId = c.id;
                document.getElementById('crmDrawerTitle').innerHTML = '<i class="fa-solid fa-user" style="color:var(--accent); margin-right:0.5rem;"></i>' + c.client_name;
                document.getElementById('crmNotes').value = '';
                document.getElementById('crmStats').innerHTML = '<div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:1rem;text-align:center;"><span class="skeleton" style="width:90px;"></span></div>';
                document.getElementById('crmRecentInvoices').innerHTML = '<div><span class="skeleton" style="width:140px;"></span></div>';
                document.getElementById('crmDrawer').style.right = '0';
                document.getElementById('crmOverlay').style.display = 'block';
                fetchCrmData(c.id);
            }
            function closeCrm() {
                document.getElementById('crmDrawer').style.right = '-440px';
                document.getElementById('crmOverlay').style.display = 'none';
                _crmClientId = null;
            }
            async function fetchCrmData(clientId) {
                const data = new URLSearchParams({ action: 'get_crm_data', client_id: clientId });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (!json.success) return;
                const s = json.stats;
                document.getElementById('crmStats').innerHTML = `
                    <div style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Total Billed</div>
                        <div style="color:var(--accent);font-weight:700;font-size:1.1rem;">$${parseFloat(s.total_billed || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Total Paid</div>
                        <div style="color:var(--success);font-weight:700;font-size:1.1rem;">$${parseFloat(s.total_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Outstanding</div>
                        <div style="color:var(--warning);font-weight:700;font-size:1.1rem;">$${(parseFloat(s.total_billed || 0) - parseFloat(s.total_paid || 0)).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;padding:1rem;text-align:center;">
                        <div style="color:var(--text-secondary);font-size:0.75rem;margin-bottom:0.25rem;">Invoices</div>
                        <div style="color:var(--text-primary);font-weight:700;font-size:1.1rem;">${s.inv_count || 0}</div>
                    </div>`;
                const invHtml = (json.recent || []).map(i => `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0.75rem;border-radius:6px;border:1px solid var(--border);margin-bottom:0.5rem;background:rgba(255,255,255,0.02);">
                        <div><strong style="font-size:0.85rem;">${i.invoice_number}</strong><div style="color:var(--text-secondary);font-size:0.75rem;">${i.invoice_date.substring(0, 10)}</div></div>
                        <div style="text-align:right;"><div style="font-weight:600;font-size:0.9rem;">$${parseFloat(i.amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</div><span class="badge ${i.status}" style="font-size:0.7rem;">${i.status}</span></div>
                    </div>`).join('');
                document.getElementById('crmRecentInvoices').innerHTML = invHtml || '<p style="color:var(--text-secondary);font-size:0.85rem;">No invoices yet.</p>';
                document.getElementById('crmNotes').value = json.crm_notes || '';
            }
            async function saveCrmNotes() {
                if (!_crmClientId) return;
                const notes = document.getElementById('crmNotes').value;
                const data = new URLSearchParams({ action: 'save_crm_notes', client_id: _crmClientId, notes: notes });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) showToast('Notes saved!');
                else showToast(json.error || 'Failed to save', true);
            }

            // ── Quotes ─────────────────────────────────────────────────────
            function openQuoteModal() {
                nav('billing');
                document.getElementById('adhocClient').value = '';
                resetLineItems();
                document.getElementById('isQuoteFlag').value = '1';
                document.getElementById('billingPageTitle').textContent = 'New Quote';
                document.getElementById('billingCardTitle').textContent = 'Create a Quote / Estimate';
                document.getElementById('adhocQuoteExpiryGroup').style.display = '';
                const defaultExpiry = new Date();
                defaultExpiry.setDate(defaultExpiry.getDate() + 30);
                document.getElementById('adhocQuoteExpiry').value = defaultExpiry.toISOString().substring(0, 10);
                showToast('Fill in the form and click "Save as Quote" to create it.');
            }
            function resetAdhocMode() {
                document.getElementById('isQuoteFlag').value = '0';
                document.getElementById('billingPageTitle').textContent = 'Ad Hoc Invoice';
                document.getElementById('billingCardTitle').textContent = 'Create Adhoc Invoice (One-Off)';
                document.getElementById('adhocQuoteExpiryGroup').style.display = 'none';
                document.getElementById('adhocQuoteExpiry').value = '';
            }
            async function convertQuote(id, num, expired = false) {
                const warning = expired ? 'This quote has expired. Convert quote ' + num + ' to a final invoice anyway? This cannot be undone.' : 'Convert quote ' + num + ' to a final invoice? This cannot be undone.';
                if (!confirm(warning)) return;
                const data = new URLSearchParams({ action: 'convert_quote', id: id });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) { showToast('Converted to invoice ' + json.invoice_number + '!'); setTimeout(() => window.location.reload(), 1500); }
                else showToast(json.error || 'Failed to convert', true);
            }
            // ── Backup & Restore ───────────────────────────────────────────
            async function backupDatabase() {
                const checkboxes = document.querySelectorAll('.backup-table-checkbox:checked');
                const selectedTables = Array.from(checkboxes).map(cb => cb.value).join(',');
                if (!selectedTables) {
                    showToast('Please select at least one table to backup.', true);
                    return;
                }
                const data = new URLSearchParams({ action: 'backup_db', tables: selectedTables });
                const res = await fetch('', { method: 'POST', body: data });
                const json = await res.json();
                if (json.success) {
                    showToast('Backup generated and saved to the backups folder!');
                } else {
                    showToast(json.error || 'Failed to generate backup', true);
                }
            }

            async function loadBackupList() {
                const sel = document.getElementById('restoreBackupSelect');
                sel.innerHTML = '<option>Loading...</option>';
                const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'list_backups' }) });
                const json = await res.json();
                if (!json.success || !json.backups.length) {
                    sel.innerHTML = '<option value="">No backups yet — create one above</option>';
                    return;
                }
                sel.innerHTML = json.backups.map(b => `<option value="${b.filename}">${b.filename} (${b.modified})</option>`).join('');
            }

            async function importBackup(file) {
                if (!file) return;
                const input = document.getElementById('importBackupFile');
                const fd = new FormData();
                fd.append('action', 'import_backup');
                fd.append('backup_file', file);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        const note = json.remapped ? ' (remapped from an older weblab_ export)' : '';
                        showToast('Imported ' + json.filename + note + ' — select it above to restore.');
                        loadBackupList();
                    } else {
                        showToast(json.error || 'Import failed', true);
                    }
                } catch (e) {
                    showToast('Import failed (network error)', true);
                }
                input.value = '';
            }

            async function resendVerificationEmail(btnId = 'resendVerifyBtn') {
                const btn = document.getElementById(btnId);
                const originalText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Sending…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'resend_verification_email' }) });
                    const json = await res.json();
                    showToast(json.success ? 'Confirmation email sent' : (json.error || 'Failed to send'), !json.success);
                } catch (e) {
                    showToast('Failed to send (network error)', true);
                } finally {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            }

            async function seedDemoData() {
                const btn = document.getElementById('seedDemoBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Inserting…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'seed_demo_data' }) });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Failed to insert demo data', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Insert Dummy Data';
                    }
                } catch (e) {
                    showToast('Failed to insert demo data (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Insert Dummy Data';
                }
            }

            async function clearDemoData() {
                const btn = document.getElementById('clearDemoBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing…';
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'clear_demo_data' }) });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Failed to clear demo data', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear Dummy Data';
                    }
                } catch (e) {
                    showToast('Failed to clear demo data (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear Dummy Data';
                }
            }

            function selectAllTests(checked) {
                document.querySelectorAll('.test-suite-checkbox, .test-suite-group-checkbox').forEach(cb => cb.checked = checked);
                if (checked) resetTestVisibility(); // "Select All" also un-does any pill filter — checked-but-hidden would be confusing
            }
            function selectAllScreenshots(checked) {
                document.querySelectorAll('.screenshot-page-checkbox').forEach(cb => cb.checked = checked);
            }
            async function captureScreenshots() {
                if (!(location.protocol === 'https:' || location.hostname === 'localhost')) {
                    return showToast('Screen capture needs HTTPS (or localhost) — this page is served over plain HTTP.', true);
                }
                const keys = Array.from(document.querySelectorAll('.screenshot-page-checkbox:checked')).map(cb => cb.value);
                if (!keys.length) return showToast('Select at least one page first', true);
                const targets = window.__screenshotManifest.filter(m => keys.includes(m.key));

                let stream;
                try {
                    stream = await navigator.mediaDevices.getDisplayMedia({ video: { displaySurface: 'browser' }, preferCurrentTab: true });
                } catch (e) {
                    return showToast('Screen sharing was cancelled or denied.', true);
                }
                const video = document.createElement('video');
                video.srcObject = stream;
                await video.play();

                const btn = document.getElementById('captureScreenshotsBtn');
                const origHtml = btn.innerHTML;
                btn.disabled = true;
                let done = 0, failed = 0;
                for (const target of targets) {
                    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Capturing ${target.label}... (${done + 1}/${targets.length})`;
                    window.nav(target.nav, true);
                    if (target.afterNavJs) new Function(target.afterNavJs)();
                    await new Promise(r => setTimeout(r, 900));
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                    const formData = new FormData();
                    formData.append('action', 'save_screenshot');
                    formData.append('key', target.key);
                    formData.append('image', blob, target.key + '.png');
                    const res = await fetch('', { method: 'POST', body: formData });
                    const json = await res.json();
                    if (json.success) done++; else { failed++; showToast(`Failed to save ${target.label}: ${json.error}`, true); }
                }

                stream.getTracks().forEach(t => t.stop());
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (done) showToast(`Captured ${done} screenshot${done === 1 ? '' : 's'} to docs/screenshots/${failed ? ` (${failed} failed)` : ''}`, failed > 0 && done === 0);
            }
            function toggleTestGroup(groupCheckbox) {
                const group = groupCheckbox.dataset.group;
                document.querySelectorAll('.test-suite-row[data-group="' + CSS.escape(group) + '"] .test-suite-checkbox').forEach(cb => cb.checked = groupCheckbox.checked);
            }
            function resetTestVisibility() {
                document.querySelectorAll('#testSuiteList .test-suite-group-row, #testSuiteList .test-suite-row').forEach(row => row.style.display = '');
            }
            function setActiveTestPill(group) {
                document.querySelectorAll('.pill-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.pillGroup === group));
            }
            // "All" pill — shows every section again and selects everything, the
            // inverse of isolating to one section below.
            function selectAllTestsPill() {
                selectAllTests(true);
                setActiveTestPill('__all__');
            }
            // Section pills — isolate to exactly one section: hides every other
            // section's rows entirely (not just unchecking them) and selects only this one.
            function selectTestGroupOnly(group) {
                document.querySelectorAll('#testSuiteList .test-suite-group-row').forEach(row => {
                    const cb = row.querySelector('.test-suite-group-checkbox');
                    const isMatch = cb.dataset.group === group;
                    row.style.display = isMatch ? '' : 'none';
                    cb.checked = isMatch;
                });
                document.querySelectorAll('#testSuiteList .test-suite-row').forEach(row => {
                    const isMatch = row.dataset.group === group;
                    row.style.display = isMatch ? '' : 'none';
                    row.querySelector('.test-suite-checkbox').checked = isMatch;
                });
                setActiveTestPill(group);
            }
            async function runTestSuite() {
                const rows = Array.from(document.querySelectorAll('.test-suite-row'));
                const selectedRows = rows.filter(row => row.querySelector('.test-suite-checkbox').checked);
                // Only touch the status of rows actually being run — an unchecked row
                // keeps its previous result (or "Not run"), so the column is never blank.
                selectedRows.forEach(row => { row.querySelector('.test-suite-time').textContent = ''; });
                if (selectedRows.length === 0) return showToast('Select at least one test first', true);
                const btn = document.getElementById('runTestSuiteBtn');
                btn.disabled = true;
                document.getElementById('testSuiteSummary').innerHTML = '';
                let passed = 0, failed = 0;
                try {
                    // One request per test, run in sequence rather than all at once — each
                    // row ticks pass/fail with its own timing the moment that test finishes,
                    // instead of every row jumping from "Running…" to a result together at
                    // the end with no way to see which case is the slow one.
                    for (let i = 0; i < selectedRows.length; i++) {
                        const row = selectedRows[i];
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Running ' + (i + 1) + '/' + selectedRows.length + '…';
                        row.querySelector('.test-suite-status').innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--text-secondary);"></i> Running…';
                        const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'run_test_suite', tests: JSON.stringify([row.dataset.testName]) }) });
                        const json = await res.json();
                        const status = row.querySelector('.test-suite-status');
                        const time = row.querySelector('.test-suite-time');
                        const r = json.success ? json.results[0] : null;
                        if (!r) {
                            status.innerHTML = '<i class="fa-solid fa-xmark" style="color:var(--danger);"></i> <span style="color:var(--danger);">' + (json.error || 'Failed to run').replace(/</g, '&lt;') + '</span>';
                            failed++;
                            continue;
                        }
                        time.textContent = r.duration_ms + ' ms';
                        if (r.status === 'pass') {
                            status.innerHTML = '<i class="fa-solid fa-check" style="color:var(--success);"></i> Passed';
                            passed++;
                        } else {
                            status.innerHTML = '<i class="fa-solid fa-xmark" style="color:var(--danger);"></i> <span style="color:var(--danger);">' + (r.message || 'Failed').replace(/</g, '&lt;') + '</span>';
                            failed++;
                        }
                    }
                    const allPassed = failed === 0;
                    document.getElementById('testSuiteSummary').innerHTML =
                        '<span style="color:' + (allPassed ? 'var(--success)' : 'var(--danger)') + '; font-weight:600;">' +
                        (allPassed ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-circle-xmark"></i> ') +
                        passed + ' passed, ' + failed + ' failed</span>';
                    showToast(allPassed ? 'All selected tests passed!' : (failed + ' test(s) failed'), !allPassed);
                } catch (e) {
                    showToast('Failed to run test suite (network error)', true);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-play"></i> Run Selected';
                }
            }

            function openFactoryReset() {
                document.getElementById('factoryResetConfirmText').value = '';
                document.getElementById('factoryResetPassword').value = '';
                document.getElementById('factoryResetBtn').disabled = true;
                document.getElementById('factoryResetModal').classList.add('active');
            }

            async function doFactoryReset() {
                const confirmText = document.getElementById('factoryResetConfirmText').value;
                const password = document.getElementById('factoryResetPassword').value;
                if (confirmText !== 'RESET') return;
                if (!password) {
                    showToast('Enter your current password', true);
                    return;
                }
                const btn = document.getElementById('factoryResetBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Erasing…';
                try {
                    const res = await fetch('', {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'factory_reset', confirm: confirmText, password })
                    });
                    const json = await res.json();
                    if (json.success) {
                        window.location.reload();
                    } else {
                        showToast(json.error || 'Factory reset failed', true);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-bomb"></i> Erase Everything';
                    }
                } catch (e) {
                    showToast('Factory reset failed (network error)', true);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-bomb"></i> Erase Everything';
                }
            }

            async function testRestore() {
                try {
                    const sel = document.getElementById('restoreBackupSelect');
                    if (!sel.value) throw new Error('Select a backup first');

                    // Computed server-side (preview_restore) so the raw SQL dump never
                    // has to be transferred to or held in the browser.
                    const previewRes = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_restore', filename: sel.value }) });
                    const previewJson = await previewRes.json();
                    if (!previewJson.success) throw new Error(previewJson.error);
                    const fileStats = previewJson.fileStats;

                    // Fetch DB stats
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'get_db_stats' }) });
                    const json = await res.json();
                    if (!json.success) throw new Error(json.error);
                    const dbStats = json.stats;

                    // Build Terminal Output
                    let textOutput = `=================================================\n`;
                    textOutput += ` DRY RUN RESTORE & DATABASE COMPARISON\n`;
                    textOutput += `=================================================\n\n`;

                    const allTables = new Set([...Object.keys(fileStats), ...Object.keys(dbStats)]);
                    let totalDrop = 0, totalCreate = Object.keys(fileStats).length, totalInsert = 0;

                    for (const t of Array.from(allTables).sort()) {
                        const bCount = fileStats[t] !== undefined ? fileStats[t] : '-';
                        const dCount = dbStats[t] !== undefined ? dbStats[t] : '-';
                        let diff = '';
                        if (bCount !== '-' && dCount !== '-') {
                            const diffNum = bCount - dCount;
                            diff = diffNum > 0 ? `+${diffNum}` : (diffNum < 0 ? `${diffNum}` : '0');
                        } else if (bCount !== '-') {
                            diff = `New Table`;
                        } else {
                            diff = `Ignored (Preserved)`;
                        }
                        if (bCount !== '-') totalInsert += fileStats[t];

                        textOutput += `[TABLE] ${t.padEnd(25)} | Backup: ${String(bCount).padEnd(6)} | DB: ${String(dCount).padEnd(6)} | Diff: ${diff}\n`;
                    }

                    textOutput += `\n-------------------------------------------------\n`;
                    textOutput += ` SUMMARY: Creates: ${totalCreate} | Drops: ${totalDrop} | Inserts: ${totalInsert}\n`;
                    textOutput += `-------------------------------------------------\n`;

                    let html = `<div style="background:#0f172a; border:1px solid rgba(255,255,255,0.1); border-radius:6px; padding:1rem; width:100%; height:400px; overflow-y:auto; box-sizing:border-box;">
                        <pre style="color:#10b981; font-family:'Courier New', Courier, monospace; font-size:13px; margin:0; line-height:1.5;">${textOutput}</pre>
                    </div>`;

                    document.getElementById('restoreModalBody').innerHTML = html;
                    document.getElementById('restoreModalTitle').innerHTML = `Dry Run Summary <span style="font-size:0.9rem; font-weight:normal; color:var(--text-secondary); margin-left:1rem;">Creates: ${totalCreate} | Drops: ${totalDrop} | Inserts: ${totalInsert}</span>`;

                    document.getElementById('restoreModal').classList.add('active');
                } catch (e) {
                    showToast('Failed during dry run: ' + e.message, true);
                }
            }

            async function confirmRestore() {
                const sel = document.getElementById('restoreBackupSelect');
                if (!sel.value) {
                    showToast('Select a backup first', true);
                    return;
                }
                if (!confirm('Are you absolutely sure you want to restore "' + sel.value + '"? This will overwrite existing data and cannot be undone.')) {
                    return;
                }

                document.body.insertAdjacentHTML('beforeend', '<div id="restoreOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.9);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;color:white;backdrop-filter:blur(5px);"><i class="fa-solid fa-spinner fa-spin fa-3x" style="margin-bottom:1.5rem;color:var(--accent);"></i><h2 style="margin:0;font-weight:600;">Restoring...</h2><p style="color:var(--warning);margin-top:1rem;font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> DO NOT REFRESH OR CLOSE THIS PAGE</p></div>');
                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'restore_db_backup', filename: sel.value, confirm: '1' }) });
                    const json = await res.json();
                    document.getElementById('restoreOverlay').remove();
                    if (!json.success) {
                        alert('Restore Error: ' + (json.error || 'Failed to restore backup'));
                        return;
                    }
                    alert('Database restored successfully from ' + sel.value + '.');
                    window.location.reload();
                } catch (e) {
                    const overlay = document.getElementById('restoreOverlay');
                    if (overlay) overlay.remove();
                    alert('Restore Error: Failed during restore process - ' + e.message);
                }
            }

            // ── Tax Year / Monthly Summary Preview Modals ─────────────────────────
            let _csvCurrentData = null; // { type: 'detail'|'monthly', rows, cols, keys }
            function _fmt(n) {
                if (n === null || n === undefined) return '—';
                if (typeof n === 'string' && isNaN(Number(n))) return n;
                return '$' + parseFloat(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function _csvEscape(v) {
                const s = (v == null ? '' : String(v));
                return s.includes(',') || s.includes('"') || s.includes('\n') ? '"' + s.replace(/"/g, '""') + '"' : s;
            }

            function _copyCsvToClipboard() {
                if (!_csvCurrentData) return;
                const { cols, rows } = _csvCurrentData;
                const lines = [cols.map(_csvEscape).join(',')];
                for (const row of rows) lines.push(row.map(_csvEscape).join(','));
                const text = lines.join('\r\n');
                navigator.clipboard.writeText(text).then(() => {
                    const btn = document.getElementById('csvPreviewCopyBtn');
                    const orig = btn.innerHTML;
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                    btn.style.background = 'rgba(16,185,129,0.25)';
                    btn.style.color = '#10b981';
                    setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.style.color = ''; }, 2000);
                }).catch(() => showToast('Clipboard access denied', true));
            }

            function _renderCsvStats(data) {
                const cards = [
                    { label: 'Total Invoiced', value: _fmt(data.total_invoiced), color: 'var(--accent)' },
                    { label: 'Total Paid', value: _fmt(data.total_paid), color: 'var(--success)' },
                    { label: 'Outstanding', value: _fmt(data.outstanding), color: (typeof data.outstanding !== 'number' || data.outstanding > 0) ? 'var(--warning)' : 'var(--success)' },
                ];
                // Only present once expenses exist for the period — keeps the stat row
                // exactly as before for anyone who's never logged an expense.
                if (data.total_expenses !== undefined) {
                    cards.push({ label: 'Total Expenses', value: _fmt(data.total_expenses), color: 'var(--danger)' });
                    cards.push({ label: 'Net Income', value: _fmt(data.net_income), color: data.net_income >= 0 ? 'var(--success)' : 'var(--danger)' });
                }
                document.getElementById('csvPreviewStats').innerHTML = cards.map(c =>
                    `<div style="background:rgba(0,0,0,0.25); border:1px solid var(--border); border-radius:8px; padding:0.85rem 1rem;">
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:0.3rem; text-transform:uppercase; letter-spacing:0.04em;">${c.label}</div>
                        <div style="font-size:1.25rem; font-weight:700; color:${c.color};">${c.value}</div>
                     </div>`
                ).join('');
            }

            function _statusBadgeStyle(status) {
                if (!status) return '';
                const s = status.toLowerCase();
                if (s === 'paid') return 'background:rgba(16,185,129,0.2); color:#10b981; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                if (s === 'partial paid') return 'background:rgba(245,158,11,0.2); color:#f59e0b; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                if (s === 'unpaid' || s === 'sent' || s === 'pending') return 'background:rgba(239,68,68,0.2); color:#ef4444; border-radius:4px; padding:1px 7px; font-size:0.8rem; font-weight:600; white-space:nowrap;';
                return 'background:rgba(148,163,184,0.15); color:var(--text-secondary); border-radius:4px; padding:1px 7px; font-size:0.8rem;';
            }

            async function openTaxYearPreview() {
                // Show modal in loading state
                const modal = document.getElementById('csvPreviewModal');
                document.getElementById('csvPreviewTitle').textContent = 'Tax Year Invoice Export';
                document.getElementById('csvPreviewSubtitle').textContent = 'Loading…';
                document.getElementById('csvPreviewLoading').style.display = 'block';
                document.getElementById('csvPreviewTableWrap').style.display = 'none';
                document.getElementById('csvPreviewStats').innerHTML = '';
                document.getElementById('csvPreviewRowCount').textContent = '';
                _csvCurrentData = null;
                const copyBtn = document.getElementById('csvPreviewCopyBtn');
                copyBtn.disabled = true;
                const dlBtn = document.getElementById('csvPreviewDownloadBtn');
                dlBtn.href = '?export=tax_year';
                dlBtn.style.background = 'var(--accent)';
                modal.classList.add('active');

                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_tax_year' }) });
                    const data = await res.json();
                    if (!data.success) { showToast(data.error || 'Failed to load preview', true); closeModal('csvPreviewModal'); return; }

                    document.getElementById('csvPreviewSubtitle').textContent = `Tax Year: ${data.label} (ordered by invoice date)`;
                    _renderCsvStats(data);

                    const cols = ['Invoice #', 'Client', 'Invoice Date', 'Due Date', 'Amount', 'Currency', 'Status', 'Paid Amount', 'Paid Date'];
                    const keys = ['invoice_number', 'client_name', 'invoice_date', 'due_date', 'amount', 'currency', 'status', 'paid_amount', 'paid_at'];

                    // Store flat CSV rows for clipboard
                    _csvCurrentData = {
                        cols,
                        rows: data.rows.map(r => keys.map(k => r[k] ?? ''))
                    };

                    const thStyle = 'padding:0.55rem 0.75rem; text-align:left; border-bottom:2px solid var(--border); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-secondary); background:var(--surface);';
                    const tdStyle = 'padding:0.5rem 0.75rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:middle;';

                    document.getElementById('csvPreviewHead').innerHTML = `<tr>${cols.map(c => `<th style="${thStyle}">${c}</th>`).join('')}</tr>`;
                    document.getElementById('csvPreviewBody').innerHTML = data.rows.map((r, i) => {
                        const bg = i % 2 === 0 ? '' : 'background:rgba(255,255,255,0.025);';
                        return `<tr style="${bg}">${keys.map(k => {
                            let val = r[k] ?? '';
                            if (k === 'amount' || k === 'paid_amount') val = val ? '$' + parseFloat(val).toFixed(2) : '';
                            if (k === 'status') return `<td style="${tdStyle}"><span style="${_statusBadgeStyle(val)}">${val}</span></td>`;
                            if (k === 'invoice_number') return `<td style="${tdStyle}; font-family:monospace; font-size:0.83rem;">${val}</td>`;
                            return `<td style="${tdStyle}">${val}</td>`;
                        }).join('')}</tr>`;
                    }).join('');

                    document.getElementById('csvPreviewRowCount').textContent = `${data.rows.length} invoice${data.rows.length !== 1 ? 's' : ''}`;
                    document.getElementById('csvPreviewLoading').style.display = 'none';
                    document.getElementById('csvPreviewTableWrap').style.display = 'block';
                    copyBtn.disabled = false;
                } catch (e) {
                    showToast('Failed to load preview: ' + e.message, true);
                    closeModal('csvPreviewModal');
                }
            }

            async function openMonthlySummaryPreview() {
                const modal = document.getElementById('csvPreviewModal');
                document.getElementById('csvPreviewTitle').textContent = 'Monthly Summary Export';
                document.getElementById('csvPreviewSubtitle').textContent = 'Loading…';
                document.getElementById('csvPreviewLoading').style.display = 'block';
                document.getElementById('csvPreviewTableWrap').style.display = 'none';
                document.getElementById('csvPreviewStats').innerHTML = '';
                document.getElementById('csvPreviewRowCount').textContent = '';
                _csvCurrentData = null;
                const copyBtn = document.getElementById('csvPreviewCopyBtn');
                copyBtn.disabled = true;
                const dlBtn = document.getElementById('csvPreviewDownloadBtn');
                dlBtn.href = '?export=tax_year_monthly';
                dlBtn.style.background = 'var(--accent)';
                modal.classList.add('active');

                try {
                    const res = await fetch('', { method: 'POST', body: new URLSearchParams({ action: 'preview_tax_year_monthly' }) });
                    const data = await res.json();
                    if (!data.success) { showToast(data.error || 'Failed to load preview', true); closeModal('csvPreviewModal'); return; }

                    document.getElementById('csvPreviewSubtitle').textContent = `Tax Year: ${data.label} — monthly totals`;
                    _renderCsvStats(data);

                    const cols = ['Month', 'Currency', 'Total Invoiced', 'Total Paid', 'Outstanding', 'Payment Status', 'Expenses', 'Net Income'];

                    // Store flat CSV rows for clipboard
                    _csvCurrentData = {
                        cols,
                        rows: data.rows.map(r => [
                            r.month_label,
                            r.currency,
                            parseFloat(r.total_invoiced).toFixed(2),
                            parseFloat(r.total_paid).toFixed(2),
                            parseFloat(r.outstanding).toFixed(2),
                            r.pay_status,
                            r.month_net_income === null ? '' : parseFloat(r.month_expenses).toFixed(2),
                            r.month_net_income === null ? '' : parseFloat(r.month_net_income).toFixed(2)
                        ])
                    };

                    const thStyle = 'padding:0.55rem 0.75rem; text-align:left; border-bottom:2px solid var(--border); font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-secondary); background:var(--surface);';
                    const tdStyle = 'padding:0.5rem 0.75rem; border-bottom:1px solid rgba(255,255,255,0.05); vertical-align:middle;';

                    document.getElementById('csvPreviewHead').innerHTML = `<tr>${cols.map(c => `<th style="${thStyle}">${c}</th>`).join('')}</tr>`;
                    document.getElementById('csvPreviewBody').innerHTML = data.rows.map((r, i) => {
                        const bg = i % 2 === 0 ? '' : 'background:rgba(255,255,255,0.025);';
                        return `<tr style="${bg}">
                            <td style="${tdStyle}; font-weight:600;">${r.month_label}</td>
                            <td style="${tdStyle}">${r.currency}</td>
                            <td style="${tdStyle}">${_fmt(r.total_invoiced)}</td>
                            <td style="${tdStyle}; color:var(--success);">${_fmt(r.total_paid)}</td>
                            <td style="${tdStyle}; color:${parseFloat(r.outstanding) > 0 ? 'var(--warning)' : 'var(--success)'}">${_fmt(r.outstanding)}</td>
                            <td style="${tdStyle}"><span style="${_statusBadgeStyle(r.pay_status)}">${r.pay_status}</span></td>
                            <td style="${tdStyle}; color:var(--danger);">${r.month_net_income === null ? '—' : _fmt(r.month_expenses)}</td>
                            <td style="${tdStyle}; color:${r.month_net_income === null ? 'var(--text-secondary)' : (r.month_net_income >= 0 ? 'var(--success)' : 'var(--danger)')}">${_fmt(r.month_net_income)}</td>
                        </tr>`;
                    }).join('');

                    document.getElementById('csvPreviewRowCount').textContent = `${data.rows.length} month${data.rows.length !== 1 ? 's' : ''}`;
                    document.getElementById('csvPreviewLoading').style.display = 'none';
                    document.getElementById('csvPreviewTableWrap').style.display = 'block';
                    copyBtn.disabled = false;
                } catch (e) {
                    showToast('Failed to load preview: ' + e.message, true);
                    closeModal('csvPreviewModal');
                }
            }
            // ── Global fixed tooltip (avoids stacking-context clipping from transform animations) ──
            (function () {
                const tip = document.createElement('div');
                tip.id = 'globalTip';
                Object.assign(tip.style, {
                    position: 'fixed',
                    background: '#1e293b',
                    color: '#f1f5f9',
                    fontSize: '0.75rem',
                    fontWeight: '400',
                    whiteSpace: 'nowrap',
                    padding: '0.35rem 0.65rem',
                    borderRadius: '6px',
                    border: '1px solid rgba(255,255,255,0.1)',
                    pointerEvents: 'none',
                    opacity: '0',
                    transition: 'opacity 0.15s ease',
                    zIndex: '2147483647',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.4)',
                });
                document.body.appendChild(tip);

                function positionTip(el) {
                    const r = el.getBoundingClientRect();
                    tip.textContent = el.getAttribute('data-tip');
                    tip.style.opacity = '0';
                    tip.style.display = 'block';
                    const tw = tip.offsetWidth;
                    const th = tip.offsetHeight;
                    let left = r.left + r.width / 2 - tw / 2;
                    let top = r.top - th - 6;
                    // Clamp to viewport
                    if (left < 6) left = 6;
                    if (left + tw > window.innerWidth - 6) left = window.innerWidth - tw - 6;
                    if (top < 6) top = r.bottom + 6; // flip below if not enough space above
                    tip.style.left = left + 'px';
                    tip.style.top = top + 'px';
                    tip.style.opacity = '1';
                }

                document.addEventListener('mouseover', function (e) {
                    const el = e.target.closest('.has-tooltip');
                    if (el) positionTip(el);
                });
                document.addEventListener('mouseout', function (e) {
                    const el = e.target.closest('.has-tooltip');
                    if (el) tip.style.opacity = '0';
                });
            })();
        </script>
