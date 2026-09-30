<?php

// Copies locio/locio into lib/ under Locio\WooCommerce\Sdk.
//
//     php bin/sync-sdk.php ../locio-php
//
// WordPress has no Composer at install time, so the SDK ships inside the
// plugin. It ships under a namespace of its own because another plugin could
// bundle locio/locio at a different version, and PHP loads one class per
// name: whichever plugin loaded first would win for both. The Laravel layer
// is left behind; WordPress has no use for it.

declare(strict_types=1);

$source = rtrim($argv[1] ?? '', '/');
if ($source === '' || !is_file("$source/src/Client.php")) {
    fwrite(STDERR, "usage: php bin/sync-sdk.php <path to locio-php>\n");
    exit(2);
}

$target = dirname(__DIR__) . '/lib';
$prefix = 'Locio\\WooCommerce\\Sdk';

$remove = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($remove as $file) {
    $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$source/src", FilesystemIterator::SKIP_DOTS));
$copied = 0;
foreach ($files as $file) {
    $relative = substr((string) $file, strlen("$source/src/"));
    if (str_starts_with($relative, 'Laravel/') || !str_ends_with($relative, '.php')) {
        continue;
    }
    $code = (string) file_get_contents((string) $file);
    // Declarations, imports and fully qualified references in code and docblocks.
    $code = preg_replace('/^namespace Locio(;|\\\\)/m', "namespace $prefix\$1", $code);
    $code = preg_replace('/^use Locio\\\\/m', "use $prefix\\\\", $code);
    $code = preg_replace('/(?<![\\\\\w])\\\\Locio\\\\/', "\\\\$prefix\\\\", $code);

    $out = "$target/$relative";
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0o755, true);
    }
    file_put_contents($out, $code);
    $copied++;
}

// MIT asks that the notice travel with the code.
copy("$source/LICENSE", "$target/LICENSE");

$version = trim((string) shell_exec('git -C ' . escapeshellarg($source) . ' describe --tags --always 2>/dev/null'));
file_put_contents("$target/VERSION", ($version ?: 'unknown') . "\n");
echo "copied $copied files from $source ($version)\n";
