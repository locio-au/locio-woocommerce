<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

use Locio\WooCommerce\Sdk\Client;

/** Loads the suggestions script on the checkout, with the public key and nothing else. */
final class Checkout
{
    public function __construct(
        private readonly Settings $settings,
        private readonly string $pluginFile,
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->settings->autocomplete) {
            return;
        }
        $key = $this->settings->publicKey;
        // A secret key is refused when the settings are saved. One written
        // some other way still never reaches a page.
        if ($key === '' || preg_match('/^[a-z]{2,}_live_/i', $key) === 1) {
            return;
        }

        wp_enqueue_style('locio-checkout', plugins_url('assets/checkout.css', $this->pluginFile), [], Plugin::VERSION);
        wp_enqueue_script('locio-checkout', plugins_url('assets/checkout.js', $this->pluginFile), [], Plugin::VERSION, [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);

        $config = [
            'publicKey' => $key,
            'baseUrl' => Client::DEFAULT_BASE_URL,
            'country' => 'AU',
            'types' => ['billing', 'shipping'],
            'minChars' => 3,
            'limit' => 6,
            'i18n' => [
                'label' => __('Address suggestions', 'locio-address-autocomplete'),
                'noResults' => __('No matching address', 'locio-address-autocomplete'),
                'poweredBy' => __('Addresses from G-NAF', 'locio-address-autocomplete'),
            ],
        ];
        // HEX_TAG and friends so no translated string can close the script tag.
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        wp_add_inline_script('locio-checkout', 'window.locioCheckout = ' . $json . ';', 'before');
    }
}
