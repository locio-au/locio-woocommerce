<?php

declare(strict_types=1);

namespace Locio\WooCommerce;

use Locio\WooCommerce\Sdk\Exception\TransportException;
use Locio\WooCommerce\Sdk\Http\Response;
use Locio\WooCommerce\Sdk\Http\Transport;

/**
 * The SDK's requests, through WordPress's HTTP API, so proxy settings,
 * certificate bundles and the http_request_args filters all apply.
 *
 * wp_safe_remote_get refuses private and loopback addresses, redirection 0
 * keeps the Authorization header from following a redirect anywhere, and the
 * response is capped at 8 MiB.
 */
final class WpTransport implements Transport
{
    public function __construct(private readonly float $timeout = 4.0)
    {
    }

    public function get(string $url, array $headers): Response
    {
        $result = wp_safe_remote_get($url, [
            'headers' => $headers,
            'user-agent' => ($headers['User-Agent'] ?? 'locio-php') . ' locio-woocommerce/' . Plugin::VERSION,
            'redirection' => 0,
            'timeout' => $this->timeout,
            'limit_response_size' => 8 * 1024 * 1024,
        ]);
        if (is_wp_error($result)) {
            throw new TransportException('locio: request failed: ' . $result->get_error_message());
        }

        $received = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            // WordPress hands back a list when a header repeats; the last wins.
            $received[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return new Response((int) wp_remote_retrieve_response_code($result), $received, (string) wp_remote_retrieve_body($result));
    }
}
