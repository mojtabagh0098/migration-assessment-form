/**
 * Assessment Form front-end behavior — pure Vanilla JS, no build step.
 * Handles: conditional field visibility (with operators), dynamic repeater
 * rows (min/max), intl-tel-input initialization, drag & drop file uploads
 * with client-side validation, and Fetch-based multipart submission against
 * the WP REST API.
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
		initRepeaters( form );
		initUploads( form );
		initConditionalLogic( form );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( form );
		} );
	}

	/**
	 * Initializes intl-tel-input on every `.maf-tel-input` in the form.
	 * @param {HTMLElement} scope
	 */
	function initPhoneInputs( scope ) {
		if ( typeof window.intlTelInput === 'undefined' ) {
			return;
		}

		scope.querySelectorAll( '.maf-tel-input' ).forEach( function ( input ) {
			if ( input.itiInstance ) {
				return;
			}
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

	/* ------------------------------------------------------------------ */
	/* Conditional logic                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Reads the current value of a field by key, scoped to the closest
	 * repeater row when applicable (so conditions inside repeaters work per row).
	 * @param {HTMLElement} wrap  The dependent field wrapper.
	 * @param {string} key
	 * @return {string|Array}
	 */
	function readValue( wrap, key ) {
		var row   = wrap.closest( '.maf-repeater__row' );
		var scope = row || wrap.closest( '.maf-form' );
		var dep   = scope.querySelector( '.maf-field[data-key="' + key + '"]' );
		if ( ! dep ) {
			return '';
		}
		var checks = dep.querySelectorAll( 'input[type="checkbox"], input[type="radio"]' );
		if ( checks.length ) {
			var vals = [];
			checks.forEach( function ( c ) { if ( c.checked ) { vals.push( c.value ); } } );
			return checks.length === 1 && checks[0].type === 'checkbox' ? ( vals.length ? '1' : '' ) : ( checks[0].type === 'radio' ? ( vals[0] || '' ) : vals );
		}
		var input = dep.querySelector( 'input, select, textarea' );
		return input ? input.value : '';
	}

	function conditionMatches( cond, actual ) {
		var op       = cond.operator || 'equals';
		var expected = String( cond.value === undefined ? '' : cond.value );
		if ( Array.isArray( actual ) ) {
			switch ( op ) {
				case 'not_empty': return actual.length > 0;
				case 'empty':     return actual.length === 0;
				case 'not_equals': return actual.indexOf( expected ) === -1;
				default:          return actual.indexOf( expected ) !== -1;
			}
		}
		actual = String( actual );
		switch ( op ) {
			case 'not_equals': return actual !== expected;
			case 'not_empty':  return actual.trim() !== '';
			case 'empty':      return actual.trim() === '';
			case 'contains':   return expected !== '' && actual.toLowerCase().indexOf( expected.toLowerCase() ) !== -1;
			default:           return actual === expected;
		}
	}

	/**
	 * Shows/hides fields whose `data-condition` rule is (not) met, and
	 * re-evaluates on every change in the form.
	 * @param {HTMLFormElement} form
	 */
	function initConditionalLogic( form ) {
		function evaluate() {
			form.querySelectorAll( '[data-condition]' ).forEach( function ( wrap ) {
				var condition = JSON.parse( wrap.getAttribute( 'data-condition' ) );
				var match     = conditionMatches( condition, readValue( wrap, condition.field ) );
				var wasHidden = wrap.hidden;
				wrap.hidden   = ! match;

				wrap.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
					if ( ! match ) {
						if ( el.hasAttribute( 'required' ) ) {
							el.dataset.wasRequired = '1';
							el.removeAttribute( 'required' );
						}
						if ( el.type === 'checkbox' || el.type === 'radio' ) {
							el.checked = false;
						} else if ( el.type !== 'file' ) {
							el.value = '';
						}
					} else if ( wasHidden && el.dataset.wasRequired ) {
						el.setAttribute( 'required', '' );
					}
				} );
			} );
		}

		form.addEventListener( 'change', evaluate );
		form.addEventListener( 'input', evaluate );
		form.mafEvaluateConditions = evaluate;
		evaluate();
	}

	/* ------------------------------------------------------------------ */
	/* Repeaters                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Wires up "Add row" / "Remove row" behavior for every `.maf-repeater`
	 * section, cloning the hidden `<template>`, honouring min/max rows.
	 * @param {HTMLFormElement} form
	 */
	function initRepeaters( form ) {
		form.querySelectorAll( '.maf-repeater' ).forEach( function ( repeater ) {
			var rowsContainer = repeater.querySelector( '.maf-repeater__rows' );
			var template      = repeater.querySelector( '.maf-repeater__template' );
			var addBtn        = repeater.querySelector( '.maf-repeater__add' );
			var minRows       = parseInt( repeater.dataset.minRows || '1', 10 );
			var maxRows       = parseInt( repeater.dataset.maxRows || '0', 10 );
			var index         = 0;

			function count() {
				return rowsContainer.querySelectorAll( ':scope > .maf-repeater__row' ).length;
			}

			function refresh() {
				var n = count();
				addBtn.disabled = maxRows > 0 && n >= maxRows;
				rowsContainer.querySelectorAll( ':scope > .maf-repeater__row' ).forEach( function ( row, i ) {
					row.querySelector( '.maf-repeater__remove' ).disabled = n <= minRows;
					var num = row.querySelector( '.maf-repeater__num' );
					if ( num ) {
						num.textContent = i + 1;
					}
				} );
			}

			function addRow() {
				if ( maxRows > 0 && count() >= maxRows ) {
					return;
				}
				var clone = template.content.cloneNode( true );
				clone.querySelectorAll( '[name]' ).forEach( function ( el ) {
					el.name = el.name.replace( '__INDEX__', index );
				} );
				clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
					var newId = el.id + '-' + index;
					var label = clone.querySelector( 'label[for="' + el.id + '"]' );
					if ( label ) {
						label.setAttribute( 'for', newId );
					}
					el.id = newId;
				} );

				var rowEl = clone.querySelector( '.maf-repeater__row' );
				rowEl.querySelector( '.maf-repeater__remove' ).addEventListener( 'click', function () {
					if ( count() <= minRows ) {
						return;
					}
					rowEl.classList.add( 'is-removing' );
					setTimeout( function () {
						rowEl.remove();
						refresh();
					}, 150 );
				} );

				rowsContainer.appendChild( clone );
				initPhoneInputs( rowEl );
				initUploads( rowEl );
				if ( form.mafEvaluateConditions ) {
					form.mafEvaluateConditions();
				}
				index++;
				refresh();
			}

			addBtn.addEventListener( 'click', addRow );

			for ( var i = 0; i < Math.max( 1, minRows ); i++ ) {
				addRow();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* File uploads                                                        */
	/* ------------------------------------------------------------------ */

	function formatSize( bytes ) {
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1048576 ) {
			return ( bytes / 1024 ).toFixed( 0 ) + ' KB';
		}
		return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
	}

	/**
	 * Drag & drop zone + client-side validation (type/size/count) for each
	 * `.maf-upload`. Selected files are kept on `input.mafFiles` so users can
	 * remove individual files (a native FileList is read-only).
	 * @param {HTMLElement} scope
	 */
	function initUploads( scope ) {
		scope.querySelectorAll( '.maf-upload' ).forEach( function ( box ) {
			var input = box.querySelector( 'input[type="file"]' );
			if ( ! input || input.mafFiles ) {
				return;
			}
			var zone     = box.querySelector( '.maf-upload__zone' );
			var list     = box.querySelector( '.maf-upload__list' );
			var maxSize  = parseInt( box.dataset.maxSize || '5', 10 ) * 1048576;
			var maxFiles = input.multiple ? parseInt( box.dataset.maxFiles || '1', 10 ) : 1;
			var accept   = ( box.dataset.accept || '' ).toLowerCase().split( ',' ).filter( Boolean );
			var field    = box.closest( '.maf-field' );
			var errorEl  = field ? field.querySelector( '.maf-field__error' ) : null;

			input.mafFiles = [];

			function setError( msg ) {
				if ( errorEl ) {
					errorEl.textContent = msg || '';
				}
				if ( field ) {
					field.classList.toggle( 'maf-field--error', !! msg );
				}
			}

			function render() {
				list.innerHTML = '';
				input.mafFiles.forEach( function ( file, i ) {
					var li = document.createElement( 'li' );
					li.className = 'maf-upload__item';
					li.innerHTML = '<span class="maf-upload__name"></span><span class="maf-upload__size"></span><button type="button" class="maf-upload__remove" aria-label="' + MAF_CONFIG.i18n.removeRow + '">&times;</button>';
					li.querySelector( '.maf-upload__name' ).textContent = file.name;
					li.querySelector( '.maf-upload__size' ).textContent = formatSize( file.size );
					li.querySelector( '.maf-upload__remove' ).addEventListener( 'click', function () {
						input.mafFiles.splice( i, 1 );
						render();
					} );
					list.appendChild( li );
				} );
				box.classList.toggle( 'has-files', input.mafFiles.length > 0 );
			}

			function addFiles( files ) {
				setError( '' );
				Array.prototype.forEach.call( files, function ( file ) {
					var ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
					if ( accept.length && accept.indexOf( ext ) === -1 ) {
						setError( MAF_CONFIG.i18n.fileType );
						return;
					}
					if ( file.size > maxSize ) {
						setError( MAF_CONFIG.i18n.fileTooLarge.replace( '%s', box.dataset.maxSize ) );
						return;
					}
					if ( ! input.multiple ) {
						input.mafFiles = [ file ];
						return;
					}
					if ( input.mafFiles.length >= maxFiles ) {
						setError( MAF_CONFIG.i18n.tooManyFiles.replace( '%s', maxFiles ) );
						return;
					}
					input.mafFiles.push( file );
				} );
				render();
			}

			input.addEventListener( 'change', function () {
				addFiles( input.files );
				input.value = ''; // Allow re-selecting the same file.
			} );

			zone.addEventListener( 'click', function () { input.click(); } );
			zone.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					input.click();
				}
			} );
			zone.setAttribute( 'tabindex', '0' );
			zone.setAttribute( 'role', 'button' );

			[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
				zone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					zone.classList.add( 'is-over' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( evt ) {
				zone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					zone.classList.remove( 'is-over' );
				} );
			} );
			zone.addEventListener( 'drop', function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.files ) {
					addFiles( e.dataTransfer.files );
				}
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Validation                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Runs required-field / email / url validation client-side (server
	 * re-validates everything regardless — this only improves UX).
	 * @param {HTMLFormElement} form
	 * @return {boolean}
	 */
	function validate( form ) {
		var valid = true;
		var firstInvalid = null;

		form.querySelectorAll( '.maf-field' ).forEach( function ( field ) {
			var errorEl = field.querySelector( '.maf-field__error' );
			if ( ! errorEl ) {
				return;
			}
			errorEl.textContent = '';
			field.classList.remove( 'maf-field--error' );

			if ( field.hidden || field.closest( '[hidden]' ) ) {
				return;
			}

			var fail = function ( msg ) {
				errorEl.textContent = msg;
				field.classList.add( 'maf-field--error' );
				valid = false;
				firstInvalid = firstInvalid || field;
			};

			var group = field.querySelector( '.maf-choices' );
			if ( group ) {
				if ( group.dataset.groupRequired === '1' && ! group.querySelector( 'input:checked' ) ) {
					fail( MAF_CONFIG.i18n.required );
				}
				return;
			}

			var input = field.querySelector( 'input, select, textarea' );
			if ( ! input ) {
				return;
			}

			if ( input.type === 'file' ) {
				if ( input.hasAttribute( 'required' ) && ! ( input.mafFiles && input.mafFiles.length ) ) {
					fail( MAF_CONFIG.i18n.required );
				}
				return;
			}

			if ( input.type === 'checkbox' ) {
				if ( input.hasAttribute( 'required' ) && ! input.checked ) {
					fail( MAF_CONFIG.i18n.required );
				}
				return;
			}

			if ( input.hasAttribute( 'required' ) && ! input.value.trim() ) {
				fail( MAF_CONFIG.i18n.required );
				return;
			}

			if ( input.type === 'email' && input.value && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( input.value ) ) {
				fail( MAF_CONFIG.i18n.invalidEmail );
			}
			if ( input.type === 'url' && input.value && ! /^https?:\/\/.+/i.test( input.value ) ) {
				fail( MAF_CONFIG.i18n.invalidUrl );
			}
			if ( input.itiInstance && input.value && typeof input.itiInstance.isValidNumber === 'function' && ! input.itiInstance.isValidNumber() ) {
				fail( MAF_CONFIG.i18n.invalidPhone );
			}
		} );

		if ( firstInvalid ) {
			firstInvalid.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		return valid;
	}

	/* ------------------------------------------------------------------ */
	/* Submission                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Serializes the form (including repeater rows and files) and POSTs it
	 * as multipart/form-data: `payload` = JSON of all scalar values, files
	 * are appended as `file_N` with a `files` JSON map describing where
	 * each belongs in the payload.
	 * @param {HTMLFormElement} form
	 */
	function submitForm( form ) {
		if ( ! validate( form ) ) {
			return;
		}

		var statusEl  = form.querySelector( '.maf-submit-status' );
		var submitBtn = form.querySelector( '.maf-submit-btn' );
		statusEl.textContent = MAF_CONFIG.i18n.submitting;
		statusEl.className   = 'maf-submit-status';
		submitBtn.disabled   = true;
		form.classList.add( 'is-submitting' );

		var payload = formToObject( form );
		var fd      = new FormData();
		var fileMap = [];
		var n       = 0;

		form.querySelectorAll( 'input[type="file"][name]' ).forEach( function ( input ) {
			if ( input.closest( '[hidden]' ) || ! input.mafFiles ) {
				return;
			}
			input.mafFiles.forEach( function ( file ) {
				var id = 'file_' + ( n++ );
				fd.append( id, file, file.name );
				fileMap.push( { id: id, path: input.name.match( /[^\[\]]+/g ), multiple: input.multiple } );
			} );
		} );

		fd.append( 'form_id', form.dataset.formId );
		fd.append( 'payload', JSON.stringify( payload ) );
		fd.append( 'files', JSON.stringify( fileMap ) );

		fetch( MAF_CONFIG.restUrl + 'submit?form_id=' + form.dataset.formId, {
			method: 'POST',
			headers: { 'X-WP-Nonce': MAF_CONFIG.nonce },
			body: fd,
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } );
			} )
			.then( function ( result ) {
				submitBtn.disabled = false;
				form.classList.remove( 'is-submitting' );
				if ( result.ok ) {
					statusEl.textContent = form.dataset.successMessage || MAF_CONFIG.i18n.success;
					statusEl.classList.add( 'maf-success' );
					form.reset();
					form.querySelectorAll( 'input[type="file"]' ).forEach( function ( input ) {
						input.mafFiles = [];
						var list = input.closest( '.maf-upload' ).querySelector( '.maf-upload__list' );
						list.innerHTML = '';
						input.closest( '.maf-upload' ).classList.remove( 'has-files' );
					} );
					if ( form.mafEvaluateConditions ) {
						form.mafEvaluateConditions();
					}
				} else {
					statusEl.textContent = result.data.message || MAF_CONFIG.i18n.error;
					statusEl.classList.add( 'maf-error' );
					showFieldErrors( form, ( result.data.data && result.data.data.fields ) || result.data.fields || {} );
				}
			} )
			.catch( function () {
				submitBtn.disabled = false;
				form.classList.remove( 'is-submitting' );
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
		var first = null;
		Object.keys( fields ).forEach( function ( key ) {
			var fieldWrap = form.querySelector( '.maf-field[data-key="' + key + '"]' ) ||
				( form.querySelector( '[name="' + key + '"]' ) || {} ).closest && form.querySelector( '[name="' + key + '"]' ).closest( '.maf-field' );
			if ( fieldWrap ) {
				fieldWrap.classList.add( 'maf-field--error' );
				var errorEl = fieldWrap.querySelector( '.maf-field__error' );
				if ( errorEl ) {
					errorEl.textContent = fields[ key ];
				}
				first = first || fieldWrap;
			}
		} );
		if ( first ) {
			first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	/**
	 * Converts a form's fields (including bracketed repeater names like
	 * "education[0][institution]" and checkbox groups "skills[]") into a
	 * nested plain JS object. File inputs are skipped (sent separately).
	 * @param {HTMLFormElement} form
	 * @return {Object}
	 */
	function formToObject( form ) {
		var result = {};
		var elements = form.querySelectorAll( 'input[name], select[name], textarea[name]' );

		elements.forEach( function ( el ) {
			if ( el.closest( '[hidden]' ) || el.type === 'file' ) {
				return;
			}
			if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
				// Still register empty checkbox groups so keys exist.
				if ( el.type === 'checkbox' && /\[\]$/.test( el.name ) ) {
					setPath( result, el.name.replace( /\[\]$/, '' ).match( /[^\[\]]+/g ), undefined, true );
				}
				return;
			}

			var value = el.value;
			if ( el.itiInstance ) {
				value = el.itiInstance.getNumber() || value;
			}

			var isArray = /\[\]$/.test( el.name );
			var path    = el.name.replace( /\[\]$/, '' ).match( /[^\[\]]+/g );
			setPath( result, path, value, isArray );
		} );

		return result;
	}

	function setPath( obj, path, value, isArray ) {
		var cursor = obj;
		for ( var i = 0; i < path.length; i++ ) {
			var key    = path[ i ];
			var isLast = i === path.length - 1;
			if ( isLast ) {
				if ( isArray ) {
					if ( ! Array.isArray( cursor[ key ] ) ) {
						cursor[ key ] = [];
					}
					if ( value !== undefined ) {
						cursor[ key ].push( value );
					}
				} else {
					cursor[ key ] = value;
				}
			} else {
				var nextIsIndex = /^\d+$/.test( path[ i + 1 ] );
				if ( ! cursor[ key ] || typeof cursor[ key ] !== 'object' ) {
					cursor[ key ] = nextIsIndex ? [] : {};
				}
				cursor = cursor[ key ];
			}
		}
	}
} )();
