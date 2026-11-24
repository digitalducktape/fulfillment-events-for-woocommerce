/**
 * Event fields on an order's shipping line: the dates follow the event, the
 * services follow the date, and the time, place and usual fee follow both.
 */
( function ( $ ) {
	'use strict';

	var events = ( window.feOrderEvent && window.feOrderEvent.events ) || {};

	function find( list, key, value ) {
		return ( list || [] ).filter( function ( row ) {
			return row[ key ] === value;
		} )[ 0 ];
	}

	function options( $select, rows, key, selected ) {
		$select.empty();
		rows.forEach( function ( row ) {
			$select.append( $( '<option>' ).val( row[ key ] ).text( row.label ) );
		} );
		if ( rows.length ) {
			$select.val( selected );
		}
	}

	function parts( el ) {
		var $row = $( el ).closest( 'tr.shipping' );
		return {
			row: $row,
			event: $row.find( 'select.fe-line-event' ),
			date: $row.find( 'select.fe-line-date' ),
			type: $row.find( 'select.fe-line-type' ),
			window: $row.find( 'input.fe-line-window' ),
			location: $row.find( 'select.fe-line-location' ),
			fees: $row.find( '.fe-line-fees' ),
			method: $row.find( 'select.shipping_method' )
		};
	}

	function show( p, key, on ) {
		p.row.find( '[data-fe-field="' + key + '"]' ).prop( 'hidden', ! on );
	}

	function dates( p ) {
		return events[ 'e' + p.event.val() ] || [];
	}

	function onEvent( p ) {
		var rows  = dates( p );
		var first = find( rows, 'open', true ) || find( rows, 'past', false ) || rows[ 0 ];
		options( p.date, rows, 'date', first && first.date );
		onDate( p );
	}

	function onDate( p ) {
		var date  = find( dates( p ), 'date', p.date.val() );
		var types = date ? date.types : [];
		var keep  = find( types, 'type', p.type.val() ) || find( types, 'type', ( p.method.val() || '' ).replace( /^fe_/, '' ) ) || types[ 0 ];
		options( p.type, types, 'type', keep && keep.type );
		onType( p );
	}

	function onType( p ) {
		var date  = find( dates( p ), 'date', p.date.val() );
		var offer = find( date ? date.types : [], 'type', p.type.val() );
		var on    = !! offer;
		show( p, 'date', on );
		show( p, 'type', on );
		show( p, 'window', on );
		show( p, 'location', on && 'pickup' === offer.type && p.location.find( 'option' ).length > 0 );
		p.window.val( on ? offer.window : '' );
		p.fees.text( on ? offer.fees : '' );
		if ( ! on ) {
			return;
		}
		if ( 'pickup' === offer.type ) {
			p.location.val( offer.location );
		}
		if ( p.method.find( 'option[value="fe_' + offer.type + '"]' ).length ) {
			p.method.val( 'fe_' + offer.type );
		}
	}

	$( document )
		.on( 'change', 'tr.shipping select.fe-line-event', function () {
			onEvent( parts( this ) );
		} )
		.on( 'change', 'tr.shipping select.fe-line-date', function () {
			onDate( parts( this ) );
		} )
		.on( 'change', 'tr.shipping select.fe-line-type', function () {
			onType( parts( this ) );
		} )
		.on( 'change', 'tr.shipping select.shipping_method', function () {
			var p    = parts( this );
			var type = ( p.method.val() || '' ).replace( /^fe_/, '' );
			if ( p.event.val() && p.type.val() !== type && p.type.find( 'option[value="' + type + '"]' ).length ) {
				p.type.val( type );
				onType( p );
			}
		} );
}( window.jQuery ) );
