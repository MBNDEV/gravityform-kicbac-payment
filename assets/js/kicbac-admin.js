/**
 * Repeatable product mapping rows on the form settings page. The rows are serialised
 * into one hidden input as JSON, which the settings framework decodes on save.
 */
( function () {
	'use strict';

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );

		if ( text !== undefined ) {
			node.textContent = text;
		}

		return node;
	}

	function select( choices, value ) {
		var node = el( 'select' );

		choices.forEach( function ( choice ) {
			var option = el( 'option', { value: choice.value }, choice.label );

			option.selected = String( choice.value ) === String( value );
			node.appendChild( option );
		} );

		return node;
	}

	function mount( root ) {
		var input    = root.querySelector( 'input[type="hidden"]' );
		var body     = root.querySelector( 'tbody' );
		var products = JSON.parse( root.dataset.products );
		var billing  = JSON.parse( root.dataset.billing );
		var labels   = JSON.parse( root.dataset.labels );
		var rows     = JSON.parse( input.value || '[]' );

		var billingChoices = Object.keys( billing ).map( function ( key ) {
			return { value: key, label: billing[ key ] };
		} );

		function fieldChoices( fieldId ) {
			var choices = [ { value: '', label: labels.select } ].concat( products.map( function ( product ) {
				return { value: product.id, label: product.label };
			} ) );

			// A mapped field since deleted from the form stays listed, so the admin sees it
			// and removes it rather than it vanishing on the next save.
			if ( fieldId && ! products.some( function ( product ) { return String( product.id ) === String( fieldId ); } ) ) {
				choices.push( { value: fieldId, label: labels.missing + ' (ID ' + fieldId + ')' } );
			}

			return choices;
		}

		function save() {
			input.value = JSON.stringify( rows );
		}

		function render() {
			body.textContent = '';

			rows.forEach( function ( row, index ) {
				var tr       = el( 'tr' );
				var field    = select( fieldChoices( row.field_id ), row.field_id );
				var type     = select( billingChoices, row.billing );
				type.style.width  = 'auto';
				field.style.width = '100%';
				var months   = el( 'input', { type: 'number', min: 1, max: 24, step: 1, value: row.months || 1, style: 'width:6em' } );
				var payments = el( 'input', { type: 'number', min: 0, step: 1, value: row.payments || 0, style: 'width:6em' } );
				var remove   = el( 'button', { type: 'button', 'class': 'button-link button-link-delete' }, labels.remove );

				function sync() {
					var recurring = 'onetime' !== type.value;

					months.disabled   = 'custom' !== type.value;
					payments.disabled = ! recurring;
				}

				field.addEventListener( 'change', function () { row.field_id = field.value; save(); } );
				type.addEventListener( 'change', function () { row.billing = type.value; sync(); save(); } );
				months.addEventListener( 'input', function () { row.months = months.value; save(); } );
				payments.addEventListener( 'input', function () { row.payments = payments.value; save(); } );
				remove.addEventListener( 'click', function () { rows.splice( index, 1 ); save(); render(); } );

				[ [ field ], [ type ], [ months ], [ payments ], [ remove ] ].forEach( function ( controls ) {
					var td = el( 'td' );
					controls.forEach( function ( control ) { td.appendChild( control ); td.appendChild( document.createTextNode( ' ' ) ); } );
					tr.appendChild( td );
				} );

				sync();
				body.appendChild( tr );
			} );
		}

		root.querySelector( '.gfmbn-kicbac-add' ).addEventListener( 'click', function () {
			rows.push( { field_id: '', billing: 'onetime', months: 1, payments: 0 } );
			save();
			render();
		} );

		render();
	}

	function init() {
		document.querySelectorAll( '.gfmbn-kicbac-products' ).forEach( mount );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
