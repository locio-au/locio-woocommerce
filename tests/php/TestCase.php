<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as Base;

abstract class TestCase extends Base
{
    /** @var array<string, mixed> what get_option answers, by option name */
    protected array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('esc_html')->alias(static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES));
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0): string|false => json_encode($v, $flags));
        Functions\when('sanitize_text_field')->alias(static fn ($s): string => trim(strip_tags((string) $s)));
        Functions\when('wp_unslash')->returnArg(1);
    }

    protected function tearDown(): void
    {
        // Mockery expectations are assertions; PHPUnit does not see them.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        Monkey\tearDown();
        parent::tearDown();
    }
}
