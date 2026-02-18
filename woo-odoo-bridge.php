<?php
/**
 * Plugin Name: Woo Odoo Bridge
 * Description: Bi-directional WooCommerce ↔ Odoo integration.
 * Version: 1.0.11
 * Author: Siyabonga Majola
 * GitHub Plugin URI: https://github.com/djsiya27/woo-odoo-bridge
 * Primary Branch: main
 * 
 */

if (!defined('ABSPATH')) exit;

define('WOO_ODOO_PATH', plugin_dir_path(__FILE__));

require_once WOO_ODOO_PATH . 'includes/class-odoo-client.php';
require_once WOO_ODOO_PATH . 'includes/class-product-sync.php';

add_action('plugins_loaded', function() {
    new Woo_Odoo_Product_Sync();
});

