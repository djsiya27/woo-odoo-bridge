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

        // IMPORTANT: Confirm this matches your WH/Stock ID
        $location_id = 8;

        $variants = $odoo->execute('product.product', 'search_read', [
            [['product_tmpl_id', '=', $odoo_template_id]],
            ['fields' => ['id', 'default_code']]
        ]);

        if (empty($variants['result'])) {
            error_log('No Odoo variants found for template: ' . $odoo_template_id);
            return;
        }

        // SIMPLE PRODUCT
        if ($product->is_type('simple')) {

            $variant_id = (int)$variants['result'][0]['id'];
            $qty = (float)$product->get_stock_quantity();

            $this->update_stock($odoo, $variant_id, $location_id, $qty);
        }

        // VARIABLE PRODUCT
        if ($product->is_type('variable')) {

            foreach ($product->get_children() as $child_id) {

                $variation = wc_get_product($child_id);
                if (!$variation || !$variation->managing_stock()) continue;

                $sku = $variation->get_sku();
                if (!$sku) continue;

                $qty = (float)$variation->get_stock_quantity();

                foreach ($variants['result'] as $odoo_variant) {

                    if ($odoo_variant['default_code'] === $sku) {

                        $variant_id = (int)$odoo_variant['id'];
                        $this->update_stock($odoo, $variant_id, $location_id, $qty);
                    }
                }
            }
        }
    }

    /*
    ==================================================
    ODOO 17 SAFE STOCK UPDATE
    ==================================================
    */

    private function update_stock($odoo, $variant_id, $location_id, $qty) {

        if ($qty === null) return;

        $qty = (float)$qty;

        // Search existing quant
        $quant = $odoo->execute('stock.quant', 'search_read', [
            [
                ['product_id', '=', (int)$variant_id],
                ['location_id', '=', (int)$location_id]
            ],
            ['fields' => ['id', 'quantity']]
        ]);

        if (!empty($quant['result'])) {

            $quant_id = (int)$quant['result'][0]['id'];

            $odoo->execute('stock.quant', 'write', [
                [$quant_id],
                ['inventory_quantity' => $qty]
            ]);

            $odoo->execute('stock.quant', 'action_apply_inventory', [
                [$quant_id]
            ]);

        } else {

            $create = $odoo->execute('stock.quant', 'create', [[
                'product_id'        => (int)$variant_id,
                'location_id'       => (int)$location_id,
                'inventory_quantity'=> $qty
            ]]);

            if (empty($create['result'])) {
                error_log('Failed creating quant for variant: ' . $variant_id);
                return;
            }

            $quant_id = (int)$create['result'];

            $odoo->execute('stock.quant', 'action_apply_inventory', [
                [$quant_id]
            ]);
        }

        error_log("Stock synced for variant {$variant_id} → Qty: {$qty}");
    }
}
