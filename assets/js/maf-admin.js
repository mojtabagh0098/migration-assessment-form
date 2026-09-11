/**
 * Assessment Entries admin screen — pure Vanilla JS + Fetch API against the
 * plugin's REST endpoints. No framework, no build step.
 */
( function () {
	'use strict';

	if ( typeof MAF_ADMIN_CONFIG === 'undefined' ) {
		return;
	}

	var state = { page: 1, perPage: 20 };

	document.addEventListener( 'DOMContentLoaded', function () {
		populateFormFilter();
		populateLanguageFilter();
		wireToolbar();
		wireModal();
		fetchEntries();
	} );

	/** Fills the "form" filter <select> from the localized forms list. */
	function populateFormFilter() {
		var select = document.getElementById( 'maf-filter-form' );
		Object.keys( MAF_ADMIN_CONFIG.forms || {} ).forEach( function ( id ) {
			var opt = document.createElement( 'option' );
			opt.value = id;
			opt.textContent = MAF_ADMIN_CONFIG.forms[ id ];
			select.appendChild( opt );
		} );
	}

	/** Fills the "language" filter <select> from WPML's active languages, defaulting to the admin-bar language. */
	function populateLanguageFilter() {
		var select = document.getElementById( 'maf-filter-language' );
		Object.keys( MAF_ADMIN_CONFIG.languages || {} ).forEach( function ( code ) {
			var opt = document.createElement( 'option' );
			opt.value = code;
			opt.textContent = MAF_ADMIN_CONFIG.languages[ code ];
			select.appendChild( opt );
		} );

		if ( MAF_ADMIN_CONFIG.activeLang ) {
			select.value = MAF_ADMIN_CONFIG.activeLang;
		}
	}

	/** Wires the filter/export toolbar controls. */
	function wireToolbar() {
		document.getElementById( 'maf-apply-filters' ).addEventListener( 'click', function () {
			state.page = 1;
			fetchEntries();
		} );

		[ 'maf-filter-search', 'maf-filter-country' ].forEach( function ( id ) {
			document.getElementById( id ).addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					state.page = 1;
					fetchEntries();
				}
			} );
		} );

		updateExportLinks();
		[ 'maf-filter-form', 'maf-filter-language', 'maf-filter-status', 'maf-filter-country', 'maf-filter-search' ].forEach( function ( id ) {
			document.getElementById( id ).addEventListener( 'change', updateExportLinks );
		} );

		var addBtn = document.getElementById( 'maf-add-filter-row' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				addFilterRow();
			} );
		}

		var applyAdvBtn = document.getElementById( 'maf-apply-advanced-filters' );
		if ( applyAdvBtn ) {
			applyAdvBtn.addEventListener( 'click', function () {
				state.page = 1;
				fetchEntries();
			} );
		}

		var resetBtn = document.getElementById( 'maf-reset-filters' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				document.getElementById( 'maf-filter-form' ).value = '';
				document.getElementById( 'maf-filter-language' ).value = MAF_ADMIN_CONFIG.activeLang || '';
				document.getElementById( 'maf-filter-status' ).value = '';
				document.getElementById( 'maf-filter-country' ).value = '';
				document.getElementById( 'maf-filter-search' ).value = '';
				document.getElementById( 'maf-filter-rows-container' ).innerHTML = '';
				state.page = 1;
				fetchEntries();
			} );
		}
	}


	function addFilterRow( preselectedField, preselectedOp, preselectedVal ) {
		var container = document.getElementById( 'maf-filter-rows-container' );
		var row = document.createElement( 'div' );
		row.className = 'maf-filter-row';

		var fieldSelect = document.createElement( 'select' );
		fieldSelect.className = 'maf-filter-field';
		var formId = document.getElementById( 'maf-filter-form' ).value;
		populateFieldSelect( fieldSelect, formId );
		if ( preselectedField ) {
			fieldSelect.value = preselectedField;
		}

		var opSelect = document.createElement( 'select' );
		opSelect.className = 'maf-filter-op';

		var valContainer = document.createElement( 'div' );
		valContainer.className = 'maf-filter-val-container';
		valContainer.style.display = 'inline-block';

		var removeBtn = document.createElement( 'button' );
		removeBtn.type = 'button';
		removeBtn.className = 'button';
		removeBtn.textContent = '×';
		removeBtn.addEventListener( 'click', function () {
			row.remove();
			updateExportLinks();
		} );

		fieldSelect.addEventListener( 'change', function () {
			updateOperators( row );
			updateExportLinks();
		} );

		opSelect.addEventListener( 'change', function () {
			updateValueInput( row );
			updateExportLinks();
		} );

		row.appendChild( fieldSelect );
		row.appendChild( opSelect );
		row.appendChild( valContainer );
		row.appendChild( removeBtn );

		container.appendChild( row );

		updateOperators( row, preselectedOp );
		if ( preselectedVal !== undefined ) {
			setValueInput( row, preselectedVal );
		}
		updateExportLinks();
	}


	/** Rebuilds the CSV/PDF export links so they carry the current filters. */
	function updateExportLinks() {
		var qs = buildFilterQuery();
		document.getElementById( 'maf-export-csv' ).href = MAF_ADMIN_CONFIG.exportCsvUrl + '&' + qs;
		document.getElementById( 'maf-export-pdf' ).href = MAF_ADMIN_CONFIG.exportPdfUrl + '&' + qs;
	}

	/** @return {string} URL-encoded query string built from the visible filter controls. */
	function populateFieldSelect( selectEl, formId ) {
		selectEl.innerHTML = '<option value="">— Select field —</option>';
		var schemas = MAF_ADMIN_CONFIG.schemas || {};
		var fieldsMap = {};

		if ( formId && schemas[ formId ] ) {
			fieldsMap = schemas[ formId ];
		} else {
			Object.keys( schemas ).forEach( function ( fId ) {
				var fMap = schemas[ fId ];
				Object.keys( fMap ).forEach( function ( key ) {
					if ( ! fieldsMap[ key ] ) {
						fieldsMap[ key ] = fMap[ key ];
					}
				} );
			} );
		}

		var generalFields = {
			'first_name': { label: 'First Name', type: 'text' },
			'last_name': { label: 'Last Name', type: 'text' },
			'email': { label: 'Email', type: 'email' },
			'phone': { label: 'Phone', type: 'tel_intl' },
			'age': { label: 'Age', type: 'number' },
			'country_residence': { label: 'Country of Residence', type: 'country' },
			'country_citizenship': { label: 'Country of Citizenship', type: 'country' },
			'marital_status': { label: 'Marital Status', type: 'select' },
			'net_worth_cad': { label: 'Net Worth (CAD)', type: 'number' },
			'status': { label: 'Status', type: 'select', options: { 'submitted': 'Submitted', 'conditional': 'Conditional', 'approved': 'Approved', 'rejected': 'Rejected' } },
			'language': { label: 'Language', type: 'select', options: MAF_ADMIN_CONFIG.languages || {} },
			'assigned_by': { label: 'Assigned By', type: 'select', options: MAF_ADMIN_CONFIG.users || {} },
			'assigned_to': { label: 'Assigned To', type: 'select', options: MAF_ADMIN_CONFIG.users || {} }
		};

		var combined = Object.assign( {}, generalFields, fieldsMap );

		Object.keys( combined ).forEach( function ( key ) {
			var f = combined[ key ];
			if ( f.type === 'file' || f.type === 'html' ) {
				return;
			}
			var opt = document.createElement( 'option' );
			opt.value = key;
			opt.textContent = f.label || key;
			selectEl.appendChild( opt );
		} );
	}

	function buildFilterQuery() {
		var params = new URLSearchParams();
		var map = {
			'maf-filter-form': 'form_id',
			'maf-filter-language': 'language',
			'maf-filter-status': 'status',
			'maf-filter-country': 'country_residence',
			'maf-filter-search': 'search',
		};
		Object.keys( map ).forEach( function ( id ) {
			var val = document.getElementById( id ).value;
			if ( val ) {
				params.set( map[ id ], val );
			}
		} );

		var rows = document.querySelectorAll( '.maf-filter-row' );
		rows.forEach( function ( row, index ) {
			var field = row.querySelector( '.maf-filter-field' ).value;
			var op = row.querySelector( '.maf-filter-op' ).value;
			if ( ! field || ! op ) {
				return;
			}
			params.set( 'adv_filters[' + index + '][field]', field );
			params.set( 'adv_filters[' + index + '][op]', op );

			if ( op === 'between' ) {
				var val1 = row.querySelector( '.maf-filter-val1' ).value;
				var val2 = row.querySelector( '.maf-filter-val2' ).value;
				params.set( 'adv_filters[' + index + '][val1]', val1 );
				params.set( 'adv_filters[' + index + '][val2]', val2 );
			} else if ( op !== 'empty' && op !== 'not_empty' ) {
				var val = row.querySelector( '.maf-filter-val' ).value;
				params.set( 'adv_filters[' + index + '][val]', val );
			}
		} );

		return params.toString();
	}

	function updateValueInput( row ) {
		var fieldKey = row.querySelector( '.maf-filter-field' ).value;
		var op = row.querySelector( '.maf-filter-op' ).value;
		var valContainer = row.querySelector( '.maf-filter-val-container' );
		var fieldDef = getFieldDef( fieldKey );
		var type = fieldDef.type || 'text';

		valContainer.innerHTML = '';

		if ( op === 'empty' || op === 'not_empty' ) {
			return;
		}

		if ( op === 'between' ) {
			var input1 = document.createElement( 'input' );
			input1.type = type === 'number' ? 'number' : ( type === 'date' ? 'date' : 'text' );
			input1.className = 'maf-filter-val1';
			input1.style.width = '100px';
			input1.style.marginRight = '5px';
			input1.placeholder = 'Min';

			var input2 = document.createElement( 'input' );
			input2.type = type === 'number' ? 'number' : ( type === 'date' ? 'date' : 'text' );
			input2.className = 'maf-filter-val2';
			input2.style.width = '100px';
			input2.placeholder = 'Max';

			valContainer.appendChild( input1 );
			valContainer.appendChild( input2 );
			return;
		}

		if ( [ 'select', 'radio', 'country' ].indexOf( type ) !== -1 && fieldDef.options ) {
			var select = document.createElement( 'select' );
			select.className = 'maf-filter-val';
			select.innerHTML = '<option value="">— Select value —</option>';
			var opts = fieldDef.options;
			if ( Array.isArray( opts ) ) {
				opts.forEach( function ( optVal ) {
					var opt = document.createElement( 'option' );
					opt.value = optVal;
					opt.textContent = optVal;
					select.appendChild( opt );
				} );
			} else if ( typeof opts === 'object' && opts !== null ) {
				Object.keys( opts ).forEach( function ( k ) {
					var opt = document.createElement( 'option' );
					opt.value = k;
					opt.textContent = opts[ k ];
					select.appendChild( opt );
				} );
			}
			valContainer.appendChild( select );
			return;
		}

		var input = document.createElement( 'input' );
		input.type = type === 'number' ? 'number' : ( type === 'date' ? 'date' : 'email' === type ? 'email' : 'tel_intl' === type ? 'tel' : 'text' );
		input.className = 'maf-filter-val';
		input.style.width = '160px';
		input.placeholder = 'Value…';
		valContainer.appendChild( input );
	}


	/** Fetches the filtered/paginated entries list and renders the table. */
	function fetchEntries() {
		var tbody = document.getElementById( 'maf-entries-tbody' );
		tbody.innerHTML = '<tr><td colspan="8">' + MAF_ADMIN_CONFIG.i18n.loading + '</td></tr>';

		var url = MAF_ADMIN_CONFIG.restUrl + 'entries?page=' + state.page + '&per_page=' + state.perPage + '&' + buildFilterQuery();

		fetch( url, {
			headers: { 'X-WP-Nonce': MAF_ADMIN_CONFIG.nonce }
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				renderEntries( data );
			} )
			.catch( function () {
				tbody.innerHTML = '<tr><td colspan="8">' + MAF_ADMIN_CONFIG.i18n.error + '</td></tr>';
			} );
	}


	/** Fetches the filtered/paginated entries list and renders the table. */
	function getFieldDef( fieldKey ) {
		var formId = document.getElementById( 'maf-filter-form' ).value;
		var schemas = MAF_ADMIN_CONFIG.schemas || {};
		if ( formId && schemas[ formId ] && schemas[ formId ][ fieldKey ] ) {
			return schemas[ formId ][ fieldKey ];
		}
		var found = null;
		Object.keys( schemas ).forEach( function ( fId ) {
			if ( schemas[ fId ][ fieldKey ] ) {
				found = schemas[ fId ][ fieldKey ];
			}
		} );
		if ( found ) {
			return found;
		}

		var generalFields = {
			'first_name': { label: 'First Name', type: 'text' },
			'last_name': { label: 'Last Name', type: 'text' },
			'email': { label: 'Email', type: 'email' },
			'phone': { label: 'Phone', type: 'tel_intl' },
			'age': { label: 'Age', type: 'number' },
			'country_residence': { label: 'Country of Residence', type: 'country' },
			'country_citizenship': { label: 'Country of Citizenship', type: 'country' },
			'marital_status': { label: 'Marital Status', type: 'select', options: { 'single': 'Single', 'married': 'Married', 'divorced': 'Divorced', 'widowed': 'Widowed' } },
			'net_worth_cad': { label: 'Net Worth (CAD)', type: 'number' },
			'status': { label: 'Status', type: 'select', options: { 'submitted': 'Submitted', 'conditional': 'Conditional', 'approved': 'Approved', 'rejected': 'Rejected' } },
			'language': { label: 'Language', type: 'select', options: MAF_ADMIN_CONFIG.languages || {} },
			'assigned_by': { label: 'Assigned By', type: 'select', options: MAF_ADMIN_CONFIG.users || {} },
			'assigned_to': { label: 'Assigned To', type: 'select', options: MAF_ADMIN_CONFIG.users || {} }
		};

		return generalFields[ fieldKey ] || { type: 'text', label: fieldKey };
	}

	function updateOperators( row, preferredOp ) {
		var fieldKey = row.querySelector( '.maf-filter-field' ).value;
		var opSelect = row.querySelector( '.maf-filter-op' );
		var fieldDef = getFieldDef( fieldKey );
		var type = fieldDef.type || 'text';

		opSelect.innerHTML = '';
		var ops = [];

		if ( [ 'text', 'textarea', 'email', 'tel_intl', 'url' ].indexOf( type ) !== -1 ) {
			ops = [
				{ value: 'contains', label: 'Contains' },
				{ value: 'equals', label: 'Equals (=)' },
				{ value: 'not_equals', label: 'Not equals (!=)' },
				{ value: 'empty', label: 'Is empty' },
				{ value: 'not_empty', label: 'Is not empty' }
			];
		} else if ( [ 'number', 'date' ].indexOf( type ) !== -1 ) {
			ops = [
				{ value: 'equals', label: 'Equals (=)' },
				{ value: 'not_equals', label: 'Not equals (!=)' },
				{ value: 'greater_than', label: 'Greater than (>)' },
				{ value: 'greater_than_equal', label: 'Greater than or equal (>=)' },
				{ value: 'less_than', label: 'Less than (<)' },
				{ value: 'less_than_equal', label: 'Less than or equal (<=)' },
				{ value: 'between', label: 'Between' },
				{ value: 'empty', label: 'Is empty' },
				{ value: 'not_empty', label: 'Is not empty' }
			];
		} else if ( [ 'select', 'radio', 'country', 'checkbox_group', 'checkbox' ].indexOf( type ) !== -1 ) {
			ops = [
				{ value: 'equals', label: 'Equals (=)' },
				{ value: 'not_equals', label: 'Not equals (!=)' },
				{ value: 'empty', label: 'Is empty' },
				{ value: 'not_empty', label: 'Is not empty' }
			];
		} else {
			ops = [
				{ value: 'contains', label: 'Contains' },
				{ value: 'equals', label: 'Equals (=)' }
			];
		}

		ops.forEach( function ( op ) {
			var opt = document.createElement( 'option' );
			opt.value = op.value;
			opt.textContent = op.label;
			opSelect.appendChild( opt );
		} );

		if ( preferredOp ) {
			opSelect.value = preferredOp;
		}

		updateValueInput( row );
	}

	function setValueInput( row, val ) {
		var valEl = row.querySelector( '.maf-filter-val' );
		if ( valEl ) {
			valEl.value = val;
			return;
		}
		var val1 = row.querySelector( '.maf-filter-val1' );
		var val2 = row.querySelector( '.maf-filter-val2' );
		if ( val1 && val2 && typeof val === 'object' ) {
			val1.value = val.min || '';
			val2.value = val.max || '';
		}
	}

	/**
	 * Renders the entries table + pagination controls.
	 * @param {Object} result {total, page, per_page, entries}
	 */
	function renderEntries( result ) {
		var tbody = document.getElementById( 'maf-entries-tbody' );
		tbody.innerHTML = '';

		if ( ! result.entries || ! result.entries.length ) {
			tbody.innerHTML = '<tr><td colspan="8">' + MAF_ADMIN_CONFIG.i18n.noResults + '</td></tr>';
			document.getElementById( 'maf-pagination' ).innerHTML = '';
			return;
		}

		result.entries.forEach( function ( entry ) {
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td>' + escapeHtml( entry.id ) + '</td>' +
				'<td>' + escapeHtml( ( entry.first_name + ' ' + entry.last_name ).trim() ) + '</td>' +
				'<td>' + escapeHtml( entry.email ) + '</td>' +
				'<td>' + escapeHtml( entry.country_residence ) + '</td>' +
				'<td>' + escapeHtml( ( entry.language || '' ).toUpperCase() ) + '</td>' +
				'<td><span class="maf-status maf-status--' + escapeHtml( entry.status ) + '">' + escapeHtml( entry.status ) + '</span></td>' +
				'<td>' + escapeHtml( entry.created_at ) + '</td>' +
				'<td><button type="button" class="button button-small maf-view-entry" data-id="' + entry.id + '">' + 'View' + '</button></td>';

			tr.querySelector( '.maf-view-entry' ).addEventListener( 'click', function () {
				openEntryModal( entry.id );
			} );

			tbody.appendChild( tr );
		} );

		renderPagination( result );
	}

	/**
	 * Renders Prev/Next pagination based on total/page/per_page.
	 * @param {Object} result
	 */
	function renderPagination( result ) {
		var totalPages = Math.max( 1, Math.ceil( result.total / result.per_page ) );
		var container = document.getElementById( 'maf-pagination' );
		container.innerHTML = '';

		var info = document.createElement( 'span' );
		info.className = 'maf-pagination__info';
		info.textContent = 'Page ' + result.page + ' / ' + totalPages + ' (' + result.total + ' total)';
		container.appendChild( info );

		var prev = document.createElement( 'button' );
		prev.type = 'button';
		prev.className = 'button';
		prev.textContent = '‹ Prev';
		prev.disabled = result.page <= 1;
		prev.addEventListener( 'click', function () { state.page = result.page - 1; fetchEntries(); } );

		var next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'button';
		next.textContent = 'Next ›';
		next.disabled = result.page >= totalPages;
		next.addEventListener( 'click', function () { state.page = result.page + 1; fetchEntries(); } );

		container.appendChild( prev );
		container.appendChild( next );
	}

	/** Wires the modal's close button + backdrop click. */
	function wireModal() {
		var modal = document.getElementById( 'maf-entry-modal' );
		document.getElementById( 'maf-modal-close' ).addEventListener( 'click', function () {
			modal.classList.remove( 'is-open' );
		} );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				modal.classList.remove( 'is-open' );
			}
		} );
	}

	/**
	 * Opens the detail modal for one entry: full field data, status changer,
	 * and complete audit-log trail.
	 * @param {number} id
	 */
	function openEntryModal( id ) {
		var modal = document.getElementById( 'maf-entry-modal' );
		var body = document.getElementById( 'maf-modal-body' );
		body.innerHTML = '<p>' + MAF_ADMIN_CONFIG.i18n.loading + '</p>';
		modal.classList.add( 'is-open' );


		fetch( MAF_ADMIN_CONFIG.restUrl + 'entries/' + id, {
			headers: { 'X-WP-Nonce': MAF_ADMIN_CONFIG.nonce },
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( entry ) {
				renderEntryModal( entry );
			} )
			.catch( function () {
				body.innerHTML = '<p>' + MAF_ADMIN_CONFIG.i18n.error + '</p>';
			} );
	}

	/**
	 * Renders the modal body: submitted field data, a status <select>, and
	 * the audit-log history table.
	 * @param {Object} entry
	 */
	function renderEntryModal( entry ) {
		var body = document.getElementById( 'maf-modal-body' );

		var dataRows = Object.keys( entry.data || {} ).map( function ( key ) {
			return '<tr><th>' + escapeHtml( key ) + '</th><td>' + formatValue( entry.data[ key ] ) + '</td></tr>';
		} ).join( '' );

		var auditRows = ( entry.audit || [] ).map( function ( a ) {
			return '<tr><td>' + escapeHtml( a.created_at ) + '</td><td>' + escapeHtml( a.admin_name ) + '</td>' +
				'<td>' + escapeHtml( a.field_changed ) + '</td><td>' + escapeHtml( a.old_value ) + '</td>' +
				'<td>' + escapeHtml( a.new_value ) + '</td><td>' + escapeHtml( a.note || '' ) + '</td></tr>';
		} ).join( '' );

		body.innerHTML =
			'<h2>Entry #' + entry.id + '</h2>' +
			'<div class="maf-modal__fields-container"><label>Status: <select id="maf-modal-status">' +
				[ 'submitted', 'conditional', 'approved', 'rejected' ].map( function ( s ) {
					return '<option value="' + s + '"' + ( s === entry.status ? ' selected' : '' ) + '>' + s + '</option>';
				} ).join( '' ) +
			'</select></label> ' +
			'<label>Importance: <input type="text" id="maf-modal-importance" value="' + escapeHtml( entry.importance || '' ) + '"></label> ' +
			'<label>Step: <input type="text" id="maf-modal-step" value="' + escapeHtml( entry.step || '' ) + '"></label> ' +
			'<label>Program Type: <input type="text" id="maf-modal-program_type" value="' + escapeHtml( entry.program_type || '' ) + '"></label> ' +
			'<label>Assigned By: <select id="maf-modal-assigned_by">' +
				Object.keys( MAF_ADMIN_CONFIG.users ).map( function ( id ) {
					return '<option value="' + id + '"' + ( id == entry.assigned_by ? ' selected' : '' ) + '>' + escapeHtml( MAF_ADMIN_CONFIG.users[ id ] ) + '</option>';
				} ).join( '' ) +
			'</select></label> ' +
			'<label>Assigned To: <select id="maf-modal-assigned_to">' +
				Object.keys( MAF_ADMIN_CONFIG.users ).map( function ( id ) {
					return '<option value="' + id + '"' + ( id == entry.assigned_to ? ' selected' : '' ) + '>' + escapeHtml( MAF_ADMIN_CONFIG.users[ id ] ) + '</option>';
				} ).join( '' ) +
			'</select></label> ' +
			'</div><textarea id="maf-modal-note" placeholder="Optional note…" rows="2" style="width:100%;margin-top:8px;"></textarea>' +
			'<button type="button" class="button button-primary" id="maf-modal-save" style="margin-top:8px;">Save</button>' +
			'<span id="maf-modal-save-status"></span>' +
			'<h3>Submitted Data</h3>' +
			'<table class="widefat striped"><tbody>' + dataRows + '</tbody></table>' +
			'<h3>Audit Log</h3>' +
			'<table class="widefat striped"><thead><tr><th>Date</th><th>Admin</th><th>Field</th><th>Old</th><th>New</th><th>Note</th></tr></thead>' +
			'<tbody>' + ( auditRows || '<tr><td colspan="6">No changes yet.</td></tr>' ) + '</tbody></table>';

		document.getElementById( 'maf-modal-save' ).addEventListener( 'click', function () {
			saveEntryStatus( entry.id );
		} );
	}

	/**
	 * PATCHes the entry's status/note, then re-renders the modal with the
	 * fresh audit trail returned by the server.
	 * @param {number} id
	 */
	function saveEntryStatus( id ) {
		var status = document.getElementById( 'maf-modal-status' ).value;
		var importance = document.getElementById( 'maf-modal-importance' ).value;
		var step = document.getElementById( 'maf-modal-step' ).value;
		var program_type = document.getElementById( 'maf-modal-program_type' ).value;
		var assigned_by = document.getElementById( 'maf-modal-assigned_by' ).value;
		var assigned_to = document.getElementById( 'maf-modal-assigned_to' ).value;
		var note = document.getElementById( 'maf-modal-note' ).value;
		var statusEl = document.getElementById( 'maf-modal-save-status' );
		statusEl.textContent = MAF_ADMIN_CONFIG.i18n.loading;

		fetch( MAF_ADMIN_CONFIG.restUrl + 'entries/' + id, {
			method: 'PATCH',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': MAF_ADMIN_CONFIG.nonce,
			},
			body: JSON.stringify( {
				status: status,
				importance: importance,
				step: step,
				program_type: program_type,
				assigned_by: assigned_by,
				assigned_to: assigned_to,
				note: note
			} ),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( updated ) {
				statusEl.textContent = MAF_ADMIN_CONFIG.i18n.saved;
				renderEntryModal( updated );
				fetchEntries(); // Refresh the list so the status column stays in sync.
			} )
			.catch( function () {
				statusEl.textContent = MAF_ADMIN_CONFIG.i18n.error;
			} );
	}

	/**
	 * Formats a submitted value for the detail modal: uploaded files become
	 * links, repeater rows become a nested table, arrays become lists.
	 * @param {*} val
	 * @return {string}
	 */
	function formatValue( val ) {
		if ( val === null || typeof val === 'undefined' || val === '' ) {
			return '<span class="maf-muted">—</span>';
		}
		if ( Array.isArray( val ) ) {
			if ( ! val.length ) {
				return '<span class="maf-muted">—</span>';
			}
			if ( typeof val[0] === 'object' && val[0] !== null && val[0].url && val[0].path ) {
				return val.map( fileLink ).join( '<br>' );
			}
			if ( typeof val[0] === 'object' && val[0] !== null ) {
				var cols = Object.keys( val[0] );
				return '<table class="maf-subtable"><thead><tr>' + cols.map( function ( c ) { return '<th>' + escapeHtml( c ) + '</th>'; } ).join( '' ) + '</tr></thead><tbody>' +
					val.map( function ( row ) {
						return '<tr>' + cols.map( function ( c ) { return '<td>' + formatValue( row[ c ] ) + '</td>'; } ).join( '' ) + '</tr>';
					} ).join( '' ) + '</tbody></table>';
			}
			return escapeHtml( val.join( ', ' ) );
		}
		if ( typeof val === 'object' ) {
			if ( val.url && val.path ) {
				return fileLink( val );
			}
			return escapeHtml( JSON.stringify( val ) );
		}
		return escapeHtml( val );
	}

	function fileLink( f ) {
		var kb = f.size ? ' <span class="maf-muted">(' + Math.round( f.size / 1024 ) + ' KB)</span>' : '';
		return '<a href="' + escapeHtml( f.url ) + '" target="_blank" rel="noopener">' + escapeHtml( f.name || f.path ) + '</a>' + kb;
	}

	/**
	 * Minimal HTML-escaping helper for safely injecting server data into innerHTML.
	 * @param {*} str
	 * @return {string}
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = ( str === null || typeof str === 'undefined' ) ? '' : str;
		return div.innerHTML;
	}
} )();
