<?php

declare(strict_types=1);

namespace Locio\WooCommerce\Sdk\Exception;

if (!defined('ABSPATH')) {
    exit;
}

/** The request could not be served as written. */
final class BadRequest extends LocioException
{
}
