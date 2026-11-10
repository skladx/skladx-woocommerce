/**
 * SkladX pickup point picker for the Checkout block.
 *
 * Plain script (no build step): renders below the shipping options through
 * the ExperimentalOrderShippingPackages slot, opens the shared picker
 * (pickup.js) and saves the choice with extensionCartUpdate. A missing point
 * is registered as a checkout validation error, so the order cannot be placed
 * without it (the server validates again).
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var wc = window.wc || {};
	var picker = window.SkladXPickup;

	if ( ! wp.plugins || ! wp.element || ! wp.data || ! wc.blocksCheckout || ! picker ) {
		return;
	}

	var h = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var cfg = picker.config;
	var t = cfg.i18n || {};
	var ERROR_ID = 'skladx-pickup-point';
	var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages;

	function carrierFor( rate ) {
		var methods = cfg.methods || {};

		return methods[ rate.method_id + ':' + rate.instance_id ] || methods[ rate.method_id ] || '';
	}

	function chosenCarriers( packages ) {
		var carriers = [];

		( packages || [] ).forEach( function ( pkg ) {
			( pkg.shipping_rates || [] ).forEach( function ( rate ) {
				var carrier = rate.selected ? carrierFor( rate ) : '';

				if ( carrier && carriers.indexOf( carrier ) === -1 ) {
					carriers.push( carrier );
				}
			} );
		} );

		return carriers;
	}

	function PickupPicker() {
		var data = useSelect( function ( select ) {
			var cart = select( 'wc/store/cart' );
			var cartData = cart.getCartData() || {};
			var validation = select( 'wc/store/validation' );
			var payment = select( 'wc/store/payment' );

			return {
				packages: cart.getShippingRates(),
				points: ( ( cartData.extensions || {} )[ cfg.namespace ] || {} ).points || {},
				address: cartData.shippingAddress || {},
				payment: payment && payment.getActivePaymentMethod ? payment.getActivePaymentMethod() : '',
				validationError: validation ? validation.getValidationError( ERROR_ID ) : null,
			};
		}, [] );

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var carriers = chosenCarriers( data.packages );
		var missing = carriers.filter( function ( carrier ) {
			return ! data.points[ carrier ];
		} );

		useEffect( function () {
			var validation = wp.data.dispatch( 'wc/store/validation' );
			var errors = {};

			if ( missing.length ) {
				errors[ ERROR_ID ] = { message: t.missing, hidden: true };
				validation.setValidationErrors( errors );
			} else {
				validation.clearValidationError( ERROR_ID );
			}
		}, [ missing.join( ',' ) ] );

		useEffect( function () {
			return function () {
				wp.data.dispatch( 'wc/store/validation' ).clearValidationError( ERROR_ID );
			};
		}, [] );

		if ( ! carriers.length ) {
			return null;
		}

		function openPicker( carrier ) {
			setError( '' );
			picker.open( {
				carrier: carrier,
				country: data.address.country || cfg.defaultCountry,
				query: data.address.city || data.address.postcode || '',
				cod: picker.isCod( data.payment ),
				onSelect: function ( point, widget ) {
					setBusy( true );

					return wc.blocksCheckout.extensionCartUpdate( {
						namespace: cfg.namespace,
						data: { carrier: carrier, point_id: point.id, widget: widget || null },
					} ).then( function () {
						setBusy( false );
					}, function ( err ) {
						setBusy( false );
						throw new Error( ( err && err.message ) || t.error );
					} );
				},
			} );
		}

		var showValidation = data.validationError && ! data.validationError.hidden;

		return h( 'div', { className: 'skladx-pickup skladx-pickup--blocks' + ( showValidation ? ' has-error' : '' ) },
			carriers.map( function ( carrier ) {
				var point = data.points[ carrier ];

				return h( 'div', { key: carrier, className: 'skladx-pickup__carrier' },
					point ? h( 'div', { className: 'skladx-pickup__chosen' },
						h( 'span', { className: 'skladx-pickup__label' }, t.chosen ), ' ',
						h( 'strong', null, point.name ),
						h( 'br' ),
						h( 'span', { className: 'skladx-pickup__address' }, point.address )
					) : null,
					h( 'button', {
						type: 'button',
						className: 'wc-block-components-button wp-element-button skladx-pickup__button' + ( point ? ' outlined' : ' contained' ),
						disabled: busy,
						onClick: function () {
							openPicker( carrier );
						},
					}, h( 'span', { className: 'wc-block-components-button__text' }, point ? t.change : t.choose ) )
				);
			} ),
			showValidation ? h( 'div', { className: 'wc-block-components-validation-error skladx-pickup__error', role: 'alert' }, h( 'p', null, data.validationError.message ) ) : null,
			error ? h( 'div', { className: 'wc-block-components-validation-error skladx-pickup__error', role: 'alert' }, h( 'p', null, error ) ) : null
		);
	}

	wp.plugins.registerPlugin( 'skladx-pickup', {
		scope: 'woocommerce-checkout',
		render: function () {
			return h( Slot, null, h( PickupPicker ) );
		},
	} );
}() );
