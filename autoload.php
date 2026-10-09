<?php
/**
 * Simple PSR-4 Autoloader
 * Allows running the bot directly with PHP without needing Composer installed.
 */

spl_autoload_register(function ($class) {
    $prefix = 'TwitterDlBot\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
