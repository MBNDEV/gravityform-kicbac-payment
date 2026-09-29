<?php

/**
 * Plugin Name: Gravity Forms Kicbac Payment
 * Plugin URI: https://github.com/MBNDEV/gravityform-kicbac-payment
 * Description: Kicbac Payment Gateway for Gravity Forms custom addon by MBNDev
 * Version: 1.0.2
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
define( 'GFORMMBN_KICBAC_COLLECTJS_URL', 'https://kicbac.transactiongateway.com/token/Collect.js' );

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

/**
 * Renders the running order total. The markup is only a placeholder: the amount is
 * recalculated in the browser from the form's product selections, so the shortcode
 * has no way to know the figure at render time.
 */
add_shortcode( 'kicbac-form-total', 'gformmbn_kicbac_total_shortcode' );
function gformmbn_kicbac_total_shortcode( $atts ) {
  $atts = shortcode_atts(
    array(
      'currency' => '$',
      'class'    => '',
    ),
    $atts,
    'kicbac-form-total'
  );

  return sprintf(
    '<span class="%s" data-kicbac-total data-kicbac-currency="%s">%s</span>',
    esc_attr( trim( 'kicbac-form-total ' . $atts['class'] ) ),
    esc_attr( $atts['currency'] ),
    esc_html( $atts['currency'] . '0.00' )
  );
}

// Form Addon
require_once plugin_dir_path( __FILE__ ) . 'form-addon.php';