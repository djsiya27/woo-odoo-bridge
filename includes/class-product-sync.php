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
        TEMPLATE SYNC
        ==================================================
        */

        $data = [
            'name'         => $product->get_name(),
            'default_code' => $sku,
            'list_price'   => (float) $product->get_price(),
            'type'         => 'product'
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
                    [$stored_id], $data
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
                    [$odoo_id], $data
                ]);

            } else {

                $create = $odoo->execute('product.template', 'create', [
                    [$data]
                ]);

                if (!empty($create['result'])) {
                    $odoo_id = (int)$create['result'];
                } else {
                    return;
                }
            }

            update_post_meta($post_id, '_odoo_product_id', (int)$odoo_id);
        }

        /*
        ==================================================
        ATTRIBUTE SYNC (VARIABLE PRODUCTS)
        ==================================================
        */

        if ($product->is_type('variable')) {

            $attributes = $product->get_attributes();

            foreach ($attributes as $attribute) {

                if (!$attribute->get_variation()) continue;

                if ($attribute->is_taxonomy()) {

                    $attribute_name = wc_attribute_label($attribute->get_name());

                    $options = wc_get_product_terms(
                        $post_id,
                        $attribute->get_name(),
                        ['fields' => 'names']
                    );

                } else {

                    $attribute_name = $attribute->get_name();
                    $options = $attribute->get_options();
                }

                $attr_search = $odoo->execute('product.attribute', 'search', [
                    [['name', '=', $attribute_name]]
                ]);

                $attr_id = !empty($attr_search['result']) ? (int)$attr_search['result'][0] : null;

                if (!$attr_id) {

                    $create_attr = $odoo->execute('product.attribute', 'create', [
                        [[
                            'name' => $attribute_name,
                            'create_variant' => 'always'
                        ]]
                    ]);

                    $attr_id = $create_attr['result'] ?? null;
                }

                if (!$attr_id) continue;

                $value_ids = [];

                foreach ($options as $option) {

                    $value_search = $odoo->execute('product.attribute.value', 'search', [
                        [
                            ['name', '=', $option],
                            ['attribute_id', '=', $attr_id]
                        ]
                    ]);

                    $value_id = !empty($value_search['result']) ? (int)$value_search['result'][0] : null;

                    if (!$value_id) {

                        $create_val = $odoo->execute('product.attribute.value', 'create', [
                            [[
                                'name' => $option,
                                'attribute_id' => $attr_id
                            ]]
                        ]);

                        $value_id = $create_val['result'] ?? null;
                    }

                    if ($value_id) {
                        $value_ids[] = $value_id;
                    }
                }

                if (empty($value_ids)) continue;

                $line_search = $odoo->execute('product.template.attribute.line', 'search', [
                    [
                        ['product_tmpl_id', '=', $odoo_id],
                        ['attribute_id', '=', $attr_id]
                    ]
                ]);

                $line_id = !empty($line_search['result']) ? (int)$line_search['result'][0] : null;

                if ($line_id) {

                    $odoo->execute('product.template.attribute.line', 'write', [
                        [$line_id],
                        ['value_ids' => [[6, 0, $value_ids]]]
                    ]);

                } else {

                    $odoo->execute('product.template.attribute.line', 'create', [
                        [[
                            'product_tmpl_id' => $odoo_id,
                            'attribute_id'    => $attr_id,
                            'value_ids'       => [[6, 0, $value_ids]]
                        ]]
                    ]);
                }
            }

            // Force regenerate variants
            $odoo->execute('product.template', 'write', [
                [$odoo_id],
                []
            ]);
        }

        /*
        ==================================================
        STOCK SYNC (Woo → Odoo)
        ==================================================
        */

        $this->sync_stock($product, $odoo, $odoo_id);
    }

    /*
    ==================================================
    STOCK SYNC METHOD
    ==================================================
    */

    private function sync_stock($product, $odoo, $odoo_template_id) {

        if (!$product->managing_stock()) return;

        // Get internal stock location (WH/Stock)
        $location = $odoo->execute('stock.location', 'search_read', [
            [['usage', '=', 'internal']],
            ['fields' => ['id'], 'limit' => 1]
        ]);

        if (empty($location['result'])) return;

        $location_id = (int)$location['result'][0]['id'];

        if ($product->is_type('simple')) {

            $variants = $odoo->execute('product.product', 'search_read', [
                [['product_tmpl_id', '=', $odoo_template_id]],
                ['fields' => ['id']]
            ]);

            if (empty($variants['result'])) return;

            $variant_id = (int)$variants['result'][0]['id'];
            $qty = (float)$product->get_stock_quantity();

            $this->update_stock($odoo, $variant_id, $location_id, $qty);
        }

        if ($product->is_type('variable')) {

            foreach ($product->get_children() as $child_id) {

                $variation = wc_get_product($child_id);
                if (!$variation->managing_stock()) continue;

                $sku = $variation->get_sku();
                $qty = (float)$variation->get_stock_quantity();

                $search = $odoo->execute('product.product', 'search_read', [
                    [['default_code', '=', $sku]],
                    ['fields' => ['id']]
                ]);

                if (empty($search['result'])) continue;

                $variant_id = (int)$search['result'][0]['id'];

                $this->update_stock($odoo, $variant_id, $location_id, $qty);
            }
        }
    }

    private function update_stock($odoo, $variant_id, $location_id, $qty) {

        // Find existing quant
        $quant = $odoo->execute('stock.quant', 'search_read', [
            [
                ['product_id', '=', $variant_id],
                ['location_id', '=', $location_id]
            ],
            ['fields' => ['id']]
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
                'product_id'         => $variant_id,
                'location_id'        => $location_id,
                'inventory_quantity' => $qty
            ]]);

            if (!empty($create['result'])) {

                $odoo->execute('stock.quant', 'action_apply_inventory', [
                    [(int)$create['result']]
                ]);
            }
        }
    }
}
