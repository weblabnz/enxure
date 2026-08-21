<?php
// Hand-rolled PSR-4 autoloader for dompdf and its dependencies, vendored as
// plain files under this directory (same pattern as ../phpmailer — this app
// isn't a Composer project). Each entry below is {namespace prefix => source
// directory}, taken from the matching package's composer.json
// "autoload.psr-4" block.
spl_autoload_register(function (string $class) {
    static $prefixes = [
        'Dompdf\\' => __DIR__ . '/dompdf/src/',
        'FontLib\\' => __DIR__ . '/php-font-lib/src/FontLib/',
        'Svg\\' => __DIR__ . '/php-svg-lib/src/Svg/',
        'Sabberworm\\CSS\\' => __DIR__ . '/php-css-parser/src/',
        'Masterminds\\' => __DIR__ . '/html5-php/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});
// Not covered by PSR-4 above: dompdf ships this one class under lib/ (a
// composer "classmap" entry, not its src/ psr-4 root) for its pure-PHP PDF
// writer backend.
require_once __DIR__ . '/dompdf/lib/Cpdf.php';
