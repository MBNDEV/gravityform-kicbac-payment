<?php

/**
 * Plugin Name: Gravity Forms Kicbac Payment
 * Plugin URI: https://github.com/MBNDEV/gravityform-kicbac-payment
 * Description: Kicbac Payment Gateway for Gravity Forms custom addon by MBNDev
 * Version: 1.0.1
 * Author: MBNDev
 * Author URI: marketing@mybizniche.com
 * License: GPL-2.0+
 * Text Domain: gravityform-kicbac-payment
 *
 */

// Composer Libs
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

 use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
 PucFactory::buildUpdateChecker(
  'https://github.com/MBNDEV/gravityform-kicbac-payment',
  __FILE__,
  'gravityform-kicbac-payment'
);

define( 'GFORMMBN_KICBAC_API_BASE_URL', 'https://kicbac.transactiongateway.com/api' );

// kicback response handler xmk
function gformmbn_kicbac_response_xml_handler( $body ) {
  $xml  = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
  $json = json_encode($xml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $response = json_decode( $json, true );
  return $response;
}

// kicback exp date format
function gformmbn_kicbac_exp_date_format( $date ) {
  $dt = DateTime::createFromFormat('!m/Y', $date);
  if( $dt === false ) {
    $dt = new DateTime('now');
  }
  return $dt->format('my');
}


// kicback response handler text
function gformmbn_kicbac_response_text_handler( $body ) {
  // Converts a Kicbac API response string (e.g., "response=1&responsetext=Customer Added...") to an associative array
  parse_str($body, $response);
  return $response;
}

// secure field
function gformmbn_kicbac_secure_field( $value ) {
  $value = (string) $value;
  return str_repeat('*', strlen($value));
}

// Form Addon
require_once plugin_dir_path( __FILE__ ) . 'form-addon.php';