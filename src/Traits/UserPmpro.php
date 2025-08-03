<?php

namespace OBA\APIsIntegration\Traits;

trait UserPmpro
{
    private function get_current_user($request)
    {
        return $request->get_param( 'current_user' )->ID;
    }
    private function get_user_level($request)
    {
        $level = pmpro_getMembershipLevelForUser( $this->get_current_user( $request ) );
    }
    private function get_pmpro_product_price_for_membership($product_id, $level_id)
    {
        $price = get_post_meta( $product_id, '_pmpro_membership_price_' . $level_id, true );

        if ( $price !== '' ) {
            return floatval( $price );
        }

        $product = wc_get_product( $product_id );
        return $product ? $product->get_price() : null;
    }
}