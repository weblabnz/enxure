        <!-- STATS -->
        <div id="sec-stats" class="section">
            <?= renderStatsSection() ?>
        </div>

        <!-- AUDIT LOG -->
        <div id="sec-audit" class="section">
            <?= renderAuditSection($actions) ?>
        </div>

        <!-- SYNC -->
        <div id="sec-sync" class="section">
            <?= renderSyncSection($missingFiles, $knownClientFolders, $missingDiskData) ?>
        </div>

