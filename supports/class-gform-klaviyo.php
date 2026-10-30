<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gravity Forms Klaviyo support: keeps the donor's Klaviyo profile in step with the entry's
 * Kicbac payment and subscription, as kicbac_ properties, whenever they change.
 */
class GF_Kicbac_Klaviyo_Support {

    /** @var GF_Kicbac_AddOn */
    private $addon;

    public function __construct( $addon ) {
        $this->addon = $addon;

        add_action( 'gfmbn_kicbac_payment_status_updated', array( $this, 'sync_klaviyo' ), 10, 4 );
    }

    /**
     * Copies the entry's Kicbac details onto the donor's Klaviyo profile as kicbac_ properties,
     * through the Gravity Forms Klaviyo connection, whenever its payment or subscription changes.
     */
    public function sync_klaviyo( $entry_id, $state, $source, $context ) {
        try {
            $this->send_to_klaviyo( $entry_id, $source, $context );
        } catch ( \Throwable $e ) {
            // A Klaviyo problem must never undo a refund, cancellation or webhook that already happened.
            $this->addon->log_error( __METHOD__ . '(): ' . $e->getMessage() );
        }
    }

    /** Looks up or creates the donor's Klaviyo profile and writes the entry's kicbac_ properties to it. */
    public function send_to_klaviyo( $entry_id, $source, $context ) {
        $klaviyo = function_exists( 'gf_klaviyo' ) ? gf_klaviyo()->initialize_api() : false;

        if ( ! $klaviyo ) {
            return;
        }

        $entry = GFAPI::get_entry( $entry_id );

        if ( is_wp_error( $entry ) ) {
            return;
        }

        $form       = GFAPI::get_form( $entry['form_id'] );
        $email      = sanitize_email( rgar( $entry, rgars( $form, 'gravityform-kicbac-payment/email' ) ) );
        $properties = apply_filters( 'gfmbn_kicbac_klaviyo_properties', $this->get_klaviyo_properties( $entry, $form, $source, $context ), $entry, $form, $source, $context );

        if ( ! is_email( $email ) || empty( $properties ) ) {
            return;
        }

        $found = $klaviyo->get_profile_by_email( $email );

        if ( is_wp_error( $found ) ) {
            $this->addon->log_error( __METHOD__ . '(): Klaviyo profile lookup failed: ' . $found->get_error_message() );

            return;
        }

        $profile_id = (string) rgars( $found, 'data/0/id' );
        $profile    = array( 'type' => 'profile', 'attributes' => array( 'properties' => $properties ) );

        if ( '' !== $profile_id ) {
            $profile['id'] = $profile_id;
            $result        = $klaviyo->update_profile( $profile_id, array( 'data' => $profile ) );
        } else {
            $profile['attributes'] = array_merge(
                $profile['attributes'],
                array_filter( array(
                    'email'      => $email,
                    'first_name' => (string) rgar( $entry, rgars( $form, 'gravityform-kicbac-payment/first_name' ) ),
                    'last_name'  => (string) rgar( $entry, rgars( $form, 'gravityform-kicbac-payment/last_name' ) ),
                ) )
            );
            $result = $klaviyo->create_profile( array( 'data' => $profile ) );
        }

        if ( is_wp_error( $result ) ) {
            $this->addon->log_error( __METHOD__ . '(): Klaviyo update failed: ' . $result->get_error_message() );
            $this->addon->add_note( $entry_id, 'Kicbac details were not sent to Klaviyo: ' . $result->get_error_message(), 'error' );

            return;
        }

        $this->addon->log_debug( __METHOD__ . '(): Kicbac details sent to Klaviyo for entry ' . $entry_id );
    }

    /** The entry's Kicbac payment and subscription details as kicbac_-prefixed Klaviyo profile properties. */
    public function get_klaviyo_properties( $entry, $form, $source, $context ) {
        $entry_id   = absint( $entry['id'] );
        $amounts    = array( 'payment_amount', 'refunded_amount', 'subscription_amount' );
        $properties = array();

        foreach ( array_keys( $this->addon->get_entry_meta_labels() ) as $key ) {
            $value = $this->addon->get_payment_meta( $entry_id, $key );

            if ( '' === $value ) {
                continue;
            }

            // An entry with several subscriptions keeps a comma list, which stays text.
            $properties[ 'kicbac_' . $key ] = in_array( $key, $amounts, true ) && false === strpos( $value, ',' ) ? (float) $value : $value;
        }

        if ( empty( $properties ) ) {
            return array();
        }

        // A field emptied since the last sync, such as the next charge date of a cancelled
        // subscription, is sent blank so Klaviyo does not keep the old value.
        $groups = array(
            'subscription_id' => array( 'subscription_status', 'subscription_amount', 'billing_cycle', 'next_charge_date', 'cancel_note' ),
            'refunded_amount' => array( 'refund_transaction_id', 'refund_date', 'refund_note' ),
        );

        foreach ( $groups as $anchor => $keys ) {
            foreach ( isset( $properties[ 'kicbac_' . $anchor ] ) ? $keys : array() as $key ) {
                if ( ! isset( $properties[ 'kicbac_' . $key ] ) ) {
                    $properties[ 'kicbac_' . $key ] = '';
                }
            }
        }

        return array_merge(
            $properties,
            array(
                'kicbac_last_update' => 'webhook' === $source && rgar( $context, 'event_type' ) ? $context['event_type'] : $source,
                'kicbac_updated_at'  => gmdate( 'c' ),
                'kicbac_entry_id'    => $entry_id,
                'kicbac_form'        => (string) rgar( $form, 'title' ),
                'kicbac_test_mode'   => $this->addon->is_test_mode( $form ),
            )
        );
    }
}
