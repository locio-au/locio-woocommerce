<?php

// Deleting the plugin deletes its settings, secret key included. Orders keep
// the G-NAF id they were given: that is the shop's data, not the plugin's.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('locio_settings');
