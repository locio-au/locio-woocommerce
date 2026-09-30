<?php

declare(strict_types=1);

// PSR-4 for the plugin and its bundled SDK, without Composer at runtime.

spl_autoload_register(static function (string $class): void {
    static $roots = [
        'Locio\\WooCommerce\\Sdk\\' => __DIR__ . '/../lib/',
        'Locio\\WooCommerce\\' => __DIR__ . '/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
