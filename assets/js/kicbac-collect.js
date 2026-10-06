/**
 * Mounts Collect.js secure iframes onto the Gravity Forms fields mapped in the addon's
 * form settings, and holds the submit until a payment token comes back.
 *
 * Collect.js can only collect card/ACH data inside its own iframe, so each mapped field
 * gets an empty mount div inside GF's existing .ginput_container and the original input
 * is hidden rather than removed. Keeping GF's wrapper, label and CSS classes in place is
 * what preserves the theme's grid and styling; styleSniffer then copies the surrounding
 * input styles onto the iframe so the two match.
 *
 * Collect.js requires every field it was configured with to be valid before it issues a
 * token. On a form offering both card and ACH that would be impossible, so it is
 * configured with only the fields currently visible and reconfigured whenever
 * conditional logic changes which set that is.
 */
( function () {
	'use strict';

	// The form element the current mounts belong to; a GF AJAX submit replaces it.
	var initialisedForm = null;

	function init() {

	var config = window.gfmbnKicbacCollect;

	if ( ! config || typeof window.CollectJS === 'undefined' ) {
		return;
	}

	var MOUNT_CLASS   = 'gfmbn-kicbac-mount';
	var ERROR_CLASS   = 'gfmbn-kicbac-error';
	var LOADING_CLASS = 'gfmbn-kicbac-loading';

	// Copied from the input Collect.js replaces. Padding is left out: the iframe's own
	// input supplies the inset, and copying it too would indent the text twice.
	var BOX_STYLES = [
		'background-color',
		'background-image',
		'border-top',
		'border-right',
		'border-bottom',
		'border-left',
		'border-radius',
		'box-shadow'
	];

	var form   = document.getElementById( 'gform_' + config.formId );
	var button = document.getElementById( 'gform_submit_button_' + config.formId );

	if ( ! form || ! button ) {
		return;
	}

	initialisedForm = form;

	// GF fires gform_post_render on the initial render as well as after an AJAX submit,
	// so init() runs more than once against the same DOM. Clear anything a previous run
	// left behind, or a second set of mounts appears with duplicate ids and Collect.js
	// fills the first while this run tracks the second.
	form.querySelectorAll( '.' + MOUNT_CLASS ).forEach( function ( stale ) {
		stale.parentNode.removeChild( stale );
	} );

	form.querySelectorAll( '[data-gfmbn-kicbac-hidden]' ).forEach( function ( input ) {
		input.style.display = '';
		input.removeAttribute( 'data-gfmbn-kicbac-hidden' );
	} );

	var containers = {};
	var mounts     = {};
	var inputs     = {};

	Object.keys( config.fields ).forEach( function ( key ) {
		var container = document.querySelector( config.fields[ key ].container );

		if ( ! container ) {
			return;
		}

		containers[ key ] = container;

		var input = container.querySelector( 'input, select' );

		// GF's own placeholder wins over the addon's label so the form editor stays in control.
		config.fields[ key ].resolvedPlaceholder =
			( input && input.getAttribute( 'placeholder' ) ) || config.fields[ key ].title || '';

		var mount = document.createElement( 'div' );
		mount.className = MOUNT_CLASS;
		mount.id = MOUNT_CLASS + '-' + key;

		if ( input ) {
			// Collect.js renders into a transparent iframe, and its styleSniffer has
			// nothing to read once the real input is hidden. Carry the field's own box
			// styling onto the mount first so the secure input looks like its neighbours
			// whatever the theme does.
			var styles = window.getComputedStyle( input );

			BOX_STYLES.forEach( function ( property ) {
				mount.style.setProperty( property, styles.getPropertyValue( property ) );
			} );

			inputs[ key ] = input;

			// Measured while the input is still laid out, so the box reserves the right
			// height from the moment it replaces it rather than collapsing for a frame.
			mount.style.minHeight = input.getBoundingClientRect().height + 'px';

			input.style.display = 'none';
			input.setAttribute( 'data-gfmbn-kicbac-hidden', '1' );
		}

		container.appendChild( mount );

		mounts[ key ] = mount;
	} );

	if ( ! Object.keys( mounts ).length ) {
		return;
	}

	var tokenInput = form.querySelector( 'input[name="gfmbn_kicbac_token"]' );

	if ( ! tokenInput ) {
		tokenInput = document.createElement( 'input' );
		tokenInput.type = 'hidden';
		tokenInput.name = 'gfmbn_kicbac_token';
		form.appendChild( tokenInput );
	}

	var awaitingToken = false;

	// A payment request Collect.js could not fulfil stays pending and resolves later,
	// the moment the shopper fixes the last invalid field. Without this flag that late
	// token would submit the form out from under them.
	var submitOnToken = false;

	// Last validity Collect.js reported per field. Collect.js only reports on change, so
	// after a request comes back this is the only record of what is still wrong.
	var fieldValidity = {};

	var configuredKeys = '';

	// Bumped on every configure so a superseded mount-poll can recognise itself.
	var currentGeneration = 0;

	function setBusy( busy ) {
		awaitingToken = busy;
		button.disabled = busy;
		button.classList.toggle( 'gfmbn-kicbac-busy', busy );
	}

	function clearError( key ) {
		var container = containers[ key ];
		var existing  = container && container.parentNode.querySelector( '.' + ERROR_CLASS );

		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
	}

	function clearAllErrors() {
		Object.keys( containers ).forEach( clearError );
	}

	function showError( key, message ) {
		var container = containers[ key ];

		if ( ! container ) {
			return;
		}

		clearError( key );

		var error = document.createElement( 'div' );
		// GF's own validation class so the theme styles these like any other field error.
		error.className = ERROR_CLASS + ' gfield_validation_message gfield_description validation_message';
		error.textContent = message;
		container.parentNode.appendChild( error );
	}

	/** Visible fields Collect.js has reported as invalid and that are still unfixed. */
	function invalidKeys() {
		return visibleKeys().filter( function ( key ) {
			return fieldValidity[ key ] && ! fieldValidity[ key ].valid;
		} );
	}

	/** Mapped fields conditional logic is currently showing. */
	function visibleKeys() {
		// Only display matters here. The container's own height is no signal: it is empty
		// until Collect.js mounts, so requiring a height would deadlock.
		return Object.keys( mounts ).filter( function ( key ) {
			return containers[ key ].offsetParent !== null;
		} );
	}

	/**
	 * The height the field would have if Collect.js had not replaced it.
	 *
	 * getComputedStyle on the hidden original returns its specified height, not the used
	 * one, so it reads short. Restoring display for the measurement costs one reflow per
	 * configure and gives the real figure the surrounding inputs use.
	 */
	function measuredFieldHeight( key ) {
		var input = inputs[ key ];

		if ( ! input ) {
			return 0;
		}

		var previous = input.style.display;

		input.style.display = '';

		var height = input.getBoundingClientRect().height;

		input.style.display = previous;

		return height;
	}

	/**
	 * Collect.js sizes each iframe once, when it mounts. Anything measured while hidden
	 * keeps `height: 0` after it is revealed, leaving an invisible input.
	 */
	/**
	 * Collect.js sizes each iframe once, from whatever it could measure at configure
	 * time. Give every mounted field the height its own replaced input has, so the
	 * secure fields line up with the ordinary ones instead of approximating them.
	 */
	function normalizeMountHeights( keys ) {
		( keys || Object.keys( mounts ) ).forEach( function ( key ) {
			var iframe = mounts[ key ].querySelector( 'iframe' );
			var height = measuredFieldHeight( key );

			if ( ! height ) {
				return;
			}

			mounts[ key ].style.minHeight = height + 'px';

			if ( iframe ) {
				iframe.style.height = height + 'px';
			}
		} );
	}

	/**
	 * Switching method tears the iframes down and builds new ones. Without this the
	 * shopper sees empty unstyled boxes for a moment and reads them as broken.
	 */
	function setLoading( loading ) {
		Object.keys( mounts ).forEach( function ( key ) {
			mounts[ key ].classList.toggle( LOADING_CLASS, loading );

			// Reserve the field's real height up front so the box holds its place
			// through the swap instead of collapsing and springing back.
			if ( loading ) {
				var height = measuredFieldHeight( key );

				if ( height ) {
					mounts[ key ].style.minHeight = height + 'px';
				}
			}
		} );

		button.disabled = loading || awaitingToken;
	}

	/**
	 * Runs `done` once every mount has an iframe Collect.js has given a height.
	 *
	 * A switch fires both a change event and GF's conditional-logic event, so a second
	 * configure can supersede this one while it is still polling. `generation` lets the
	 * stale poll drop out instead of clearing a loading state it no longer owns.
	 */
	function whenMounted( keys, generation, done ) {
		var startedAt = Date.now();

		( function poll() {
			if ( generation !== currentGeneration ) {
				return;
			}

			var ready = keys.every( function ( key ) {
				var iframe = mounts[ key ].querySelector( 'iframe' );

				return iframe && parseFloat( iframe.style.height ) > 0;
			} );

			// Give up after a few seconds rather than leaving the form locked.
			if ( ready || Date.now() - startedAt > 8000 ) {
				done();
				return;
			}

			window.setTimeout( poll, 100 );
		} )();
	}

	function configureFor( keys ) {
		var generation = ++currentGeneration;
		var fields = {};

		// Reserve each field's real height before the iframes arrive, so the row never
		// collapses and Collect.js has a correctly sized box to render into.
		normalizeMountHeights( keys );

		keys.forEach( function ( key ) {
			// Reconfiguring appends a fresh iframe, so drop the previous one.
			mounts[ key ].innerHTML = '';

			fields[ key ] = {
				selector: '#' + mounts[ key ].id,
				title: config.fields[ key ].title,
				placeholder: config.fields[ key ].resolvedPlaceholder
			};
		} );

		Object.keys( mounts ).forEach( function ( key ) {
			if ( keys.indexOf( key ) === -1 ) {
				mounts[ key ].innerHTML = '';
			}
		} );

		window.CollectJS.configure( {
			variant: 'inline',
			styleSniffer: true,
			// Without a duration Collect.js never times out, so timeoutCallback below
			// could not fire and a request that goes quiet left the form locked.
			timeoutDuration: 10000,
			fields: fields,
			validationCallback: function ( field, valid, message ) {
				// After a card/ACH switch Collect.js still reports the fields of the set it
				// was configured with before, as empty. Counting those would cancel the
				// pending submit and strand the token of the set actually in use.
				if ( keys.indexOf( field ) === -1 ) {
					return;
				}

				fieldValidity[ field ] = { valid: valid, message: message };

				if ( valid ) {
					clearError( field );
					return;
				}

				showError( field, message );

				// Collect.js never fires the success callback when a field is invalid,
				// so the button has to be released here or the form locks up.
				if ( awaitingToken ) {
					setBusy( false );
					submitOnToken = false;
				}
			},
			timeoutCallback: function () {
				setBusy( false );
				submitOnToken = false;
				showError(
					visibleKeys()[ 0 ],
					'Payment details could not be verified. Please check your entries and try again.'
				);
			},
			callback: function ( response ) {
				tokenInput.value = response && response.token ? response.token : '';
				setBusy( false );

				if ( ! tokenInput.value ) {
					return;
				}

				clearAllErrors();

				if ( submitOnToken ) {
					submitOnToken = false;
					button.click();
				}
			}
		} );

		whenMounted( keys, generation, function () {
			normalizeMountHeights( keys );
			setLoading( false );
		} );
	}

	function syncFields() {
		var keys = visibleKeys();

		if ( ! keys.length || keys.join( ',' ) === configuredKeys ) {
			return;
		}

		// The visible set changed, so any token from the previous set no longer applies.
		tokenInput.value = '';
		clearAllErrors();
		fieldValidity = {};
		configuredKeys = keys.join( ',' );
		setLoading( true );
		configureFor( keys );
	}

	if ( window.jQuery ) {
		// Namespaced and cleared first: a GF re-render runs init() again, and the old
		// handler would still be pointing at the discarded DOM.
		jQuery( document )
			.off( 'gform_post_conditional_logic.gfmbnKicbac' )
			.on( 'gform_post_conditional_logic.gfmbnKicbac', syncFields );
	}

	form.addEventListener( 'change', syncFields );

	button.addEventListener(
		'click',
		function ( event ) {
			if ( tokenInput.value || awaitingToken ) {
				return;
			}

			// Hold GF's own submit handlers until Collect.js hands back a token.
			event.preventDefault();
			event.stopPropagation();

			// Collect.js re-runs validationCallback only when a field's contents change,
			// so clicking again on still-invalid fields produces no callback and no
			// token: the button would sit disabled with every error just wiped. Re-show
			// what is already known to be wrong and leave the button usable.
			var invalid = invalidKeys();

			if ( invalid.length ) {
				invalid.forEach( function ( key ) {
					showError( key, fieldValidity[ key ].message );
				} );

				return;
			}

			clearAllErrors();
			setBusy( true );
			submitOnToken = true;
			window.CollectJS.startPaymentRequest();
		},
		true
	);

	syncFields();

	}

	init();

	// Gravity Forms replaces the whole form element on an AJAX submit, so everything
	// mounted above is discarded when validation sends the form back. Re-run against
	// the fresh markup.
	if ( window.jQuery ) {
		jQuery( document ).on( 'gform_post_render', function ( event, formId ) {
			var config = window.gfmbnKicbacCollect;

			if ( ! config || parseInt( formId, 10 ) !== parseInt( config.formId, 10 ) ) {
				return;
			}

			// GF fires this on the first render too. Only the AJAX re-render replaces the
			// form element, and re-running against the same one would tear down working
			// iframes and make Collect.js build them a second time for nothing.
			if ( document.getElementById( 'gform_' + config.formId ) === initialisedForm ) {
				return;
			}

			init();
		} );
	}
} )();
