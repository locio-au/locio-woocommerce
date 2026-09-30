<?php

declare(strict_types=1);

namespace {

// The few WordPress and WooCommerce classes the plugin touches, reduced to
// what the tests observe. Functions are stubbed per test with Brain Monkey.

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '')
        {
        }

        /** @var array<string, list<string>> */
        public array $errors = [];

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function add(string $code, string $message): void
        {
            $this->errors[$code][] = $message;
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var array<string, mixed> */
        public array $meta = [];
        /** @var list<string> */
        public array $notes = [];

        /** @param array<string, string> $shipping @param array<string, string> $billing */
        public function __construct(public array $shipping = [], public array $billing = [])
        {
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function get_meta(string $key): mixed
        {
            return $this->meta[$key] ?? '';
        }

        public function add_order_note(string $note): void
        {
            $this->notes[] = $note;
        }

        public function has_shipping_address(): bool
        {
            return ($this->shipping['address_1'] ?? '') !== '';
        }

        public function __call(string $name, array $args): string
        {
            if (preg_match('/^get_(shipping|billing)_(.+)$/', $name, $m)) {
                return $this->{$m[1]}[$m[2]] ?? '';
            }
            throw new BadMethodCallException($name);
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

}

namespace Automattic\WooCommerce\StoreApi\Exceptions {
    if (!class_exists(RouteException::class)) {
        class RouteException extends \Exception
        {
            public function __construct(public string $errorCode, string $message, public int $httpStatus = 400)
            {
                parent::__construct($message);
            }
        }
    }
}
