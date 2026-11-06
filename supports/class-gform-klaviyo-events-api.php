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

    /** IDs of every Klaviyo list the profile is on, following pagination, or a WP_Error when Klaviyo did not answer. */
    public function get_profile_list_ids( $profile_id ) {
        $ids      = array();
        $endpoint = 'profiles/' . rawurlencode( $profile_id ) . '/relationships/lists';

        while ( '' !== $endpoint ) {
            $response = $this->make_request( $endpoint );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            foreach ( (array) rgar( $response, 'data' ) as $list ) {
                $ids[] = (string) rgar( $list, 'id' );
            }

            $next     = (string) rgars( $response, 'links/next' );
            $endpoint = '' === $next ? '' : ltrim( (string) substr( $next, strlen( $this->api_url ) ), '/' );
        }

        return $ids;
    }
}
