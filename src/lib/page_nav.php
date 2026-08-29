<body>

    <div class="mobile-brand-icon"><img src="assets/img/invoxa-mark.svg" alt="Invoxa"></div>
    <button type="button" class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Toggle menu"><i
            class="fa-solid fa-bars"></i></button>
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar()"></div>

    <nav class="mobile-bottom-nav">
        <button type="button" class="mobile-bottom-nav-item" data-target="dashboard" onclick="nav('dashboard', true)"><i
                class="fa-solid fa-chart-pie"></i><span>Dashboard</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="invoices" onclick="nav('invoices', true)"><i
                class="fa-solid fa-file-lines"></i><span>Invoices</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="billing" onclick="nav('billing', true); resetAdhocMode();"><i
                class="fa-solid fa-circle-plus"></i><span>Add Invoice</span></button>
        <button type="button" class="mobile-bottom-nav-item" data-target="clients" onclick="nav('clients', true)"><i
                class="fa-solid fa-users"></i><span>Clients</span></button>
    </nav>

    <div class="sidebar">
        <div class="sidebar-header">
            <h1 id="sidebarBrandName"><img src="assets/img/invoxa-mark.svg" width="36" height="36" alt="">
                <img src="assets/img/invoxa-wordmark.svg" height="30" alt="Invoxa" style="width:auto;"></h1>
        </div>
        <div class="nav-section-label">Main Menu</div>

        <div class="nav-item" data-target="dashboard" onclick="nav('dashboard', true)"><i
                class="fa-solid fa-chart-pie"></i>
            Dashboard</div>
        <div class="nav-item" data-target="invoices" onclick="nav('invoices', true)"><i
                class="fa-solid fa-file-lines"></i>
            Invoices <span id="navInvoiceCountBadge" class="badge" title="Total invoices"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= $invoice_count ?></span><span
                id="navUnpaidCountBadge" class="badge" title="Unpaid invoices"
                style="margin-left:0.3rem; background:var(--warning); color:white; <?= $unpaid_count > 0 ? '' : 'display:none;' ?>"><?= $unpaid_count ?></span>
        </div>
        <div class="nav-item" data-target="billing" onclick="nav('billing', true); resetAdhocMode();"><i
                class="fa-solid fa-money-check-dollar"></i> Ad Hoc Invoice</div>
        <div class="nav-item" data-target="quotes" onclick="nav('quotes', true)"><i class="fa-solid fa-file-pen"></i>
            Quotes
            <span id="navQuoteCountBadge" class="badge" title="Total quotes"
                style="margin-left:auto; background:<?= $quote_count > 0 ? 'var(--accent)' : 'var(--surface-hover)' ?>; color:<?= $quote_count > 0 ? 'white' : 'var(--text-primary)' ?>;"><?= $quote_count ?></span>
        </div>
        <div class="nav-item" data-target="expenses" onclick="nav('expenses', true)"><i
                class="fa-solid fa-receipt"></i> Expenses
            <span id="navExpenseCountBadge" class="badge" title="Total expenses"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= count($expenses) ?></span>
        </div>
        <div class="nav-item" data-target="clients" onclick="nav('clients', true)"><i class="fa-solid fa-users"></i>
            Clients
            <span id="navClientCountBadge" class="badge" title="Total clients"
                style="margin-left:auto; background:var(--surface-hover); color:var(--text-primary);"><?= $client_count ?></span>
        </div>

        <div class="mid-panel">
        </div>

        <div class="nav-section-label">Data &amp; Tools</div>

        <div class="nav-item tool-item" data-target="stats" onclick="nav('stats', true)"><i
                class="fa-solid fa-chart-line"></i> Statistics
            <?php if (!$licenseValid): ?><i class="fa-solid fa-lock" title="Requires a license"
                    style="margin-left:auto; color:var(--text-secondary); font-size:0.8rem;"></i><?php endif; ?>
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('stats')"
                aria-label="Expand Statistics menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="stats"></div>
        <div class="nav-item tool-item" data-target="sync" onclick="nav('sync', true)"><i
                class="fa-solid fa-rotate"></i> Sync <span class="badge" title="Files needing sync"
                style="margin-left:auto; background:<?= (count($missingFiles) + count($missingDiskData)) > 0 ? 'var(--warning)' : 'var(--surface-hover)' ?>; color:<?= (count($missingFiles) + count($missingDiskData)) > 0 ? 'white' : 'var(--text-primary)' ?>;"><?= count($missingFiles) + count($missingDiskData) ?></span>
        </div>
        <div class="nav-item tool-item" data-target="audit" onclick="nav('audit', true)"><i
                class="fa-solid fa-clock-rotate-left"></i>
            Audit Log</div>
        <?php if ($isAdmin): ?>
        <div class="nav-item tool-item" data-target="backup" onclick="nav('backup', true)"><i
                class="fa-solid fa-database"></i> Data Management
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('backup')"
                aria-label="Expand Data Management menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="backup"></div>
        <?php endif; ?>
        <div class="nav-item tool-item" data-target="docs" onclick="nav('docs', true)"><i class="fa-solid fa-book"></i> Docs
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('docs')"
                aria-label="Expand Docs menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="docs"></div>
        <div class="nav-item tool-item" data-target="settings" onclick="nav('settings', true)"><i
                class="fa-solid fa-gear"></i> Settings
            <?php if (!$licenseValid): ?><span class="badge" title="Not licensed — see License in Settings"
                    style="margin-left:auto; background:var(--warning); color:white;">!</span><?php endif; ?>
            <button type="button" class="nav-subnav-toggle" onclick="event.stopPropagation(); toggleNavSubnav('settings')"
                aria-label="Expand Settings menu"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
        <div class="nav-subnav-slot" data-for="settings"></div>
        <div style="margin-top:1.25rem; border-top:1px solid var(--border);"></div>
        <div style="padding-top:2rem;">
            <div class="global-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="globalSearchInput" placeholder="Search"
                    autocomplete="off" oninput="handleGlobalSearch()" onkeydown="handleGlobalSearchKeydown(event)"
                    onfocus="if (document.getElementById('globalSearchResults').innerHTML.trim() !== '') document.getElementById('globalSearchResults').classList.add('active')">
                <kbd>Ctrl K</kbd>
                <div id="globalSearchResults" class="global-search-results"></div>
            </div>
        </div>
        <div class="user-panel">
            <form method="POST"><input type="hidden" name="auth_action" value="logout"><button type="submit"
                    class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</button></form>
            <div style="display:flex; align-items:center; justify-content:center; gap:0.6rem; margin-top:1rem; font-size:0.75rem; color:var(--text-secondary);">
                <span style="cursor:pointer;" title="View changelog" onclick="nav('docs', true); navDocs('changelog');">
                    <span class="brand-wordmark">Invoxa</span> v<?= htmlspecialchars(APP_VERSION) ?></span>
                <a href="https://gitlab.com/weblabnz/invoxa" target="_blank" title="Source on GitLab"
                    style="color:var(--text-secondary);"><i class="fa-brands fa-gitlab"></i></a>
            </div>
        </div>
    </div>

