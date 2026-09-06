/**
 * Assessment Form front-end behavior — pure Vanilla JS, no build step.
 * Handles: conditional field visibility, dynamic repeater rows (Education),
 * intl-tel-input initialization, client-side validation and Fetch-based
 * submission against the WP REST API.
 */
( function () {
	'use strict';

	if ( typeof MAF_CONFIG === 'undefined' ) {
		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.maf-form' ).forEach( initForm );
	} );

	/**
	 * Wires up a single form instance.
	 * @param {HTMLFormElement} form
	 */
	function initForm( form ) {
		initPhoneInputs( form );
		initConditionalLogic( form );
		initRepeaters( form );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( form );
		} );
	}

	/**
	 * Initializes intl-tel-input on every `.maf-tel-input` in the form.
	 * @param {HTMLFormElement} form
	 */
	function initPhoneInputs( form ) {
		if ( typeof window.intlTelInput === 'undefined' ) {
			return;
		}

		form.querySelectorAll( '.maf-tel-input' ).forEach( function ( input ) {
			var iti = window.intlTelInput( input, {
				initialCountry: 'auto',
				geoIpLookup: function ( callback ) {
					// Lightweight, no-key geo lookup with a safe fallback.
					fetch( 'https://ipapi.co/json' )
						.then( function ( res ) { return res.json(); } )
						.then( function ( data ) { callback( ( data && data.country_code ) ? data.country_code : 'us' ); } )
						.catch( function () { callback( 'us' ); } );
				},
				utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/js/utils.js',
			} );
			input.itiInstance = iti;
		} );
	}

	/**
	 * Shows/hides fields whose `data-condition` says "only show when
	 * [field] === [value]", and re-evaluates on every relevant change.
	 * @param {HTMLFormElement} form
	 */
	function initConditionalLogic( form ) {
		function evaluate() {
			form.querySelectorAll( '[data-condition]' ).forEach( function ( wrap ) {
				var condition = JSON.parse( wrap.getAttribute( 'data-condition' ) );
				var depField  = form.querySelector( '[name="' + condition.field + '"]' );
				var match     = depField && depField.value === condition.value;

				wrap.hidden = ! match;

				wrap.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
					if ( ! match ) {
						el.removeAttribute( 'required' );
						el.value = '';
					}
				} );
			} );
		}

		form.addEventListener( 'change', evaluate );
		evaluate();
	}

	/**
	 * Wires up "Add row" / "Remove row" behavior for every `.maf-repeater`
	 * section (e.g. Education & Training), cloning the hidden `<template>`.
	 * @param {HTMLFormElement} form
	 */
	function initRepeaters( form ) {
		form.querySelectorAll( '.maf-repeater' ).forEach( function ( repeater ) {
			var rowsContainer = repeater.querySelector( '.maf-repeater__rows' );
			var template      = repeater.querySelector( '.maf-repeater__template' );
			var addBtn        = repeater.querySelector( '.maf-repeater__add' );
			var index          = 0;

			function addRow() {
				var clone = template.content.cloneNode( true );
				clone.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( '__INDEX__', index );
				} );
				clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
					el.id = el.id + '-' + index;
				} );

				var rowEl = clone.querySelector( '.maf-repeater__row' );
				rowEl.querySelector( '.maf-repeater__remove' ).addEventListener( 'click', function () {
					rowEl.remove();
				} );

				rowsContainer.appendChild( clone );
				index++;
			}

			addBtn.addEventListener( 'click', addRow );

			// Start with a single empty row so the section isn't blank.
			addRow();
		} );
	}

	/**
	 * Runs minimal required-field / email validation client-side (server
	 * re-validates everything regardless — this only improves UX).
	 * @param {HTMLFormElement} form
	 * @return {boolean}
	 */
	function validate( form ) {
		var valid = true;

		form.querySelectorAll( '.maf-field' ).forEach( function ( field ) {
			if ( field.hidden ) {
				return;
			}
			var input   = field.querySelector( 'input, select, textarea' );
			var errorEl = field.querySelector( '.maf-field__error' );
			if ( ! input || ! errorEl ) {
				return;
			}
			errorEl.textContent = '';
			field.classList.remove( 'maf-field--error' );

			if ( input.hasAttribute( 'required' ) && ! input.value.trim() ) {
				errorEl.textContent = MAF_CONFIG.i18n.required;
				field.classList.add( 'maf-field--error' );
				valid = false;
				return;
			}

			if ( input.type === 'email' && input.value && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( input.value ) ) {
				errorEl.textContent = MAF_CONFIG.i18n.invalidEmail;
				field.classList.add( 'maf-field--error' );
				valid = false;
			}
		} );

		return valid;
	}

	/**
	 * Serializes the form (including repeater rows) into a plain object and
	 * POSTs it to the submit REST endpoint via Fetch.
	 * @param {HTMLFormElement} form
	 */
	function submitForm( form ) {
		if ( ! validate( form ) ) {
			return;
		}

		var statusEl = form.querySelector( '.maf-submit-status' );
		var submitBtn = form.querySelector( '.maf-submit-btn' );
		statusEl.textContent = MAF_CONFIG.i18n.submitting;
		submitBtn.disabled = true;

		var payload = formToObject( form );

		fetch( MAF_CONFIG.restUrl + 'submit?form_id=' + form.dataset.formId, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': MAF_CONFIG.nonce,
			},
			body: JSON.stringify( Object.assign( { form_id: form.dataset.formId }, payload ) ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } );
			} )
			.then( function ( result ) {
				submitBtn.disabled = false;
				if ( result.ok ) {
					statusEl.textContent = MAF_CONFIG.i18n.success;
					statusEl.classList.add( 'maf-success' );
					form.reset();
				} else {
					statusEl.textContent = result.data.message || MAF_CONFIG.i18n.error;
					statusEl.classList.add( 'maf-error' );
					showFieldErrors( form, result.data.fields || result.data.additional_errors || {} );
				}
			} )
			.catch( function () {
				submitBtn.disabled = false;
				statusEl.textContent = MAF_CONFIG.i18n.error;
				statusEl.classList.add( 'maf-error' );
			} );
	}

	/**
	 * Maps server-side validation errors (field key => message) onto the
	 * matching `.maf-field__error` elements.
	 * @param {HTMLFormElement} form
	 * @param {Object} fields
	 */
	function showFieldErrors( form, fields ) {
		Object.keys( fields ).forEach( function ( key ) {
			var input = form.querySelector( '[name="' + key + '"]' );
			if ( ! input ) {
				return;
			}
			var fieldWrap = input.closest( '.maf-field' );
			if ( fieldWrap ) {
				fieldWrap.classList.add( 'maf-field--error' );
				var errorEl = fieldWrap.querySelector( '.maf-field__error' );
				if ( errorEl ) {
					errorEl.textContent = fields[ key ];
				}
			}
		} );
	}

	/**
	 * Converts a form's fields (including bracketed repeater names like
	 * "education[0][institution]") into a nested plain JS object.
	 * @param {HTMLFormElement} form
	 * @return {Object}
	 */
	function formToObject( form ) {
		var result = {};
		var elements = form.querySelectorAll( 'input[name], select[name], textarea[name]' );

		elements.forEach( function ( el ) {
			if ( el.closest( '[hidden]' ) ) {
				return;
			}

			var name = el.name;
			var value = el.value;

			// Use the full international number when intl-tel-input is active.
			if ( el.itiInstance ) {
				value = el.itiInstance.getNumber();
			}

			var path = name.match( /[^\[\]]+/g ); // e.g. education[0][institution] -> ['education','0','institution']
			var cursor = result;

			for ( var i = 0; i < path.length; i++ ) {
				var key = path[ i ];
				var isLast = i === path.length - 1;
				var nextKeyIsIndex = ! isLast && /^\d+$/.test( path[ i + 1 ] );

				if ( isLast ) {
					cursor[ key ] = value;
				} else {
					if ( ! cursor[ key ] ) {
						cursor[ key ] = nextKeyIsIndex ? [] : {};
					}
					cursor = cursor[ key ];
				}
			}
		} );

		return result;
	}
} )();
