<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Tests;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Locio\WooCommerce\Sdk\Client;
use Locio\WooCommerce\Sdk\Http\Response;
use Locio\WooCommerce\Sdk\Http\Transport;
use Locio\WooCommerce\Settings;
use Locio\WooCommerce\Validation;

final class ValidationTest extends TestCase
{
    private const SECRET = 'lc_live_abcdefghijklmnopqrstuvwxyz234567';

    private const MATCH = ['data' => ['matched' => true, 'address' => [
        'id' => 'GAVIC411711441', 'formatted' => '12 SMITH STREET, COBURG VIC 3058',
        'lat' => -37.74, 'lng' => 144.96, 'mesh_block' => '20663070000',
    ]]];

    /** @var list<string> */
    private array $urls = [];

    /** @param array<string, mixed>|null $answer null means the service is unreachable */
    private function validation(?array $answer, string $mode = 'note', int $status = 200): Validation
    {
        $this->options[Settings::OPTION] = ['secret_key' => self::SECRET, 'validate' => $mode];
        $this->urls = [];
        $transport = new class ($answer, $status, $this->urls) implements Transport {
            /** @param list<string> $urls */
            public function __construct(private ?array $answer, private int $status, private array &$urls)
            {
            }

            public function get(string $url, array $headers): Response
            {
                $this->urls[] = $url;
                if ($this->answer === null) {
                    throw new \Locio\WooCommerce\Sdk\Exception\TransportException('locio: request failed: timed out');
                }

                return new Response($this->status, [], (string) json_encode($this->answer));
            }
        };

        return new Validation(Settings::load(), static fn (string $key): Client => new Client($key, country: 'AU', transport: $transport));
    }

    /** @return array<string, string> */
    private function posted(string $type = 'billing', array $extra = []): array
    {
        return $extra + [
            "{$type}_address_1" => '12 smith st',
            "{$type}_address_2" => '',
            "{$type}_city" => 'Coburg',
            "{$type}_state" => 'VIC',
            "{$type}_postcode" => '3058',
            "{$type}_country" => 'AU',
        ];
    }

    public function testAMatchedAddressIsStoredOnTheOrder(): void
    {
        $v = $this->validation(self::MATCH);
        $errors = new \WP_Error();
        $v->classic($this->posted(), $errors);
        $order = new \WC_Order();
        $v->saveClassic($order, []);

        self::assertFalse($errors->has_errors());
        self::assertStringContainsString('q=12%20smith%20st%2C%20Coburg%20VIC%203058', $this->urls[0]);
        self::assertSame('matched', $order->meta['_locio_status']);
        self::assertSame('GAVIC411711441', $order->meta['_locio_address_id']);
        self::assertSame(-37.74, $order->meta['_locio_lat']);
        self::assertSame('20663070000', $order->meta['_locio_mesh_block']);
    }

    public function testTheShippingAddressIsCheckedWhenItDiffers(): void
    {
        $v = $this->validation(self::MATCH);
        $data = $this->posted('shipping', ['ship_to_different_address' => 1]) + $this->posted('billing', ['billing_address_1' => '99 other rd']);
        $v->classic($data, new \WP_Error());
        self::assertStringContainsString('12%20smith%20st', $this->urls[0]);
    }

    public function testAnUnmatchedAddressIsNotedAndTheOrderGoesThrough(): void
    {
        $v = $this->validation(['data' => ['matched' => false, 'address' => null]], 'note');
        $errors = new \WP_Error();
        $v->classic($this->posted(), $errors);
        $order = new \WC_Order();
        $v->saveClassic($order, []);

        self::assertFalse($errors->has_errors());
        self::assertSame('unmatched', $order->meta['_locio_status']);
        self::assertCount(1, $order->notes);
    }

    public function testBlockModeRefusesAnUnmatchedAddress(): void
    {
        $v = $this->validation(['data' => ['matched' => false, 'address' => null]], 'block');
        $errors = new \WP_Error();
        $v->classic($this->posted(), $errors);
        self::assertTrue($errors->has_errors());
        self::assertArrayHasKey('locio_address', $errors->errors);
    }

