<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Locio\WooCommerce\Settings;

/** Deleting the plugin takes the stored keys with it. */
final class UninstallTest extends TestCase
{
    public function testUninstallDeletesTheSettingsAndTheKeysInThem(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        Functions\expect('delete_option')->once()->with(Settings::OPTION);
        require dirname(__DIR__, 2) . '/uninstall.php';
    }

    public function testThePluginFileRefusesToRunOutsideWordPress(): void
    {
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/locio-address-autocomplete.php') . ' 2>&1');
        self::assertSame('', (string) $out);
    }
}
