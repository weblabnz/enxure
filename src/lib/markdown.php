<?php
// Small Markdown -> HTML renderer for the in-app doc viewer (README.md /
// INSTALL.md). Supports headings, paragraphs, bold/italic, inline code,
// fenced code blocks, links, ordered/unordered lists, and GFM pipe tables —
// not a general-purpose parser.
function invoxaMarkdownInline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // The one bit of raw HTML markdown source is allowed to use — a line
    // break inside a pipe-table cell, which GFM tables have no other way to
    // express. Narrow allowlist, not general HTML passthrough.
    $text = preg_replace('/&lt;br\s*\/?&gt;/i', '<br>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
    // Must run before the link regex below — otherwise it matches the
    // [alt](url) part of an image and leaves the leading ! behind as text.
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function ($m) {
        if (preg_match('#^(javascript|data):#i', $m[2])) {
            return $m[1];
        }
        // $m[1]/$m[2] are already htmlspecialchars-escaped (this whole string
        // was, above) so they're safe to drop straight into src=/alt= as-is —
        // escaping them again here would double-escape any & in the path.
        return '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $url = $m[2];
        // A link to README.md/INSTALL.md (optionally #anchor) doesn't correspond
        // to a real server route inside the app — the doc viewer (the login
        // page's modal, or the authenticated Docs tab) renders their content
        // dynamically rather than serving the raw files, so a plain href to it
        // 404s / does nothing. Route it through the app's own doc navigation
        // instead, staying inside whichever doc viewer the reader is already in.
        if (preg_match('/^(README|INSTALL)\.md(?:#([a-zA-Z0-9-]+))?$/i', $url, $dm)) {
            $target = strtolower($dm[1]) === 'install' ? 'install' : 'readme';
            $anchor = $dm[2] ?? '';
            return '<a href="javascript:void(0)" onclick="invoxaGoToDoc(\'' . $target . '\', ' . ($anchor !== '' ? "'{$anchor}'" : 'null') . '); return false;">' . $m[1] . '</a>';
        }
        // A same-document anchor (e.g. "#licensing", linking to a heading
        // elsewhere in this same file) needs to jump in place, not open a new
        // tab to a blank page — target="_blank" is only meaningful for an
        // actual external URL.
        if (strpos($url, '#') === 0) {
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>';
        }
        $safe = preg_match('#^(https?:)?//#i', $url);
        if (!$safe) {
            return htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $text);
    return $text;
}

// GitHub-style heading slug (lowercase, spaces/underscores to hyphens, strip
// punctuation) — matches the anchors README.md/INSTALL.md's own cross-links
// already use (e.g. "## Migrating to a new server" -> "migrating-to-a-new-server"),
// so a rendered heading's id lines up with an incoming #anchor link.
function invoxaSlugify(string $text): string
{
    $slug = strtolower($text);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s_]+/', '-', trim($slug));
    return trim($slug, '-');
}

