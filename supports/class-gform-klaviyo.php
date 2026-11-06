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
     * through the Gravity Forms Klaviyo connection, whenever its payment or subscription changes,
     * and records a "Donation Approved" event for each approved submission. A Klaviyo failure is
     * logged and never undoes the payment, refund, cancellation or webhook that triggered it.
     */
    public function sync_klaviyo( $entry_id, $state, $source, $context ) {
        try {
            $this->send_to_klaviyo( $entry_id, $source, $context );
        } catch ( \Throwable $e ) {
            $this->addon->log_error( __METHOD__ . '(): ' . $e->getMessage() );
        }

        if ( 'charge' !== $source ) {
            return;
        }

        try {
            $this->send_donation_event( $entry_id );
        } catch ( \Throwable $e ) {
            $this->addon->log_error( __METHOD__ . '(): ' . $e->getMessage() );
        }
    }

    /** Records a "Donation Approved" Klaviyo event for the entry, so a flow can thank every donation, not only a donor's first. */
    public function send_donation_event( $entry_id ) {
        $klaviyo = function_exists( 'gf_klaviyo' ) ? gf_klaviyo() : null;
        $entry   = GFAPI::get_entry( $entry_id );

        if ( ! $klaviyo || ! $klaviyo->initialize_api() || is_wp_error( $entry ) ) {
            return;
        }

        $form  = GFAPI::get_form( $entry['form_id'] );
        $event = apply_filters( 'gfmbn_kicbac_klaviyo_event', $this->get_donation_event( $entry, $form ), $entry, $form );

        if ( empty( $event ) ) {
            return;
        }

        require_once __DIR__ . '/class-gform-klaviyo-events-api.php';

        $api    = new GF_Kicbac_Klaviyo_Events_API( $klaviyo->get_auth_settings(), $klaviyo );
        $result = $api->create_event( $event );

        if ( is_wp_error( $result ) ) {
            $this->addon->log_error( __METHOD__ . '(): Klaviyo event failed: ' . $result->get_error_message() );
            $this->addon->add_note( $entry_id, 'Donation Approved event was not sent to Klaviyo: ' . $result->get_error_message(), 'error' );

            return;
        }

        $this->addon->add_note( $entry_id, 'Donation Approved event sent to Klaviyo.', 'success' );
    }

    /**
     * The Klaviyo "Donation Approved" event for an entry whose one-time gift was approved or
     * whose subscription started, keyed by the order ID so a resend is not counted twice.
     *
     * @return array The Klaviyo API request body, or an empty array when there is nothing to report.
     */
    public function get_donation_event( $entry, $form ) {
        $entry_id  = absint( $entry['id'] );
        $settings  = (array) rgar( $form, 'gravityform-kicbac-payment' );
        $email     = sanitize_email( rgar( $entry, rgar( $settings, 'email' ) ) );
        $onetime   = 'Approved' === $this->addon->get_payment_meta( $entry_id, 'payment_status' ) ? (float) $this->addon->get_payment_meta( $entry_id, 'payment_amount', 0 ) : 0;
        $statuses  = explode( ',', $this->addon->get_payment_meta( $entry_id, 'subscription_status' ) );
        $amounts   = explode( ',', $this->addon->get_payment_meta( $entry_id, 'subscription_amount' ) );
        $recurring = 0;

        foreach ( $statuses as $index => $status ) {
            if ( 'Active' === $status ) {
                $recurring += (float) rgar( $amounts, $index );
            }
        }

        if ( ! is_email( $email ) || ( $onetime <= 0 && $recurring <= 0 ) ) {
            return array();
        }

        $amount     = round( $onetime + $recurring, 2 );
        $properties = array_filter(
            array(
                'fund'             => (string) rgar( $entry, rgar( $settings, 'company' ) ),
                'donation_type'    => $onetime > 0 && $recurring > 0 ? 'one-time and recurring' : ( $onetime > 0 ? 'one-time' : 'recurring' ),
                'amount'           => $amount,
                'one_time_amount'  => $onetime > 0 ? $onetime : null,
                'recurring_amount' => $recurring > 0 ? $recurring : null,
                'billing_cycle'    => $recurring > 0 ? $this->addon->get_payment_meta( $entry_id, 'billing_cycle' ) : null,
                'transaction_id'   => $onetime > 0 ? $this->addon->get_payment_meta( $entry_id, 'transaction_id' ) : null,
                'subscription_id'  => $recurring > 0 ? $this->addon->get_payment_meta( $entry_id, 'subscription_id' ) : null,
                'payment_method'   => 'check' === rgar( $entry, rgar( $settings, 'payment' ) ) ? 'ach' : 'card',
                'form'             => (string) rgar( $form, 'title' ),
                'entry_id'         => $entry_id,
            ),
            function ( $value ) {
                return null !== $value && '' !== $value;
            }
        );

        $properties['test_mode'] = $this->addon->is_test_mode( $form );

        return array(
            'data' => array(
                'type'       => 'event',
                'attributes' => array(
                    'properties'     => $properties,
                    'value'          => $amount,
                    'value_currency' => rgar( $entry, 'currency' ) ? $entry['currency'] : 'USD',
                    'unique_id'      => $this->addon->get_payment_meta( $entry_id, 'order_id', 'entry-' . $entry_id ),
                    'metric'         => array( 'data' => array( 'type' => 'metric', 'attributes' => array( 'name' => 'Donation Approved' ) ) ),
                    'profile'        => array(
                        'data' => array(
                            'type'       => 'profile',
                            'attributes' => array_filter( array(
                                'email'      => $email,
                                'first_name' => (string) rgar( $entry, rgar( $settings, 'first_name' ) ),
                                'last_name'  => (string) rgar( $entry, rgar( $settings, 'last_name' ) ),
                            ) ),
                        ),
                    ),
                ),
            ),
        );
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
