<?php

declare(strict_types=1);

// Vendor autoload (normal composer install)
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require $vendorAutoload;
    return;
}

// Fallback: manual PSR-4 autoloader
spl_autoload_register(function (string $class): void {
    $base = dirname(__DIR__) . '/src/';
    if (!str_starts_with($class, 'LaravelAnalyzer\\')) return;
    $relative = substr($class, strlen('LaravelAnalyzer\\'));
    $file = $base . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
