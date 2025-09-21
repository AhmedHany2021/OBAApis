<?php

namespace OBA\APIsIntegration\Helpers;

/**
 * Product helper
 *
 * @package OBA\APIsIntegration\Helpers
 */
class ProductHelper {

    /**
     * Format product for API response
     *
     * @param \WC_Product $product Product object.
     * @param bool        $detailed Whether to include detailed information.
     * @return array
     */
    public static function format_product($product, $detailed = false)
    {
        $id = $product->get_id();

        $product_data = [
            'id'                => $id,
            'type'              => $product->get_type(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'description'       => wp_strip_all_tags($product->get_description()),
            'short_description' => wp_strip_all_tags($product->get_short_description()),
            'regular_price'     => (float) $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'membership_price'  => get_post_meta($id, '_membership_price', true) ?: false,
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'required_survey'   => get_post_meta($id, '_required_survey_id', true) ?: false,
        ];

        // ✅ Images (unique only)
        $image_ids = array_unique(array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        ));
        $product_data['images'] = array_values(array_filter(array_map(function ($image_id) {
            if (!$image_id) return null;
            return [
                'id'  => $image_id,
                'url' => wp_get_attachment_image_url($image_id, 'full')
            ];
        }, $image_ids)));

        // ✅ Categories
        $categories = get_the_terms($id, 'product_cat') ?: [];
        $product_data['categories'] = array_map(function ($cat) {
            return [
                'id'   => $cat->term_id,
                'name' => $cat->name
            ];
        }, is_array($categories) ? $categories : []);

        // ✅ Detailed data
        if ($detailed) {
            $subscription_plans_meta = get_post_meta($id, '_wcsatt_schemes', true) ?: [];

            $subscription_plans = [];
            if (is_array($subscription_plans_meta) && !empty($subscription_plans_meta)) {
                foreach ($subscription_plans_meta as $plan_id => $plan_data) {
                    $subscription_plans[] = array_merge(
                        ['plan_id' => $plan_id],
                        $plan_data
                    );
                }
            }

            $product_data['subscription_plans'] = [
                'enabled'                  => !empty($subscription_plans),
                'subscription_plans'       => $subscription_plans,
                'allow_one_time_purchase'  => get_post_meta($id, '_wcsatt_force_subscription', true) === 'no',
                'one_time_purchase_prompt' => get_post_meta($id, '_wcsatt_subscription_prompt', true) ?: false,
            ];
        }

        // ✅ Variations
        if ($product->is_type('variable')) {
            $variations_data = [];
            foreach ($product->get_children() as $variation_id) {
                $variation_obj = wc_get_product($variation_id);
                if (!$variation_obj) continue;

                $var_image_ids = array_unique(array_merge(
                    [$variation_obj->get_image_id()],
                    $variation_obj->get_gallery_image_ids()
                ));

                $variations_data[] = [
                    'id'            => $variation_id,
                    'attributes'    => $variation_obj->get_attributes(),
                    'regular_price' => (float) $variation_obj->get_regular_price(),
                    'sale_price'    => $variation_obj->get_sale_price() ? (float) $variation_obj->get_sale_price() : null,
                    'stock_status'  => $variation_obj->get_stock_status(),
                    'stock_quantity'=> $variation_obj->get_stock_quantity(),
                    'images'        => array_values(array_filter(array_map(function ($image_id) {
                        if (!$image_id) return null;
                        return [
                            'id'  => $image_id,
                            'url' => wp_get_attachment_image_url($image_id, 'full')
                        ];
                    }, $var_image_ids)))
                ];
            }
            $product_data['variations'] = $variations_data;
        }

        return $product_data;
    }
}
