/**
 * Keeps every [kicbac-form-total] placeholder in step with the mapped product fields.
 *
 * Gravity Forms deprecated gformCalculateTotalPrice() with no public replacement, and
 * its own total covers every price field on the form. This sums only the one-time and
 * recurring fields the addon is configured to charge, which is what the donor is about
 * to be billed.
 */
( function () {
	'use strict';

	function init() {

	var config = window.gfmbnKicbacTotal;

	if ( ! config ) {
		return;
	}

	var form    = document.getElementById( 'gform_' + config.formId );
	var outputs = document.querySelectorAll( '[data-kicbac-total]' );

	if ( ! form || ! outputs.length ) {
		return;
	}

	function toNumber( raw ) {
		var number = parseFloat( String( raw ).replace( /[^0-9.\-]/g, '' ) );

		return isNaN( number ) ? 0 : number;
	}

	// GF stores a choice's price in the input value as "label|price".
	function priceFromChoice( value ) {
		if ( ! value || value.indexOf( '|' ) === -1 ) {
			return 0;
		}

		return toNumber( value.slice( value.lastIndexOf( '|' ) + 1 ) );
	}

	function fieldTotal( fieldId ) {
		var wrapper = document.getElementById( 'field_' + config.formId + '_' + fieldId );

		if ( ! wrapper ) {
			return 0;
		}

		var total = 0;

		wrapper
			.querySelectorAll( 'input[type="radio"]:checked, input[type="checkbox"]:checked' )
			.forEach( function ( input ) {
				total += priceFromChoice( input.value );
			} );

		wrapper.querySelectorAll( 'select' ).forEach( function ( select ) {
			total += priceFromChoice( select.value );
		} );

		// The "Enter an amount" input carries a raw figure instead of a choice value.
		// GF disables it unless its own choice is selected, which keeps it out of the sum.
		wrapper
			.querySelectorAll( 'input[type="text"], input[type="number"], input[type="tel"]' )
			.forEach( function ( input ) {
				if ( ! input.disabled ) {
					total += toNumber( input.value );
				}
			} );

		return total;
	}

	function render() {
		var total = 0;

		config.productFields.forEach( function ( fieldId ) {
			total += fieldTotal( fieldId );
		} );

		outputs.forEach( function ( node ) {
			var currency = node.getAttribute( 'data-kicbac-currency' ) || '';

			node.textContent =
				currency +
				total.toLocaleString( undefined, {
					minimumFractionDigits: 2,
					maximumFractionDigits: 2
				} );
		} );
	}

	document.addEventListener( 'gform/products/product_field_changed', render );

	// Covers typing into the user-defined amount and any field GF's product module
	// does not announce, such as a choice shown by conditional logic.
	form.addEventListener( 'change', render );
	form.addEventListener( 'input', render );

	if ( window.jQuery ) {
		// Namespaced and cleared first: a GF re-render runs init() again, and the old
		// handler would still be pointing at the discarded DOM.
		jQuery( document )
			.off( 'gform_post_conditional_logic.gfmbnKicbacTotal' )
			.on( 'gform_post_conditional_logic.gfmbnKicbacTotal', render );
	}

	render();

	}

	init();

	// Gravity Forms replaces the form element on an AJAX submit, so the outputs and
	// listeners bound above are discarded when validation sends the form back.
	if ( window.jQuery ) {
		jQuery( document ).on( 'gform_post_render', function ( event, formId ) {
			var config = window.gfmbnKicbacTotal;

			if ( config && parseInt( formId, 10 ) === parseInt( config.formId, 10 ) ) {
				init();
			}
		} );
	}
} )();
