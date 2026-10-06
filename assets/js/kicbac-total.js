/**
 * Keeps every [kicbac-form-total] placeholder in step with the form's priced fields.
 *
 * Gravity Forms deprecated gformCalculateTotalPrice() with no public replacement, so the
 * running total is summed here from the priced fields and choice prices the addon
 * localizes off the form object.
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

	/**
	 * What a selected choice costs.
	 *
	 * The localized price map is consulted first: GF's own "label|price" input value is
	 * only there while GF renders the choice, and a theme that supplies its own markup
	 * for a choice field posts the bare value instead. The price half of the value is
	 * the fallback, for a choice added to the rendered form after it was localized.
	 */
	function choicePrice( field, value ) {
		if ( ! value ) {
			return 0;
		}

		if ( hasPrice( field, value ) ) {
			return toNumber( field.choices[ value ] );
		}

		if ( value.indexOf( '|' ) === -1 ) {
			return 0;
		}

		var label = value.slice( 0, value.lastIndexOf( '|' ) );

		return hasPrice( field, label )
			? toNumber( field.choices[ label ] )
			: toNumber( value.slice( value.lastIndexOf( '|' ) + 1 ) );
	}

	function hasPrice( field, value ) {
		return Object.prototype.hasOwnProperty.call( field.choices, value );
	}

	function wrapperFor( fieldId ) {
		return document.getElementById( 'field_' + config.formId + '_' + fieldId );
	}

	/**
	 * A field's own price inputs.
	 *
	 * Each product input type posts its price somewhere different: "input_<id>.2" for
	 * Single Product, Hidden Product and Calculation, plain "input_<id>" for a
	 * user-defined price or shipping, "input_<id>_other" for the figure typed into a
	 * choice field's "other" option. Matching them by name rather than by class or type
	 * is what covers all of them: Hidden Product and Calculation post theirs in a hidden
	 * input, and no two of them share a class. The product name (".1") and the quantity
	 * (".3") post alongside and are not amounts.
	 *
	 * GF disables the "other" input unless its choice is selected, which keeps it out.
	 */
	function amountInputs( wrapper, fieldId ) {
		var isAmount = new RegExp( '^input_' + fieldId + '(\\.2|_other)?$' );

		return [].filter.call(
			wrapper.querySelectorAll(
				'input[type="text"], input[type="number"], input[type="tel"], input[type="hidden"]'
			),
			function ( input ) {
				return ! input.disabled && isAmount.test( input.name );
			}
		);
	}

	/**
	 * How many of the field were ordered.
	 *
	 * A quantity multiplies the field's amount rather than joining it. It posts as
	 * "input_<id>.3" inside the field's own markup, unless the form carries a Quantity
	 * field for this product, which lives in its own markup elsewhere. Only a Product
	 * field has that input: on a checkbox field "input_<id>.3" is the third choice.
	 */
	function quantityFor( field ) {
		var input = 'product' === field.type && document.querySelector(
			'#field_' + config.formId + '_' + field.id + ' [name="input_' + field.id + '.3"]'
		);

		if ( ! input && field.quantity ) {
			input = document.querySelector(
				'#field_' + config.formId + '_' + field.quantity + ' [name="input_' + field.quantity + '"]'
			);
		}

		if ( ! input || input.disabled || '' === input.value ) {
			return 1;
		}

		return toNumber( input.value );
	}

	// Whether anything is chosen in a field. A field with no choices to make — a Single
	// Product, a Hidden Product, a price — always counts.
	function isSelected( fieldId ) {
		var wrapper = wrapperFor( fieldId );

		if ( ! wrapper ) {
			return false;
		}

		var choices = wrapper.querySelectorAll(
			'input[type="radio"], input[type="checkbox"], select'
		);

		return ! choices.length || [].some.call( choices, function ( input ) {
			return 'SELECT' === input.tagName ? '' !== input.value : input.checked;
		} );
	}

	function fieldTotal( field ) {
		var wrapper = wrapperFor( field.id );

		// An Option field costs nothing until its product is chosen.
		if ( ! wrapper || ( field.product && ! isSelected( field.product ) ) ) {
			return 0;
		}

		var total = 0;

		wrapper
			.querySelectorAll( 'input[type="radio"]:checked, input[type="checkbox"]:checked' )
			.forEach( function ( input ) {
				total += choicePrice( field, input.value );
			} );

		wrapper.querySelectorAll( 'select' ).forEach( function ( select ) {
			total += choicePrice( field, select.value );
		} );

		amountInputs( wrapper, field.id ).forEach( function ( input ) {
			total += toNumber( input.value );
		} );

		return total * quantityFor( field );
	}

	function render() {
		var total = 0;

		config.productFields.forEach( function ( field ) {
			total += fieldTotal( field );
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