function invoxaRenderMarkdown(string $md): string
{
    $lines = preg_split('/\r\n|\n/', $md);
    $html = [];
    $i = 0;
    $n = count($lines);
    $listStack = []; // stack of 'ul'|'ol'

    $closeLists = function () use (&$html, &$listStack) {
        while ($listStack) {
            $html[] = '</' . array_pop($listStack) . '>';
        }
    };

    while ($i < $n) {
        $line = $lines[$i];

        // Fenced code block
        if (preg_match('/^```/', $line)) {
            $closeLists();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', $lines[$i])) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++; // skip closing fence
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $closeLists();
            $level = strlen($m[1]);
            $title = trim($m[2]);
            $html[] = "<h{$level} id=\"" . invoxaSlugify($title) . "\">" . invoxaMarkdownInline($title) . "</h{$level}>";
            $i++;
            continue;
        }

        // Raw passthrough for <details>/<summary>/</details> — the one
        // block-level HTML tag README.md's GitHub-flavored "collapsible
        // section" convention needs, which this renderer otherwise has no
        // way to express (everything else gets htmlspecialchars'd as plain
        // text, same as <br> below getting a narrow allowlist of its own).
        if (preg_match('#^<(details|/details)>$#i', trim($line)) || preg_match('#^<summary>.*</summary>$#i', trim($line))) {
            $closeLists();
            $html[] = trim($line);
            $i++;
            continue;
        }

        // Pipe table (header row + separator row)
        if (strpos($line, '|') !== false && $i + 1 < $n && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*\|?\s*$/', $lines[$i + 1])) {
            $closeLists();
            $headerCells = array_map('trim', explode('|', trim(trim($line), '|')));
            $html[] = '<table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . invoxaMarkdownInline($c) . '</th>', $headerCells)) . '</tr></thead><tbody>';
            $i += 2;
            while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') {
                $cells = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
                $html[] = '<tr>' . implode('', array_map(fn($c) => '<td>' . invoxaMarkdownInline($c) . '</td>', $cells)) . '</tr>';
                $i++;
            }
            $html[] = '</tbody></table>';
            continue;
        }

        // Unordered list
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            if (!$listStack || end($listStack) !== 'ul') {
                $closeLists();
                $listStack[] = 'ul';
                $html[] = '<ul>';
            }
            $html[] = '<li>' . invoxaMarkdownInline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Ordered list
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            if (!$listStack || end($listStack) !== 'ol') {
                $closeLists();
                $listStack[] = 'ol';
                $html[] = '<ol>';
            }
            $html[] = '<li>' . invoxaMarkdownInline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Blank line
        if (trim($line) === '') {
            $closeLists();
            $i++;
            continue;
        }

        // Paragraph (accumulate until blank line / block-level marker)
        $closeLists();
        $para = [$line];
        $i++;
        while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,4}\s|```|\s*[-*]\s|\s*\d+\.\s)/', $lines[$i])) {
            $para[] = $lines[$i];
            $i++;
        }
        $html[] = '<p>' . invoxaMarkdownInline(implode(' ', $para)) . '</p>';
    }
    $closeLists();

    return '<div class="doc-content">' . implode("\n", $html) . '</div>';
}

function invoxaChangelogCategoryMeta(string $category): array
{
    switch (strtolower($category)) {
        case 'added': return ['success', 'fa-plus'];
        case 'changed': return ['accent', 'fa-arrows-rotate'];
        case 'fixed': return ['warning', 'fa-wrench'];
        case 'removed': return ['danger', 'fa-minus'];
        default: return ['accent', 'fa-circle-dot'];
    }
}

// Timeline layout tailored to CHANGELOG.md's own convention — `## [x.y.z] -
// YYYY-MM-DD` release headings each followed by `### Added`/`Changed`/
// `Fixed`/`Removed` bullet groups — rather than the generic renderer above.
function invoxaRenderChangelog(string $md): string
{
    $lines = preg_split('/\r\n|\n/', $md);
    $n = count($lines);
    $i = 0;

    $intro = [];
    while ($i < $n && !preg_match('/^##\s+\[/', $lines[$i])) {
        $intro[] = $lines[$i];
        $i++;
    }

    $entries = [];
    while ($i < $n) {
        if (!preg_match('/^##\s+\[([^\]]+)\]\s*-\s*(\d{4}-\d{2}-\d{2})/', $lines[$i], $m)) {
            $i++;
            continue;
        }
        $version = $m[1];
        $date = $m[2];
        $i++;
        $categories = [];
        $curCat = null;
        $notes = [];
        while ($i < $n && !preg_match('/^##\s+\[/', $lines[$i])) {
            $line = $lines[$i];
            if (preg_match('/^###\s+(.*)$/', $line, $mm)) {
                $curCat = trim($mm[1]);
                $categories[$curCat] = $categories[$curCat] ?? [];
            } elseif (preg_match('/^\s*[-*]\s+(.*)$/', $line, $mm)) {
                if ($curCat === null) {
                    $curCat = 'Notes';
                    $categories[$curCat] = $categories[$curCat] ?? [];
                }
                $categories[$curCat][] = $mm[1];
            } elseif (trim($line) !== '') {
                $notes[] = $line;
            }
            $i++;
        }
        $entries[] = ['version' => $version, 'date' => $date, 'categories' => $categories, 'notes' => $notes];
    }

    $html = [invoxaRenderMarkdown(implode("\n", $intro))];

    $visibleCount = 8;
    $html[] = '<div class="changelog-timeline">';
    foreach ($entries as $idx => $entry) {
        $classes = 'changelog-entry' . ($idx === 0 ? ' is-latest' : '') . ($idx >= $visibleCount ? ' changelog-older' : '');
        $html[] = '<div class="' . $classes . '">';
        $html[] = '<div class="changelog-dot"></div>';
        $html[] = '<div class="changelog-card">';
        $html[] = '<div class="changelog-card-head">';
        $html[] = '<span class="changelog-version">v' . htmlspecialchars($entry['version'], ENT_QUOTES, 'UTF-8') . '</span>';
        if ($idx === 0) {
            $html[] = '<span class="badge changelog-latest-badge">Latest</span>';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $entry['date']);
        $html[] = '<span class="changelog-date">' . ($dt ? $dt->format('M j, Y') : htmlspecialchars($entry['date'], ENT_QUOTES, 'UTF-8')) . '</span>';
        $html[] = '</div>';
        if ($entry['notes']) {
            $html[] = '<p class="changelog-notes">' . invoxaMarkdownInline(implode(' ', $entry['notes'])) . '</p>';
        }
        foreach ($entry['categories'] as $cat => $items) {
            [$color, $icon] = invoxaChangelogCategoryMeta($cat);
            $html[] = '<div class="changelog-category changelog-category-' . $color . '">';
            $html[] = '<div class="changelog-category-label"><i class="fa-solid ' . $icon . '"></i>' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . '</div>';
            $html[] = '<ul>';
            foreach ($items as $item) {
                $html[] = '<li>' . invoxaMarkdownInline($item) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '</div>';
        }
        $html[] = '</div>'; // .changelog-card
        $html[] = '</div>'; // .changelog-entry
    }
    $html[] = '</div>'; // .changelog-timeline

    $olderCount = count($entries) - $visibleCount;
    if ($olderCount > 0) {
        $html[] = '<div class="changelog-show-more">';
        $html[] = '<button type="button" class="btn" data-show-label="Show ' . $olderCount . ' older release' . ($olderCount === 1 ? '' : 's') . '" onclick="toggleChangelogOlder(this)">Show ' . $olderCount . ' older release' . ($olderCount === 1 ? '' : 's') . '</button>';
        $html[] = '</div>';
    }

    return implode("\n", $html);
}
