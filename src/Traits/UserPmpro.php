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
}