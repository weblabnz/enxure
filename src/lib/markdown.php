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
        $safe = preg_match('#^(https?:)?//#i', $url) || strpos($url, '#') === 0 || preg_match('/^[a-zA-Z0-9_.\-]+\.md(#.*)?$/', $url);
        if (!$safe) {
            return htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $text);
    return $text;
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
            $html[] = "<h{$level}>" . invoxaMarkdownInline(trim($m[2])) . "</h{$level}>";
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
