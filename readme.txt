=== Locio Address Autocomplete for WooCommerce ===
Contributors: locio
Tags: address autocomplete, address validation, australia, checkout, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Australian address autocomplete at checkout, and a check against G-NAF when the order is placed, so parcels go to addresses that exist.

== Description ==

Customers type the start of their address and pick it from a list. The street, suburb, state and postcode fill themselves in, spelled the way Australia Post expects.

When the order is placed, the address is checked against G-NAF, the national register of every Australian address. A matched order carries its G-NAF id, latitude, longitude and ABS mesh block. An address that is not found is either noted on the order or sent back to the customer to correct, whichever you choose.

* Works on the block checkout and the classic checkout
* Keyboard and screen reader friendly suggestions, sized for phones
* Never blocks a sale: if Locio cannot answer, the order goes through and is marked unchecked
* Your secret key stays on the server, and can live in wp-config.php instead of the database
* Compatible with High Performance Order Storage

Suggestions appear for Australian addresses only. Other countries check out exactly as before.

= Open source =

The plugin is open source under the GPL, version 2 or later. The source, issues and changes live at https://github.com/locio-au/locio-woocommerce, and contributions are welcome there. The zip is the source as it is in the repository: nothing is minified, obfuscated or built from code you cannot read.

== External services ==

This plugin sends addresses to Locio (https://locio.com.au) to suggest and check them. Nothing is sent until you enter a key.

* **While a customer types an address at checkout**, their browser sends what they have typed so far, with your public key, to https://api.locio.com.au/v1/addresses. This happens only when suggestions are switched on and a public key is saved.
* **When an order is placed**, your server sends the Australian delivery address, with your secret key, to https://api.locio.com.au/v1/addresses/resolve. This happens only when checking is switched on and a secret key is saved.

No name, email, phone number or order detail is sent. Locio's terms and privacy policy: https://locio.com.au/legal/

The suggestions list shows the line "Addresses from G-NAF". It is the attribution G-NAF's CC BY 4.0 licence requires wherever its data is shown, and it is plain text, not a link.

Address data is G-NAF, © Geoscape Australia, licensed under CC BY 4.0.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Create a free account at https://locio.com.au and open Keys.
3. Create a **public key** and add your shop's address (for example https://shop.example.com.au) to its allowed origins. Paste it into WooCommerce → Locio.
4. To check addresses when orders are placed, also paste a **secret key**, or add this line to wp-config.php:

    define( 'LOCIO_SECRET_KEY', 'lc_live_...' );

5. Choose what happens when an address is not found, and save.

== Frequently Asked Questions ==

= What does it cost? =

Each suggestion request and each order check uses one unit of your Locio plan. The free plan includes 10,000 units a month, which covers a small shop; see https://locio.com.au/pricing/.

Nothing in the plugin is locked or limited: every feature works on every plan, including the free one. The only limit is the service's monthly allowance, and when it runs out the checkout carries on as a normal WooCommerce checkout.

= What if Locio is down or my quota runs out? =

The order goes through. It is marked unchecked, with a note saying why. Suggestions simply stop appearing and the fields work as normal.

= Where do I see the result? =

On the order screen, under the shipping address. The G-NAF id, coordinates and mesh block are saved as order meta: `_locio_address_id`, `_locio_lat`, `_locio_lng`, `_locio_mesh_block`, and `_locio_status` (matched, unmatched or unchecked).

= My theme is dark. =

The suggestions panel reads five CSS variables. Override them in your theme:

    .locio-panel { --locio-bg: #111; --locio-fg: #eee; --locio-muted: #999; --locio-line: #333; --locio-active: #222; }

= Why a public key and a secret key? =

The public key is printed into your checkout page so browsers can ask for suggestions. It only works from the origins you allow, so a copy is useless elsewhere. The secret key can do everything your plan allows, so it never leaves your server. The plugin refuses a secret key pasted into the public field.

== Changelog ==

= 0.1.3 =
* Every PHP file refuses to run unless WordPress loaded it.
* Requests go only through the WordPress HTTP API; the bundled client's cURL transport is no longer shipped.
* The picked address id is unslashed and sanitized where it is read.

= 0.1.2 =
* The plugin's page is now its setup guide, separate from the author's.

= 0.1.1 =
* The listing says the plugin is open source, and where the source lives.
* Tested up to WordPress 7.1.

= 0.1.0 =
* First release: suggestions on the block and classic checkouts, an order time check against G-NAF, and the G-NAF id saved on the order.
