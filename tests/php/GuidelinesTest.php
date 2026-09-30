<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What the wordpress.org review team and Plugin Check refuse, checked against
 * every PHP file the release zip ships.
 */
final class GuidelinesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function shipped(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['locio-address-autocomplete.php', 'uninstall.php'] as $file) {
            $out[$file] = ["$root/$file"];
        }
        foreach (['src', 'lib'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_ends_with((string) $file, '.php')) {
                    $out[substr((string) $file, strlen($root) + 1)] = [(string) $file];
                }
            }
        }

        return $out;
    }

    /** A PHP file opened by URL does nothing: it exits unless WordPress loaded it. */
    #[\PHPUnit\Framework\Attributes\DataProvider('shipped')]
    public function testEveryFileRefusesDirectAccess(string $file): void
    {
        self::assertMatchesRegularExpression(
            "/if \\(!defined\\('(ABSPATH|WP_UNINSTALL_PLUGIN)'\\)\\) \\{\\s*exit;\\s*\\}/",
            (string) file_get_contents($file),
        );
    }

    /** Requests go through the WordPress HTTP API, never cURL directly. */
    #[\PHPUnit\Framework\Attributes\DataProvider('shipped')]
    public function testNothingCallsCurlDirectly(string $file): void
    {
        self::assertDoesNotMatchRegularExpression('/\bcurl_[a-z_]+\s*\(/', (string) file_get_contents($file));
    }

    /** Request input is unslashed and sanitized where it is read. */
    #[\PHPUnit\Framework\Attributes\DataProvider('shipped')]
    public function testRequestInputIsSanitizedWhereItIsRead(string $file): void
    {
        $source = (string) file_get_contents($file);
        preg_match_all('/\$_(POST|GET|REQUEST|COOKIE|SERVER)\[/', $source, $reads, PREG_OFFSET_CAPTURE);
        foreach ($reads[0] as [$match, $offset]) {
            $before = substr($source, max(0, $offset - 40), 40);
            // isset() only asks whether it is there; every read is sanitized.
            self::assertMatchesRegularExpression('/(sanitize_text_field\(\s*wp_unslash\(|isset\()\s*$/', $before, "$match in $file");
        }
        self::addToAssertionCount(1);
    }
}
