<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Gravity Forms Klaviyo API client with event tracking added, reusing its connection,
 * authentication and token refresh. Loaded only once GF_Klaviyo_API is available.
 */
class GF_Kicbac_Klaviyo_Events_API extends GF_Klaviyo_API {

    /** Records a Klaviyo event; returns the API response, or a WP_Error when Klaviyo refused it. */
    public function create_event( $event ) {
        return $this->make_request( 'events', $event, 'POST' );
    }
}
