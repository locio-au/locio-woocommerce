<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Closure;
use Locio\WooCommerce\Sdk\Address;
use Locio\WooCommerce\Sdk\Client;
use Locio\WooCommerce\Sdk\Exception\LocioException;

/**
 * Checks the address when the order is placed, with the secret key, and
 * records what G-NAF said on the order.
 *
 * One unit per order. Whatever goes wrong on our side (a timeout, a rate
 * limit, a spent quota, a revoked key) the order goes through and is marked
 * unchecked: an outage of ours must never cost a shop a sale.
 */
final class Validation
{
    /** @var Closure(string): Client */
    private readonly Closure $client;

    /** @var array{status: string, address: ?Address, reason: string, picked: ?string, line: string}|null */
    private ?array $last = null;

    /** @param (Closure(string): Client)|null $client builds a client for a secret key */
    public function __construct(
        private readonly Settings $settings,
        ?Closure $client = null,
    ) {
        $this->client = $client ?? static fn (#[\SensitiveParameter] string $key): Client => new Client($key, country: 'AU', transport: new WpTransport());
    }

    public function register(): void
    {
        add_action('woocommerce_after_checkout_validation', [$this, 'classic'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'saveClassic'], 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'blocks'], 10, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'show']);
    }

    /**
     * The classic checkout, after WooCommerce's own checks.
     *
     * @param array<string, mixed> $data the posted checkout
     */
    public function classic(array $data, \WP_Error $errors): void
    {
        $type = !empty($data['ship_to_different_address']) ? 'shipping' : 'billing';
        $fields = [];
        foreach (['address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $key) {
            $fields[$key] = $data["{$type}_$key"] ?? '';
        }
        $field = "locio_{$type}_address_id";
        // WooCommerce checked woocommerce-process-checkout-nonce before this
        // hook runs, and cleanId accepts only the shape an id has.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : null;
        $picked = AddressLine::cleanId($data[$field] ?? $posted);

        $this->last = $this->check($fields, $picked);
        if ($this->last['status'] === 'unmatched' && $this->settings->validate === 'block') {
            $errors->add('locio_address', $this->refusal());
        }
    }

    /** @param array<string, mixed> $data */
    public function saveClassic(\WC_Order $order, array $data): void
    {
        if ($this->last !== null) {
            $this->record($order, $this->last);
            $this->last = null;
        }
    }

    /** The block checkout, through the Store API. */
    public function blocks(\WC_Order $order, \WP_REST_Request $request): void
    {
        $type = $order->has_shipping_address() ? 'shipping' : 'billing';
        $fields = [];
        foreach (['address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $key) {
            $fields[$key] = (string) $order->{"get_{$type}_$key"}();
        }
        $extensions = $request->get_param('extensions');
        $picked = AddressLine::cleanId(is_array($extensions) ? ($extensions['locio']["{$type}_address_id"] ?? null) : null);

        $result = $this->check($fields, $picked);
        if ($result['status'] === 'unmatched' && $this->settings->validate === 'block' && class_exists(RouteException::class)) {
            throw new RouteException('locio_address_not_found', $this->refusal(), 400);
        }
        $this->record($order, $result);
    }

    /** A line under the shipping address on the order screen. */
    public function show(\WC_Order $order): void
    {
        $status = (string) $order->get_meta('_locio_status');
        if ($status === 'matched') {
            printf('<p><strong>%s</strong> %s</p>', esc_html__('G-NAF:', 'locio-address-autocomplete'), esc_html((string) $order->get_meta('_locio_address_id')));
        } elseif ($status === 'unmatched') {
            printf('<p><strong>%s</strong></p>', esc_html__('Address not found in G-NAF', 'locio-address-autocomplete'));
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{status: string, address: ?Address, reason: string, picked: ?string, line: string}
     */
    private function check(array $fields, ?string $picked): array
    {
        $result = ['status' => 'skipped', 'address' => null, 'reason' => '', 'picked' => $picked, 'line' => ''];
        $country = strtoupper(is_string($fields['country'] ?? null) ? $fields['country'] : '');
        $line = AddressLine::fromFields($fields);
        $key = $this->settings->secretKey();
        if ($this->settings->validate === 'off' || $key === '' || ($country !== '' && $country !== 'AU') || $line === '') {
            return $result;
        }
        $result['line'] = $line;

        try {
            $resolution = ($this->client)($key)->resolve($line);
        } catch (LocioException $e) {
            // The service's own words, which never carry the key.
            $result['status'] = 'unchecked';
            $result['reason'] = $e->getMessage();

            return $result;
        }
        if ($resolution->matched && $resolution->address !== null) {
            $result['status'] = 'matched';
            $result['address'] = $resolution->address;
        } else {
            $result['status'] = 'unmatched';
        }

        return $result;
    }

    /** @param array{status: string, address: ?Address, reason: string, picked: ?string, line: string} $result */
    private function record(\WC_Order $order, array $result): void
    {
        if ($result['picked'] !== null) {
            $order->update_meta_data('_locio_selected_id', $result['picked']);
        }
        if ($result['status'] === 'skipped') {
            return;
        }
        $order->update_meta_data('_locio_status', $result['status']);

        if ($result['status'] === 'matched' && $result['address'] !== null) {
            $a = $result['address'];
            $order->update_meta_data('_locio_address_id', $a->id);
            $order->update_meta_data('_locio_lat', $a->lat);
            $order->update_meta_data('_locio_lng', $a->lng);
            if ($a->meshBlock !== null) {
                $order->update_meta_data('_locio_mesh_block', $a->meshBlock);
            }
        } elseif ($result['status'] === 'unmatched') {
            $order->add_order_note(sprintf(
                /* translators: %s: the address as the customer wrote it */
                __('Locio could not find this address in G-NAF: %s. Worth checking before it ships.', 'locio-address-autocomplete'),
                $result['line'],
            ));
        } else {
            $order->add_order_note(sprintf(
                /* translators: %s: why the check could not run */
                __('Locio could not check this address (%s). The order was accepted.', 'locio-address-autocomplete'),
                $result['reason'],
            ));
        }
    }

    private function refusal(): string
    {
        return __('We could not find that address. Please check the street number, street and suburb, or pick one of the suggestions.', 'locio-address-autocomplete');
    }
}
