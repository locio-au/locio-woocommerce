<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Locio\WooCommerce\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * One version, said three times: the header WordPress reads, the constant the
 * scripts are cache busted with, and the readme's Stable tag, which is what
 * wordpress.org serves. Out of step, a shop keeps a stale script or the
 * directory offers the wrong release.
 */
final class VersionTest extends TestCase
{
    public function testTheHeaderTheConstantAndTheStableTagAgree(): void
    {
        $root = dirname(__DIR__, 2);
        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents("$root/locio-address-autocomplete.php"), $header);
        preg_match('/^Stable tag:\s*(\S+)/m', (string) file_get_contents("$root/readme.txt"), $stable);

        self::assertSame(Plugin::VERSION, $header[1] ?? null, 'plugin header');
        self::assertSame(Plugin::VERSION, $stable[1] ?? null, 'readme Stable tag');
    }

    public function testTheReadmeHasAChangelogEntryForThisVersion(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/readme.txt');
        self::assertStringContainsString('= ' . Plugin::VERSION . ' =', $readme);
    }
}
