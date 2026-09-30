<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

define('ABSPATH', sys_get_temp_dir() . '/');

// The plugin's own autoloader, as WordPress would load it.
require dirname(__DIR__, 2) . '/src/autoload.php';

require __DIR__ . '/Stubs.php';
