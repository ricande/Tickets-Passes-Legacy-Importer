<?php
/**
 * Boot the local WordPress install so importer tests run against real WooCommerce + TPFW.
 */
require __DIR__ . '/lib/checkout-code.php';
tpfwli_test_load_wordpress_and_checkout();
