/**
 * SkladX receipt editor (WooCommerce → SkladX receipts).
 *
 * Tabs: Detail, Edit receipt (header + items with product autocomplete),
 * Bulk add (pasted lines), Scan EAN (USB scanner = keyboard, Enter adds)
 * and Attachments. SkladX is the source of truth: "Save draft" creates
 * (POST) or replaces (PUT) the draft, "Send to warehouse" announces it.
 *
 * No build step and no dependencies. Every call goes through the plugin's
 * admin-ajax proxy with a nonce; the SkladX API key never reaches the browser.
 */
( function () {
	'use strict';

	var cfg = window.skladxInbound || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'skladx-inbound-app' );

	if ( ! root ) {
		return;
	}

	var state = {
		receipt: null,
		products: {},
		header: emptyHeader(),
		items: [],
		tab: 'edit',
		dirty: false,
		busy: false,
		token: uuid(),
		message: null,
		errors: [],
		fieldErrors: {},
		lineErrors: {},
		uploaded: [],
		scanLog: [],
		scanSettings: { quantity: 1, batch: '', expires_at: '' },
		bulkText: '',
		codeCache: {},
	};
	var nextKey = 1;
	var pollTimer = null;
	var audio = null;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function emptyHeader() {
		return {
			name: '',
			expected_at: '',
			carrier: '',
			carrier_other: '',
			tracking_number: '',
			reference: '',
			is_blind: false,
			note: '',
			warehouse_code: '',
		};
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}

		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;

			return ( c === 'x' ? r : ( r & 0x3 ) | 0x8 ).toString( 16 );
		} );
	}

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;

		return String( text || '' ).replace( /%(\d+\$)?([sd])/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];

			return value === undefined ? match : String( value );
		} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( value === null || value === undefined || value === false ) {
				return;
			}

			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'className' ) {
				node.className = value;
			} else if ( key === 'value' ) {
				node.value = value;
			} else if ( key === 'checked' ) {
				node.checked = !! value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child !== null && child !== undefined && child !== false ) {
				node.appendChild( typeof child === 'string' || typeof child === 'number' ? document.createTextNode( String( child ) ) : child );
			}
		} );

		return node;
	}

	function formatDate( value, withTime ) {
		if ( ! value ) {
			return '—';
		}

		var date = /^\d{4}-\d{2}-\d{2}$/.test( value ) ? new Date( value + 'T00:00:00' ) : new Date( value );

		if ( isNaN( date.getTime() ) ) {
			return value;
		}

		try {
			return withTime ? date.toLocaleString( cfg.locale || undefined ) : date.toLocaleDateString( cfg.locale || undefined );
		} catch ( e ) {
			return value;
		}
	}

	function number( value ) {
		try {
			return Number( value ).toLocaleString( cfg.locale || undefined );
		} catch ( e ) {
			return String( value );
		}
	}

	function request( action, data, file ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else if ( value !== undefined && value !== null ) {
				body.append( key, value );
			}
		} );

		if ( file ) {
			body.append( 'file', file );
		}

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: t.errorGeneric } };
				} );
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					return json.data;
				}

				var error = new Error( ( json && json.data && json.data.message ) || t.errorGeneric );

				error.errors = ( json && json.data && json.data.errors ) || [];
				throw error;
			} );
	}

	function beep( ok ) {
		try {
			audio = audio || new ( window.AudioContext || window.webkitAudioContext )();

			var oscillator = audio.createOscillator();
			var gain = audio.createGain();

			oscillator.frequency.value = ok ? 1200 : 220;
			gain.gain.value = 0.05;
			oscillator.connect( gain );
			gain.connect( audio.destination );
			oscillator.start();
			oscillator.stop( audio.currentTime + ( ok ? 0.08 : 0.3 ) );
		} catch ( e ) {
			// Sound is optional.
		}
	}

	/* ------------------------------------------------------------------ */
	/* State                                                              */
	/* ------------------------------------------------------------------ */

	function isNew() {
		return ! state.receipt || ! state.receipt.id;
	}

	function isEditable() {
		return isNew() || !! state.receipt.editable;
	}

	function tabs() {
		var list = [];

		if ( ! isNew() ) {
			list.push( [ 'detail', t.tabDetail ] );
		}

		if ( isEditable() ) {
			list.push( [ 'edit', t.tabEdit ], [ 'bulk', t.tabBulk ], [ 'scan', t.tabScan ] );
		}

		list.push( [ 'attachments', t.tabAttachments ] );

		return list;
	}

	function load( data ) {
		var receipt = data.receipt;

		state.receipt = receipt;
		state.products = Object.assign( {}, state.products, data.products || {} );
		state.header = {
			name: receipt.name || '',
			expected_at: receipt.expected_at || '',
			carrier: '',
			carrier_other: '',
			tracking_number: receipt.tracking_number || '',
			reference: receipt.reference || '',
			is_blind: !! receipt.is_blind,
			note: receipt.note || '',
			warehouse_code: ( receipt.warehouse && receipt.warehouse.code ) || '',
		};

		var known = ( cfg.carriers || [] ).some( function ( option ) {
			return option.value === receipt.carrier;
		} );

		if ( ! receipt.carrier ) {
			state.header.carrier = '';
		} else if ( known && receipt.carrier !== cfg.other ) {
			state.header.carrier = receipt.carrier;
		} else {
			state.header.carrier = cfg.other;
			state.header.carrier_other = receipt.carrier;
		}

		state.items = ( receipt.items || [] ).map( function ( item ) {
			return {
				key: nextKey++,
				product: state.products[ item.sku ] || { id: 0, sku: item.sku, name: item.name || item.sku, ean: '', in_skladx: true, missing: true },
				quantity: item.quantity_expected,
				batch: item.batch || '',
				expires_at: item.expires_at || '',
			};
		} );
		state.dirty = false;
		state.lineErrors = {};
		state.fieldErrors = {};
	}

	function totals() {
		return state.items.reduce(
			function ( sum, item ) {
				sum.lines++;
				sum.pieces += parseInt( item.quantity, 10 ) || 0;

				return sum;
			},
			{ lines: 0, pieces: 0 }
		);
	}

	function unknownProducts() {
		var seen = {};

		return state.items
			.map( function ( item ) {
				return item.product;
			} )
			.filter( function ( product ) {
				if ( ! product.id || product.in_skladx || seen[ product.id ] ) {
					return false;
				}

				seen[ product.id ] = true;

				return true;
			} );
	}

	/**
	 * Adds pieces to the line with the same product, batch and expiry, or a new line.
	 */
	function addItem( product, quantity, batch, expiresAt ) {
		batch = ( batch || '' ).trim();
		expiresAt = expiresAt || '';
		state.products[ product.sku ] = product;

		var existing = state.items.filter( function ( item ) {
			return item.product.sku === product.sku && item.batch === batch && item.expires_at === expiresAt;
		} )[ 0 ];

		if ( existing ) {
			existing.quantity = ( parseInt( existing.quantity, 10 ) || 0 ) + quantity;
			existing.product = product;
		} else {
			existing = { key: nextKey++, product: product, quantity: quantity, batch: batch, expires_at: expiresAt };
			state.items.push( existing );
		}

		state.dirty = true;

		return existing;
	}

	function payload() {
		var header = state.header;

		return {
			name: header.name,
			expected_at: header.expected_at,
			carrier: header.carrier,
			carrier_other: header.carrier_other,
			tracking_number: header.carrier === cfg.personal ? '' : header.tracking_number,
			reference: header.reference,
			is_blind: header.is_blind,
			note: header.note,
			warehouse_code: header.warehouse_code,
			items: state.items.map( function ( item ) {
				return { sku: item.product.sku, quantity: item.quantity, batch: item.batch, expires_at: item.expires_at };
			} ),
		};
	}

	function setMessage( type, text, errors ) {
		state.message = text ? { type: type, text: text } : null;
		state.errors = errors || [];
		state.fieldErrors = {};
		state.lineErrors = {};

		state.errors.forEach( function ( error ) {
			var match = /^items\.(\d+)\.(\w+)$/.exec( error.field || '' );

			if ( match ) {
				state.lineErrors[ match[ 1 ] ] = state.lineErrors[ match[ 1 ] ] || {};
				state.lineErrors[ match[ 1 ] ][ match[ 2 ] ] = true;
			} else if ( error.field ) {
				state.fieldErrors[ error.field ] = true;
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                            */
	/* ------------------------------------------------------------------ */

	function run( promise, done ) {
		state.busy = true;
		render();

		return promise
			.then( function ( result ) {
				state.busy = false;
				done( result );
				render();

				return result;
			} )
			.catch( function ( error ) {
				state.busy = false;
				setMessage( 'error', error.message, error.errors );
				render();
				throw error;
			} );
	}

	function save() {
		var wasNew = isNew();

		return run(
			request( 'skladx_inbound_save', {
				id: wasNew ? '' : state.receipt.id,
				token: state.token,
				receipt: JSON.stringify( payload() ),
			} ),
			function ( data ) {
				load( data );
				setMessage( 'success', t.saved );

				if ( wasNew && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', data.receipt.edit_url );
				}
			}
		);
	}

	function announce() {
		if ( ! window.confirm( t.announceConfirm ) ) {
			return;
		}

		var saved = state.dirty || isNew() ? save() : Promise.resolve();

		saved
			.then( function () {
				return run( request( 'skladx_inbound_announce', { id: state.receipt.id } ), function ( data ) {
					load( data );
					state.tab = 'detail';
					setMessage( 'success', t.announced );
					schedulePoll();
				} );
			} )
			.catch( function () {} );
	}

	function cancelReceipt() {
		if ( ! window.confirm( t.cancelConfirm ) ) {
			return;
		}

		run( request( 'skladx_inbound_cancel', { id: state.receipt.id } ), function ( data ) {
			load( data );
			state.tab = 'detail';
			setMessage( 'success', t.cancelled );
		} ).catch( function () {} );
	}

	function refresh( quiet ) {
		if ( isNew() || state.busy || state.dirty ) {
			return;
		}

		var call = request( 'skladx_inbound_get', { id: state.receipt.id } );

		if ( quiet ) {
			call.then( function ( data ) {
				var before = state.receipt.updated_at;

				load( data );

				if ( before !== data.receipt.updated_at ) {
					render();
				}
			} ).catch( function () {} );

			return;
		}

		run( call, function ( data ) {
			load( data );
			setMessage( null, null );
		} ).catch( function () {} );
	}

	function schedulePoll() {
		window.clearInterval( pollTimer );

		if ( isNew() || state.receipt.editable || state.receipt.status === 'received' || state.receipt.status === 'cancelled' ) {
			return;
		}

		pollTimer = window.setInterval( function () {
			if ( ! document.hidden ) {
				refresh( true );
			}
		}, 60000 );
	}

	function syncProducts( products ) {
		run(
			request( 'skladx_inbound_sync_products', {
				product_ids: products.map( function ( product ) {
					return product.id;
				} ),
			} ),
			function ( data ) {
				var updated = data.products || {};

				Object.keys( updated ).forEach( function ( sku ) {
					state.products[ sku ] = updated[ sku ];
				} );
				state.items.forEach( function ( item ) {
					if ( updated[ item.product.sku ] ) {
						item.product = updated[ item.product.sku ];
					}
				} );

				var failed = products.filter( function ( product ) {
					return updated[ product.sku ] && ! updated[ product.sku ].in_skladx;
				} );

				if ( failed.length ) {
					setMessage(
						'error',
						t.syncError,
						failed.map( function ( product ) {
							return { message: product.sku + ': ' + ( updated[ product.sku ].sync_error || t.errorGeneric ) };
						} )
					);
				} else {
					setMessage( 'success', t.synced );
				}
			}
		).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	function render() {
		var focused = document.activeElement && root.contains( document.activeElement ) ? document.activeElement.getAttribute( 'data-focus' ) : null;

		root.textContent = '';
		root.appendChild( renderHeader() );
		root.appendChild( renderMessage() );
		root.appendChild( renderTabs() );

		var panel = el( 'div', { className: 'skladx-inbound-panel', role: 'tabpanel' } );

		switch ( state.tab ) {
			case 'detail':
				renderDetail( panel );
				break;
			case 'bulk':
				renderBulk( panel );
				break;
			case 'scan':
				renderScan( panel );
				break;
			case 'attachments':
				renderAttachments( panel );
				break;
			default:
				renderEdit( panel );
		}

		root.appendChild( panel );

		if ( focused ) {
			var node = root.querySelector( '[data-focus="' + focused + '"]' );

			if ( node ) {
				node.focus();
			}
		}
	}

	function badge( receipt ) {
		return el( 'span', { className: 'skladx-badge skladx-badge--' + ( receipt.tone || 'unknown' ), text: receipt.status_text || receipt.status } );
	}

	function button( label, onClick, className, disabled ) {
		return el( 'button', { type: 'button', className: 'button ' + ( className || '' ), disabled: disabled || state.busy, onclick: onClick, text: label } );
	}

	function renderHeader() {
		var receipt = state.receipt;
		var title = isNew() ? t.newReceipt : format( t.receiptTitle, receipt.number || receipt.id );
		var actions = el( 'div', { className: 'skladx-inbound-toolbar' } );

		if ( isEditable() ) {
			actions.appendChild( button( state.busy ? t.saving : t.saveDraft, function () {
				save().catch( function () {} );
			} ) );
			actions.appendChild( button( t.announce, announce, 'button-primary' ) );
		}

		if ( ! isNew() && receipt.label_url && receipt.status !== 'cancelled' ) {
			actions.appendChild( el( 'a', { className: 'button', href: receipt.label_url, target: '_blank', rel: 'noopener', text: t.printLabel, title: t.labelHelp } ) );
		}

		if ( ! isNew() && ! receipt.editable ) {
			actions.appendChild( button( t.refresh, function () {
				refresh( false );
			} ) );
		}

		if ( ! isNew() && receipt.cancellable ) {
			actions.appendChild( button( t.cancel, cancelReceipt, 'button-link-delete skladx-inbound-cancel' ) );
		}

		return el( 'div', { className: 'skladx-inbound-head' }, [
			el( 'h1', { className: 'wp-heading-inline' }, [ title, ' ', isNew() ? null : badge( receipt ) ] ),
			actions,
			el( 'hr', { className: 'wp-header-end' } ),
		] );
	}

	function renderMessage() {
		var box = el( 'div', { 'aria-live': 'polite', className: 'skladx-inbound-message' } );

		if ( ! state.message ) {
			return box;
		}

		var notice = el( 'div', { className: 'notice inline notice-' + state.message.type }, [ el( 'p', { text: state.message.text } ) ] );

		if ( state.errors.length ) {
			notice.appendChild(
				el(
					'ul',
					{ className: 'skladx-inbound-errors' },
					state.errors.map( function ( error ) {
						return el( 'li', { text: error.message } );
					} )
				)
			);
		}

		box.appendChild( notice );

		return box;
	}

	function renderTabs() {
		var list = tabs();

		if ( ! list.some( function ( tab ) {
			return tab[ 0 ] === state.tab;
		} ) ) {
			state.tab = list[ 0 ][ 0 ];
		}

		return el(
			'nav',
			{ className: 'nav-tab-wrapper skladx-inbound-tabs', role: 'tablist' },
			list.map( function ( tab ) {
				return el( 'a', {
					href: '#' + tab[ 0 ],
					role: 'tab',
					'aria-selected': state.tab === tab[ 0 ] ? 'true' : 'false',
					className: 'nav-tab' + ( state.tab === tab[ 0 ] ? ' nav-tab-active' : '' ),
					text: tab[ 1 ],
					onclick: function ( event ) {
						event.preventDefault();
						state.tab = tab[ 0 ];
						render();
					},
				} );
			} )
		);
	}

	/* Detail ------------------------------------------------------------ */

	function renderDetail( panel ) {
		var receipt = state.receipt;
		var rows = [
			[ t.number, receipt.number || receipt.id ],
			[ t.status, badge( receipt ) ],
			[ t.name, receipt.name ],
			[ t.referenceLabel, receipt.reference || '—' ],
			[ t.expectedAt, formatDate( receipt.expected_at ) ],
			[ t.carrier, receipt.carrier_text || '—' ],
			[
				t.tracking,
				receipt.tracking_number
					? receipt.tracking_link
						? el( 'a', { href: receipt.tracking_link, target: '_blank', rel: 'noopener noreferrer', text: receipt.tracking_number } )
						: el( 'code', { text: receipt.tracking_number } )
					: '—',
			],
			[ t.blind, receipt.is_blind ? t.yes : t.no ],
			[ t.warehouse, ( receipt.warehouse && ( receipt.warehouse.name || receipt.warehouse.code ) ) || '—' ],
			[ t.created, formatDate( receipt.created_at, true ) ],
			[ t.announcedAt, formatDate( receipt.announced_at, true ) ],
			[ t.receivedAt, formatDate( receipt.received_at, true ) ],
		];

		if ( receipt.note ) {
			rows.push( [ t.note, receipt.note ] );
		}

		if ( ! receipt.editable && receipt.status !== 'cancelled' && receipt.status !== 'received' ) {
			panel.appendChild( el( 'p', { className: 'description', text: t.readOnly + ' ' + t.labelHelp } ) );
		}

		panel.appendChild(
			el(
				'dl',
				{ className: 'skladx-inbound-summary' },
				rows.reduce( function ( list, row ) {
					list.push( el( 'dt', { text: row[ 0 ] } ), el( 'dd', {}, [ row[ 1 ] ] ) );

					return list;
				}, [] )
			)
		);

		var hasReceived = receipt.totals && receipt.totals.received !== null && receipt.totals.received !== undefined;

		/* The verdict (differences / all fine) only once the receipt is closed; while counting, just the numbers. */
		if ( hasReceived && receipt.status === 'received' ) {
			panel.appendChild(
				el( 'div', { className: 'notice inline ' + ( receipt.has_differences ? 'notice-warning' : 'notice-success' ) }, [
					el( 'p', { text: receipt.has_differences ? t.differencesFound : t.receivedOk } ),
				] )
			);
		}

		var head = [ t.product, t.sku, t.batch, t.expiry, t.expected ];

		if ( hasReceived ) {
			head.push( t.received, t.difference );
		}

		var body = ( receipt.items || [] ).map( function ( item ) {
			var product = state.products[ item.sku ];
			var cells = [
				el( 'td', {}, [ product && product.edit_url ? el( 'a', { href: product.edit_url, text: product.name } ) : item.name || item.sku ] ),
				el( 'td', {}, [ el( 'code', { text: item.sku } ) ] ),
				el( 'td', { text: item.batch || '—' } ),
				el( 'td', { text: item.expires_at ? formatDate( item.expires_at ) : '—' } ),
				el( 'td', { className: 'num', text: number( item.quantity_expected ) } ),
			];

			if ( hasReceived ) {
				var diff = item.difference;

				cells.push(
					el( 'td', { className: 'num', text: item.quantity_received === null ? '—' : number( item.quantity_received ) } ),
					el( 'td', { className: 'num' }, [ diffNode( diff ) ] )
				);
			}

			return el( 'tr', { className: hasReceived && diff !== 0 ? 'skladx-row-diff' : '' }, cells );
		} );

		var foot = [ el( 'th', { colspan: 4, text: t.total } ), el( 'th', { className: 'num', text: number( receipt.totals.expected ) } ) ];

		if ( hasReceived ) {
			foot.push( el( 'th', { className: 'num', text: number( receipt.totals.received ) } ), el( 'th', { className: 'num' }, [ diffNode( receipt.totals.difference ) ] ) );
		}

		panel.appendChild(
			el( 'table', { className: 'widefat striped skladx-inbound-items' }, [
				el( 'thead', {}, [ el( 'tr', {}, head.map( function ( label, index ) {
					return el( 'th', { scope: 'col', className: index >= 4 ? 'num' : '', text: label } );
				} ) ) ] ),
				el( 'tbody', {}, body.length ? body : [ el( 'tr', {}, [ el( 'td', { colspan: head.length, text: t.noItems } ) ] ) ] ),
				el( 'tfoot', {}, [ el( 'tr', {}, foot ) ] ),
			] )
		);

		if ( receipt.label_zpl_url && receipt.status !== 'cancelled' ) {
			panel.appendChild( el( 'p', {}, [ el( 'a', { href: receipt.label_zpl_url, text: t.labelZpl } ) ] ) );
		}
	}

	function diffNode( diff ) {
		if ( diff === null || diff === undefined ) {
			return '—';
		}

		return el( 'span', { className: 'skladx-diff ' + ( diff < 0 ? 'is-minus' : diff > 0 ? 'is-plus' : 'is-zero' ), text: ( diff > 0 ? '+' : '' ) + number( diff ) } );
	}

	/* Edit -------------------------------------------------------------- */

	function field( key, label, input, help, required ) {
		var id = 'skladx-inbound-' + key;

		input.id = id;
		input.setAttribute( 'data-focus', key );

		if ( state.fieldErrors[ key ] ) {
			input.setAttribute( 'aria-invalid', 'true' );
		}

		return el( 'div', { className: 'skladx-field skladx-field--' + key + ( state.fieldErrors[ key ] ? ' has-error' : '' ) }, [
			el( 'label', { for: id }, [ label, required ? el( 'span', { className: 'required', 'aria-hidden': 'true', text: ' *' } ) : null ] ),
			input,
			help ? el( 'p', { className: 'description', text: help } ) : null,
		] );
	}

	function bindHeader( input, key, isCheckbox ) {
		input.addEventListener( isCheckbox ? 'change' : 'input', function () {
			state.header[ key ] = isCheckbox ? input.checked : input.value;
			state.dirty = true;

			if ( key === 'carrier' ) {
				render();
			}
		} );

		return input;
	}

	function renderEdit( panel ) {
		var header = state.header;
		var carrierSelect = el( 'select', {}, [ el( 'option', { value: '', text: t.chooseCarrier } ) ].concat(
			( cfg.carriers || [] ).map( function ( option ) {
				return el( 'option', { value: option.value, text: option.label, selected: header.carrier === option.value } );
			} )
		) );

		carrierSelect.value = header.carrier;

		var grid = el( 'div', { className: 'skladx-inbound-form' }, [
			field( 'name', t.name, bindHeader( el( 'input', { type: 'text', className: 'regular-text', value: header.name, maxlength: 191, placeholder: t.namePlaceholder, required: true } ), 'name' ), null, true ),
			field( 'expected_at', t.expectedAt, bindHeader( el( 'input', { type: 'date', value: header.expected_at, required: true } ), 'expected_at' ), null, true ),
			field( 'carrier', t.carrier, bindHeader( carrierSelect, 'carrier' ) ),
			header.carrier === cfg.other ? field( 'carrier_other', t.carrierOther, bindHeader( el( 'input', { type: 'text', value: header.carrier_other, maxlength: 64 } ), 'carrier_other' ), null, true ) : null,
			header.carrier === cfg.personal ? null : field( 'tracking_number', t.tracking, bindHeader( el( 'input', { type: 'text', value: header.tracking_number, maxlength: 64, autocomplete: 'off' } ), 'tracking_number' ) ),
			field( 'reference', t.reference, bindHeader( el( 'input', { type: 'text', value: header.reference, maxlength: 64 } ), 'reference' ), t.referenceHelp ),
			el( 'div', { className: 'skladx-field skladx-field--blind' }, [
				el( 'label', {}, [ bindHeader( el( 'input', { type: 'checkbox', checked: header.is_blind, 'data-focus': 'is_blind' } ), 'is_blind', true ), ' ', t.blind ] ),
				el( 'p', { className: 'description', text: t.blindHelp } ),
			] ),
			field( 'note', t.note, bindHeader( el( 'textarea', { rows: 2, maxlength: 1000, value: header.note } ), 'note' ) ),
		] );

		panel.appendChild( grid );
		panel.appendChild( el( 'h2', { text: t.items } ) );
		panel.appendChild( renderAddRow() );
		panel.appendChild( renderItems() );
	}

	function renderAddRow() {
		var selected = null;
		var search = el( 'input', { type: 'search', className: 'skladx-ac-input', placeholder: t.productSearch, autocomplete: 'off', 'data-focus': 'add-product', 'aria-autocomplete': 'list', 'aria-label': t.product } );
		var quantity = el( 'input', { type: 'number', min: 1, step: 1, value: 1, className: 'small-text', 'aria-label': t.quantity, 'data-focus': 'add-quantity' } );
		var batch = el( 'input', { type: 'text', maxlength: 64, placeholder: t.batch, 'aria-label': t.batch, className: 'skladx-batch' } );
		var expiry = el( 'input', { type: 'date', 'aria-label': t.expiry } );
		var list = el( 'ul', { className: 'skladx-ac-list', role: 'listbox', hidden: true } );
		var timer = null;
		var results = [];
		var active = -1;
		var sequence = 0;

		function choose( product ) {
			selected = product;
			search.value = product.name + ' (' + product.sku + ')';
			list.hidden = true;
			quantity.focus();
			quantity.select();
		}

		function show( items, text ) {
			results = items;
			active = items.length ? 0 : -1;
			list.textContent = '';

			if ( text ) {
				list.appendChild( el( 'li', { className: 'skladx-ac-empty', text: text } ) );
			}

			items.forEach( function ( product, index ) {
				list.appendChild(
					el(
						'li',
						{
							role: 'option',
							className: index === active ? 'is-active' : '',
							onmousedown: function ( event ) {
								event.preventDefault();
								choose( product );
							},
						},
						[
							el( 'strong', { text: product.name } ),
							el( 'span', { className: 'skladx-ac-meta', text: t.sku + ' ' + product.sku + ( product.ean ? ' · ' + t.ean + ' ' + product.ean : '' ) } ),
							product.in_skladx ? null : el( 'span', { className: 'skladx-warn', text: t.notInSkladx } ),
						]
					)
				);
			} );
			list.hidden = false;
		}

		function highlight() {
			Array.prototype.forEach.call( list.querySelectorAll( '[role="option"]' ), function ( node, index ) {
				node.className = index === active ? 'is-active' : '';
			} );
		}

		search.addEventListener( 'input', function () {
			selected = null;
			window.clearTimeout( timer );

			var term = search.value.trim();

			if ( term.length < 2 ) {
				list.hidden = true;

				return;
			}

			timer = window.setTimeout( function () {
				var current = ++sequence;

				show( [], t.searching );
				request( 'skladx_inbound_products', { q: term } )
					.then( function ( data ) {
						if ( current === sequence ) {
							show( data.products || [], data.products && data.products.length ? null : t.noResults );
						}
					} )
					.catch( function ( error ) {
						if ( current === sequence ) {
							show( [], error.message );
						}
					} );
			}, 250 );
		} );

		search.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden || ! results.length ) {
				return;
			}

			if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
				event.preventDefault();
				active = ( active + ( event.key === 'ArrowDown' ? 1 : -1 ) + results.length ) % results.length;
				highlight();
			} else if ( event.key === 'Enter' ) {
				event.preventDefault();
				choose( results[ Math.max( 0, active ) ] );
			} else if ( event.key === 'Escape' ) {
				list.hidden = true;
			}
		} );

		search.addEventListener( 'blur', function () {
			window.setTimeout( function () {
				list.hidden = true;
			}, 150 );
		} );

		function add() {
			var pieces = parseInt( quantity.value, 10 );

			if ( ! selected ) {
				setMessage( 'error', t.chooseProduct );
				render();

				return;
			}

			if ( ! pieces || pieces < 1 ) {
				quantity.focus();

				return;
			}

			addItem( selected, pieces, batch.value, expiry.value );
			setMessage( null, null );
			render();

			var next = root.querySelector( '[data-focus="add-product"]' );

			if ( next ) {
				next.focus();
			}
		}

		quantity.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				add();
			}
		} );

		return el( 'div', { className: 'skladx-inbound-add' }, [
			el( 'div', { className: 'skladx-ac' }, [ search, list ] ),
			el( 'label', {}, [ el( 'span', { text: t.quantity } ), quantity ] ),
			el( 'label', {}, [ el( 'span', { text: t.batch } ), batch ] ),
			el( 'label', {}, [ el( 'span', { text: t.expiry } ), expiry ] ),
			button( t.addItem, add, 'button-secondary' ),
		] );
	}

	function renderItems() {
		var wrap = el( 'div', { className: 'skladx-inbound-items-wrap' } );
		var unknown = unknownProducts();

		if ( unknown.length ) {
			wrap.appendChild(
				el( 'div', { className: 'notice inline notice-warning' }, [
					el( 'p', {}, [
						t.notInSkladxHelp + ' ',
						button( format( t.syncAll, unknown.length ), function () {
							syncProducts( unknown );
						}, 'button-small' ),
					] ),
				] )
			);
		}

		var rows = state.items.map( function ( item, index ) {
			var errors = state.lineErrors[ index ] || {};
			var product = item.product;
			var status = null;

			if ( product.missing ) {
				status = el( 'span', { className: 'skladx-warn', text: t.notInShop } );
			} else if ( ! product.in_skladx ) {
				status = el( 'span', { className: 'skladx-warn', title: product.sync_error ? t.syncError + ' ' + product.sync_error : t.notInSkladxHelp }, [
					t.notInSkladx + ' ',
					button( t.sync, function () {
						syncProducts( [ product ] );
					}, 'button-small' ),
				] );
			}

			function input( key, attrs ) {
				var node = el( 'input', Object.assign( { value: item[ key ], 'data-focus': 'item-' + item.key + '-' + key, 'aria-invalid': errors[ key ] ? 'true' : null }, attrs ) );

				node.addEventListener( 'input', function () {
					item[ key ] = node.value;
					state.dirty = true;

					if ( key === 'quantity' ) {
						var sum = root.querySelector( '.skladx-inbound-totals' );

						if ( sum ) {
							var total = totals();

							sum.textContent = format( t.totals, total.lines, total.pieces );
						}
					}
				} );

				return node;
			}

			return el( 'tr', { className: Object.keys( errors ).length ? 'has-error' : '' }, [
				el( 'td', {}, [ product.edit_url ? el( 'a', { href: product.edit_url, target: '_blank', rel: 'noopener', text: product.name } ) : product.name, status ? el( 'br' ) : null, status ] ),
				el( 'td', {}, [ el( 'code', { text: product.sku } ) ] ),
				el( 'td', { text: product.ean || '—' } ),
				el( 'td', {}, [ input( 'quantity', { type: 'number', min: 1, step: 1, className: 'small-text', 'aria-label': t.quantity } ) ] ),
				el( 'td', {}, [ input( 'batch', { type: 'text', maxlength: 64, className: 'skladx-batch', 'aria-label': t.batch } ) ] ),
				el( 'td', {}, [ input( 'expires_at', { type: 'date', 'aria-label': t.expiry } ) ] ),
				el( 'td', {}, [
					el( 'button', {
						type: 'button',
						className: 'button-link button-link-delete',
						'aria-label': t.remove + ' ' + product.name,
						text: '×',
						onclick: function () {
							state.items.splice( index, 1 );
							state.dirty = true;
							state.lineErrors = {};
							render();
						},
					} ),
				] ),
			] );
		} );

		var total = totals();

		wrap.appendChild(
			el( 'table', { className: 'widefat striped skladx-inbound-items' }, [
				el( 'thead', {}, [
					el( 'tr', {}, [ t.product, t.sku, t.ean, t.quantity, t.batch, t.expiry, '' ].map( function ( label ) {
						return el( 'th', { scope: 'col', text: label } );
					} ) ),
				] ),
				el( 'tbody', {}, rows.length ? rows : [ el( 'tr', {}, [ el( 'td', { colspan: 7, text: t.noItems } ) ] ) ] ),
			] )
		);
		wrap.appendChild( el( 'p', { className: 'skladx-inbound-totals', text: format( t.totals, total.lines, total.pieces ) } ) );

		return wrap;
	}

	/* Bulk -------------------------------------------------------------- */

	function renderBulk( panel ) {
		var textarea = el( 'textarea', { rows: 12, className: 'large-text code', placeholder: t.bulkPlaceholder, 'data-focus': 'bulk', 'aria-label': t.tabBulk, value: state.bulkText } );

		textarea.addEventListener( 'input', function () {
			state.bulkText = textarea.value;
		} );

		panel.appendChild( el( 'p', { className: 'description', text: t.bulkHelp } ) );
		panel.appendChild( textarea );
		panel.appendChild(
			el( 'p', {}, [
				button( t.bulkAdd, function () {
					if ( ! textarea.value.trim() ) {
						setMessage( 'error', t.bulkEmpty );
						render();

						return;
					}

					run( request( 'skladx_inbound_parse', { text: textarea.value } ), function ( data ) {
						( data.rows || [] ).forEach( function ( row ) {
							addItem( row.product, row.quantity, row.batch, row.expires_at );
						} );

						var added = ( data.rows || [] ).length;

						if ( data.errors && data.errors.length ) {
							setMessage( added ? 'warning' : 'error', ( added ? format( t.bulkAdded, added ) + ' ' : '' ) + t.bulkErrors, data.errors );
						} else {
							state.bulkText = '';
							setMessage( 'success', format( t.bulkAdded, added ) );
							state.tab = 'edit';
						}
					} ).catch( function () {} );
				}, 'button-primary' ),
			] )
		);
	}

	/* Scan -------------------------------------------------------------- */

	function renderScan( panel ) {
		var settings = state.scanSettings;
		var input = el( 'input', { type: 'text', className: 'skladx-scan-input', placeholder: t.scanPlaceholder, autocomplete: 'off', autofocus: true, 'data-focus': 'scan', 'aria-label': t.scanPlaceholder } );
		var quantity = el( 'input', { type: 'number', min: 1, step: 1, value: settings.quantity, className: 'small-text' } );
		var batch = el( 'input', { type: 'text', maxlength: 64, value: settings.batch, className: 'skladx-batch' } );
		var expiry = el( 'input', { type: 'date', value: settings.expires_at } );
		var log = el( 'ul', { className: 'skladx-scan-log', 'aria-live': 'polite' } );

		quantity.addEventListener( 'input', function () {
			settings.quantity = Math.max( 1, parseInt( quantity.value, 10 ) || 1 );
		} );
		batch.addEventListener( 'input', function () {
			settings.batch = batch.value;
		} );
		expiry.addEventListener( 'input', function () {
			settings.expires_at = expiry.value;
		} );

		function logLine( ok, text ) {
			state.scanLog.unshift( { ok: ok, text: text } );
			state.scanLog = state.scanLog.slice( 0, 15 );
			drawLog();
			beep( ok );
		}

		function drawLog() {
			log.textContent = '';
			state.scanLog.forEach( function ( entry ) {
				log.appendChild( el( 'li', { className: entry.ok ? 'is-ok' : 'is-error', text: entry.text } ) );
			} );
		}

		function apply( product, code ) {
			var line = addItem( product, settings.quantity, settings.batch, settings.expires_at );

			logLine( true, code + ' → ' + format( t.scanAdded, product.name, line.quantity ) );

			var sum = root.querySelector( '.skladx-inbound-totals' );
			var total = totals();

			if ( sum ) {
				sum.textContent = format( t.totals, total.lines, total.pieces );
			}
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Enter' ) {
				return;
			}

			event.preventDefault();

			var code = input.value.trim();

			input.value = '';

			if ( ! code ) {
				return;
			}

			if ( state.codeCache[ code ] ) {
				apply( state.codeCache[ code ], code );

				return;
			}

			request( 'skladx_inbound_resolve', { code: code } )
				.then( function ( data ) {
					state.codeCache[ code ] = data.product;
					apply( data.product, code );
				} )
				.catch( function ( error ) {
					logLine( false, error.message );
				} );
		} );

		drawLog();

		panel.appendChild( el( 'p', { className: 'description', text: t.scanHelp } ) );
		panel.appendChild( input );
		panel.appendChild(
			el( 'div', { className: 'skladx-scan-settings' }, [
				el( 'label', {}, [ el( 'span', { text: t.scanQuantity } ), quantity ] ),
				el( 'label', {}, [ el( 'span', { text: t.scanBatch } ), batch ] ),
				el( 'label', {}, [ el( 'span', { text: t.scanExpiry } ), expiry ] ),
			] )
		);
		panel.appendChild( log );
		panel.appendChild( el( 'p', { className: 'description', text: t.scanSaveHint } ) );

		var total = totals();

		panel.appendChild( el( 'p', { className: 'skladx-inbound-totals', text: format( t.totals, total.lines, total.pieces ) } ) );

		window.setTimeout( function () {
			input.focus();
		}, 0 );
	}

	/* Attachments ------------------------------------------------------- */

	function renderAttachments( panel ) {
		if ( isNew() ) {
			panel.appendChild( el( 'p', { text: t.attachmentsFirst } ) );

			return;
		}

		var files = el( 'input', { type: 'file', multiple: true, accept: '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp' } );
		var list = ( state.receipt.attachments || [] ).map( function ( attachment ) {
			return attachment.name || attachment.id;
		} );

		state.uploaded.forEach( function ( name ) {
			if ( list.indexOf( name ) === -1 ) {
				list.push( name );
			}
		} );

		panel.appendChild( el( 'p', { className: 'description', text: t.attachmentsHelp } ) );
		panel.appendChild(
			el( 'p', {}, [
				files,
				' ',
				button( state.busy ? t.uploading : t.upload, function () {
					var queue = Array.prototype.slice.call( files.files || [] );

					if ( ! queue.length ) {
						return;
					}

					var tooLarge = queue.filter( function ( file ) {
						return file.size > cfg.maxUpload;
					} );

					if ( tooLarge.length ) {
						setMessage( 'error', t.tooLarge, tooLarge.map( function ( file ) {
							return { message: file.name };
						} ) );
						render();

						return;
					}

					var chain = Promise.resolve();

					queue.forEach( function ( file ) {
						chain = chain.then( function () {
							return run( request( 'skladx_inbound_attach', { id: state.receipt.id }, file ), function ( data ) {
								var dirty = state.dirty;
								var items = state.items;

								state.uploaded.push( data.uploaded || file.name );
								state.receipt.attachments = data.receipt.attachments;
								state.items = items;
								state.dirty = dirty;
								setMessage( 'success', format( t.uploaded, data.uploaded || file.name ) );
							} );
						} );
					} );
					chain.catch( function () {} );
				}, 'button-primary' ),
			] )
		);
		panel.appendChild(
			list.length
				? el( 'ul', { className: 'skladx-inbound-attachments' }, list.map( function ( name ) {
					return el( 'li', { text: name } );
				} ) )
				: el( 'p', { className: 'description', text: t.noAttachments } )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                              */
	/* ------------------------------------------------------------------ */

	if ( cfg.initial && cfg.initial.receipt ) {
		load( cfg.initial );
		state.tab = state.receipt.editable ? 'edit' : 'detail';
		schedulePoll();
	} else {
		var tomorrow = new Date( Date.now() + 86400000 );

		state.header.expected_at = tomorrow.getFullYear() + '-' + String( tomorrow.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( tomorrow.getDate() ).padStart( 2, '0' );
		state.tab = 'edit';
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.dirty ) {
			event.preventDefault();
			event.returnValue = t.unsaved;

			return t.unsaved;
		}
	} );

	render();
}() );
