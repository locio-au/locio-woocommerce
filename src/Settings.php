<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce → Locio: the keys and the two switches.
 *
 * The public key is not a secret: it carries an origin allow list and is
 * printed into the checkout page by design. The secret key is, so it is never
 * rendered back into the form, is refused in the public field, and can live in
 * wp-config.php as LOCIO_SECRET_KEY instead of the database.
 */
final class Settings
{
    public const OPTION = 'locio_settings';
    public const PAGE = 'locio';
    public const MODES = ['off', 'note', 'block'];

    private const PUBLIC_KEY = '/^[a-z]{2}_pub_[a-z2-7]{16,}$/';
    private const SECRET_KEY = '/^[a-z]{2}_live_[a-z2-7]{16,}$/';

    public readonly string $publicKey;
    public readonly bool $autocomplete;
    /** off, note (flag the order) or block (refuse the checkout). */
    public readonly string $validate;
    /** Whether the secret key came from wp-config.php rather than the database. */
    public readonly bool $secretFromConfig;
    private readonly string $secret;

    public function __construct(#[\SensitiveParameter] ?string $constant = null)
    {
        $constant ??= defined('LOCIO_SECRET_KEY') ? (string) constant('LOCIO_SECRET_KEY') : null;
        $saved = self::saved();

        $this->publicKey = $saved['public_key'];
        $this->autocomplete = $saved['autocomplete'];
        $this->validate = $saved['validate'];
        $this->secretFromConfig = $constant !== null && $constant !== '';
        $this->secret = $this->secretFromConfig ? trim((string) $constant) : $saved['secret_key'];
    }

    public static function load(#[\SensitiveParameter] ?string $constant = null): self
    {
        return new self($constant);
    }

    public function secretKey(): string
    {
        return $this->secret;
    }

    public static function capability(): string
    {
        return 'manage_woocommerce';
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSetting']);
        // options.php checks manage_options unless told otherwise; a shop
        // manager who can open the page must be able to save it, and nobody
        // else can.
        add_filter('option_page_capability_' . self::PAGE, [self::class, 'capability']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Locio addresses', 'locio-address-autocomplete'),
            __('Locio', 'locio-address-autocomplete'),
            self::capability(),
            self::PAGE,
            [$this, 'render'],
        );
    }

    public function registerSetting(): void
    {
        register_setting(self::PAGE, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'show_in_rest' => false,
        ]);
    }

    /**
     * @return array{public_key: string, secret_key: string, autocomplete: bool, validate: string}
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $saved = self::saved();

        $public = self::field($input, 'public_key');
        if ($public !== '' && preg_match(self::SECRET_KEY, $public) === 1) {
            // Never repeat the key: this message is rendered into the page.
            add_settings_error(self::OPTION, 'locio_secret_in_public', __('That is a secret key (lc_live_...). It would be printed into your checkout page, where anybody could copy it. The public key field takes an lc_pub_... key; the secret key goes in the field below it.', 'locio-address-autocomplete'));
            $public = $saved['public_key'];
        } elseif ($public !== '' && preg_match(self::PUBLIC_KEY, $public) !== 1) {
            add_settings_error(self::OPTION, 'locio_bad_public', __('That does not look like a public key. Copy the lc_pub_... key from locio.com.au/account/api.', 'locio-address-autocomplete'));
            $public = '';
        }

        $secret = $saved['secret_key'];
        if (!$this->secretFromConfig) {
            $typed = self::field($input, 'secret_key');
            if (!empty($input['remove_secret_key'])) {
                $secret = '';
            } elseif ($typed === '') {
                // The field is never filled in on render, so blank means unchanged.
            } elseif (preg_match(self::SECRET_KEY, $typed) === 1) {
                $secret = $typed;
            } else {
                add_settings_error(self::OPTION, 'locio_bad_secret', __('That is not a secret key. Server side checks need the lc_live_... key; the lc_pub_... key goes in the public key field.', 'locio-address-autocomplete'));
            }
        }

        $mode = is_string($input['validate'] ?? null) ? $input['validate'] : '';

        return [
            'public_key' => $public,
            'secret_key' => $secret,
            'autocomplete' => !empty($input['autocomplete']),
            'validate' => in_array($mode, self::MODES, true) ? $mode : 'note',
        ];
    }

    public function render(): void
    {
        if (!current_user_can(self::capability())) {
            return;
        }
        $o = self::OPTION;
        $secret = $this->secret;
        $hint = $secret !== '' ? sprintf(
            /* translators: %s: the last four characters of the saved key */
            __('Saved, ends in %s. Leave blank to keep it.', 'locio-address-autocomplete'),
            substr($secret, -4),
        ) : __('lc_live_...', 'locio-address-autocomplete');
        ?>
<div class="wrap">
    <h1><?php echo esc_html__('Locio addresses', 'locio-address-autocomplete'); ?></h1>
    <p><?php echo esc_html__('Australian address autocomplete and validation at checkout, from G-NAF. Keys are at locio.com.au/account/api.', 'locio-address-autocomplete'); ?></p>
    <?php settings_errors($o); ?>
    <form method="post" action="options.php">
        <?php settings_fields(self::PAGE); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="locio_public_key"><?php echo esc_html__('Public key', 'locio-address-autocomplete'); ?></label></th>
                <td>
                    <input type="text" class="regular-text code" id="locio_public_key" name="<?php echo esc_attr($o); ?>[public_key]" value="<?php echo esc_attr($this->publicKey); ?>" placeholder="lc_pub_..." autocomplete="off" spellcheck="false">
                    <p class="description"><?php echo esc_html__('For the suggestions at checkout. Printed into the page, which is safe: add this site to the key\'s allowed origins.', 'locio-address-autocomplete'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="locio_secret_key"><?php echo esc_html__('Secret key', 'locio-address-autocomplete'); ?></label></th>
                <td>
                    <?php if ($this->secretFromConfig) : ?>
                        <p><?php echo esc_html__('Set in wp-config.php as LOCIO_SECRET_KEY.', 'locio-address-autocomplete'); ?></p>
                    <?php else : ?>
                        <input type="password" class="regular-text code" id="locio_secret_key" name="<?php echo esc_attr($o); ?>[secret_key]" value="" placeholder="<?php echo esc_attr($hint); ?>" autocomplete="new-password" spellcheck="false">
                        <?php if ($secret !== '') : ?>
                            <label><input type="checkbox" name="<?php echo esc_attr($o); ?>[remove_secret_key]" value="1"> <?php echo esc_html__('Remove the saved key', 'locio-address-autocomplete'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php echo esc_html__('For checking the address when the order is placed. Stays on the server. You can define LOCIO_SECRET_KEY in wp-config.php instead.', 'locio-address-autocomplete'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Suggestions', 'locio-address-autocomplete'); ?></th>
                <td><label><input type="checkbox" name="<?php echo esc_attr($o); ?>[autocomplete]" value="1" <?php checked($this->autocomplete); ?>> <?php echo esc_html__('Suggest addresses as the customer types', 'locio-address-autocomplete'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="locio_validate"><?php echo esc_html__('When an address is not found', 'locio-address-autocomplete'); ?></label></th>
                <td>
                    <select id="locio_validate" name="<?php echo esc_attr($o); ?>[validate]">
                        <option value="off" <?php selected($this->validate, 'off'); ?>><?php echo esc_html__('Do not check', 'locio-address-autocomplete'); ?></option>
                        <option value="note" <?php selected($this->validate, 'note'); ?>><?php echo esc_html__('Accept the order and add a note', 'locio-address-autocomplete'); ?></option>
                        <option value="block" <?php selected($this->validate, 'block'); ?>><?php echo esc_html__('Ask the customer to correct it', 'locio-address-autocomplete'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('One unit per order. If Locio cannot answer, the order always goes through.', 'locio-address-autocomplete'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
        <?php
    }

    /**
     * @return array{public_key: string, secret_key: string, autocomplete: bool, validate: string}
     */
    private static function saved(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $mode = is_string($saved['validate'] ?? null) && in_array($saved['validate'], self::MODES, true) ? $saved['validate'] : 'note';

        return [
            'public_key' => is_string($saved['public_key'] ?? null) ? $saved['public_key'] : '',
            'secret_key' => is_string($saved['secret_key'] ?? null) ? $saved['secret_key'] : '',
            'autocomplete' => (bool) ($saved['autocomplete'] ?? true),
            'validate' => $mode,
        ];
    }

    /** @param array<mixed> $input */
    private static function field(array $input, string $name): string
    {
        return is_string($input[$name] ?? null) ? trim($input[$name]) : '';
    }
}
