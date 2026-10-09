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
                    array(
                        'handle'    => 'gfmbn_kicbac_admin',
                        'src'       => plugins_url( 'assets/js/kicbac-admin.js', $this->_full_path ),
                        'version'   => $this->get_asset_version( 'assets/js/kicbac-admin.js' ),
                        'in_footer' => true,
                        'enqueue'   => array(
                            array(
                                'admin_page' => array( 'form_settings' ),
                                'tab'        => $this->_slug,
                            ),
                        ),
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

        /** Tells the total script which fields are priced, and what each choice costs. */
        public function localize_total_config( $form, $is_ajax = false ) {
            wp_localize_script(
                'gfmbn_kicbac_total',
                'gfmbnKicbacTotal',
                array(
                    'formId'        => absint( rgar( $form, 'id' ) ),
                    'productFields' => $this->get_priced_fields( $form ),
                )
            );
        }

        /**
         * Every priced field on the form, each with the price of its choices.
         *
         * The charge reads only the two mapped product fields, but the donor is shown one
         * figure for the whole form, so the running total covers all of them — a second
         * product or an add-on option belongs in what the donor sees. GF's own
         * is_product_field() is no use for picking them: it counts quantity and total
         * fields, which would multiply or double the amount.
         *
         * Prices come from the form object rather than the rendered input, whose
         * "value|price" format only survives while GF renders the choice itself. A theme
         * that replaces a choice field with its own markup drops the price half, and the
         * total then reads that field as free.
         */
        public function get_priced_fields( $form ) {
            $priced = array( 'product', 'option', 'shipping' );
            $fields = array();

            foreach ( (array) rgar( $form, 'fields' ) as $field ) {
                if ( ! in_array( (string) $field->type, $priced, true ) ) {
                    continue;
                }

                $quantity = GFCommon::get_product_fields_by_type( $form, array( 'quantity' ), $field->id );

                $fields[] = array(
                    'id'      => absint( $field->id ),
                    'type'    => (string) $field->type,
                    'choices' => $this->get_choice_prices( $field ),
                    // A Quantity field sits outside the product's own markup, so the
                    // script is told where to look for it.
                    'quantity' => empty( $quantity ) ? 0 : absint( $quantity[0]->id ),
                    // Set on an Option field: the product it belongs to. GF charges an
                    // option only while its product is selected.
                    'product' => absint( rgobj( $field, 'productField' ) ),
                );
            }

            $priced_ids = wp_list_pluck( $fields, 'id' );

            foreach ( $this->get_product_mappings( $form ) as $row ) {
                $field = GFAPI::get_field( $form, $row['field_id'] );

                if ( ! $field || in_array( $row['field_id'], $priced_ids, true ) ) {
                    continue;
                }

                // A mapped non-product field charges its own value, so each choice is
                // priced at the number it posts.
                $choices = array();

                foreach ( is_array( $field->choices ) ? $field->choices : array() as $choice ) {
                    $value = rgblank( rgar( $choice, 'value' ) ) ? rgar( $choice, 'text' ) : rgar( $choice, 'value' );

                    if ( ! rgblank( $value ) ) {
                        $choices[ $value ] = (float) GFCommon::to_number( $value );
                    }
                }

                $fields[]     = array( 'id' => $row['field_id'], 'type' => (string) $field->type, 'choices' => $choices, 'quantity' => 0, 'product' => 0 );
                $priced_ids[] = $row['field_id'];
            }

            return $fields;
        }

        /** A choice field's prices, keyed by the value the choice posts. */
        public function get_choice_prices( $field ) {
            $prices = array();

            foreach ( (array) rgobj( $field, 'choices' ) as $choice ) {
                $value = rgar( $choice, 'value' );

                // GF posts the label when the field has no separate choice values.
                if ( rgblank( $value ) && ! rgobj( $field, 'enableChoiceValue' ) ) {
                    $value = rgar( $choice, 'text' );
                }

                if ( ! rgblank( $value ) ) {
                    $prices[ $value ] = GFCommon::to_number( rgar( $choice, 'price' ) );
                }
            }

            return $prices;
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

            $this->collectjs_public_key = $this->get_public_key( $form );

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

        /**
         * A Kicbac key for a form: the form's own when it has one, the global one otherwise.
         * Each key falls back on its own, so a form can override only the keys it needs.
         * In test mode the test_ variant is read instead, and never falls back to a live key,
         * so a missing test key can't put a sandbox charge through the live account.
         *
         * @param array|null $form Null reads the global key alone.
         * @param string     $key  api_key, public_key or webhook_signing_key.
         */
        public function get_credential( $form, $key ) {
            $form_settings = $form ? $this->get_form_settings( $form ) : array();

            if ( $this->is_test_mode( $form ) ) {
                $key = 'test_' . $key;
            }

            $value = trim( (string) rgar( $form_settings, $key ) );

            return '' !== $value ? $value : trim( (string) rgar( $this->get_plugin_settings(), $key ) );
        }

        /** Test mode is on when the form or the global settings enable it. */
        public function is_test_mode( $form = null ) {
            return (bool) rgar( $this->get_plugin_settings(), 'test_mode' )
                || ( $form && (bool) rgar( $this->get_form_settings( $form ), 'test_mode' ) );
        }

        /**
         * The public key of the form whose Collect.js was enqueued. Collect.js takes one
         * tokenization key per page, read off its script tag, and the tag is printed long
         * after the form that chose it is out of scope.
         */
        private $collectjs_public_key = '';

        /** The Collect.js tokenization key, safe to expose in the browser. */
        public function get_public_key( $form = null ) {
            return $this->get_credential( $form, 'public_key' );
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
            return ! empty( $form ) && $this->is_gateway_enabled( $form ) && $this->get_public_key( $form ) !== '';
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
                ' data-tokenization-key="' . esc_attr( $this->collectjs_public_key ) . '" src=',
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
                array(
                    'title'       => esc_html__( 'Kicbac Test Mode', 'gravityform-kicbac-payment' ),
                    'description' => esc_html__( 'While test mode is on, every form charges, tokenizes and verifies webhooks with the test keys below instead of the live ones.', 'gravityform-kicbac-payment' ),
                    'fields'      => $this->get_test_credential_fields(),
                ),
            );
        }

        /** Test mode toggle and the sandbox keys it switches to; shared by plugin and form settings. */
        public function get_test_credential_fields() {
            return array(
                array(
                    'type'    => 'checkbox',
                    'name'    => 'test_mode',
                    'choices' => array(
                        array(
                            'label' => esc_html__( 'Enable test mode (sandbox)', 'gravityform-kicbac-payment' ),
                            'name'  => 'test_mode',
                        ),
                    ),
                ),
                array(
                    'name'              => 'test_api_key',
                    'label'             => esc_html__( 'Test Security Key', 'gravityform-kicbac-payment' ),
                    'type'              => 'text',
                    'input_type'        => 'password',
                    'class'             => 'medium',
                    'feedback_callback' => array( $this, 'is_valid_form_api_key' ),
                ),
                array(
                    'name'  => 'test_public_key',
                    'label' => esc_html__( 'Test Public Security Key', 'gravityform-kicbac-payment' ),
                    'type'  => 'text',
                    'class' => 'medium',
                ),
                array(
                    'name'        => 'test_webhook_signing_key',
                    'label'       => esc_html__( 'Test Webhook Signing Key', 'gravityform-kicbac-payment' ),
                    'type'        => 'text',
                    'input_type'  => 'password',
                    'class'       => 'medium',
                    'description' => sprintf(
                        /* translators: %s: the webhook endpoint URL to paste into Kicbac. */
                        esc_html__( 'Signing key of the webhook set up in the test account. Point it at: %s', 'gravityform-kicbac-payment' ),
                        '<code>' . esc_url( $this->get_webhook_url() ) . '</code>'
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


        /** Unlike the global key, a per-form key is optional: empty shows no verdict at all. */
        public function is_valid_form_api_key( $value ) {
            return '' === trim( (string) $value ) ? null : $this->is_valid_api_key( $value );
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

          $fields  = array();
          $choices = array_merge(
            array( array( 'value' => '', 'label' => esc_html__( 'Select a field', 'gravityform-kicbac-payment' ) ) ),
            $this->get_mappable_field_choices( $form )
          );

          foreach( $this->get_kicbac_fields() as $key => $value ) {
            $fields[] = array(
              'label'   => esc_html__( $value, 'gravityform-kicbac-payment' ),
              'type'    => 'select',
              'name'    => $key,
              'choices' => $choices,
            );
          }

          return array(
              array(
                  'title'  => esc_html__( 'Kicbac Payment Gateway Setup', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Map the fields that drive the charge. One-time amounts are added up into a single sale; recurring amounts start a subscription per billing schedule.', 'gravityform-kicbac-payment' ),
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
                          'label'         => esc_html__( 'Product Mappings', 'gravityform-kicbac-payment' ),
                          'type'          => 'kicbac_products',
                          'name'          => 'product_mappings',
                          'default_value' => $this->get_product_mappings( $form ),
                          'save_callback' => array( $this, 'save_product_mappings' ),
                      ),
                  ),
              ),
              array(
                  'title'       => esc_html__( 'Kicbac Credentials for this Form', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Optional. Fill these only when this form charges a different Kicbac account. Any key left empty uses the global one under Forms → Settings → MBN Kicbac Payment.', 'gravityform-kicbac-payment' ),
                  'fields'      => array(
                      array(
                          'name'              => 'api_key',
                          'label'             => esc_html__( 'Kicbac Security Key', 'gravityform-kicbac-payment' ),
                          'type'              => 'text',
                          'input_type'        => 'password',
                          'class'             => 'medium',
                          'feedback_callback' => array( $this, 'is_valid_form_api_key' ),
                      ),
                      array(
                          'name'        => 'public_key',
                          'label'       => esc_html__( 'Public Security Key', 'gravityform-kicbac-payment' ),
                          'type'        => 'text',
                          'class'       => 'medium',
                          'description' => esc_html__( 'Collect.js tokenization key for this account. A form with its own Security Key needs its own Public Key too, or the card is tokenized against the other account.', 'gravityform-kicbac-payment' ),
                      ),
                      array(
                          'name'        => 'webhook_signing_key',
                          'label'       => esc_html__( 'Webhook Signing Key', 'gravityform-kicbac-payment' ),
                          'type'        => 'text',
                          'input_type'  => 'password',
                          'class'       => 'medium',
                          'description' => sprintf(
                              /* translators: %s: the webhook endpoint URL to paste into Kicbac. */
                              esc_html__( 'Signing key of the webhook set up in this account. Point it at: %s', 'gravityform-kicbac-payment' ),
                              '<code>' . esc_url( $this->get_webhook_url() ) . '</code>'
                          ),
                      ),
                  ),
              ),
              array(
                  'title'       => esc_html__( 'Kicbac Test Mode for this Form', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Test mode is on when it is enabled here or globally. Any test key left empty uses the global test key.', 'gravityform-kicbac-payment' ),
                  'fields'      => $this->get_test_credential_fields(),
              ),
              array(
                  'title'  => esc_html__( 'Kicbac Payment Gateway Data Mapping for Customers Vault', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Pick the form field that holds each Kicbac value. Anything left unselected is not sent.', 'gravityform-kicbac-payment' )
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

        /** Billing choices for a product mapping, keyed by the stored value. */
        public function get_billing_choices() {
            return array(
                'onetime' => esc_html__( 'One-time', 'gravityform-kicbac-payment' ),
                'monthly' => esc_html__( 'Recurring - Monthly', 'gravityform-kicbac-payment' ),
                'yearly'  => esc_html__( 'Recurring - Yearly', 'gravityform-kicbac-payment' ),
                'custom'  => esc_html__( 'Recurring - Every N months', 'gravityform-kicbac-payment' ),
            );
        }

        /**
         * The form's product mappings, normalised. Forms saved before mappings existed
         * carry a single one-time and a single recurring field ID; those are read as
         * rows so the form keeps charging the same way until it is next saved.
         */
        public function get_product_mappings( $form ) {
            $settings = $this->get_form_settings( $form );
            $rows     = rgar( $settings, 'product_mappings' );

            if ( ! is_array( $rows ) ) {
                $rows = array(
                    array( 'field_id' => rgar( $settings, 'onetime_product' ), 'billing' => 'onetime' ),
                    array( 'field_id' => rgar( $settings, 'recurring_product' ), 'billing' => 'monthly' ),
                );
            }

            return $this->sanitize_product_mappings( $rows );
        }

        public function sanitize_product_mappings( $rows ) {
            $billing = $this->get_billing_choices();
            $clean   = array();

            foreach ( (array) $rows as $row ) {
                $field_id = absint( rgar( (array) $row, 'field_id' ) );

                if ( ! $field_id ) {
                    continue;
                }

                $type = (string) rgar( $row, 'billing' );

                $clean[] = array(
                    'field_id' => $field_id,
                    'billing'  => isset( $billing[ $type ] ) ? $type : 'onetime',
                    // Kicbac accepts a month_frequency of 1 to 24.
                    'months'   => min( 24, max( 1, (int) rgar( $row, 'months' ) ) ),
                    'payments' => max( 0, (int) rgar( $row, 'payments' ) ),
                );
            }

            return $clean;
        }

        public function save_product_mappings( $field, $value ) {
            return $this->sanitize_product_mappings( is_string( $value ) ? json_decode( $value, true ) : $value );
        }

        /** Fields that can hold a value; layout-only fields are left out. */
        public function get_form_field_list( $form ) {
            return array_filter( (array) rgar( $form, 'fields' ), function ( $field ) {
                return ! in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true );
            } );
        }

        public function get_field_choice_label( $field, $input = null ) {
            $label = GFCommon::get_label( $field );

            if ( $input ) {
                return sprintf( '%s - %s (ID %s, %s)', $label, rgar( $input, 'label' ), rgar( $input, 'id' ), $field->type );
            }

            return sprintf( '%s (ID %d, %s)', $label, $field->id, $field->type );
        }

        /**
         * Dropdown choices for the Customer Vault mapping. Name and Address fields store
         * each part under its own input ID and nothing under the field ID, so they are
         * offered part by part.
         */
        public function get_mappable_field_choices( $form ) {
            $choices = array();

            foreach ( $this->get_form_field_list( $form ) as $field ) {
                if ( in_array( $field->get_input_type(), array( 'name', 'address' ), true ) && is_array( $field->inputs ) ) {
                    foreach ( $field->inputs as $input ) {
                        if ( ! rgar( $input, 'isHidden' ) ) {
                            $choices[] = array( 'value' => (string) $input['id'], 'label' => $this->get_field_choice_label( $field, $input ) );
                        }
                    }

                    continue;
                }

                $choices[] = array( 'value' => (string) $field->id, 'label' => $this->get_field_choice_label( $field ) );
            }

            return $choices;
        }

        /** GF prints no description for a callback-rendered field, so the help sits in its markup. */
        public function get_product_mappings_help() {
            return '<ul class="gform-settings-description" style="list-style:disc;margin-left:1.5em">'
                . '<li>' . esc_html__( 'Field: the form field holding the amount. Any field works: a Product field, or a Number, text, hidden or choice field whose value is the amount.', 'gravityform-kicbac-payment' ) . '</li>'
                . '<li>' . esc_html__( 'Billing: One-time amounts are added up into a single charge. Recurring amounts start a subscription; rows with the same schedule share one.', 'gravityform-kicbac-payment' ) . '</li>'
                . '<li>' . esc_html__( 'Every (months): how often an "Every N months" gift bills, 1 to 24. Monthly and Yearly set it for you.', 'gravityform-kicbac-payment' ) . '</li>'
                . '<li>' . esc_html__( 'Payments: how many times a recurring gift bills before it stops on its own. 0 bills until the donor or you cancel it. Example: Monthly with 12 payments is a one-year pledge; Every 3 months with 4 payments is four quarterly charges. Not used for One-time.', 'gravityform-kicbac-payment' ) . '</li>'
                . '</ul>';
        }

        /**
         * Renders the repeatable product mapping rows. The rows themselves are drawn and
         * serialised into the hidden input by kicbac-admin.js.
         */
        public function settings_kicbac_products( $field, $echo = true ) {
            $products = array();

            foreach ( $this->get_form_field_list( $this->get_current_form() ) as $form_field ) {
                $products[] = array(
                    'id'    => absint( $form_field->id ),
                    'label' => $this->get_field_choice_label( $form_field ),
                );
            }

            $html = sprintf(
                '<div class="gfmbn-kicbac-products" data-products="%s" data-billing="%s" data-labels="%s">'
                . '<input type="hidden" name="_gform_setting_%s" value="%s" />'
                . '<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th></th></tr></thead><tbody></tbody></table>'
                . '<p><button type="button" class="button gfmbn-kicbac-add">%s</button></p>'
                . '%s'
                . '</div>',
                esc_attr( wp_json_encode( $products ) ),
                esc_attr( wp_json_encode( $this->get_billing_choices() ) ),
                esc_attr( wp_json_encode( array(
                    'remove' => __( 'Remove', 'gravityform-kicbac-payment' ),
                    'select'  => __( 'Select a field', 'gravityform-kicbac-payment' ),
                    'missing' => __( 'Deleted field', 'gravityform-kicbac-payment' ),
                ) ) ),
                esc_attr( $field->name ),
                esc_attr( wp_json_encode( $this->sanitize_product_mappings( $field->get_value() ) ) ),
                esc_html__( 'Field', 'gravityform-kicbac-payment' ),
                esc_html__( 'Billing', 'gravityform-kicbac-payment' ),
                esc_html__( 'Every (months)', 'gravityform-kicbac-payment' ),
                esc_html__( 'Payments', 'gravityform-kicbac-payment' ),
                esc_html__( 'Add field', 'gravityform-kicbac-payment' ),
                $this->get_product_mappings_help()
            );

            if ( $echo ) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            }

            return $html;
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

                $parts = explode( ',', $value );

                // Amounts are stored exactly as sent to the gateway; format for reading.
                if ( in_array( $key, array( 'payment_amount', 'subscription_amount' ), true ) ) {
                    $parts = array_map( array( 'GFCommon', 'to_money' ), $parts );
                }

                $value = implode( ', ', $parts );

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
                        "SELECT entry_id FROM {$table} WHERE meta_key = %s AND ( meta_value = %s OR FIND_IN_SET( %s, meta_value ) ) ORDER BY entry_id DESC LIMIT 1",
                        self::META_PREFIX . $key,
                        $value,
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
            $body    = $request->get_body();
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

            // The entry is looked up before the signature is checked because a form with
            // its own Kicbac account signs with its own key; nothing is written until the
            // signature passes.
            $entry       = $entry_id ? GFAPI::get_entry( $entry_id ) : null;
            $form        = is_array( $entry ) ? GFAPI::get_form( $entry['form_id'] ) : null;
            $signing_key = $this->get_credential( $form ?: null, 'webhook_signing_key' );

            if ( '' === $signing_key ) {
                return new WP_REST_Response( array( 'message' => 'Webhook handling is disabled.' ), 503 );
            }

            if ( ! $this->is_valid_webhook_signature( $request->get_header( 'webhook_signature' ), $body, $signing_key ) ) {
                $this->log_error( __METHOD__ . '(): rejected a webhook with a missing or invalid signature.' );

                return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
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

            // An entry with several subscriptions keeps its ID list intact.
            if ( $subscription_id && '' === $this->get_payment_meta( $entry_id, 'subscription_id' ) ) {
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

          $api_key = $this->get_credential( $form, 'api_key' );
          $formsetting = rgar( $form, 'gravityform-kicbac-payment' );

          if( !rgar( $formsetting, 'enabled' ) ) {
            // nothing to do if disabled.
            return;
          }

            $token     = $this->get_submitted_token();
            $params    = $this->build_gateway_params( $entry, $form, $api_key );
            $charges   = $this->get_charges( $form, $entry );
            $onetime   = $charges['onetime'];
            $schedules = $charges['recurring'];

            if ( ( $onetime > 0 ? 1 : 0 ) + count( $schedules ) > 1 ) {
              // A Collect.js token can only be charged once, so a donor giving more than
              // one gift is vaulted first and each gift is billed against the stored
              // customer.
              $vault_id = $this->process_customer_vault( $entry, $params );

              if ( $vault_id ) {
                $params = array(
                  'security_key'      => $api_key,
                  'customer_vault_id' => $vault_id,
                );
              } else {
                $onetime   = 0;
                $schedules = array();
              }
            } elseif ( 0 === $onetime && empty( $schedules ) ) {
              $this->process_customer_vault( $entry, $params );
            }

            if ( $onetime > 0 ) {
              $this->process_sale( $entry, $params, $onetime );
            }

            foreach ( $schedules as $schedule ) {
              $this->process_subscription( $entry, $params, $schedule );
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

        /**
         * What the entry owes, from the form's product mappings: one-time products summed
         * into a single sale, recurring products summed per billing schedule so products
         * that bill alike share one subscription.
         *
         * @return array { onetime: float, recurring: array of { amount, months, payments } }
         */
        public function get_charges( $form, $entry ) {
            $onetime   = 0;
            $recurring = array();

            foreach ( $this->get_product_mappings( $form ) as $row ) {
                $amount = $this->get_product_amount( $form, $entry, $row['field_id'] );

                if ( $amount <= 0 ) {
                    continue;
                }

                if ( 'onetime' === $row['billing'] ) {
                    $onetime += $amount;
                    continue;
                }

                $months = array( 'monthly' => 1, 'yearly' => 12 );
                $months = isset( $months[ $row['billing'] ] ) ? $months[ $row['billing'] ] : $row['months'];
                $key    = $months . ':' . $row['payments'];

                if ( ! isset( $recurring[ $key ] ) ) {
                    $recurring[ $key ] = array( 'amount' => 0, 'months' => $months, 'payments' => $row['payments'] );
                }

                $recurring[ $key ]['amount'] += $amount;
            }

            return array(
                'onetime'   => $onetime,
                'recurring' => array_values( $recurring ),
            );
        }

        /**
         * Total for one mapped field. A Product field is priced by GF, with its options
         * and quantity; any other field is read as an amount, so a Number, text,
         * hidden or choice field can drive the charge too.
         */
        public function get_product_amount( $form, $entry, $field_id ) {
            $field_id = absint( $field_id );

            $field    = $field_id ? GFAPI::get_field( $form, $field_id ) : false;

            if ( ! $field ) {
              return 0;
            }

            if ( 'product' !== $field->type ) {
              return $this->get_field_value_amount( $field, $entry );
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

        /** A non-product field's submitted value as a number; a checkbox sums its ticks. */
        public function get_field_value_amount( $field, $entry ) {
            $value = RGFormsModel::get_lead_field_value( $entry, $field );
            $total = 0;

            foreach ( (array) $value as $part ) {
              $total += (float) GFCommon::to_number( $part, rgar( $entry, 'currency' ) );
            }

            return max( 0, $total );
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

        /**
         * Starts one recurring gift.
         *
         * @param array $schedule { amount, months, payments } from get_charges().
         */
        public function process_subscription( $entry, $params, $schedule ) {
            $params['recurring']       = 'add_subscription';
            $params['plan_amount']     = number_format( $schedule['amount'], 2, '.', '' );
            $params['plan_payments']   = $schedule['payments']; // 0 bills until the donor cancels
            $params['month_frequency'] = $schedule['months'];
            // Clamped to 28 so a gift started on the 29th-31st still bills every cycle.
            $params['day_of_month']    = min( (int) current_time( 'j' ), 28 );
            $params['orderid']         = $entry['id'];

            $cycle    = $this->get_billing_cycle_label( $schedule['months'], $schedule['payments'] );
            $response = $this->post_to_gateway( 'transact.php', $params );

            if ( is_wp_error( $response ) ) {
              $this->add_subscription_meta( $entry['id'], array(
                'subscription_status' => 'Failed',
                'subscription_amount' => $params['plan_amount'],
                'billing_cycle'       => $cycle,
              ) );
              $this->update_payment_meta( $entry['id'], array( 'response_text' => $response->get_error_message() ) );
              $this->add_note( $entry['id'], 'Recurring gift failed: API submission error', 'error' );
              $this->log_error( __METHOD__ . '(): ' . $response->get_error_message() );
              return;
            }

            $ret    = gformmbn_kicbac_response_text_handler( wp_remote_retrieve_body( $response ) );
            $status = $this->get_response_status( $ret );

            $this->add_subscription_meta( $entry['id'], array(
              'subscription_status' => 'Approved' === $status ? 'Active' : $status,
              'subscription_id'     => rgar( $ret, 'subscription_id' ),
              'subscription_amount' => $params['plan_amount'],
              'billing_cycle'       => $cycle,
              'next_charge_date'    => 'Approved' === $status ? $this->calculate_next_charge_date( $params['day_of_month'], $schedule['months'] ) : '',
            ) );
            $this->update_payment_meta( $entry['id'], array( 'response_text' => rgar( $ret, 'responsetext' ) ) );

            if ( 'Approved' === $status ) {
              $this->add_note(
                $entry['id'],
                sprintf( 'Recurring gift started at %s, %s. Subscription ID %s', $params['plan_amount'], $cycle, rgar( $ret, 'subscription_id' ) ),
                'success'
              );
            } else {
              $this->add_note( $entry['id'], 'Recurring gift was not started: ' . rgar( $ret, 'responsetext' ), 'error' );
            }
        }

        public function get_billing_cycle_label( $months, $payments ) {
            if ( 1 === (int) $months ) {
                $label = 'Monthly';
            } elseif ( 12 === (int) $months ) {
                $label = 'Yearly';
            } else {
                $label = sprintf( 'Every %d months', $months );
            }

            return $payments ? sprintf( '%s (%d payments)', $label, $payments ) : $label;
        }

        /**
         * An entry can start more than one subscription, one per billing schedule, so
         * each subscription field holds a comma-separated list in subscription order.
         * Commas without spaces, so find_entry_by_meta() can match one ID with FIND_IN_SET.
         */
        public function add_subscription_meta( $entry_id, $data ) {
            foreach ( $data as $key => $value ) {
                $existing     = $this->get_payment_meta( $entry_id, $key );
                $data[ $key ] = '' === $existing ? $value : $existing . ',' . $value;
            }

            $this->update_payment_meta( $entry_id, $data );
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
         * A new subscription bills immediately, then on $day_of_month every $months months.
         * The webhook replaces this with the gateway's own figure once it reports one.
         */
        public function calculate_next_charge_date( $day_of_month, $months = 1 ) {
            $next_month = strtotime( sprintf( 'first day of +%d month', $months ), current_time( 'timestamp' ) );

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