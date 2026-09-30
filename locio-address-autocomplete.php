<?php
/**
 * Plugin Name:          Locio Address Autocomplete for WooCommerce
 * Plugin URI:           https://locio.com.au/guides/address-autocomplete-woocommerce/
 * Description:          Australian address suggestions at checkout and a check against G-NAF when the order is placed, so parcels go to addresses that exist.
 * Version:              0.1.3
 * Requires at least:    6.4
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               Locio
 * Author URI:           https://locio.com.au
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          locio-address-autocomplete
 * WC requires at least: 8.3
 * WC tested up to:      10.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/src/autoload.php';

Locio\WooCommerce\Plugin::boot(__FILE__);
