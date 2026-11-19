/* Fulfillment Events: add and remove date rows in the event editor. */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
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
