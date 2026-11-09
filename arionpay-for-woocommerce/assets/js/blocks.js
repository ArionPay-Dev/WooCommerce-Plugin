/* Adds ArionPay to the WooCommerce Checkout block. Plain JS, no build step. */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp.element ) {
		return;
	}

	var createElement = wp.element.createElement;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function ( text ) { return text; };
	var data = wc.wcSettings.getSetting( 'arionpay_data', {} );
	var label = decode( data.title || 'Cryptocurrency (ArionPay)' );
	var description = decode( data.description || '' );

	var Content = function () {
		return description ? createElement( 'div', null, description ) : null;
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'arionpay',
		label: createElement( 'span', null, label ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel: label,
		supports: { features: data.supports || [ 'products' ] }
	} );
}() );
