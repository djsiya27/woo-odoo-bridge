<?php

if (!defined('ABSPATH')) {
    exit;
}

class Woo_Odoo_Product_Sync {

    public function __construct() {
        add_action('save_post_product', [$this, 'sync_product'], 20, 3);
    }

    public function sync_product($post_id, $post, $update) {

        // Prevent autosave/revisions
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
        TEMPLATE SYNC (SAFE + SERIALIZATION FIX)
        ==================================================
        */

        $data = [
            'name'         => $product->get_name(),
            'default_code' => $sku,
            'list_price'   => (float) $product->get_price(),
            'type'         => 'product'
        ];

        $stored_id = get_post_meta($post_id, '_odoo_product_id', true);

        // 🔥 Fix serialized array issue
        if (is_array($stored_id)) {
            $stored_id = reset($stored_id);
        }

        $stored_id = $stored_id ? (int)$stored_id : null;
        $odoo_id   = null;

        // Verify stored template exists
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

        // If no valid stored ID → search or create
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

            // 🔥 Always store as integer (not array)
            update_post_meta($post_id, '_odoo_product_id', (int)$odoo_id);
        }

        /*
        ==================================================
        VARIABLE PRODUCT ATTRIBUTE SYNC
        ==================================================
        */

        if (!$product->is_type('variable')) {
            return;
        }

        $attributes = $product->get_attributes();

        foreach ($attributes as $attribute) {

            if (!$attribute->get_variation()) continue;

            // Handle global taxonomy attributes
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

            /*
            ----------------------------
            Find or Create Attribute
            ----------------------------
            */

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

            /*
            ----------------------------
            Find or Create Values
            ----------------------------
            */

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

            /*
            ----------------------------
            Update or Create Attribute Line
            ----------------------------
            */

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
                    [
                        'value_ids' => [[6, 0, $value_ids]]
                    ]
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

        // Trigger variant regeneration
        $odoo->execute('product.template', 'write', [
            [$odoo_id],
            []
        ]);
    }
}
