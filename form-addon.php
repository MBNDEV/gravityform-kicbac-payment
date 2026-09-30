<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'gform_loaded', 'gfmbn_kicbac_addon_bootstrap', 5 );
function gfmbn_kicbac_addon_bootstrap() {
    if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
        return; // Gravity Forms not active or too old
    }

    GFForms::include_addon_framework();

    class GF_Kicbac_AddOn extends GFAddOn {

        protected $_version                  = '1.0.2';
        protected $_min_gravityforms_version = '2.7';
        protected $_slug                     = 'gravityform-kicbac-payment';
        protected $_path                     = 'gravityform-kicbac-payment/form-addon.php';
        protected $_full_path                = __FILE__;
        protected $_title                    = 'MBN Kicbac Payment';
        protected $_short_title              = 'MBN Kicbac Payment';

        /** Singleton */
        private static $_instance = null;
        public static function get_instance() {
            if ( self::$_instance === null ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /** Optional: register scripts/styles used by feeds/admin UI */
        public function scripts() {
            return array_merge(
                parent::scripts(),
                array(
                    array(
                        'handle'  => 'gfmbn_kicbac_collectjs',
                        'src'     => GFORMMBN_KICBAC_COLLECTJS_URL,
                        'version' => $this->_version,
                        'enqueue' => array( array( $this, 'is_collectjs_enabled' ) ),
                    ),
                    array(
                        'handle'    => 'gfmbn_kicbac_collect_init',
                        'src'       => plugins_url( 'assets/js/kicbac-collect.js', $this->_full_path ),
                        'deps'      => array( 'gfmbn_kicbac_collectjs' ),
                        'version'   => $this->get_asset_version( 'assets/js/kicbac-collect.js' ),
                        'in_footer' => true,
                        'enqueue'   => array( array( $this, 'is_collectjs_enabled' ) ),
                        'callback'  => array( $this, 'localize_collectjs_config' ),
                    ),
                    array(
                        'handle'    => 'gfmbn_kicbac_total',
                        'src'       => plugins_url( 'assets/js/kicbac-total.js', $this->_full_path ),
                        'version'   => $this->get_asset_version( 'assets/js/kicbac-total.js' ),
                        'in_footer' => true,
                        // The running total is display only, so it does not need a public key.
                        'enqueue'   => array( array( $this, 'is_total_enabled' ) ),
                        'callback'  => array( $this, 'localize_total_config' ),
                    ),
                )
            );
        }

        public function styles() {
            return array_merge(
                parent::styles(),
                array(
                    array(
                        'handle'  => 'gfmbn_kicbac',
                        'src'     => plugins_url( 'assets/css/kicbac.css', $this->_full_path ),
                        'version' => $this->get_asset_version( 'assets/css/kicbac.css' ),
                        'enqueue' => array( array( $this, 'is_total_enabled' ) ),
                    ),
                )
            );
        }

        /**
         * @param array $form    The form being rendered.
         * @param bool  $is_ajax Unused; part of the GF enqueue-condition callback signature.
         */
        public function is_total_enabled( $form, $is_ajax = false ) {
            return ! empty( $form ) && $this->is_gateway_enabled( $form );
        }

        /** Tells the total script which fields make up the amount being charged. */
        public function localize_total_config( $form, $is_ajax = false ) {
            wp_localize_script(
                'gfmbn_kicbac_total',
                'gfmbnKicbacTotal',
                array(
                    'formId'        => absint( rgar( $form, 'id' ) ),
                    'productFields' => $this->get_priced_field_ids( $form ),
                )
            );
        }

        /**
         * Every field on the form that carries a price.
         *
         * The charge reads only the two mapped product fields, but the donor is shown one
         * figure for the whole form, so the running total covers all of them — a second
         * product or an add-on option belongs in what the donor sees. GF's own
         * is_product_field() is no use here: it counts quantity and total fields, which
         * would multiply or double the amount.
         */
        public function get_priced_field_ids( $form ) {
            $priced = array( 'product', 'option', 'shipping' );
            $ids    = array();

            foreach ( (array) rgar( $form, 'fields' ) as $field ) {
                if ( in_array( (string) $field->type, $priced, true ) ) {
                    $ids[] = absint( $field->id );
                }
            }

            return $ids;
        }

        /**
         * Hands the mount script the container selector for each mapped secure field.
         *
         * @param array $form    The form being rendered.
         * @param bool  $is_ajax Unused; part of the GF script callback signature.
         */
        public function localize_collectjs_config( $form, $is_ajax = false ) {
            $settings = $this->get_form_settings( $form );
            $labels   = $this->get_kicbac_fields();
            $form_id  = absint( rgar( $form, 'id' ) );
            $fields   = array();

            foreach ( $this->get_kicbac_secure_fields() as $key ) {
                $field_id = absint( rgar( $settings, $key ) );

                if ( ! $field_id ) {
                    continue;
                }

                $fields[ $key ] = array(
                    'container' => '#field_' . $form_id . '_' . $field_id . ' .ginput_container',
                    'title'     => rgar( $labels, $key ),
                );
            }

            wp_localize_script(
                'gfmbn_kicbac_collect_init',
                'gfmbnKicbacCollect',
                array(
                    'formId' => $form_id,
                    'fields' => $fields,
                )
            );
        }

        /**
         * Versions bundled assets by modification time. The addon version rarely moves,
         * so without this an edited script stays cached in browsers that already have it.
         */
        public function get_asset_version( $relative_path ) {
            $file = plugin_dir_path( $this->_full_path ) . $relative_path;

            return file_exists( $file ) ? (string) filemtime( $file ) : $this->_version;
        }

        /** The Collect.js tokenization key, safe to expose in the browser. */
        public function get_public_key() {
            return trim( (string) rgar( $this->get_plugin_settings(), 'public_key' ) );
        }

        public function is_gateway_enabled( $form ) {
            return (bool) rgar( $this->get_form_settings( $form ), 'enabled' );
        }

        /**
         * Enqueue condition for Collect.js. Without a public key there is nothing to
         * tokenize against, so the form keeps submitting as it did before.
         *
         * @param array $form    The form being rendered.
         * @param bool  $is_ajax Unused; part of the GF enqueue-condition callback signature.
         */
        public function is_collectjs_enabled( $form, $is_ajax = false ) {
            return ! empty( $form ) && $this->is_gateway_enabled( $form ) && $this->get_public_key() !== '';
        }

        /**
         * Collect.js reads its tokenization key off its own script tag, which
         * wp_enqueue_script() has no way to express.
         */
        public function add_collectjs_attributes( $tag, $handle ) {
            if ( 'gfmbn_kicbac_collectjs' !== $handle ) {
                return $tag;
            }

            return str_replace(
                ' src=',
                ' data-tokenization-key="' . esc_attr( $this->get_public_key() ) . '" src=',
                $tag
            );
        }

        /** Plugin-wide settings (e.g., API key) shown under Forms → Settings → MBN Kicbac Payment */
        public function plugin_settings_fields() {
            return array(
                array(
                    'title'  => esc_html__( 'Kicbac Payment Gateway Setting', 'gravityform-kicbac-payment' ),
                    'fields' => array(
                        array(
                            'name'              => 'api_key',
                            'label'             => esc_html__( 'Kicbac Security Key', 'gravityform-kicbac-payment' ),
                            'type'              => 'text',
                            'input_type'        => 'password',
                            'class'             => 'medium',
                            'required'          => true,
                            'feedback_callback' => array( $this, 'is_valid_api_key' )
                        ),
                        array(
                            'name'        => 'public_key',
                            'label'       => esc_html__( 'Public Security Key', 'gravityform-kicbac-payment' ),
                            'type'        => 'text',
                            'class'       => 'medium',
                            'description' => esc_html__( 'Tokenization key used by Collect.js in the browser. Safe to expose publicly. Leave empty to disable tokenization.', 'gravityform-kicbac-payment' ),
                        ),
                        array(
                            'name'        => 'webhook_signing_key',
                            'label'       => esc_html__( 'Webhook Signing Key', 'gravityform-kicbac-payment' ),
                            'type'        => 'text',
                            'input_type'  => 'password',
                            'class'       => 'medium',
                            'description' => sprintf(
                                /* translators: %s: the webhook endpoint URL to paste into Kicbac. */
                                esc_html__( 'Verifies the signature on inbound Kicbac webhooks. Leave empty to disable webhook handling. Point Kicbac at: %s', 'gravityform-kicbac-payment' ),
                                '<code>' . esc_url( $this->get_webhook_url() ) . '</code>'
                            ),
                        )
                    ),
                ),
            );
        }

        /** Validation from Kicbac API */
        public function is_valid_api_key( $value ) {
          if( !$value ) {
            return false;
          }
          $query_params = array(
            'security_key' => $value,
            'transaction_id' => strval( mt_rand( 1000000000, 9999999999 ) )
          );

          $response = wp_remote_get( GFORMMBN_KICBAC_API_BASE_URL . '/query.php?' . http_build_query( $query_params ));

          if ( is_wp_error( $response ) ) {
            return false;
          }

          $body = wp_remote_retrieve_body( $response );
          $ret = gformmbn_kicbac_response_xml_handler( $body );
          
          if( isset( $ret['error_response']) ) {
            return false;
          }

          return true;
        }


        public function get_kicbac_fields() {
          return array(
            'company' => 'Company',
            'email' => 'Email',
            'phone' => 'Phone',
            'payment' => 'Payment Method',
            'first_name' => 'Card Holder Firstname',
            'last_name' => 'Card Holder Lastname',

            // secure fields
            'ccnumber' => 'Card Number',
            'ccexp' => 'Card Expiration Date',
            'cvv' => 'Card CVV',
            'checkname' => 'Check ACH Name',
            'checkaba' => 'Check Routing',
            'checkaccount' => 'Check Account Number',

            // addresss fields
            'address1' => 'Address Line 1',
            'address2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'country' => 'Country',

            // shipping fields
            'shipping_address1' => 'Shipping Address Line 1',
            'shipping_address2' => 'Shipping Address Line 2',
            'shipping_city' => 'Shipping City',
            'shipping_state' => 'Shipping State',
            'shipping_zip' => 'Shipping ZIP',
            'shipping_country' => 'Shipping Country',
          );
        }


        public function get_kicbac_secure_fields() {
          return array(
            'ccnumber',
            'ccexp',
            'cvv',
            'checkname',
            'checkaba',
            'checkaccount',
          );
        }

        /** Enable Feeds UI on each form (Forms → your form → Settings → MBN Kicbac Payment) */
        public function form_settings_fields( $form ) {

          $fields = array();

          foreach( $this->get_kicbac_fields() as $key => $value ) {
            $fields[] = array(
              'label' => esc_html__( $value . " Field ID", 'gravityform-kicbac-payment' ),
              'type' => 'text',
              'name' => $key,
            );
          }

          return array(
              array(
                  'title'  => esc_html__( 'Kicbac Payment Gateway Setup', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Map the product field IDs that drive the charge. A value in the one-time field bills a single sale, a value in the recurring field starts a subscription.', 'gravityform-kicbac-payment' ),
                  'fields' => array(
                      array(
                          'type'    => 'checkbox',
                          'name'    => 'enabled',
                          'choices' => array(
                              array(
                                  'label' => esc_html__( 'Enable Kicbac Payment Gateway for this form', 'gravityform-kicbac-payment' ),
                                  'name'  => 'enabled',
                              ),
                          ),
                      ),
                      array(
                          'label' => esc_html__( 'One-time Product Field ID', 'gravityform-kicbac-payment' ),
                          'type'  => 'text',
                          'name'  => 'onetime_product',
                      ),
                      array(
                          'label' => esc_html__( 'Recurring Product Field ID', 'gravityform-kicbac-payment' ),
                          'type'  => 'text',
                          'name'  => 'recurring_product',
                      ),
                  ),
              ),
              array(
                  'title'  => esc_html__( 'Kicbac Payment Gateway Data Mapping for Customers Vault', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Map your form field IDs to Kicbac Payment Gateway fields, Leaving empty will be ignored.', 'gravityform-kicbac-payment' )
                      . '<br /><br />' . sprintf(
                          /* translators: 1: the [kicbac-form-total] shortcode, 2: the Gravity Forms field type it belongs in. */
                          esc_html__( 'Running total: put %1$s in a Gravity Forms %2$s field to show what the donor is about to be charged. It adds up the product fields above and updates in the browser as the selection changes. A Paragraph field will not work — it prints the shortcode as text instead of running it.', 'gravityform-kicbac-payment' ),
                          '<code>[kicbac-form-total]</code>',
                          '<strong>' . esc_html__( 'HTML', 'gravityform-kicbac-payment' ) . '</strong>'
                      ),
                  'fields' => $fields,
              ),
          );
      }

        /** Field IDs on this form that Collect.js collects instead of Gravity Forms. */
        public function get_tokenized_field_ids( $form ) {
            $settings = $this->get_form_settings( $form );
            $ids      = array();

            foreach ( $this->get_kicbac_secure_fields() as $key ) {
                $field_id = absint( rgar( $settings, $key ) );

                if ( $field_id ) {
                    $ids[] = $field_id;
                }
            }

            return $ids;
        }

        /**
         * The tokenized inputs are hidden and always post empty, so GF would fail them
         * on "required". Clear those results and judge the payment on the token instead.
         */
        public function validate_payment_token( $validation_result ) {
            $form = $validation_result['form'];

            if ( ! $this->is_collectjs_enabled( $form ) ) {
                return $validation_result;
            }

            $tokenized = $this->get_tokenized_field_ids( $form );

            if ( empty( $tokenized ) ) {
                return $validation_result;
            }

            $token       = trim( (string) rgpost( 'gfmbn_kicbac_token' ) );
            $token_field = null;

            foreach ( $form['fields'] as $field ) {
                if ( ! in_array( (int) $field->id, $tokenized, true ) ) {
                    continue;
                }

                $field->failed_validation  = false;
                $field->validation_message = '';

                if ( null === $token_field ) {
                    $token_field = $field;
                }
            }

            if ( '' === $token && $token_field ) {
                $token_field->failed_validation  = true;
                $token_field->validation_message = esc_html__( 'Your payment details could not be verified. Please re-enter them and try again.', 'gravityform-kicbac-payment' );
            }

            $is_valid = true;

            foreach ( $form['fields'] as $field ) {
                if ( ! empty( $field->failed_validation ) ) {
                    $is_valid = false;
                    break;
                }
            }

            $validation_result['is_valid'] = $is_valid;
            $validation_result['form']     = $form;

            return $validation_result;
        }

        /**
         * Entry meta shown in the metabox, in display order. Keys are the short names
         * used by update_payment_meta() and the webhook.
         */
        public function get_entry_meta_labels() {
            return array(
                'payment_status'      => esc_html__( 'Payment Status', 'gravityform-kicbac-payment' ),
                'payment_amount'      => esc_html__( 'Amount', 'gravityform-kicbac-payment' ),
                'payment_date'        => esc_html__( 'Payment Date', 'gravityform-kicbac-payment' ),
                'transaction_id'      => esc_html__( 'Transaction ID', 'gravityform-kicbac-payment' ),
                'auth_code'           => esc_html__( 'Authorization Code', 'gravityform-kicbac-payment' ),
                'subscription_status' => esc_html__( 'Subscription', 'gravityform-kicbac-payment' ),
                'subscription_id'     => esc_html__( 'Subscription ID', 'gravityform-kicbac-payment' ),
                'subscription_amount' => esc_html__( 'Recurring Amount', 'gravityform-kicbac-payment' ),
                'billing_cycle'       => esc_html__( 'Billing Cycle', 'gravityform-kicbac-payment' ),
                'next_charge_date'    => esc_html__( 'Next Charge Date', 'gravityform-kicbac-payment' ),
                'customer_vault_id'   => esc_html__( 'Customer Vault ID', 'gravityform-kicbac-payment' ),
                'response_text'       => esc_html__( 'Gateway Response', 'gravityform-kicbac-payment' ),
            );
        }

        public function add_entry_meta_box( $meta_boxes, $entry, $form ) {
            if ( ! $this->is_gateway_enabled( $form ) ) {
                return $meta_boxes;
            }

            $meta_boxes['gfmbn_kicbac_payment'] = array(
                'title'    => esc_html__( 'Kicbac Payment', 'gravityform-kicbac-payment' ),
                'callback' => array( $this, 'render_entry_meta_box' ),
                'context'  => 'side',
            );

            return $meta_boxes;
        }

        public function render_entry_meta_box( $args ) {
            $entry_id = absint( rgars( $args, 'entry/id' ) );
            $rows     = array();

            foreach ( $this->get_entry_meta_labels() as $key => $label ) {
                $value = $this->get_payment_meta( $entry_id, $key );

                if ( '' === $value ) {
                    continue;
                }

                // Amounts are stored exactly as sent to the gateway; format for reading.
                if ( in_array( $key, array( 'payment_amount', 'subscription_amount' ), true ) ) {
                    $value = GFCommon::to_money( $value );
                }

                $rows[ $label ] = $value;
            }

            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No payment has been recorded for this entry.', 'gravityform-kicbac-payment' ) . '</p>';

                return;
            }

            echo '<table class="widefat fixed striped"><tbody>';

            foreach ( $rows as $label => $value ) {
                printf(
                    '<tr><th scope="row" style="width:45%%">%s</th><td>%s</td></tr>',
                    esc_html( $label ),
                    esc_html( $value )
                );
            }

            echo '</tbody></table>';
        }

        public function get_webhook_url() {
            return rest_url( 'kicbac/v1/webhook' );
        }

        public function register_webhook_route() {
            register_rest_route(
                'kicbac/v1',
                '/webhook',
                array(
                    'methods'  => 'POST',
                    'callback' => array( $this, 'handle_webhook' ),
                    // A gateway callback carries no WordPress user; the signing-key
                    // check inside the handler is the authentication.
                    'permission_callback' => '__return_true',
                )
            );
        }

        /**
         * Kicbac signs each delivery with a `webhook-signature: t=<ts>,v1=<hmac>` header,
         * where the HMAC covers "<timestamp>.<raw body>".
         */
        public function is_valid_webhook_signature( $header, $body, $signing_key ) {
            if ( empty( $header ) ) {
                return false;
            }

            $parts = array();

            foreach ( explode( ',', $header ) as $piece ) {
                $pair = explode( '=', trim( $piece ), 2 );

                if ( 2 === count( $pair ) ) {
                    $parts[ $pair[0] ] = $pair[1];
                }
            }

            $timestamp = rgar( $parts, 't' );
            $signature = rgar( $parts, 'v1' );

            if ( ! $timestamp || ! $signature ) {
                return false;
            }

            $expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $signing_key );

            // Constant-time compare so a wrong signature cannot be guessed by timing.
            return hash_equals( $expected, $signature );
        }

        /** The entry carrying a given gateway identifier, or 0 when none does. */
        public function find_entry_by_meta( $key, $value ) {
            global $wpdb;

            if ( '' === (string) $value ) {
                return 0;
            }

            $table = GFFormsModel::get_entry_meta_table_name();

            return absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT entry_id FROM {$table} WHERE meta_key = %s AND meta_value = %s ORDER BY entry_id DESC LIMIT 1",
                        self::META_PREFIX . $key,
                        $value
                    )
                )
            );
        }

        /** Event type to the payment status it leaves the entry in. */
        public function get_webhook_status_map() {
            return array(
                'transaction.sale.success'             => array( 'payment_status', 'Approved' ),
                'transaction.sale.failure'             => array( 'payment_status', 'Declined' ),
                'transaction.sale.unknown'             => array( 'payment_status', 'Unknown' ),
                'transaction.auth.success'             => array( 'payment_status', 'Authorized' ),
                'transaction.refund.success'           => array( 'payment_status', 'Refunded' ),
                'transaction.void.success'             => array( 'payment_status', 'Voided' ),
                'recurring.subscription.add'           => array( 'subscription_status', 'Active' ),
                'recurring.subscription.update'        => array( 'subscription_status', 'Active' ),
                'recurring.subscription.delete'        => array( 'subscription_status', 'Cancelled' ),
                'recurring.subscription.payment.success' => array( 'subscription_status', 'Active' ),
                'recurring.subscription.payment.failure' => array( 'subscription_status', 'Payment Failed' ),
            );
        }

        public function handle_webhook( $request ) {
            $signing_key = trim( (string) rgar( $this->get_plugin_settings(), 'webhook_signing_key' ) );

            if ( '' === $signing_key ) {
                return new WP_REST_Response( array( 'message' => 'Webhook handling is disabled.' ), 503 );
            }

            $body = $request->get_body();

            if ( ! $this->is_valid_webhook_signature( $request->get_header( 'webhook_signature' ), $body, $signing_key ) ) {
                $this->log_error( __METHOD__ . '(): rejected a webhook with a missing or invalid signature.' );

                return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
            }

            $payload = json_decode( $body, true );

            if ( ! is_array( $payload ) ) {
                return new WP_REST_Response( array( 'message' => 'Malformed payload.' ), 400 );
            }

            $event_type     = (string) rgar( $payload, 'event_type' );
            $event          = (array) rgar( $payload, 'event_body' );
            $transaction_id = (string) rgar( $event, 'transaction_id' );
            $subscription   = (array) rgar( $event, 'subscription' );
            $subscription_id = (string) ( rgar( $event, 'subscription_id' ) ?: rgar( $subscription, 'subscription_id' ) );

            $entry_id = $this->find_entry_by_meta( 'transaction_id', $transaction_id );

            if ( ! $entry_id ) {
                $entry_id = $this->find_entry_by_meta( 'subscription_id', $subscription_id );
            }

            if ( ! $entry_id ) {
                // Nothing to attach it to; 200 so the gateway stops retrying.
                $this->log_debug( __METHOD__ . '(): no entry matches event ' . $event_type );

                return new WP_REST_Response( array( 'message' => 'No matching entry.' ), 200 );
            }

            $meta   = array( 'response_text' => $event_type );
            $status = rgar( $this->get_webhook_status_map(), $event_type );

            if ( $status ) {
                $meta[ $status[0] ] = $status[1];
            }

            if ( $transaction_id ) {
                $meta['transaction_id'] = $transaction_id;
            }

            if ( $subscription_id ) {
                $meta['subscription_id'] = $subscription_id;
            }

            $next_charge = (string) ( rgar( $event, 'next_charge_date' ) ?: rgar( $subscription, 'next_charge_date' ) );

            if ( $next_charge ) {
                $meta['next_charge_date'] = $next_charge;
            }

            $amount = (string) ( rgar( $event, 'amount' ) ?: rgar( $subscription, 'plan_amount' ) );

            if ( $amount && 0 === strpos( $event_type, 'recurring.' ) ) {
                $meta['subscription_amount'] = $amount;
            } elseif ( $amount ) {
                $meta['payment_amount'] = $amount;
            }

            $this->update_payment_meta( $entry_id, $meta );
            $this->add_note( $entry_id, 'Kicbac webhook received: ' . $event_type, 'success' );

            return new WP_REST_Response( array( 'message' => 'Processed.' ), 200 );
        }

        /** Columns shown in the Feeds list */
        public function feed_list_columns() {
            return array(
                'feed_name' => esc_html__( 'MBN Kicbac Payment', 'gravityform-kicbac-payment' ),
            );
        }

        /** Tell the framework when to run process_feed */
        public function can_create_feed() {
            return true;
        }

        /** The main action: send entry to your API */
        public function process_feed( $entry, $form ) {

          $settings = $this->get_plugin_settings();
          $api_key = rgar( $settings, 'api_key' );
          $formsetting = rgar( $form, 'gravityform-kicbac-payment' );

          if( !rgar( $formsetting, 'enabled' ) ) {
            // nothing to do if disabled.
            return;
          }

            $token     = $this->get_submitted_token();
            $params    = $this->build_gateway_params( $entry, $form, $api_key );
            $onetime   = $this->get_product_amount( $form, $entry, rgar( $formsetting, 'onetime_product' ) );
            $recurring = $this->get_product_amount( $form, $entry, rgar( $formsetting, 'recurring_product' ) );

            if ( $onetime > 0 && $recurring > 0 ) {
              // A Collect.js token can only be charged once, so a donor giving both a
              // one-time and a recurring gift is vaulted first and billed twice against
              // the stored customer.
              $vault_id = $this->process_customer_vault( $entry, $params );

              if ( $vault_id ) {
                $vault_params = array(
                  'security_key'      => $api_key,
                  'customer_vault_id' => $vault_id,
                );
                $this->process_sale( $entry, $vault_params, $onetime );
                $this->process_subscription( $entry, $vault_params, $recurring );
              }
            } elseif ( $onetime > 0 ) {
              $this->process_sale( $entry, $params, $onetime );
            } elseif ( $recurring > 0 ) {
              $this->process_subscription( $entry, $params, $recurring );
            } else {
              $this->process_customer_vault( $entry, $params );
            }

            // Tokenized submissions never store card data in the first place, so there is
            // nothing to mask; only the legacy untokenized path needs scrubbing.
            if ( '' === $token ) {
              foreach( $this->get_kicbac_secure_fields() as $value ) {
                $formsetting_id = rgar( $formsetting, $value );
                if ( ! empty( $formsetting_id ) ) {
                  $secure_value = gformmbn_kicbac_secure_field( rgar( $entry, $formsetting_id ) );
                  GFAPI::update_entry_field( $entry['id'], $formsetting_id, $secure_value );
                }
              }
            }
        }

        /** Billing, contact and payment-instrument params shared by every gateway call. */
        public function build_gateway_params( $entry, $form, $api_key ) {
            $formsetting   = rgar( $form, 'gravityform-kicbac-payment' );
            $token         = $this->get_submitted_token();
            $secure_fields = $this->get_kicbac_secure_fields();
            $params        = array( 'security_key' => $api_key );

            foreach ( $this->get_kicbac_fields() as $key => $value ) {
              // Tokenized card/ACH data never reaches the entry: those inputs are hidden
              // and post empty, so sending them would only overwrite the token's data.
              if ( '' !== $token && in_array( $key, $secure_fields, true ) ) {
                continue;
              }
              $params[ $key ] = rgar( $entry, rgar( $formsetting, $key ) );
            }

            if ( '' !== $token ) {
              $params['payment_token'] = $token;
            } elseif ( isset( $params['ccexp'] ) ) {
              // transform correct format ccexp to MMYY
              $params['ccexp'] = gformmbn_kicbac_exp_date_format( $params['ccexp'] );
            }

            return $params;
        }

        /** Total for one mapped product field, including its options and quantity. */
        public function get_product_amount( $form, $entry, $field_id ) {
            $field_id = absint( $field_id );

            if ( ! $field_id ) {
              return 0;
            }

            $products = GFCommon::get_product_fields( $form, $entry );
            $total    = 0;

            foreach ( (array) rgar( $products, 'products' ) as $id => $product ) {
              if ( absint( $id ) !== $field_id ) {
                continue;
              }

              $quantity = GFCommon::to_number( rgar( $product, 'quantity', 1 ) );

              if ( ! $quantity ) {
                $quantity = 1;
              }

              $price = GFCommon::to_number( rgar( $product, 'price' ) );

              foreach ( (array) rgar( $product, 'options' ) as $option ) {
                $price += GFCommon::to_number( rgar( $option, 'price' ) );
              }

              $total += $price * $quantity;
            }

            return $total;
        }

        public function post_to_gateway( $endpoint, $params ) {
            return wp_remote_post( GFORMMBN_KICBAC_API_BASE_URL . '/' . $endpoint . '?' . http_build_query( $params ) );
        }

        /** Charges the one-time gift amount. */
        public function process_sale( $entry, $params, $amount ) {
            $params['type']    = 'sale';
            $params['amount']  = number_format( $amount, 2, '.', '' );
            $params['orderid'] = $entry['id'];

            $response = $this->post_to_gateway( 'transact.php', $params );

            if ( is_wp_error( $response ) ) {
              $this->update_payment_meta( $entry['id'], array(
                'payment_status' => 'Failed',
                'payment_amount' => $params['amount'],
                'response_text'  => $response->get_error_message(),
              ) );
              $this->add_note( $entry['id'], 'Payment failed: API submission error', 'error' );
              $this->log_error( __METHOD__ . '(): ' . $response->get_error_message() );
              return;
            }

            $ret    = gformmbn_kicbac_response_text_handler( wp_remote_retrieve_body( $response ) );
            $status = $this->get_response_status( $ret );

            $this->update_payment_meta( $entry['id'], array(
              'payment_status' => $status,
              'payment_amount' => $params['amount'],
              'payment_date'   => current_time( 'mysql' ),
              'transaction_id' => rgar( $ret, 'transactionid' ),
              'auth_code'      => rgar( $ret, 'authcode' ),
              'response_text'  => rgar( $ret, 'responsetext' ),
            ) );

            if ( 'Approved' === $status ) {
              $this->add_note(
                $entry['id'],
                sprintf( 'Payment approved for %s. Transaction ID %s', $params['amount'], rgar( $ret, 'transactionid' ) ),
                'success'
              );
            } else {
              $this->add_note( $entry['id'], 'Payment was not approved: ' . rgar( $ret, 'responsetext' ), 'error' );
            }
        }

        /** Starts the recurring gift as a monthly subscription. */
        public function process_subscription( $entry, $params, $amount ) {
            $params['recurring']     = 'add_subscription';
            $params['plan_amount']   = number_format( $amount, 2, '.', '' );
            $params['plan_payments'] = 0; // bill until the donor cancels
            $params['month_frequency'] = 1;
            // Clamped to 28 so a gift started on the 29th-31st still bills every month.
            $params['day_of_month']  = min( (int) current_time( 'j' ), 28 );
            $params['orderid']       = $entry['id'];

            $response = $this->post_to_gateway( 'transact.php', $params );

            if ( is_wp_error( $response ) ) {
              $this->update_payment_meta( $entry['id'], array(
                'subscription_status' => 'Failed',
                'subscription_amount' => $params['plan_amount'],
                'response_text'       => $response->get_error_message(),
              ) );
              $this->add_note( $entry['id'], 'Recurring gift failed: API submission error', 'error' );
              $this->log_error( __METHOD__ . '(): ' . $response->get_error_message() );
              return;
            }

            $ret    = gformmbn_kicbac_response_text_handler( wp_remote_retrieve_body( $response ) );
            $status = $this->get_response_status( $ret );

            $this->update_payment_meta( $entry['id'], array(
              'subscription_status' => 'Approved' === $status ? 'Active' : $status,
              'subscription_id'     => rgar( $ret, 'subscription_id' ),
              'subscription_amount' => $params['plan_amount'],
              'billing_cycle'       => 'Monthly',
              'response_text'       => rgar( $ret, 'responsetext' ),
              'next_charge_date'    => 'Approved' === $status ? $this->calculate_next_charge_date( $params['day_of_month'] ) : '',
            ) );

            if ( 'Approved' === $status ) {
              $this->add_note(
                $entry['id'],
                sprintf( 'Recurring gift started at %s/month. Subscription ID %s', $params['plan_amount'], rgar( $ret, 'subscription_id' ) ),
                'success'
              );
            } else {
              $this->add_note( $entry['id'], 'Recurring gift was not started: ' . rgar( $ret, 'responsetext' ), 'error' );
            }
        }

        /**
         * Records the payer in the vault. Also the fallback when there is nothing to charge.
         *
         * @return string The customer vault ID, or an empty string when the call failed.
         */
        public function process_customer_vault( $entry, $params ) {
            $params['customer_vault'] = 'add_customer';

            $response = $this->post_to_gateway( 'transact.php', $params );

            if ( is_wp_error( $response ) ) {
              $this->add_note( $entry['id'], 'Merchant has not been added to the vault: API Submission Error', 'error' );
              $this->log_error( __METHOD__ . '(): ' . $response->get_error_message() );
              return '';
            }

            $this->log_debug( __METHOD__ . '(): Entry posted successfully.' );

            $ret = gformmbn_kicbac_response_text_handler( wp_remote_retrieve_body( $response ) );

            if ( isset( $ret['customer_vault_id'] ) ) {
              $this->update_payment_meta( $entry['id'], array( 'customer_vault_id' => $ret['customer_vault_id'] ) );
              $this->add_note( $entry['id'], 'Merchant has been added to the vault: Customer Vault ID ' . $ret['customer_vault_id'], 'success' );

              return (string) $ret['customer_vault_id'];
            }

            $this->add_note( $entry['id'], 'Merchant has not been added to the vault: ' . rgar( $ret, 'responsetext' ), 'error' );

            return '';
        }

        /** The Collect.js token for the submission being processed, if the browser produced one. */
        public function get_submitted_token() {
            return trim( (string) rgpost( 'gfmbn_kicbac_token' ) );
        }

        /**
         * Entry meta written by the gateway calls and by the webhook, and read back by the
         * entry detail metabox. Keys are stored prefixed; callers use the short name.
         */
        const META_PREFIX = 'kicbac_';

        public function update_payment_meta( $entry_id, $data ) {
            foreach ( $data as $key => $value ) {
                gform_update_meta( $entry_id, self::META_PREFIX . $key, $value );
            }
        }

        public function get_payment_meta( $entry_id, $key, $default = '' ) {
            // gform_get_meta() returns false, not null, for a key that was never written.
            $value = gform_get_meta( $entry_id, self::META_PREFIX . $key );

            return ( null === $value || false === $value || '' === $value ) ? $default : $value;
        }

        /** Kicbac returns response=1 approved, 2 declined, 3 gateway error. */
        public function get_response_status( $ret ) {
            switch ( (string) rgar( $ret, 'response' ) ) {
                case '1':
                    return 'Approved';
                case '2':
                    return 'Declined';
                default:
                    return 'Failed';
            }
        }

        /**
         * A new subscription bills immediately, then on $day_of_month each following month.
         * The webhook replaces this with the gateway's own figure once it reports one.
         */
        public function calculate_next_charge_date( $day_of_month ) {
            $next_month = strtotime( 'first day of next month', current_time( 'timestamp' ) );

            return gmdate(
                'Y-m-d',
                mktime( 0, 0, 0, (int) gmdate( 'n', $next_month ), (int) $day_of_month, (int) gmdate( 'Y', $next_month ) )
            );
        }

        /** Optional: add form-level UI, validation, etc. */
        public function init() {
            parent::init();
            // Register the action that triggers feed processing
            add_action( 'gform_after_submission', array( $this, 'process_feed' ), 10, 2 );
            add_filter( 'script_loader_tag', array( $this, 'add_collectjs_attributes' ), 10, 2 );
            add_filter( 'gform_validation', array( $this, 'validate_payment_token' ) );
            add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_entry_meta_box' ), 10, 3 );
            add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
        }
    }

    // Kick it off
    GF_Kicbac_AddOn::get_instance();
    GFAddOn::register( 'GF_Kicbac_AddOn' );
}