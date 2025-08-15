<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class MedicationRequestService
{
    public function get_requests(WP_REST_Request $request)
    {
        global $wpdb;

        $user_id = $request->get_param('current_user');

        $table_name = $wpdb->prefix . 'survey_maker_woo_product_access';
        $sql = "
            SELECT
            p.ID,
            p.post_title,
            pa.created_at AS request_date,
            pa.doctor_comment
            FROM {$wpdb->posts} p
            INNER JOIN {$table_name} pa ON p.ID = pa.product_id
            WHERE pa.user_id = %d
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
        ";

        $prepared_sql = $wpdb->prepare($sql, $user_id);
        $products = $wpdb->get_results($prepared_sql);

        return new WP_REST_Response([
            'success'  => true,
            'products' => $products
        ], 200);
    }
}
