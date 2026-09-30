<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/** Checkout fields to the one line the API resolves, and the id a browser posts back. */
final class AddressLine
{
    /**
     * "Unit 3, 12 Smith Street, Coburg VIC 3058". Empty when there is no
     * street line, since a suburb alone is not an address and costs a unit.
     *
     * @param array<string, mixed> $fields address_1, address_2, city, state, postcode
     */
    public static function fromFields(array $fields): string
    {
        $get = static fn (string $key): string => is_string($fields[$key] ?? null) ? trim($fields[$key]) : '';
        if ($get('address_1') === '') {
            return '';
        }
        $place = implode(' ', array_filter([$get('city'), $get('state'), $get('postcode')], 'strlen'));

        return implode(', ', array_filter([$get('address_2'), $get('address_1'), $place], 'strlen'));
    }

    /**
     * The id the checkout script posts for a picked suggestion, or null.
     *
     * It comes from the browser, so it is only a hint: kept on the order when
     * nothing was checked server side, and only in the shape an id has.
     */
    public static function cleanId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) === 1 ? $value : null;
    }
}
