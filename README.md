# Locio Address Autocomplete for WooCommerce

Australian address suggestions at WooCommerce checkout, and a check against
G-NAF, the national address register, when the order is placed. A matched
order carries its G-NAF id, coordinates and ABS mesh block.

Open source under the GPL, version 2 or later. The store listing text is in
[`readme.txt`](readme.txt).

- Works on the block checkout and the classic checkout
- Never blocks a sale: if Locio cannot answer, the order goes through, marked unchecked
- The secret key stays on the server, and can live in `wp-config.php`

## Install

Download `locio-address-autocomplete.zip` from the
[latest release](https://github.com/locio-au/locio-woocommerce/releases/latest),
then in WordPress go to **Plugins**, **Add New Plugin**, **Upload Plugin**. Or:

```sh
wp plugin install https://github.com/locio-au/locio-woocommerce/releases/latest/download/locio-address-autocomplete.zip --activate
```

The setup guide, keys included:
[locio.com.au/guides/address-autocomplete-woocommerce](https://locio.com.au/guides/address-autocomplete-woocommerce/).

## Develop

A local WooCommerce shop with this checkout mounted live is in
[locio-woocommerce-dev](https://github.com/locio-au/locio-woocommerce-dev):
podman and `make`.

```sh
composer install && vendor/bin/phpunit   # PHP, with Brain Monkey
npm ci && npm test                       # the checkout script, with vitest and jsdom
bin/build-zip.sh                         # build/locio-address-autocomplete.zip
```

| Path | What |
|---|---|
| `locio-address-autocomplete.php` | the plugin header and bootstrap |
| `src/` | settings, checkout script loading, order time validation |
| `lib/` | [locio-php](https://github.com/locio-au/locio-php), copied under `Locio\WooCommerce\Sdk` by `bin/sync-sdk.php` |
| `assets/` | the checkout script and styles, plain ES5 with no build step |

Changes to `lib/` belong in locio-php, then `php bin/sync-sdk.php ../locio-php`.

## Licence

GPL, version 2 or later. `lib/` is locio-php, MIT. Address data is G-NAF,
© Geoscape Australia, CC BY 4.0.