    public function testAnOutageNeverBlocksACheckout(): void
    {
        foreach ([[null, 200], [['title' => 'quota exhausted'], 402], [['title' => 'too many'], 429], [['title' => 'bad key'], 401]] as [$answer, $status]) {
            $v = $this->validation($answer, 'block', $status);
            $errors = new \WP_Error();
            $v->classic($this->posted(), $errors);
            $order = new \WC_Order();
            $v->saveClassic($order, []);

            self::assertFalse($errors->has_errors(), "status $status");
            self::assertSame('unchecked', $order->meta['_locio_status']);
            self::assertStringNotContainsString(self::SECRET, implode(' ', $order->notes));
        }
    }

    public function testNothingIsCheckedOutsideAustralia(): void
    {
        $v = $this->validation(self::MATCH, 'block');
        $v->classic($this->posted('billing', ['billing_country' => 'NZ']), new \WP_Error());
        self::assertSame([], $this->urls);
    }

    public function testNothingIsCheckedWhenValidationIsOff(): void
    {
        $v = $this->validation(self::MATCH, 'off');
        $v->classic($this->posted(), new \WP_Error());
        self::assertSame([], $this->urls);
    }

    public function testNothingIsCheckedWithoutASecretKey(): void
    {
        $this->options[Settings::OPTION] = ['validate' => 'block'];
        $called = false;
        $v = new Validation(Settings::load(), static function () use (&$called): Client {
            $called = true;
            throw new \LogicException('no client without a key');
        });
        $errors = new \WP_Error();
        $v->classic($this->posted(), $errors);
        self::assertFalse($called);
        self::assertFalse($errors->has_errors());
    }

    public function testThePickedIdIsKeptWhenNothingWasChecked(): void
    {
        $this->options[Settings::OPTION] = ['validate' => 'off'];
        $v = new Validation(Settings::load(), static fn (): Client => throw new \LogicException());
        $v->classic($this->posted('billing', ['locio_billing_address_id' => 'GAVIC411711441']), new \WP_Error());
        $order = new \WC_Order();
        $v->saveClassic($order, []);
        self::assertSame('GAVIC411711441', $order->meta['_locio_selected_id']);
    }

    public function testAForgedPickedIdIsDropped(): void
    {
        $this->options[Settings::OPTION] = ['validate' => 'off'];
        $v = new Validation(Settings::load(), static fn (): Client => throw new \LogicException());
        $v->classic($this->posted('billing', ['locio_billing_address_id' => '<script>alert(1)</script>']), new \WP_Error());
        $order = new \WC_Order();
        $v->saveClassic($order, []);
        self::assertArrayNotHasKey('_locio_selected_id', $order->meta);
    }

    public function testTheBlockCheckoutIsCheckedFromTheOrder(): void
    {
        $v = $this->validation(self::MATCH);
        $order = new \WC_Order(shipping: ['address_1' => '12 smith st', 'city' => 'Coburg', 'state' => 'VIC', 'postcode' => '3058', 'country' => 'AU']);
        $v->blocks($order, new \WP_REST_Request());

        self::assertSame('matched', $order->meta['_locio_status']);
        self::assertSame('GAVIC411711441', $order->meta['_locio_address_id']);
    }

    public function testTheBlockCheckoutRefusesAnUnmatchedAddressInBlockMode(): void
    {
        $v = $this->validation(['data' => ['matched' => false, 'address' => null]], 'block');
        $order = new \WC_Order(billing: ['address_1' => '1 nowhere', 'city' => 'X', 'postcode' => '3000', 'country' => 'AU']);

        $this->expectException(RouteException::class);
        $v->blocks($order, new \WP_REST_Request());
    }

    public function testTheBlockCheckoutKeepsAPickedIdFromExtensionData(): void
    {
        $this->options[Settings::OPTION] = ['validate' => 'off'];
        $v = new Validation(Settings::load(), static fn (): Client => throw new \LogicException());
        $order = new \WC_Order(billing: ['address_1' => '12 smith st', 'country' => 'AU']);
        $v->blocks($order, new \WP_REST_Request(['extensions' => ['locio' => ['billing_address_id' => 'GAVIC411711441']]]));
        self::assertSame('GAVIC411711441', $order->meta['_locio_selected_id']);
    }
}
