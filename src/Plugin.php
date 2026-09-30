<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

/** Wires the parts to WordPress once WooCommerce is loaded. */
final class Plugin
{
    public const VERSION = '0.1.1';

    public static function boot(string $pluginFile): void
    {
        // HPOS and the block checkout, declared before WooCommerce initialises.
        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $pluginFile, true);
            }
        });

        add_action('plugins_loaded', static function () use ($pluginFile): void {
            if (!class_exists(\WooCommerce::class)) {
                add_action('admin_notices', static function (): void {
                    printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html__('Locio Address Autocomplete needs WooCommerce to be active.', 'locio-address-autocomplete'));
                });

                return;
            }
            $settings = Settings::load();
            $settings->register();
            (new Checkout($settings, $pluginFile))->register();
            (new Validation($settings))->register();

            add_filter('plugin_action_links_' . plugin_basename($pluginFile), static function (array $links): array {
                $url = admin_url('admin.php?page=' . Settings::PAGE);
                array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Settings', 'locio-address-autocomplete')));

                return $links;
            });
        });
    }
}
