<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The SDK ships inside the plugin under a namespace of its own, so another
 * plugin bundling locio/locio at a different version cannot collide with it.
 */
final class SdkTest extends TestCase
{
    public function testTheBundledSdkLoadsUnderThePluginsNamespace(): void
    {
        self::assertTrue(class_exists(\Locio\WooCommerce\Sdk\Client::class));
        self::assertTrue(interface_exists(\Locio\WooCommerce\Sdk\Http\Transport::class));
        self::assertFalse(class_exists(\Locio\Client::class, false), 'the unprefixed SDK leaked in');
    }

    public function testNoFileDeclaresOrUsesTheUnprefixedNamespace(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/lib', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file);
            self::assertDoesNotMatchRegularExpression('/^(namespace|use) Locio\\\\(?!WooCommerce\\\\Sdk)/m', $source, (string) $file);
            self::assertStringNotContainsString('Illuminate', $source, (string) $file);
            $count++;
        }
        self::assertGreaterThan(10, $count);
    }

    public function testTheBundledVersionIsRecorded(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', \Locio\WooCommerce\Sdk\Client::VERSION);
    }
}
