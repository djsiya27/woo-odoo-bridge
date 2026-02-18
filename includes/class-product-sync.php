<?php

if (!defined('ABSPATH')) {
    exit;
}

class Woo_Odoo_Product_Sync {

    public function __construct() {
        add_action('save_post_product', [$this, 'sync_product'], 20, 3);
    }

    public function sync_product($post_id, $post, $update) {

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish') return;

        $product = wc_get_product($post_id);
        if (!$product) return;

        $sku = $product->get_sku();
        if (!$sku) return;

        if (!class_exists('Woo_Odoo_Client')) return;

        $odoo = new Woo_Odoo_Client();

        /*
        ==================================================
        PRODUCT TEMPLATE SYNC
        ==================================================
        */

        $data = [
            'name'         => $product->get_name(),
            'default_code' => $sku,
            'list_price'   => (float) $product->get_price(),
            'type'         => 'product',
            'sale_ok'      => true,
            'purchase_ok'  => true
        ];

        $stored_id = get_post_meta($post_id, '_odoo_product_id', true);
        if (is_array($stored_id)) {
            $stored_id = reset($stored_id);
        }

        $stored_id = $stored_id ? (int)$stored_id : null;
        $odoo_id   = null;

        if ($stored_id) {

            $exists = $odoo->execute('product.template', 'search', [
                [['id', '=', $stored_id]]
            ]);

            if (!empty($exists['result'])) {

                $odoo->execute('product.template', 'write', [
                    [$stored_id],
                    $data
                ]);

                $odoo_id = $stored_id;

            } else {
                delete_post_meta($post_id, '_odoo_product_id');
                $stored_id = null;
            }
        }

        if (!$stored_id) {

            $search = $odoo->execute('product.template', 'search', [
                [['default_code', '=', $sku]]
            ]);

            $odoo_id = !empty($search['result']) ? (int)$search['result'][0] : null;

            if ($odoo_id) {

                $odoo->execute('product.template', 'write', [
                    [$odoo_id],
                    $data
                ]);

            } else {

                $create = $odoo->execute('product.template', 'create', [
                    [$data]
                ]);

                if (empty($create['result'])) return;

                $odoo_id = (int)$create['result'];
            }

            update_post_meta($post_id, '_odoo_product_id', $odoo_id);
        }

        /*
        ==================================================
        STOCK SYNC
        ==================================================
        */

        $this->sync_stock($product, $odoo, $odoo_id);
    }

    /*
    ==================================================
    STOCK SYNC
    ==================================================
    */

    private function sync_stock($product, $odoo, $odoo_template_id) {

        if (!$product->managing_stock()) return;

        // VERIFY THIS MATCHES YOUR WH/STOCK ID
        $location_id = 8;

        // Get variant
        $variants = $odoo->execute('product.product', 'search_read', [
            [['product_tmpl_id', '=', $odoo_template_id]],
            ['fields' => ['id']]
        ]);

        if (empty($variants['result'])) return;

        $variant_id = (int)$variants['result'][0]['id'];
        $qty = (float)$product->get_stock_quantity();

        $this->update_stock($odoo, $variant_id, $location_id, $qty);
    }

    /*
    ==================================================
    ODOO 17 SAFE STOCK UPDATE
    ==================================================
    */

    private function update_stock($odoo, $variant_id, $location_id, $qty) {

        if ($qty === null) return;

        $qty = (float)$qty;

        // Search quant
        $search = $odoo->execute('stock.quant', 'search', [
            [
                ['product_id', '=', (int)$variant_id],
                ['location_id', '=', (int)$location_id]
            ]
        ]);

        $quant_id = !empty($search['result']) ? (int)$search['result'][0] : null;

        if (!$quant_id) {

            $create = $odoo->execute('stock.quant', 'create', [[
                'product_id'  => (int)$variant_id,
                'location_id' => (int)$location_id
            ]]);

            if (empty($create['result'])) return;

            $quant_id = (int)$create['result'];
        }

        // Set inventory quantity
        $odoo->execute('stock.quant', 'write', [
            [$quant_id],
            ['inventory_quantity' => $qty]
        ]);

        // APPLY inventory adjustment (CRITICAL)
        $odoo->execute('stock.quant', 'action_apply_inventory', [
            [$quant_id]
        ]);
    }
}
