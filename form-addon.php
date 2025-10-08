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

        protected $_version                  = '1.0.0';
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
            return array();
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

          $fields[] = array(
            'type'    => 'checkbox',
            'name'    => 'enabled',
            'choices' => array(
                array(
                    'label' => esc_html__( 'Enable Kicbac Payment Gateway for this form', 'gravityform-kicbac-payment' ),
                    'name'  => 'enabled',
                ),
            ),
          );

          foreach( $this->get_kicbac_fields() as $key => $value ) {
            $fields[] = array(
              'label' => esc_html__( $value . " Field ID", 'gravityform-kicbac-payment' ),
              'type' => 'text',
              'name' => $key,
            );
          }

          return array(
              array(
                  'title'  => esc_html__( 'Kicbac Payment Gateway Data Mapping for Customers Vault', 'gravityform-kicbac-payment' ),
                  'description' => esc_html__( 'Map your form field IDs to Kicbac Payment Gateway fields, Leaving empty will be ignored.', 'gravityform-kicbac-payment' ),
                  'fields' => $fields,
              ),
          );
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


            $kicbac_params = array(
              'customer_vault' => 'add_customer',
              'security_key' => $api_key
            );
            foreach( $this->get_kicbac_fields() as $key => $value ) {
              $formsetting_id = rgar( $formsetting, $key );
              $kicbac_params[$key] = rgar( $entry, $formsetting_id );
            }

            if( isset( $kicbac_params['ccexp'] ) ) {
              // transform correct format ccexp to MMYY
              $kicbac_params['ccexp'] = gformmbn_kicbac_exp_date_format( $kicbac_params['ccexp'] );
            }

            // process to kicbac
            $response = wp_remote_post( GFORMMBN_KICBAC_API_BASE_URL . '/transact.php?' . http_build_query( $kicbac_params ));

            if ( is_wp_error( $response ) ) {
              $this->add_note( $entry['id'], 'Merchant has not been added to the vault: API Submission Error', 'error' );
              $this->log_error( __METHOD__ . '(): ' . $response->get_error_message() );
            } else {
              $this->log_debug( __METHOD__ . '(): Entry posted successfully.' );
              // validation if there's a customer's vault id
              $body = wp_remote_retrieve_body( $response );
              $ret = gformmbn_kicbac_response_text_handler( $body );
              if( isset( $ret['customer_vault_id'] ) ) {
                $this->add_note( $entry['id'], 'Merchant has been added to the vault: Customer Vault ID ' . $ret['customer_vault_id'], 'success');
              } else {
                $this->add_note( $entry['id'], 'Merchant has not been added to the vault: ' . $ret['responsetext'], 'error');
              }
            }

            // after processing to kicbac for security purposes, will remove and mask all confidential data
            foreach( $this->get_kicbac_secure_fields() as $key => $value ) {
              $formsetting_id = rgar( $formsetting, $value );
              if ( ! empty( $formsetting_id ) ) {
                $secure_value = gformmbn_kicbac_secure_field( rgar( $entry, $formsetting_id ) );
                GFAPI::update_entry_field( $entry['id'], $formsetting_id, $secure_value );
              }
            }
        }

        /** Optional: add form-level UI, validation, etc. */
        public function init() {
            parent::init();
            // Register the action that triggers feed processing
            add_action( 'gform_after_submission', array( $this, 'process_feed' ), 10, 2 );
        }
    }

    // Kick it off
    GF_Kicbac_AddOn::get_instance();
    GFAddOn::register( 'GF_Kicbac_AddOn' );
}