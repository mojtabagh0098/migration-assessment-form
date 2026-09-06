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
	}

	/** Rebuilds the CSV/PDF export links so they carry the current filters. */
	function updateExportLinks() {
		var qs = buildFilterQuery();
		document.getElementById( 'maf-export-csv' ).href = MAF_ADMIN_CONFIG.exportCsvUrl + '&' + qs;
		document.getElementById( 'maf-export-pdf' ).href = MAF_ADMIN_CONFIG.exportPdfUrl + '&' + qs;
	}

	/** @return {string} URL-encoded query string built from the visible filter controls. */
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
		return params.toString();
	}

	/** Fetches the filtered/paginated entries list and renders the table. */
	function fetchEntries() {
		var tbody = document.getElementById( 'maf-entries-tbody' );
		tbody.innerHTML = '<tr><td colspan="8">' + MAF_ADMIN_CONFIG.i18n.loading + '</td></tr>';

		var qs = buildFilterQuery();
		qs += ( qs ? '&' : '' ) + 'page=' + state.page + '&per_page=' + state.perPage;

		fetch( MAF_ADMIN_CONFIG.restUrl + 'entries?' + qs, {
			headers: { 'X-WP-Nonce': MAF_ADMIN_CONFIG.nonce },
		} )
			.then( function ( res ) { return res.json(); } )
			.then( renderEntries )
			.catch( function () {
				tbody.innerHTML = '<tr><td colspan="8">' + MAF_ADMIN_CONFIG.i18n.error + '</td></tr>';
			} );
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
			modal.hidden = true;
		} );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) {
				modal.hidden = true;
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
		modal.hidden = false;

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
			var val = entry.data[ key ];
			if ( typeof val === 'object' && val !== null ) {
				val = JSON.stringify( val );
			}
			return '<tr><th>' + escapeHtml( key ) + '</th><td>' + escapeHtml( val ) + '</td></tr>';
		} ).join( '' );

		var auditRows = ( entry.audit || [] ).map( function ( a ) {
			return '<tr><td>' + escapeHtml( a.created_at ) + '</td><td>' + escapeHtml( a.admin_name ) + '</td>' +
				'<td>' + escapeHtml( a.field_changed ) + '</td><td>' + escapeHtml( a.old_value ) + '</td>' +
				'<td>' + escapeHtml( a.new_value ) + '</td><td>' + escapeHtml( a.note || '' ) + '</td></tr>';
		} ).join( '' );

		body.innerHTML =
			'<h2>Entry #' + entry.id + '</h2>' +
			'<label>Status: <select id="maf-modal-status">' +
				[ 'submitted', 'conditional', 'approved', 'rejected' ].map( function ( s ) {
					return '<option value="' + s + '"' + ( s === entry.status ? ' selected' : '' ) + '>' + s + '</option>';
				} ).join( '' ) +
			'</select></label> ' +
			'<textarea id="maf-modal-note" placeholder="Optional note…" rows="2" style="width:100%;margin-top:8px;"></textarea>' +
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
		var note = document.getElementById( 'maf-modal-note' ).value;
		var statusEl = document.getElementById( 'maf-modal-save-status' );
		statusEl.textContent = MAF_ADMIN_CONFIG.i18n.loading;

		fetch( MAF_ADMIN_CONFIG.restUrl + 'entries/' + id, {
			method: 'PATCH',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': MAF_ADMIN_CONFIG.nonce,
			},
			body: JSON.stringify( { status: status, note: note } ),
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
