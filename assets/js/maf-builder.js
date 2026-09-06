/**
 * Visual Form Builder for Assessment Forms — pure Vanilla JS, no build step.
 *
 * Three-pane layout:
 *   ┌───────────┬──────────────────────────┬───────────────┐
 *   │ Palette   │ Canvas (sections/fields) │ Inspector     │
 *   └───────────┴──────────────────────────┴───────────────┘
 *
 * State is a plain JS schema (same structure stored in `_maf_form_schema`),
 * rendered from scratch on every change. Drag & drop uses native HTML5 DnD:
 *  - sections can be reordered,
 *  - fields can be reordered within and across sections,
 *  - palette items can be dropped straight into a section.
 * The schema is mirrored into the hidden <textarea name="maf_form_schema">
 * so the classic post form persists it on Update/Publish.
 */
( function () {
	'use strict';

	if ( typeof MAF_BUILDER === 'undefined' ) {
		return;
	}

	var I18N   = MAF_BUILDER.i18n;
	var TYPES  = MAF_BUILDER.fieldTypes;
	var OPTION_TYPES = [ 'select', 'radio', 'checkbox_group' ];

	var root, textarea, schema, selected, history, future, activeTab, collapsed, dragState, dirty;

	document.addEventListener( 'DOMContentLoaded', init );

	/* ------------------------------------------------------------------ */
	/* Bootstrap                                                           */
	/* ------------------------------------------------------------------ */

	function init() {
		root     = document.getElementById( 'maf-builder' );
		textarea = document.getElementById( 'maf_form_schema' );
		if ( ! root || ! textarea ) {
			return;
		}

		schema    = parseSchema( textarea.value ) || parseSchema( root.dataset.defaultSchema ) || [];
		selected  = null;           // { type: 'section'|'field', s: idx, f: idx }
		history   = [];
		future    = [];
		activeTab = 'builder';
		collapsed = {};
		dirty     = false;

		root.innerHTML = '';
		root.classList.add( 'is-ready' );
		render();
		sync();

		// Ensure the latest schema is serialized right before WP submits the post form.
		var postForm = document.getElementById( 'post' );
		if ( postForm ) {
			postForm.addEventListener( 'submit', function () {
				if ( activeTab === 'json' ) {
					applyJson( true );
				}
				sync();
			} );
		}

		document.addEventListener( 'keydown', function ( e ) {
			var tag = ( e.target.tagName || '' ).toLowerCase();
			if ( tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable ) {
				return;
			}
			if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'z' ) {
				e.preventDefault();
				e.shiftKey ? redo() : undo();
			}
			if ( e.key === 'Delete' && selected ) {
				deleteSelected();
			}
		} );
	}

	function parseSchema( raw ) {
		try {
			var parsed = JSON.parse( raw );
			return Array.isArray( parsed ) ? parsed : null;
		} catch ( e ) {
			return null;
		}
	}

	/* ------------------------------------------------------------------ */
	/* State helpers                                                       */
	/* ------------------------------------------------------------------ */

	function clone( obj ) {
		return JSON.parse( JSON.stringify( obj ) );
	}

	/** Snapshot before a mutation (for undo). */
	function commit() {
		history.push( clone( schema ) );
		if ( history.length > 60 ) {
			history.shift();
		}
		future = [];
		dirty  = true;
	}

	function undo() {
		if ( ! history.length ) {
			return;
		}
		future.push( clone( schema ) );
		schema = history.pop();
		selected = null;
		render();
		sync();
	}

	function redo() {
		if ( ! future.length ) {
			return;
		}
		history.push( clone( schema ) );
		schema = future.pop();
		selected = null;
		render();
		sync();
	}

	/** Mirrors the schema into the hidden textarea. */
	function sync() {
		textarea.value = JSON.stringify( schema, null, 2 );
	}

	function slugify( str ) {
		return String( str || '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.slice( 0, 40 );
	}

	function uniqueKey( base, taken ) {
		var key = base || 'field';
		var n = 2;
		while ( taken.indexOf( key ) !== -1 ) {
			key = base + '_' + n++;
		}
		return key;
	}

	/** Keys in the same validation scope (global for normal sections, per-section for repeaters). */
	function scopeKeys( sIdx, excludeF ) {
		var keys = [];
		var sec = schema[ sIdx ];
		if ( sec.repeater ) {
			sec.fields.forEach( function ( f, i ) {
				if ( i !== excludeF ) {
					keys.push( f.key );
				}
			} );
			return keys;
		}
		schema.forEach( function ( s, si ) {
			if ( s.repeater ) {
				return;
			}
			s.fields.forEach( function ( f, fi ) {
				if ( ! ( si === sIdx && fi === excludeF ) ) {
					keys.push( f.key );
				}
			} );
		} );
		return keys;
	}

	function makeField( type ) {
		var def = TYPES[ type ] || TYPES.text;
		var field = { key: '', type: type, label: def.label };
		if ( OPTION_TYPES.indexOf( type ) !== -1 ) {
			field.options = { option_1: 'Option 1', option_2: 'Option 2' };
		}
		if ( type === 'file' ) {
			var fd = MAF_BUILDER.fileDefaults;
			field.accept    = fd.accept.slice();
			field.max_size  = fd.max_size;
			field.multiple  = false;
			field.max_files = fd.max_files;
		}
		if ( type === 'html' ) {
			field.label   = I18N.newField;
			field.content = '<p>…</p>';
		}
		if ( type === 'checkbox' ) {
			field.checkbox_label = def.label;
		}
		return field;
	}

	function makeSection( repeater ) {
		var sec = { id: '', title: repeater ? I18N.newRepeater : I18N.newSection, fields: [] };
		if ( repeater ) {
			sec.repeater  = true;
			sec.row_label = I18N.newRepeater;
		}
		var ids = schema.map( function ( s ) { return s.id; } );
		sec.id = uniqueKey( slugify( sec.title ) || 'section', ids );
		return sec;
	}

	/* ------------------------------------------------------------------ */
	/* Mutations                                                           */
	/* ------------------------------------------------------------------ */

	function addSection( repeater, atIndex ) {
		commit();
		var sec = makeSection( repeater );
		if ( typeof atIndex === 'number' ) {
			schema.splice( atIndex, 0, sec );
		} else {
			schema.push( sec );
			atIndex = schema.length - 1;
		}
		selected = { type: 'section', s: atIndex };
		render();
		sync();
	}

	function addField( type, sIdx, atIndex ) {
		if ( ! schema.length ) {
			addSection( false );
			sIdx = 0;
		}
		if ( typeof sIdx !== 'number' ) {
			sIdx = selected ? selected.s : schema.length - 1;
		}
		commit();
		var field = makeField( type );
		field.key = uniqueKey( slugify( field.label ) || type, scopeKeys( sIdx ) );
		var list  = schema[ sIdx ].fields;
		if ( typeof atIndex !== 'number' ) {
			atIndex = list.length;
		}
		list.splice( atIndex, 0, field );
		collapsed[ schema[ sIdx ].id ] = false;
		selected = { type: 'field', s: sIdx, f: atIndex };
		render();
		sync();
	}

	function moveSection( from, to ) {
		if ( from === to || to < 0 || to >= schema.length ) {
			return;
		}
		commit();
		var item = schema.splice( from, 1 )[0];
		schema.splice( to, 0, item );
		selected = { type: 'section', s: to };
		render();
		sync();
	}

	function moveField( fromS, fromF, toS, toF ) {
		commit();
		var item = schema[ fromS ].fields.splice( fromF, 1 )[0];
		if ( fromS === toS && fromF < toF ) {
			toF--;
		}
		toF = Math.max( 0, Math.min( toF, schema[ toS ].fields.length ) );
		// Avoid key clashes when moving between scopes.
		item.key = uniqueKey( item.key, scopeKeys( toS ) );
		schema[ toS ].fields.splice( toF, 0, item );
		selected = { type: 'field', s: toS, f: toF };
		render();
		sync();
	}

	function duplicateSelected() {
		if ( ! selected ) {
			return;
		}
		commit();
		if ( selected.type === 'section' ) {
			var sec = clone( schema[ selected.s ] );
			sec.id = uniqueKey( sec.id, schema.map( function ( s ) { return s.id; } ) );
			schema.splice( selected.s + 1, 0, sec );
			// Re-key fields if global scope.
			if ( ! sec.repeater ) {
				sec.fields.forEach( function ( f, i ) {
					f.key = uniqueKey( f.key, scopeKeys( selected.s + 1, i ) );
				} );
			}
			selected = { type: 'section', s: selected.s + 1 };
		} else {
			var fld = clone( schema[ selected.s ].fields[ selected.f ] );
			schema[ selected.s ].fields.splice( selected.f + 1, 0, fld );
			fld.key = uniqueKey( fld.key, scopeKeys( selected.s, selected.f + 1 ) );
			selected = { type: 'field', s: selected.s, f: selected.f + 1 };
		}
		render();
		sync();
	}

	function deleteSelected() {
		if ( ! selected ) {
			return;
		}
		var msg = selected.type === 'section' ? I18N.confirmDeleteSec : I18N.confirmDeleteFld;
		if ( ! window.confirm( msg ) ) {
			return;
		}
		commit();
		if ( selected.type === 'section' ) {
			schema.splice( selected.s, 1 );
		} else {
			schema[ selected.s ].fields.splice( selected.f, 1 );
		}
		selected = null;
		render();
		sync();
	}

	/** Applies an inspector edit to the selected item. */
	function update( mutator, rerenderInspector ) {
		if ( ! selected ) {
			return;
		}
		commit();
		var target = selected.type === 'section' ? schema[ selected.s ] : schema[ selected.s ].fields[ selected.f ];
		mutator( target );
		renderCanvas();
		renderHeader();
		if ( rerenderInspector ) {
			renderInspector();
		}
		sync();
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v === null || v === undefined || v === false ) {
					return;
				}
				if ( k === 'class' ) {
					node.className = v;
				} else if ( k === 'html' ) {
					node.innerHTML = v;
				} else if ( k === 'text' ) {
					node.textContent = v;
				} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
					node.addEventListener( k.slice( 2 ).toLowerCase(), v );
				} else if ( k === 'checked' || k === 'disabled' || k === 'selected' || k === 'draggable' ) {
					node[ k ] = !! v;
					if ( k === 'draggable' ) {
						node.setAttribute( 'draggable', v ? 'true' : 'false' );
					}
				} else if ( k === 'value' ) {
					node.value = v;
				} else {
					node.setAttribute( k, v );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function icon( name ) {
		return el( 'span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );
	}

	function render() {
		root.innerHTML = '';
		root.appendChild( el( 'div', { class: 'maf-b-header', id: 'maf-b-header' } ) );
		var body = el( 'div', { class: 'maf-b-body', id: 'maf-b-body' } );
		root.appendChild( body );
		renderHeader();
		renderBody();
	}

	function renderHeader() {
		var header = document.getElementById( 'maf-b-header' );
		if ( ! header ) {
			return;
		}
		var nFields = schema.reduce( function ( n, s ) { return n + s.fields.length; }, 0 );
		header.innerHTML = '';

		var tabs = el( 'div', { class: 'maf-b-tabs', role: 'tablist' } );
		[ [ 'builder', I18N.tabBuilder, 'layout' ], [ 'preview', I18N.tabPreview, 'visibility' ], [ 'json', I18N.tabJson, 'editor-code' ] ].forEach( function ( t ) {
			tabs.appendChild( el( 'button', {
				type: 'button', role: 'tab', class: 'maf-b-tab' + ( activeTab === t[0] ? ' is-active' : '' ),
				onClick: function () {
					if ( activeTab === 'json' && t[0] !== 'json' ) {
						if ( ! applyJson( false ) ) {
							return;
						}
					}
					activeTab = t[0];
					render();
				},
			}, [ icon( t[2] ), t[1] ] ) );
		} );
		header.appendChild( tabs );

		header.appendChild( el( 'div', { class: 'maf-b-stats' }, [
			el( 'span', { text: I18N.statsSections.replace( '%d', schema.length ) } ),
			el( 'span', { text: I18N.statsFields.replace( '%d', nFields ) } ),
			dirty ? el( 'span', { class: 'maf-b-dirty', text: I18N.unsaved } ) : null,
		] ) );

		header.appendChild( el( 'div', { class: 'maf-b-actions' }, [
			el( 'button', { type: 'button', class: 'button maf-b-iconbtn', title: I18N.undo, disabled: ! history.length, onClick: undo }, [ icon( 'undo' ) ] ),
			el( 'button', { type: 'button', class: 'button maf-b-iconbtn', title: I18N.redo, disabled: ! future.length, onClick: redo }, [ icon( 'redo' ) ] ),
			el( 'button', { type: 'button', class: 'button', onClick: function () {
				if ( window.confirm( I18N.confirmReset ) ) {
					commit();
					schema = parseSchema( root.dataset.defaultSchema ) || [];
					selected = null;
					render();
					sync();
				}
			} }, [ I18N.resetDefault ] ),
		] ) );
	}

	function renderBody() {
		var body = document.getElementById( 'maf-b-body' );
		body.innerHTML = '';
		body.className = 'maf-b-body maf-b-body--' + activeTab;

		if ( activeTab === 'preview' ) {
			body.appendChild( renderPreview() );
			return;
		}
		if ( activeTab === 'json' ) {
			body.appendChild( renderJsonTab() );
			return;
		}

		body.appendChild( renderPalette() );
		body.appendChild( el( 'div', { class: 'maf-b-canvas-wrap', id: 'maf-b-canvas' } ) );
		body.appendChild( el( 'aside', { class: 'maf-b-inspector', id: 'maf-b-inspector' } ) );
		renderCanvas();
		renderInspector();
	}

	/* ---------------------------- Palette ---------------------------- */

	function renderPalette() {
		var pane = el( 'aside', { class: 'maf-b-palette' } );

		pane.appendChild( el( 'h3', { text: I18N.sections } ) );
		pane.appendChild( el( 'div', { class: 'maf-b-palette__grid' }, [
			paletteItem( 'plus-alt2', I18N.addSection, function () { addSection( false ); }, 'section' ),
			paletteItem( 'controls-repeat', I18N.addRepeater, function () { addSection( true ); }, 'repeater' ),
		] ) );

		pane.appendChild( el( 'h3', { text: I18N.fields } ) );
		var search = el( 'input', { type: 'search', class: 'maf-b-palette__search', placeholder: I18N.search } );
		pane.appendChild( search );
		var grid = el( 'div', { class: 'maf-b-palette__grid' } );
		Object.keys( TYPES ).forEach( function ( type ) {
			var item = paletteItem( TYPES[ type ].icon, TYPES[ type ].label, function () { addField( type ); }, 'field:' + type );
			item.dataset.search = ( TYPES[ type ].label + ' ' + type ).toLowerCase();
			grid.appendChild( item );
		} );
		pane.appendChild( grid );
		search.addEventListener( 'input', function () {
			var q = search.value.trim().toLowerCase();
			grid.querySelectorAll( '.maf-b-palette__item' ).forEach( function ( it ) {
				it.hidden = q && it.dataset.search.indexOf( q ) === -1;
			} );
		} );

		return pane;
	}

	function paletteItem( iconName, label, onClick, dragPayload ) {
		var item = el( 'button', { type: 'button', class: 'maf-b-palette__item', draggable: true, onClick: onClick }, [ icon( iconName ), el( 'span', { text: label } ) ] );
		item.addEventListener( 'dragstart', function ( e ) {
			dragState = { kind: 'palette', payload: dragPayload };
			e.dataTransfer.effectAllowed = 'copy';
			e.dataTransfer.setData( 'text/plain', dragPayload );
			root.classList.add( 'is-dragging' );
		} );
		item.addEventListener( 'dragend', endDrag );
		return item;
	}

	/* ---------------------------- Canvas ----------------------------- */

	function renderCanvas() {
		var wrap = document.getElementById( 'maf-b-canvas' );
		if ( ! wrap ) {
			return;
		}
		wrap.innerHTML = '';
		var canvas = el( 'div', { class: 'maf-b-canvas' } );
		wrap.appendChild( canvas );

		if ( ! schema.length ) {
			var empty = el( 'div', { class: 'maf-b-empty' }, [
				icon( 'welcome-widgets-menus' ),
				el( 'p', { text: I18N.emptyForm } ),
				el( 'button', { type: 'button', class: 'button button-primary', onClick: function () { addSection( false ); } }, [ I18N.addSection ] ),
			] );
			bindSectionDropZone( empty, 0 );
			canvas.appendChild( empty );
			return;
		}

		schema.forEach( function ( sec, sIdx ) {
			canvas.appendChild( sectionDropZone( sIdx ) );
			canvas.appendChild( renderSectionCard( sec, sIdx ) );
		} );
		canvas.appendChild( sectionDropZone( schema.length ) );

		canvas.appendChild( el( 'div', { class: 'maf-b-canvas__footer' }, [
			el( 'button', { type: 'button', class: 'button', onClick: function () { addSection( false ); } }, [ icon( 'plus-alt2' ), I18N.addSection ] ),
			el( 'button', { type: 'button', class: 'button', onClick: function () { addSection( true ); } }, [ icon( 'controls-repeat' ), I18N.addRepeater ] ),
		] ) );

		// Keep the selected element in view.
		var sel = canvas.querySelector( '.is-selected' );
		if ( sel && typeof sel.scrollIntoView === 'function' ) {
			var r = sel.getBoundingClientRect();
			if ( r.top < 0 || r.bottom > window.innerHeight ) {
				sel.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			}
		}
	}

	function sectionDropZone( index ) {
		var zone = el( 'div', { class: 'maf-b-dropzone maf-b-dropzone--section' } );
		bindSectionDropZone( zone, index );
		return zone;
	}

	function bindSectionDropZone( zone, index ) {
		zone.addEventListener( 'dragover', function ( e ) {
			if ( ! dragState ) {
				return;
			}
			var ok = dragState.kind === 'section' || ( dragState.kind === 'palette' && dragState.payload.indexOf( 'field:' ) !== 0 );
			if ( ! ok ) {
				return;
			}
			e.preventDefault();
			zone.classList.add( 'is-over' );
		} );
		zone.addEventListener( 'dragleave', function () { zone.classList.remove( 'is-over' ); } );
		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			zone.classList.remove( 'is-over' );
			if ( ! dragState ) {
				return;
			}
			if ( dragState.kind === 'section' ) {
				var from = dragState.s;
				var to   = index > from ? index - 1 : index;
				moveSection( from, to );
			} else if ( dragState.kind === 'palette' ) {
				addSection( dragState.payload === 'repeater', index );
			}
			endDrag();
		} );
	}

	function renderSectionCard( sec, sIdx ) {
		var isSel = selected && selected.type === 'section' && selected.s === sIdx;
		var isCollapsed = !! collapsed[ sec.id ];
		var card = el( 'section', {
			class: 'maf-b-section' + ( isSel ? ' is-selected' : '' ) + ( sec.repeater ? ' is-repeater' : '' ) + ( isCollapsed ? ' is-collapsed' : '' ),
			'data-s': sIdx,
		} );

		var handle = el( 'span', { class: 'maf-b-handle', title: I18N.dragHint, draggable: true }, [ icon( 'move' ) ] );
		handle.addEventListener( 'dragstart', function ( e ) {
			dragState = { kind: 'section', s: sIdx };
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', 'section:' + sIdx );
			card.classList.add( 'is-dragging' );
			root.classList.add( 'is-dragging' );
		} );
		handle.addEventListener( 'dragend', endDrag );

		var head = el( 'header', { class: 'maf-b-section__head', onClick: function ( e ) {
			if ( e.target.closest( 'button' ) ) {
				return;
			}
			selected = { type: 'section', s: sIdx };
			renderCanvas();
			renderInspector();
		} }, [
			handle,
			el( 'div', { class: 'maf-b-section__titles' }, [
				el( 'strong', { text: sec.title || I18N.untitled } ),
				el( 'span', { class: 'maf-b-muted', text: sec.id } ),
				sec.repeater ? el( 'span', { class: 'maf-b-badge maf-b-badge--repeater' }, [ icon( 'controls-repeat' ), I18N.repeaterBadge ] ) : null,
			] ),
			el( 'div', { class: 'maf-b-section__actions' }, [
				el( 'button', { type: 'button', class: 'maf-b-iconbtn', title: I18N.moveUp, disabled: sIdx === 0, onClick: function () { moveSection( sIdx, sIdx - 1 ); } }, [ icon( 'arrow-up-alt2' ) ] ),
				el( 'button', { type: 'button', class: 'maf-b-iconbtn', title: I18N.moveDown, disabled: sIdx === schema.length - 1, onClick: function () { moveSection( sIdx, sIdx + 1 ); } }, [ icon( 'arrow-down-alt2' ) ] ),
				el( 'button', { type: 'button', class: 'maf-b-iconbtn', title: I18N.duplicate, onClick: function () { selected = { type: 'section', s: sIdx }; duplicateSelected(); } }, [ icon( 'admin-page' ) ] ),
				el( 'button', { type: 'button', class: 'maf-b-iconbtn is-danger', title: I18N.delete, onClick: function () { selected = { type: 'section', s: sIdx }; deleteSelected(); } }, [ icon( 'trash' ) ] ),
				el( 'button', { type: 'button', class: 'maf-b-iconbtn', title: I18N.collapse, onClick: function () { collapsed[ sec.id ] = ! isCollapsed; renderCanvas(); } }, [ icon( isCollapsed ? 'arrow-down-alt2' : 'arrow-up-alt2' ) ] ),
			] ),
		] );
		card.appendChild( head );

		var body = el( 'div', { class: 'maf-b-section__body' } );
		card.appendChild( body );

		if ( sec.description ) {
			body.appendChild( el( 'p', { class: 'maf-b-section__desc', text: sec.description } ) );
		}

		var list = el( 'div', { class: 'maf-b-fields' } );
		body.appendChild( list );

		if ( ! sec.fields.length ) {
			var placeholder = el( 'div', { class: 'maf-b-fields__empty', text: I18N.emptySection } );
			bindFieldDropZone( placeholder, sIdx, 0 );
			list.appendChild( placeholder );
		} else {
			sec.fields.forEach( function ( field, fIdx ) {
				list.appendChild( fieldDropZone( sIdx, fIdx ) );
				list.appendChild( renderFieldCard( field, sIdx, fIdx ) );
			} );
			list.appendChild( fieldDropZone( sIdx, sec.fields.length ) );
		}

		var addBtn = el( 'button', { type: 'button', class: 'maf-b-addfield', onClick: function ( e ) { openQuickAdd( e.currentTarget, sIdx ); } }, [ icon( 'plus' ), I18N.addField ] );
		body.appendChild( addBtn );

		return card;
	}

	function fieldDropZone( sIdx, fIdx ) {
		var zone = el( 'div', { class: 'maf-b-dropzone maf-b-dropzone--field' } );
		bindFieldDropZone( zone, sIdx, fIdx );
		return zone;
	}

	function bindFieldDropZone( zone, sIdx, fIdx ) {
		zone.addEventListener( 'dragover', function ( e ) {
			if ( ! dragState ) {
				return;
			}
			var ok = dragState.kind === 'field' || ( dragState.kind === 'palette' && dragState.payload.indexOf( 'field:' ) === 0 );
			if ( ! ok ) {
				return;
			}
			e.preventDefault();
			e.dataTransfer.dropEffect = dragState.kind === 'palette' ? 'copy' : 'move';
			zone.classList.add( 'is-over' );
		} );
		zone.addEventListener( 'dragleave', function () { zone.classList.remove( 'is-over' ); } );
		zone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove( 'is-over' );
			if ( ! dragState ) {
				return;
			}
			if ( dragState.kind === 'field' ) {
				moveField( dragState.s, dragState.f, sIdx, fIdx );
			} else if ( dragState.kind === 'palette' ) {
				addField( dragState.payload.replace( 'field:', '' ), sIdx, fIdx );
			}
			endDrag();
		} );
	}

	function endDrag() {
		dragState = null;
		root.classList.remove( 'is-dragging' );
		root.querySelectorAll( '.is-dragging, .is-over' ).forEach( function ( n ) {
			n.classList.remove( 'is-dragging', 'is-over' );
		} );
	}

	function renderFieldCard( field, sIdx, fIdx ) {
		var isSel = selected && selected.type === 'field' && selected.s === sIdx && selected.f === fIdx;
		var def   = TYPES[ field.type ] || TYPES.text;
		var dup   = scopeKeys( sIdx, fIdx ).indexOf( field.key ) !== -1;
		var card  = el( 'div', {
			class: 'maf-b-field maf-b-field--' + ( field.width || 'full' ) + ( isSel ? ' is-selected' : '' ),
			draggable: true,
			tabindex: '0',
			onClick: function ( e ) {
				if ( e.target.closest( 'button' ) ) {
					return;
				}
				selected = { type: 'field', s: sIdx, f: fIdx };
				renderCanvas();
				renderInspector();
			},
			onKeydown: function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					selected = { type: 'field', s: sIdx, f: fIdx };
					renderCanvas();
					renderInspector();
				}
			},
		} );

		card.addEventListener( 'dragstart', function ( e ) {
			dragState = { kind: 'field', s: sIdx, f: fIdx };
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', 'field:' + sIdx + ':' + fIdx );
			setTimeout( function () { card.classList.add( 'is-dragging' ); }, 0 );
			root.classList.add( 'is-dragging' );
		} );
		card.addEventListener( 'dragend', endDrag );

		card.appendChild( el( 'span', { class: 'maf-b-handle', title: I18N.dragHint }, [ icon( 'move' ) ] ) );
		card.appendChild( el( 'span', { class: 'maf-b-field__icon' }, [ icon( def.icon ) ] ) );
		card.appendChild( el( 'div', { class: 'maf-b-field__main' }, [
			el( 'div', { class: 'maf-b-field__label' }, [
				el( 'span', { text: field.label || I18N.untitled } ),
				field.required ? el( 'span', { class: 'maf-b-req', text: '*' } ) : null,
			] ),
			el( 'div', { class: 'maf-b-field__meta' }, [
				el( 'span', { class: 'maf-b-chip', text: def.label } ),
				el( 'code', { class: dup ? 'is-dup' : '', text: field.key, title: dup ? I18N.dupKey : '' } ),
				field.condition ? el( 'span', { class: 'maf-b-badge maf-b-badge--cond' }, [ icon( 'randomize' ), I18N.conditionalBadge ] ) : null,
				field.type === 'file' ? el( 'span', { class: 'maf-b-muted', text: ( field.accept || [] ).join( ', ' ) + ' · ' + field.max_size + 'MB' } ) : null,
				OPTION_TYPES.indexOf( field.type ) !== -1 ? el( 'span', { class: 'maf-b-muted', text: Object.keys( field.options || {} ).length + ' options' } ) : null,
			] ),
		] ) );
		card.appendChild( el( 'div', { class: 'maf-b-field__actions' }, [
			el( 'button', { type: 'button', class: 'maf-b-iconbtn', title: I18N.duplicate, onClick: function () { selected = { type: 'field', s: sIdx, f: fIdx }; duplicateSelected(); } }, [ icon( 'admin-page' ) ] ),
			el( 'button', { type: 'button', class: 'maf-b-iconbtn is-danger', title: I18N.delete, onClick: function () { selected = { type: 'field', s: sIdx, f: fIdx }; deleteSelected(); } }, [ icon( 'trash' ) ] ),
		] ) );
		return card;
	}

	/** Small popover listing field types, anchored to an "Add field" button. */
	function openQuickAdd( anchor, sIdx ) {
		closeQuickAdd();
		var pop = el( 'div', { class: 'maf-b-quickadd', id: 'maf-b-quickadd' } );
		Object.keys( TYPES ).forEach( function ( type ) {
			pop.appendChild( el( 'button', { type: 'button', onClick: function () { closeQuickAdd(); addField( type, sIdx ); } }, [ icon( TYPES[ type ].icon ), TYPES[ type ].label ] ) );
		} );
		anchor.parentNode.insertBefore( pop, anchor.nextSibling );
		setTimeout( function () {
			document.addEventListener( 'click', quickAddOutside );
		}, 0 );
	}

	function quickAddOutside( e ) {
		if ( ! e.target.closest( '#maf-b-quickadd' ) ) {
			closeQuickAdd();
		}
	}

	function closeQuickAdd() {
		var pop = document.getElementById( 'maf-b-quickadd' );
		if ( pop ) {
			pop.remove();
		}
		document.removeEventListener( 'click', quickAddOutside );
	}

	/* --------------------------- Inspector --------------------------- */

	function renderInspector() {
		var pane = document.getElementById( 'maf-b-inspector' );
		if ( ! pane ) {
			return;
		}
		pane.innerHTML = '';

		if ( ! selected || ! schema[ selected.s ] || ( selected.type === 'field' && ! schema[ selected.s ].fields[ selected.f ] ) ) {
			selected = null;
			pane.appendChild( el( 'div', { class: 'maf-b-inspector__empty' }, [ icon( 'admin-generic' ), el( 'p', { text: I18N.selectField } ) ] ) );
			return;
		}

		if ( selected.type === 'section' ) {
			renderSectionInspector( pane, schema[ selected.s ] );
		} else {
			renderFieldInspector( pane, schema[ selected.s ].fields[ selected.f ], selected.s );
		}
	}

	function control( labelText, input, help ) {
		var id = 'maf-b-ctl-' + Math.random().toString( 36 ).slice( 2, 8 );
		input.id = id;
		return el( 'div', { class: 'maf-b-control' }, [
			el( 'label', { for: id, text: labelText } ),
			input,
			help ? el( 'p', { class: 'description', text: help } ) : null,
		] );
	}

	function toggle( labelText, checked, onChange ) {
		var input = el( 'input', { type: 'checkbox', checked: checked, onChange: function () { onChange( input.checked ); } } );
		return el( 'label', { class: 'maf-b-toggle' }, [ input, el( 'span', { class: 'maf-b-toggle__track' } ), el( 'span', { text: labelText } ) ] );
	}

	function textInput( value, onInput, attrs ) {
		var input = el( 'input', Object.assign( { type: 'text', class: 'regular-text', value: value === undefined || value === null ? '' : value }, attrs || {} ) );
		input.addEventListener( 'input', function () { onInput( input.value ); } );
		return input;
	}

	function renderSectionInspector( pane, sec ) {
		pane.appendChild( el( 'h3', { class: 'maf-b-inspector__title' }, [ icon( 'category' ), I18N.sectionSettings ] ) );

		pane.appendChild( control( I18N.title, textInput( sec.title, function ( v ) { update( function ( s ) { s.title = v; } ); } ) ) );
		pane.appendChild( control( I18N.description, ( function () {
			var ta = el( 'textarea', { rows: 2, class: 'large-text', value: sec.description || '' } );
			ta.addEventListener( 'input', function () { update( function ( s ) { s.description = ta.value; } ); } );
			return ta;
		} )() ) );
		pane.appendChild( control( I18N.sectionId, textInput( sec.id, function ( v ) {
			update( function ( s ) {
				var old = s.id;
				s.id = slugify( v ) || old;
				collapsed[ s.id ] = collapsed[ old ];
			} );
		}, { dir: 'ltr', class: 'regular-text code' } ) ) );

		pane.appendChild( toggle( I18N.repeater, !! sec.repeater, function ( on ) {
			update( function ( s ) {
				if ( on ) {
					s.repeater  = true;
					s.row_label = s.row_label || s.title;
				} else {
					delete s.repeater;
					delete s.row_label;
					delete s.min_rows;
					delete s.max_rows;
				}
			}, true );
		} ) );

		if ( sec.repeater ) {
			var grp = el( 'div', { class: 'maf-b-group' } );
			grp.appendChild( control( I18N.rowLabel, textInput( sec.row_label, function ( v ) { update( function ( s ) { s.row_label = v; } ); } ) ) );
			grp.appendChild( el( 'div', { class: 'maf-b-row' }, [
				control( I18N.minRows, textInput( sec.min_rows || 0, function ( v ) { update( function ( s ) { s.min_rows = parseInt( v, 10 ) || 0; } ); }, { type: 'number', min: 0, class: 'small-text' } ) ),
				control( I18N.maxRows, textInput( sec.max_rows || 0, function ( v ) { update( function ( s ) { s.max_rows = parseInt( v, 10 ) || 0; } ); }, { type: 'number', min: 0, class: 'small-text' } ) ),
			] ) );
			pane.appendChild( grp );
		}

		pane.appendChild( inspectorFooter() );
	}

	function renderFieldInspector( pane, field, sIdx ) {
		var def = TYPES[ field.type ] || TYPES.text;
		var supports = function ( k ) { return def.supports.indexOf( k ) !== -1; };

		pane.appendChild( el( 'h3', { class: 'maf-b-inspector__title' }, [ icon( def.icon ), I18N.fieldSettings ] ) );

		// Type switcher.
		var typeSel = el( 'select', {} );
		Object.keys( TYPES ).forEach( function ( t ) {
			typeSel.appendChild( el( 'option', { value: t, selected: t === field.type, text: TYPES[ t ].label } ) );
		} );
		typeSel.addEventListener( 'change', function () {
			update( function ( f ) {
				var fresh = makeField( typeSel.value );
				// Preserve common attributes, reset type-specific ones.
				[ 'key', 'label', 'required', 'placeholder', 'help', 'width', 'condition' ].forEach( function ( k ) {
					if ( f[ k ] !== undefined ) {
						fresh[ k ] = f[ k ];
					}
				} );
				if ( OPTION_TYPES.indexOf( typeSel.value ) !== -1 && f.options ) {
					fresh.options = f.options;
				}
				Object.keys( f ).forEach( function ( k ) { delete f[ k ]; } );
				Object.assign( f, fresh );
			}, true );
		} );
		pane.appendChild( control( I18N.type, typeSel ) );

		pane.appendChild( control( I18N.label, textInput( field.label, function ( v ) { update( function ( f ) { f.label = v; } ); } ) ) );

		if ( ! def.static ) {
			var keyInput = textInput( field.key, function ( v ) {
				var k = slugify( v );
				keyInput.classList.toggle( 'is-invalid', ! k || scopeKeys( sIdx, selected.f ).indexOf( k ) !== -1 );
				if ( k ) {
					update( function ( f ) { f.key = k; } );
				}
			}, { dir: 'ltr', class: 'regular-text code' } );
			pane.appendChild( control( I18N.key, keyInput, I18N.keyHelp ) );
		}

		if ( supports( 'content' ) ) {
			var ta = el( 'textarea', { rows: 6, class: 'large-text code', value: field.content || '' } );
			ta.addEventListener( 'input', function () { update( function ( f ) { f.content = ta.value; } ); } );
			pane.appendChild( control( I18N.content, ta ) );
		}

		if ( supports( 'checkbox_label' ) ) {
			pane.appendChild( control( I18N.checkboxLabel, textInput( field.checkbox_label, function ( v ) { update( function ( f ) { f.checkbox_label = v; } ); } ) ) );
		}

		if ( supports( 'placeholder' ) ) {
			pane.appendChild( control( I18N.placeholder, textInput( field.placeholder, function ( v ) { update( function ( f ) { v ? f.placeholder = v : delete f.placeholder; } ); } ) ) );
		}

		if ( ! def.static ) {
			pane.appendChild( control( I18N.help, textInput( field.help, function ( v ) { update( function ( f ) { v ? f.help = v : delete f.help; } ); } ) ) );
		}

		var toggles = el( 'div', { class: 'maf-b-toggles' } );
		if ( supports( 'required' ) ) {
			toggles.appendChild( toggle( I18N.required, !! field.required, function ( on ) { update( function ( f ) { on ? f.required = true : delete f.required; } ); } ) );
		}
		if ( supports( 'inline' ) ) {
			toggles.appendChild( toggle( I18N.inline, !! field.inline, function ( on ) { update( function ( f ) { on ? f.inline = true : delete f.inline; } ); } ) );
		}
		pane.appendChild( toggles );

		// Width.
		var widthWrap = el( 'div', { class: 'maf-b-segmented' } );
		[ [ 'full', I18N.widthFull ], [ 'half', I18N.widthHalf ], [ 'third', I18N.widthThird ] ].forEach( function ( w ) {
			widthWrap.appendChild( el( 'button', { type: 'button', class: ( field.width || 'full' ) === w[0] ? 'is-active' : '', onClick: function () {
				update( function ( f ) { w[0] === 'full' ? delete f.width : f.width = w[0]; }, true );
			} }, [ w[1] ] ) );
		} );
		pane.appendChild( control( I18N.width, widthWrap ) );

		// Numeric constraints.
		if ( supports( 'min' ) || supports( 'maxlength' ) || supports( 'rows' ) ) {
			var row = el( 'div', { class: 'maf-b-row' } );
			var numType = field.type === 'date' ? 'date' : 'number';
			if ( supports( 'min' ) ) {
				row.appendChild( control( I18N.min, textInput( field.min, function ( v ) { update( function ( f ) { v === '' ? delete f.min : f.min = numType === 'number' ? Number( v ) : v; } ); }, { type: numType, class: 'small-text' } ) ) );
				row.appendChild( control( I18N.max, textInput( field.max, function ( v ) { update( function ( f ) { v === '' ? delete f.max : f.max = numType === 'number' ? Number( v ) : v; } ); }, { type: numType, class: 'small-text' } ) ) );
			}
			if ( supports( 'step' ) ) {
				row.appendChild( control( I18N.step, textInput( field.step, function ( v ) { update( function ( f ) { v === '' ? delete f.step : f.step = Number( v ); } ); }, { type: 'number', class: 'small-text', step: 'any' } ) ) );
			}
			if ( supports( 'maxlength' ) ) {
				row.appendChild( control( I18N.maxlength, textInput( field.maxlength, function ( v ) { update( function ( f ) { v === '' ? delete f.maxlength : f.maxlength = parseInt( v, 10 ); } ); }, { type: 'number', class: 'small-text', min: 1 } ) ) );
			}
			if ( supports( 'rows' ) ) {
				row.appendChild( control( I18N.rows, textInput( field.rows, function ( v ) { update( function ( f ) { v === '' ? delete f.rows : f.rows = parseInt( v, 10 ); } ); }, { type: 'number', class: 'small-text', min: 1 } ) ) );
			}
			pane.appendChild( row );
		}

		if ( supports( 'options' ) ) {
			pane.appendChild( renderOptionsEditor( field ) );
		}

		if ( supports( 'file' ) ) {
			pane.appendChild( renderFileSettings( field ) );
		}

		if ( ! def.static ) {
			pane.appendChild( renderConditionEditor( field, sIdx ) );
		}

		pane.appendChild( inspectorFooter() );
	}

	function renderOptionsEditor( field ) {
		var box = el( 'div', { class: 'maf-b-group' } );
		box.appendChild( el( 'h4', { text: I18N.options } ) );
		var list = el( 'div', { class: 'maf-b-options' } );
		var entries = Object.keys( field.options || {} ).map( function ( k ) { return [ k, field.options[ k ] ]; } );

		function persist() {
			update( function ( f ) {
				f.options = {};
				entries.forEach( function ( pair ) {
					if ( pair[0] !== '' ) {
						f.options[ pair[0] ] = pair[1];
					}
				} );
			} );
		}

		function draw() {
			list.innerHTML = '';
			entries.forEach( function ( pair, i ) {
				var rowEl = el( 'div', { class: 'maf-b-option', draggable: true } );
				var valIn = textInput( pair[0], function ( v ) { pair[0] = v; persist(); }, { placeholder: I18N.optionValue, dir: 'ltr', class: 'code' } );
				var labIn = textInput( pair[1], function ( v ) {
					pair[1] = v;
					if ( ! valIn.dataset.touched ) {
						pair[0] = slugify( v );
						valIn.value = pair[0];
					}
					persist();
				}, { placeholder: I18N.optionLabel } );
				valIn.addEventListener( 'input', function () { valIn.dataset.touched = '1'; } );
				rowEl.appendChild( el( 'span', { class: 'maf-b-handle' }, [ icon( 'move' ) ] ) );
				rowEl.appendChild( labIn );
				rowEl.appendChild( valIn );
				rowEl.appendChild( el( 'button', { type: 'button', class: 'maf-b-iconbtn is-danger', title: I18N.delete, onClick: function () { entries.splice( i, 1 ); draw(); persist(); } }, [ icon( 'no-alt' ) ] ) );

				rowEl.addEventListener( 'dragstart', function ( e ) { e.stopPropagation(); dragState = { kind: 'option', i: i }; e.dataTransfer.setData( 'text/plain', 'option' ); } );
				rowEl.addEventListener( 'dragover', function ( e ) { if ( dragState && dragState.kind === 'option' ) { e.preventDefault(); rowEl.classList.add( 'is-over' ); } } );
				rowEl.addEventListener( 'dragleave', function () { rowEl.classList.remove( 'is-over' ); } );
				rowEl.addEventListener( 'drop', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					if ( ! dragState || dragState.kind !== 'option' ) {
						return;
					}
					var moved = entries.splice( dragState.i, 1 )[0];
					entries.splice( i, 0, moved );
					dragState = null;
					draw();
					persist();
				} );
				list.appendChild( rowEl );
			} );
		}
		draw();
		box.appendChild( list );

		var bulk = el( 'textarea', { rows: 5, class: 'large-text code', hidden: true } );
		box.appendChild( el( 'div', { class: 'maf-b-options__actions' }, [
			el( 'button', { type: 'button', class: 'button', onClick: function () {
				entries.push( [ 'option_' + ( entries.length + 1 ), 'Option ' + ( entries.length + 1 ) ] );
				draw();
				persist();
				var inputs = list.querySelectorAll( 'input' );
				if ( inputs.length ) {
					inputs[ inputs.length - 2 ].focus();
					inputs[ inputs.length - 2 ].select();
				}
			} }, [ icon( 'plus' ), I18N.addOption ] ),
			el( 'button', { type: 'button', class: 'button-link', onClick: function () {
				bulk.hidden = ! bulk.hidden;
				bulkHelp.hidden = bulk.hidden;
				if ( ! bulk.hidden ) {
					bulk.value = entries.map( function ( p ) { return p[0] === slugify( p[1] ) ? p[1] : p[0] + ' | ' + p[1]; } ).join( '\n' );
					bulk.focus();
				}
			} }, [ I18N.bulkOptions ] ),
		] ) );
		var bulkHelp = el( 'p', { class: 'description', text: I18N.bulkOptionsHelp, hidden: true } );
		box.appendChild( bulk );
		box.appendChild( bulkHelp );
		bulk.addEventListener( 'input', function () {
			entries = bulk.value.split( '\n' ).map( function ( line ) {
				line = line.trim();
				if ( ! line ) {
					return null;
				}
				var parts = line.split( '|' );
				if ( parts.length > 1 ) {
					return [ parts[0].trim(), parts.slice( 1 ).join( '|' ).trim() ];
				}
				return [ slugify( line ), line ];
			} ).filter( Boolean );
			draw();
			persist();
		} );
		return box;
	}

	function renderFileSettings( field ) {
		var box = el( 'div', { class: 'maf-b-group' } );
		box.appendChild( el( 'h4', {}, [ icon( 'upload' ), ' ', TYPES.file.label ] ) );
		box.appendChild( control( I18N.fileAccept, textInput( ( field.accept || [] ).join( ', ' ), function ( v ) {
			update( function ( f ) {
				f.accept = v.split( /[\s,]+/ ).map( function ( x ) { return x.replace( /[^a-z0-9]/gi, '' ).toLowerCase(); } ).filter( Boolean );
			} );
		}, { dir: 'ltr', class: 'regular-text code' } ), I18N.fileAcceptHelp ) );
		box.appendChild( control( I18N.fileMaxSize, textInput( field.max_size, function ( v ) { update( function ( f ) { f.max_size = Math.max( 1, parseInt( v, 10 ) || 1 ); } ); }, { type: 'number', min: 1, class: 'small-text' } ) ) );
		box.appendChild( toggle( I18N.fileMultiple, !! field.multiple, function ( on ) { update( function ( f ) { f.multiple = on; }, true ); } ) );
		if ( field.multiple ) {
			box.appendChild( control( I18N.fileMaxFiles, textInput( field.max_files, function ( v ) { update( function ( f ) { f.max_files = Math.max( 1, parseInt( v, 10 ) || 1 ); } ); }, { type: 'number', min: 1, class: 'small-text' } ) ) );
		}
		return box;
	}

	function renderConditionEditor( field, sIdx ) {
		var box = el( 'div', { class: 'maf-b-group' } );
		box.appendChild( el( 'h4', {}, [ icon( 'randomize' ), ' ', I18N.conditional ] ) );

		// Candidate fields: same scope, excluding self and static types.
		var candidates = [];
		var pushFrom = function ( sec ) {
			sec.fields.forEach( function ( f ) {
				if ( f !== field && ! ( TYPES[ f.type ] || {} ).static ) {
					candidates.push( f );
				}
			} );
		};
		if ( schema[ sIdx ].repeater ) {
			pushFrom( schema[ sIdx ] );
		} else {
			schema.forEach( function ( s ) { if ( ! s.repeater ) { pushFrom( s ); } } );
		}

		box.appendChild( toggle( I18N.conditionalEnable, !! field.condition, function ( on ) {
			update( function ( f ) {
				if ( on ) {
					f.condition = { field: candidates.length ? candidates[0].key : '', operator: 'equals', value: '' };
				} else {
					delete f.condition;
				}
			}, true );
		} ) );

		if ( ! field.condition ) {
			return box;
		}
		if ( ! candidates.length ) {
			box.appendChild( el( 'p', { class: 'description', text: I18N.noCondFields } ) );
			return box;
		}

		var cond = field.condition;
		var fieldSel = el( 'select', {} );
		candidates.forEach( function ( c ) {
			fieldSel.appendChild( el( 'option', { value: c.key, selected: c.key === cond.field, text: ( c.label || c.key ) + ' (' + c.key + ')' } ) );
		} );
		fieldSel.addEventListener( 'change', function () { update( function ( f ) { f.condition.field = fieldSel.value; f.condition.value = ''; }, true ); } );

		var opSel = el( 'select', {} );
		[ [ 'equals', I18N.opEquals ], [ 'not_equals', I18N.opNotEquals ], [ 'contains', I18N.opContains ], [ 'not_empty', I18N.opNotEmpty ], [ 'empty', I18N.opEmpty ] ].forEach( function ( o ) {
			opSel.appendChild( el( 'option', { value: o[0], selected: ( cond.operator || 'equals' ) === o[0], text: o[1] } ) );
		} );
		opSel.addEventListener( 'change', function () { update( function ( f ) { f.condition.operator = opSel.value; }, true ); } );

		box.appendChild( control( I18N.condField, fieldSel ) );
		box.appendChild( control( I18N.condOperator, opSel ) );

		if ( [ 'not_empty', 'empty' ].indexOf( cond.operator || 'equals' ) === -1 ) {
			var dep = candidates.filter( function ( c ) { return c.key === cond.field; } )[0];
			var valueCtl;
			if ( dep && dep.options && Object.keys( dep.options ).length ) {
				valueCtl = el( 'select', {} );
				valueCtl.appendChild( el( 'option', { value: '', text: I18N.select } ) );
				Object.keys( dep.options ).forEach( function ( k ) {
					valueCtl.appendChild( el( 'option', { value: k, selected: k === cond.value, text: dep.options[ k ] } ) );
				} );
				valueCtl.addEventListener( 'change', function () { update( function ( f ) { f.condition.value = valueCtl.value; } ); } );
			} else if ( dep && dep.type === 'checkbox' ) {
				valueCtl = el( 'select', {} );
				[ [ '1', 'checked' ], [ '', 'unchecked' ] ].forEach( function ( o ) {
					valueCtl.appendChild( el( 'option', { value: o[0], selected: o[0] === cond.value, text: o[1] } ) );
				} );
				valueCtl.addEventListener( 'change', function () { update( function ( f ) { f.condition.value = valueCtl.value; } ); } );
			} else {
				valueCtl = textInput( cond.value, function ( v ) { update( function ( f ) { f.condition.value = v; } ); } );
			}
			box.appendChild( control( I18N.condValue, valueCtl ) );
		}

		return box;
	}

	function inspectorFooter() {
		return el( 'div', { class: 'maf-b-inspector__footer' }, [
			el( 'button', { type: 'button', class: 'button', onClick: duplicateSelected }, [ icon( 'admin-page' ), I18N.duplicate ] ),
			el( 'button', { type: 'button', class: 'button maf-b-danger', onClick: deleteSelected }, [ icon( 'trash' ), I18N.delete ] ),
		] );
	}

	/* ---------------------------- Preview ---------------------------- */

	function renderPreview() {
		var wrap = el( 'div', { class: 'maf-b-preview' } );
		wrap.appendChild( el( 'p', { class: 'maf-b-preview__note' }, [ icon( 'info-outline' ), I18N.previewNote ] ) );
		var form = el( 'div', { class: 'maf-form maf-b-preview__form', dir: MAF_BUILDER.isRtl ? 'rtl' : 'ltr' } );

		schema.forEach( function ( sec ) {
			var card = el( 'section', { class: 'maf-card' } );
			card.appendChild( el( 'h3', { class: 'maf-card__title', text: sec.title } ) );
			if ( sec.description ) {
				card.appendChild( el( 'p', { class: 'maf-card__desc', text: sec.description } ) );
			}
			var grid = el( 'div', { class: 'maf-grid' } );
			sec.fields.forEach( function ( f ) { grid.appendChild( previewField( f ) ); } );
			if ( sec.repeater ) {
				var rowEl = el( 'div', { class: 'maf-repeater__row' }, [ grid, el( 'button', { type: 'button', class: 'maf-repeater__remove', text: '×' } ) ] );
				card.appendChild( el( 'div', { class: 'maf-repeater' }, [ rowEl, el( 'button', { type: 'button', class: 'maf-repeater__add', text: '+ ' + ( sec.row_label || sec.title ) } ) ] ) );
			} else {
				card.appendChild( grid );
			}
			form.appendChild( card );
		} );
		form.appendChild( el( 'div', { class: 'maf-submit-row' }, [ el( 'button', { type: 'button', class: 'maf-submit-btn', text: 'Send Application', disabled: true } ) ] ) );
		wrap.appendChild( form );
		return wrap;
	}

	function previewField( f ) {
		var wrap = el( 'div', { class: 'maf-field maf-field--' + f.type + ' maf-field--w-' + ( f.width || 'full' ) } );
		if ( f.type === 'html' ) {
			wrap.appendChild( el( 'div', { class: 'maf-html', html: f.content || '' } ) );
			return wrap;
		}
		wrap.appendChild( el( 'label', {}, [ f.label, f.required ? el( 'span', { class: 'maf-required', text: '*' } ) : null ] ) );
		var input;
		switch ( f.type ) {
			case 'textarea':
				input = el( 'textarea', { rows: f.rows || 3, placeholder: f.placeholder || '', disabled: true } );
				break;
			case 'select':
			case 'country':
				input = el( 'select', { disabled: true } );
				input.appendChild( el( 'option', { text: I18N.select } ) );
				Object.keys( f.options || {} ).forEach( function ( k ) { input.appendChild( el( 'option', { text: f.options[ k ] } ) ); } );
				break;
			case 'radio':
			case 'checkbox_group':
				input = el( 'div', { class: 'maf-choices' + ( f.inline ? ' maf-choices--inline' : '' ) } );
				Object.keys( f.options || {} ).forEach( function ( k ) {
					input.appendChild( el( 'label', { class: 'maf-choice' }, [ el( 'input', { type: f.type === 'radio' ? 'radio' : 'checkbox', disabled: true } ), f.options[ k ] ] ) );
				} );
				break;
			case 'checkbox':
				input = el( 'label', { class: 'maf-choice' }, [ el( 'input', { type: 'checkbox', disabled: true } ), f.checkbox_label || f.label ] );
				break;
			case 'file':
				input = el( 'div', { class: 'maf-upload' }, [ icon( 'upload' ), el( 'span', { text: I18N.chooseFile } ), el( 'small', { text: ( f.accept || [] ).join( ', ' ) + ' · ≤ ' + f.max_size + 'MB' } ) ] );
				break;
			default:
				input = el( 'input', { type: f.type === 'tel_intl' ? 'tel' : ( f.type === 'date' || f.type === 'number' || f.type === 'email' || f.type === 'url' ? f.type : 'text' ), placeholder: f.placeholder || '', disabled: true } );
		}
		wrap.appendChild( input );
		if ( f.help ) {
			wrap.appendChild( el( 'small', { class: 'maf-field__help', text: f.help } ) );
		}
		if ( f.condition ) {
			wrap.appendChild( el( 'small', { class: 'maf-b-preview__cond' }, [ icon( 'randomize' ), f.condition.field + ' ' + ( f.condition.operator || 'equals' ) + ( f.condition.value ? ' "' + f.condition.value + '"' : '' ) ] ) );
		}
		return wrap;
	}

	/* ------------------------------ JSON ----------------------------- */

	function renderJsonTab() {
		var wrap = el( 'div', { class: 'maf-b-json' } );
		wrap.appendChild( el( 'p', { class: 'description', text: I18N.jsonHelp } ) );
		var ta = el( 'textarea', { id: 'maf-b-json-editor', class: 'large-text code', rows: 30, spellcheck: 'false', dir: 'ltr', value: JSON.stringify( schema, null, 2 ) } );
		wrap.appendChild( ta );
		var status = el( 'span', { class: 'maf-b-json__status', id: 'maf-b-json-status' } );
		wrap.appendChild( el( 'div', { class: 'maf-b-json__actions' }, [
			el( 'button', { type: 'button', class: 'button button-primary', onClick: function () { applyJson( false ); } }, [ I18N.applyJson ] ),
			el( 'button', { type: 'button', class: 'button', onClick: function () {
				ta.select();
				try {
					navigator.clipboard ? navigator.clipboard.writeText( ta.value ) : document.execCommand( 'copy' );
					status.textContent = I18N.copied;
					status.className = 'maf-b-json__status is-ok';
				} catch ( e ) {}
			} }, [ I18N.copyJson ] ),
			status,
		] ) );
		return wrap;
	}

	/** Parses the JSON tab and loads it into state. Returns success. */
	function applyJson( silent ) {
		var ta = document.getElementById( 'maf-b-json-editor' );
		var status = document.getElementById( 'maf-b-json-status' );
		if ( ! ta ) {
			return true;
		}
		try {
			var parsed = JSON.parse( ta.value );
			if ( ! Array.isArray( parsed ) ) {
				throw new Error( 'Root must be an array of sections.' );
			}
			parsed.forEach( function ( s ) { s.fields = Array.isArray( s.fields ) ? s.fields : []; } );
			if ( JSON.stringify( parsed ) !== JSON.stringify( schema ) ) {
				commit();
				schema = parsed;
				selected = null;
			}
			sync();
			if ( status && ! silent ) {
				status.textContent = I18N.jsonOk;
				status.className = 'maf-b-json__status is-ok';
			}
			return true;
		} catch ( e ) {
			if ( status ) {
				status.textContent = I18N.jsonErr + e.message;
				status.className = 'maf-b-json__status is-err';
			}
			return false;
		}
	}
} )();
