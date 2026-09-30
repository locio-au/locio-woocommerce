<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Locio\WooCommerce\Settings;

/**
 * The settings page holds a secret key. It must never render that key back,
 * never accept a key in the wrong field, and only open to shop managers.
 */
final class SettingsTest extends TestCase
{
    private const SECRET = 'lc_live_zyxwvutsrqponmlkjihgfedcba765432';
    private const PUBLIC = 'lc_pub_abcdefghijklmnopqrstuvwxyz234567';

    /** @var list<array{string, string}> */
    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errors = [];
        Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message): void {
            $this->errors[] = [$code, $message];
        });
    }

    public function testDefaultsApplyBeforeAnythingIsSaved(): void
    {
        $s = Settings::load();
        self::assertSame('', $s->publicKey);
        self::assertSame('', $s->secretKey());
        self::assertTrue($s->autocomplete);
        self::assertSame('note', $s->validate);
    }

    public function testThePageIsForShopManagers(): void
    {
        Functions\expect('add_submenu_page')
            ->once()
            ->with('woocommerce', \Mockery::any(), \Mockery::any(), 'manage_woocommerce', 'locio', \Mockery::any());
        (new Settings())->menu();
    }

    public function testSavingNeedsTheSameCapabilityAsThePage(): void
    {
        $settings = new Settings();
        $settings->register();
        self::assertNotFalse(has_action('admin_menu', [$settings, 'menu']));
        self::assertNotFalse(has_action('admin_init', [$settings, 'registerSetting']));
        self::assertNotFalse(has_filter('option_page_capability_locio', [Settings::class, 'capability']));
        self::assertSame('manage_woocommerce', Settings::capability());
    }

    public function testTheRenderedPageNeverContainsTheSecretKey(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'secret_key' => self::SECRET];
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);
        Functions\when('settings_errors')->justReturn(null);
        Functions\when('checked')->justReturn('');
        Functions\when('selected')->justReturn('');

        ob_start();
        (new Settings())->render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString(substr(self::SECRET, 8, 20), $html);
        self::assertStringContainsString(self::PUBLIC, $html, 'the public key is not a secret and is shown');
        self::assertStringContainsString('5432', $html, 'the last four identify the saved secret');
    }

    public function testThePageRendersNothingForSomebodyWithoutTheCapability(): void
    {
        $this->options[Settings::OPTION] = ['secret_key' => self::SECRET];
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        (new Settings())->render();
        self::assertSame('', ob_get_clean());
    }

    public function testASecretKeyInThePublicFieldIsRefusedAndNotRepeated(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC];
        $saved = (new Settings())->sanitize(['public_key' => self::SECRET]);

        self::assertSame(self::PUBLIC, $saved['public_key'], 'the previous public key is kept');
        self::assertCount(1, $this->errors);
        self::assertStringNotContainsString(self::SECRET, $this->errors[0][1]);
    }

    public function testAPublicKeyInTheSecretFieldIsRefused(): void
    {
        $saved = (new Settings())->sanitize(['secret_key' => self::PUBLIC]);

        self::assertSame('', $saved['secret_key']);
        self::assertCount(1, $this->errors);
        self::assertStringNotContainsString(self::PUBLIC, $this->errors[0][1]);
    }

    public function testASecretKeyIsSaved(): void
    {
        $saved = (new Settings())->sanitize(['secret_key' => '  ' . self::SECRET . "\n"]);
        self::assertSame(self::SECRET, $saved['secret_key']);
        self::assertSame([], $this->errors);
    }

    public function testABlankSecretFieldKeepsTheSavedKey(): void
    {
        // The field is never filled in on render, so a blank submit means
        // "unchanged", not "remove".
        $this->options[Settings::OPTION] = ['secret_key' => self::SECRET];
        $saved = (new Settings())->sanitize(['secret_key' => '', 'public_key' => self::PUBLIC]);
        self::assertSame(self::SECRET, $saved['secret_key']);
    }

    public function testTheSecretKeyIsRemovedOnlyWhenAskedTo(): void
    {
        $this->options[Settings::OPTION] = ['secret_key' => self::SECRET];
        $saved = (new Settings())->sanitize(['secret_key' => '', 'remove_secret_key' => '1']);
        self::assertSame('', $saved['secret_key']);
    }

    public function testAKeyThatIsNotAKeyIsRefused(): void
    {
        foreach (['<script>alert(1)</script>', 'lc_pub_', 'hunter2', "lc_pub_abc\r\nx"] as $bad) {
            $this->errors = [];
            $saved = (new Settings())->sanitize(['public_key' => $bad]);
            self::assertSame('', $saved['public_key'], $bad);
            self::assertCount(1, $this->errors, $bad);
        }
    }

    public function testTheValidationModeIsOneOfThree(): void
    {
        $s = new Settings();
        self::assertSame('block', $s->sanitize(['validate' => 'block'])['validate']);
        self::assertSame('off', $s->sanitize(['validate' => 'off'])['validate']);
        self::assertSame('note', $s->sanitize(['validate' => 'drop table'])['validate']);
    }

    public function testAutocompleteIsAToggle(): void
    {
        $s = new Settings();
        self::assertTrue($s->sanitize(['autocomplete' => '1'])['autocomplete']);
        self::assertFalse($s->sanitize([])['autocomplete']);
    }

    public function testASecretKeyInWpConfigWinsAndIsNotStored(): void
    {
        $this->options[Settings::OPTION] = ['secret_key' => 'lc_live_fromthedatabase'];

        $s = Settings::load(constant: self::SECRET);
        self::assertSame(self::SECRET, $s->secretKey());
        self::assertTrue($s->secretFromConfig);

        $saved = (new Settings(constant: self::SECRET))->sanitize(['secret_key' => 'lc_live_somethingelse']);
        self::assertSame('lc_live_fromthedatabase', $saved['secret_key'], 'the field is ignored while the constant is set');
    }

    public function testSomethingThatIsNotAnArrayIsTreatedAsNothing(): void
    {
        $saved = (new Settings())->sanitize('nonsense');
        self::assertSame('', $saved['public_key']);
        self::assertSame('note', $saved['validate']);
    }
}
