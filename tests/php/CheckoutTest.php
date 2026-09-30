<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Locio\WooCommerce\AddressLine;
use Locio\WooCommerce\Checkout;
use Locio\WooCommerce\Settings;
use Locio\WooCommerce\WpTransport;
use Locio\WooCommerce\Sdk\Exception\TransportException;

final class CheckoutTest extends TestCase
{
    private const SECRET = 'lc_live_abcdefghijklmnopqrstuvwxyz234567';
    private const PUBLIC = 'lc_pub_abcdefghijklmnopqrstuvwxyz234567';

    /** @var list<string> */
    private array $inline = [];
    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->inline = [];
        $this->enqueued = [];
        Functions\when('is_checkout')->justReturn(true);
        Functions\when('plugins_url')->alias(static fn (string $path): string => "https://shop.example/wp-content/plugins/locio/$path");
        Functions\when('wp_enqueue_script')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });
        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });
        Functions\when('wp_add_inline_script')->alias(function (string $handle, string $code): bool {
            $this->inline[] = $code;

            return true;
        });
    }

    private function enqueue(): void
    {
        (new Checkout(Settings::load(), '/plugin/locio-address-autocomplete.php'))->enqueue();
    }

    public function testTheScriptLoadsOnCheckoutWithThePublicKey(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'secret_key' => self::SECRET, 'autocomplete' => true];
        $this->enqueue();

        self::assertContains('locio-checkout', $this->enqueued);
        self::assertCount(1, $this->inline);
        self::assertStringStartsWith('window.locioCheckout = ', $this->inline[0]);
        $config = json_decode(substr(rtrim($this->inline[0], ';'), strlen('window.locioCheckout = ')), true);
        self::assertSame(self::PUBLIC, $config['publicKey']);
        self::assertSame('https://api.locio.com.au', $config['baseUrl']);
        self::assertSame('AU', $config['country']);
        self::assertSame(['billing', 'shipping'], $config['types']);
    }

    public function testTheSecretKeyNeverReachesThePage(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'secret_key' => self::SECRET, 'autocomplete' => true];
        $this->enqueue();

        self::assertStringNotContainsString(self::SECRET, implode("\n", $this->inline));
        self::assertStringNotContainsString('_live_', implode("\n", $this->inline));
    }

    public function testASecretKeySavedAsThePublicOneIsNeverEmitted(): void
    {
        // Settings refuses this on save; an option written some other way
        // (WP-CLI, a migration) still must not reach a page.
        $this->options[Settings::OPTION] = ['public_key' => self::SECRET, 'autocomplete' => true];
        $this->enqueue();

        self::assertSame([], $this->enqueued);
        self::assertSame([], $this->inline);
    }

    public function testNothingLoadsOffTheCheckout(): void
    {
        Functions\when('is_checkout')->justReturn(false);
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'autocomplete' => true];
        $this->enqueue();
        self::assertSame([], $this->enqueued);
    }

    public function testNothingLoadsWithoutAPublicKeyOrWithAutocompleteOff(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => '', 'autocomplete' => true];
        $this->enqueue();
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'autocomplete' => false];
        $this->enqueue();
        self::assertSame([], $this->enqueued);
    }

    public function testTheInlineConfigCannotCloseTheScriptTag(): void
    {
        $this->options[Settings::OPTION] = ['public_key' => self::PUBLIC, 'autocomplete' => true];
        Functions\when('__')->justReturn('</script><script>alert(1)</script>');
        $this->enqueue();

        self::assertStringNotContainsString('</script>', $this->inline[0]);
    }

    // AddressLine: what is resolved on the server.

    public function testTheLineIsBuiltFromTheCheckoutFields(): void
    {
        self::assertSame(
            'Unit 3, 12 Smith Street, Coburg VIC 3058',
            AddressLine::fromFields(['address_1' => '12 Smith Street', 'address_2' => 'Unit 3', 'city' => 'Coburg', 'state' => 'VIC', 'postcode' => '3058']),
        );
        self::assertSame(
            '12 Smith Street, Coburg 3058',
            AddressLine::fromFields(['address_1' => ' 12 Smith Street ', 'address_2' => '', 'city' => 'Coburg', 'postcode' => '3058']),
        );
        self::assertSame('', AddressLine::fromFields(['address_1' => '', 'city' => 'Coburg']));
    }

    public function testAPickedIdIsKeptOnlyIfItLooksLikeOne(): void
    {
        self::assertSame('GAVIC411711441', AddressLine::cleanId('GAVIC411711441'));
        self::assertSame('us-123_ab', AddressLine::cleanId('us-123_ab'));
        foreach (['', '<script>', str_repeat('A', 65), "GAVIC1\nx", 'GAVIC 1', ['array']] as $bad) {
            self::assertNull(AddressLine::cleanId($bad), json_encode($bad));
        }
    }

    // WpTransport: requests through WordPress's HTTP API.

    public function testTheTransportGoesThroughWordPressSafely(): void
    {
        $args = null;
        Functions\when('wp_safe_remote_get')->alias(function (string $url, array $a) use (&$args): array {
            $args = $a;

            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_headers')->justReturn(['X-Quota-Limit' => '100', 'Set-Cookie' => ['a=1', 'b=2']]);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"data":[]}');

        $response = (new WpTransport(timeout: 4.0))->get('https://api.locio.com.au/v1/addresses?q=x', ['Authorization' => 'Bearer lc_live_x', 'User-Agent' => 'locio-php/0.1.0']);

        self::assertSame(0, $args['redirection'], 'a redirect would carry the key');
        self::assertSame(4.0, $args['timeout']);
        self::assertSame(8 * 1024 * 1024, $args['limit_response_size']);
        self::assertSame('Bearer lc_live_x', $args['headers']['Authorization']);
        self::assertStringStartsWith('locio-php/0.1.0', $args['user-agent']);
        self::assertSame(200, $response->status);
        self::assertSame('100', $response->header('x-quota-limit'));
        self::assertSame('b=2', $response->header('set-cookie'));
        self::assertSame('{"data":[]}', $response->body);
    }

    public function testAFailedRequestIsATransportException(): void
    {
        Functions\when('wp_safe_remote_get')->justReturn(new \WP_Error('http_request_failed', 'cURL error 28: timed out'));
        Functions\when('is_wp_error')->alias(static fn ($v): bool => $v instanceof \WP_Error);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/timed out/');
        (new WpTransport())->get('https://api.locio.com.au/v1/addresses', []);
    }
}
