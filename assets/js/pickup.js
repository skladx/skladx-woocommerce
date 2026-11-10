/**
 * SkladX pickup point picker: modal (search, list, map preview) shared by the
 * classic checkout and the Checkout block, plus the classic checkout glue.
 *
 * No build step and no dependencies. Points come from the plugin's REST
 * proxy; the choice is saved through the Store API (`cart/extensions`).
 */
( function () {
	'use strict';

	var cfg = window.skladxPickupConfig || {};
	var t = cfg.i18n || {};
	var overlay = null;
	var state = null;
	var lastFocus = null;
	var packetaLoading = null;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( value === null || value === undefined || value === false ) {
				return;
			}

			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );

		return node;
	}

	function request( url, options ) {
		return window.fetch( url, options ).then( function ( response ) {
			var nonce = response.headers.get( 'Nonce' );

			if ( nonce ) {
				cfg.storeApiNonce = nonce;
			}

			return response.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( ( body && body.message ) || t.error );
				}

				return body;
			} );
		} );
	}

	function fetchPoints( params ) {
		var query = Object.keys( params ).filter( function ( key ) {
			return params[ key ] !== '' && params[ key ] !== null && params[ key ] !== undefined && params[ key ] !== false;
		} ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} ).join( '&' );

		return request( cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + query, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} );
	}

	/**
	 * Saves the choice in the WooCommerce session (Store API cart/extensions).
	 */
	function save( carrier, pointId, widget ) {
		return request( cfg.storeApiUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', Nonce: cfg.storeApiNonce || '' },
			body: JSON.stringify( { namespace: cfg.namespace, data: { carrier: carrier, point_id: pointId, widget: widget || null } } ),
		} );
	}

	function isCod( paymentMethod ) {
		return ( cfg.codGateways || [] ).indexOf( paymentMethod ) !== -1;
	}

	function typeLabel( type ) {
		return { locker: t.locker, post_office: t.postOffice }[ type ] || t.point;
	}

	function mapUrl( point ) {
		var d = 0.004;

		return 'https://www.openstreetmap.org/export/embed.html?bbox=' + [ point.lng - d, point.lat - d / 2, point.lng + d, point.lat + d / 2 ].join( ',' ) +
			'&layer=mapnik&marker=' + point.lat + ',' + point.lng;
	}

	function showMap( point ) {
		var frame = overlay && overlay.querySelector( '.skladx-pp__map iframe' );

		if ( ! frame ) {
			return;
		}

		if ( point && typeof point.lat === 'number' && typeof point.lng === 'number' ) {
			frame.src = mapUrl( point );
			frame.parentNode.hidden = false;
		} else {
			frame.parentNode.hidden = true;
		}
	}

	function setStatus( text, isError ) {
		var status = overlay.querySelector( '.skladx-pp__status' );

		status.textContent = text || '';
		status.className = 'skladx-pp__status' + ( isError ? ' skladx-pp__status--error' : '' );
	}

	function renderPoint( point ) {
		var badges = [ el( 'span', { 'class': 'skladx-pp__badge', text: typeLabel( point.type ) } ) ];

		if ( point.cod === true ) {
			badges.push( el( 'span', { 'class': 'skladx-pp__badge skladx-pp__badge--ok', text: t.cod } ) );
		} else if ( point.cod === false ) {
			badges.push( el( 'span', { 'class': 'skladx-pp__badge skladx-pp__badge--no', text: t.noCod } ) );
		}

		if ( point.card ) {
			badges.push( el( 'span', { 'class': 'skladx-pp__badge', text: t.card } ) );
		}

		if ( typeof point.distance_km === 'number' ) {
			badges.push( el( 'span', { 'class': 'skladx-pp__badge', text: point.distance_km.toLocaleString( undefined, { maximumFractionDigits: 1 } ) + ' km' } ) );
		}

		var details = null;

		if ( point.hours && point.hours.length ) {
			details = el( 'details', { 'class': 'skladx-pp__hours' }, [
				el( 'summary', { text: t.hours } ),
				el( 'ul', {}, point.hours.map( function ( line ) {
					return el( 'li', { text: line } );
				} ) ),
			] );
		}

		var selectButton = el( 'button', {
			type: 'button',
			'class': 'button skladx-pp__select',
			text: t.select,
			onclick: function ( event ) {
				event.stopPropagation();
				choose( point, null );
			},
		} );

		var item = el( 'li', {
			'class': 'skladx-pp__item',
			tabindex: '-1',
			onclick: function () {
				Array.prototype.forEach.call( overlay.querySelectorAll( '.skladx-pp__item--active' ), function ( node ) {
					node.classList.remove( 'skladx-pp__item--active' );
				} );
				item.classList.add( 'skladx-pp__item--active' );
				showMap( point );
			},
		}, [
			el( 'div', { 'class': 'skladx-pp__info' }, [
				el( 'strong', { 'class': 'skladx-pp__name', text: point.name } ),
				el( 'span', { 'class': 'skladx-pp__address', text: point.address } ),
				point.closing_on ? el( 'span', { 'class': 'skladx-pp__closing', text: ( t.closing || '%s' ).replace( '%s', point.closing_on ) } ) : null,
				el( 'span', { 'class': 'skladx-pp__badges' }, badges ),
				details,
			] ),
			selectButton,
		] );

		return item;
	}

	function load( append ) {
		var list = overlay.querySelector( '.skladx-pp__list' );
		var more = overlay.querySelector( '.skladx-pp__more' );
		var token = {};

		state.token = token;
		more.hidden = true;

		if ( ! append ) {
			list.innerHTML = '';
			state.cursor = null;
		}

		setStatus( t.loading );

		fetchPoints( {
			carrier: state.carrier,
			country: state.country,
			q: state.near ? '' : state.query,
			near: state.near || '',
			cod: state.cod ? 1 : '',
			cursor: append ? state.cursor : '',
		} ).then( function ( body ) {
			if ( state === null || state.token !== token ) {
				return;
			}

			( body.points || [] ).forEach( function ( point ) {
				list.appendChild( renderPoint( point ) );
			} );

			state.cursor = body.next_cursor || null;
			more.hidden = ! body.has_more;
			setStatus( list.children.length ? '' : t.empty );

			if ( ! append && list.firstChild ) {
				list.firstChild.click();
			} else if ( ! list.children.length ) {
				showMap( null );
			}
		} ).catch( function ( error ) {
			if ( state !== null && state.token === token ) {
				setStatus( error.message || t.error, true );
			}
		} );
	}

	function choose( point, widget ) {
		setStatus( t.loading );

		Promise.resolve( state.onSelect( point, widget ) ).then( function () {
			close();
		} ).catch( function ( error ) {
			setStatus( ( error && error.message ) || t.error, true );
		} );
	}

	function close() {
		if ( overlay ) {
			overlay.remove();
			overlay = null;
		}

		state = null;
		document.removeEventListener( 'keydown', onKey, true );
		document.documentElement.classList.remove( 'skladx-pp-open' );

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	function onKey( event ) {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();
		} else if ( event.key === 'Tab' && overlay ) {
			var focusable = overlay.querySelectorAll( 'button:not([disabled]):not([hidden]), input, summary, iframe' );
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	}

	function loadPacketaWidget() {
		if ( window.Packeta && window.Packeta.Widget ) {
			return Promise.resolve();
		}

		if ( ! packetaLoading ) {
			packetaLoading = new Promise( function ( resolve, reject ) {
				var script = el( 'script', { src: 'https://widget.packeta.com/v6/www/js/library.js', async: true } );

				script.onload = resolve;
				script.onerror = function () {
					packetaLoading = null;
					reject( new Error( t.error ) );
				};
				document.head.appendChild( script );
			} );
		}

		return packetaLoading;
	}

	function openPacketaWidget( options ) {
		return loadPacketaWidget().then( function () {
			window.Packeta.Widget.pick( cfg.packetaKey, function ( point ) {
				if ( ! point ) {
					return;
				}

				Promise.resolve( options.onSelect( { id: String( point.id ) }, {
					name: point.name || '',
					street: point.street || '',
					city: point.city || '',
					zip: point.zip || '',
					country: ( point.country || '' ).toUpperCase(),
				} ) ).catch( function ( error ) {
					window.alert( ( error && error.message ) || t.error ); // eslint-disable-line no-alert
				} );
			}, {
				country: ( options.country || cfg.defaultCountry || 'sk' ).toLowerCase(),
				language: cfg.language || 'sk',
				appIdentity: 'skladx-woocommerce',
			} );
		} );
	}

	/**
	 * Opens the picker.
	 *
	 * @param {Object}   options
	 * @param {string}   options.carrier  Carrier code.
	 * @param {string}   options.country  Two-letter country code.
	 * @param {string}   options.query    Initial search (e.g. city).
	 * @param {boolean}  options.cod      Show only points accepting cash on delivery.
	 * @param {Function} options.onSelect Called with (point, widgetData); may return a Promise.
	 */
	function open( options ) {
		if ( options.carrier === 'packeta' && cfg.packetaKey ) {
			return openPacketaWidget( options ).catch( function () {
				openModal( options );
			} );
		}

		openModal( options );
	}

	function openModal( options ) {
		close();
		lastFocus = document.activeElement;

		state = {
			carrier: options.carrier,
			country: options.country || cfg.defaultCountry || 'SK',
			query: options.query || '',
			cod: !! options.cod,
			near: '',
			cursor: null,
			onSelect: options.onSelect,
		};

		var input = el( 'input', {
			type: 'search',
			'class': 'skladx-pp__input',
			placeholder: t.search,
			'aria-label': t.search,
			value: state.query,
			autocomplete: 'off',
		} );
		var timer = null;

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				state.query = input.value.trim();
				state.near = '';
				load( false );
			}, 350 );
		} );

		var nearButton = navigator.geolocation ? el( 'button', {
			type: 'button',
			'class': 'button skladx-pp__near',
			text: t.nearMe,
			onclick: function () {
				setStatus( t.loading );
				navigator.geolocation.getCurrentPosition( function ( position ) {
					if ( ! state ) {
						return;
					}

					state.near = position.coords.latitude.toFixed( 4 ) + ',' + position.coords.longitude.toFixed( 4 );
					input.value = '';
					load( false );
				}, function () {
					setStatus( t.geoError, true );
				}, { timeout: 10000, maximumAge: 600000 } );
			},
		} ) : null;

		overlay = el( 'div', {
			'class': 'skladx-pp-overlay',
			onclick: function ( event ) {
				if ( event.target === overlay ) {
					close();
				}
			},
		}, [
			el( 'div', { 'class': 'skladx-pp', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'skladx-pp-title' }, [
				el( 'div', { 'class': 'skladx-pp__header' }, [
					el( 'h2', { id: 'skladx-pp-title', 'class': 'skladx-pp__title', text: t.title } ),
					el( 'button', { type: 'button', 'class': 'skladx-pp__close', 'aria-label': t.close, text: '×', onclick: close } ),
				] ),
				el( 'form', {
					'class': 'skladx-pp__search',
					onsubmit: function ( event ) {
						event.preventDefault();
						window.clearTimeout( timer );
						state.query = input.value.trim();
						state.near = '';
						load( false );
					},
				}, [ input, el( 'button', { type: 'submit', 'class': 'button', text: t.searchButton } ), nearButton ] ),
				state.cod ? el( 'p', { 'class': 'skladx-pp__hint', text: t.onlyCodPoints } ) : null,
				el( 'div', { 'class': 'skladx-pp__status', 'aria-live': 'polite' } ),
				el( 'div', { 'class': 'skladx-pp__body' }, [
					el( 'div', { 'class': 'skladx-pp__results' }, [
						el( 'ul', { 'class': 'skladx-pp__list' } ),
						el( 'button', { type: 'button', 'class': 'button skladx-pp__more', hidden: true, text: t.more, onclick: function () {
							load( true );
						} } ),
					] ),
					el( 'div', { 'class': 'skladx-pp__map', hidden: true }, [
						el( 'iframe', { title: t.map, loading: 'lazy', referrerpolicy: 'no-referrer' } ),
					] ),
				] ),
			] ),
		] );

		document.body.appendChild( overlay );
		document.documentElement.classList.add( 'skladx-pp-open' );
		document.addEventListener( 'keydown', onKey, true );
		input.focus();
		load( false );
	}

	window.SkladXPickup = { open: open, close: close, save: save, isCod: isCod, config: cfg };

	/* ---------------------------------------------------------------------
	 * Classic checkout
	 * ------------------------------------------------------------------- */

	function fieldValue( name ) {
		var shipToDifferent = document.getElementById( 'ship-to-different-address-checkbox' );
		var prefix = shipToDifferent && shipToDifferent.checked ? 'shipping_' : 'billing_';
		var field = document.getElementById( prefix + name ) || document.getElementById( 'billing_' + name );

		return field ? String( field.value || '' ).trim() : '';
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-skladx-pickup-open]' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var payment = document.querySelector( 'input[name="payment_method"]:checked' );
		var carrier = button.getAttribute( 'data-skladx-pickup-open' );

		open( {
			carrier: carrier,
			country: fieldValue( 'country' ),
			query: fieldValue( 'city' ) || fieldValue( 'postcode' ),
			cod: payment ? isCod( payment.value ) : false,
			onSelect: function ( point, widget ) {
				return save( carrier, point.id, widget ).then( function () {
					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'update_checkout' );
					}
				} );
			},
		} );
	} );
}() );
