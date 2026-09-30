<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Sdk\Exception;

if (!defined('ABSPATH')) {
    exit;
}

/** Nothing is filed under that id. */
final class NotFound extends LocioException
{
}
