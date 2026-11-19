/* Fulfillment Events: event editor date rows and zone grid. */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Zone grid: show the price box only for "Custom price", and recolour the cell.
		document.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target.classList && target.classList.contains( 'fe-zmode' ) ) {
				var row = target.closest( '.fe-zrow' );
				row.classList.toggle( 'is-custom', 'on' === target.value );
				if ( 'on' === target.value ) {
					row.querySelector( 'input[type="number"]' ).focus();
				}
				var cell = target.closest( '.fe-zcell' );
				var modes = Array.prototype.map.call( cell.querySelectorAll( '.fe-zmode' ), function ( s ) {
					return s.value;
				} );
				var state = 'off' === modes[ 0 ] ? 'off' : ( modes.some( function ( m ) {
					return 'inherit' !== m;
				} ) ? 'override' : 'default' );
				cell.classList.remove( 'fe-zcell--default', 'fe-zcell--override', 'fe-zcell--off' );
				cell.classList.add( 'fe-zcell--' + state );
			}
			// Dates box: dim the time and place of a type that isn't offered.
			if ( target.type === 'checkbox' && target.closest && target.closest( '.fe-offer' ) ) {
				target.closest( '.fe-offer' ).classList.toggle( 'is-off', ! target.checked );
				var place = target.name && /\[pickup\]$/.test( target.name ) ? target.closest( 'tr' ).querySelector( '.fe-place' ) : null;
				if ( place ) {
					place.classList.toggle( 'is-off', ! target.checked );
				}
			}
		} );

		var rows = document.getElementById( 'fe-dates-rows' );
		var tpl = document.getElementById( 'fe-date-template' );
		var add = document.getElementById( 'fe-add-date' );
		if ( ! rows || ! tpl || ! add ) {
			return;
		}

		var next = rows.querySelectorAll( '.fe-date-row' ).length;

		add.addEventListener( 'click', function () {
			var html = tpl.innerHTML.replace( /__i__/g, String( next ) );
			next += 1;
			rows.insertAdjacentHTML( 'beforeend', html );
		} );

		rows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.fe-remove-date' ) : null;
			if ( button ) {
				var row = button.closest( '.fe-date-row' );
				if ( row ) {
					row.remove();
				}
			}
		} );
	} );
}() );
