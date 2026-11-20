/* Fulfillment Events: the Event setup screen. */
( function () {
	'use strict';

	var templates = {
		date: { tpl: 'fe-date-template', rows: 'fe-date-rows', token: /__i__/g },
		exception: { tpl: 'fe-exception-template', rows: 'fe-exception-rows', token: /__x__/g },
		price: { tpl: 'fe-price-template', rows: 'fe-price-rows', token: /__p__/g }
	};

	function addRow( kind ) {
		var spec = templates[ kind ];
		var tpl = document.getElementById( spec.tpl );
		var rows = document.getElementById( spec.rows );
		if ( ! tpl || ! rows ) {
			return;
		}
		var next = parseInt( rows.getAttribute( 'data-next' ) || '0', 10 );
		// Keep indexes unique even after rows were removed.
		rows.setAttribute( 'data-next', String( next + 1 ) );
		rows.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( spec.token, 'n' + next ) );
		// Let WooCommerce turn new product search fields into search boxes.
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
		}
		var added = rows.lastElementChild;
		var first = added ? added.querySelector( 'input:not([type=hidden]), select' ) : null;
		if ( first ) {
			first.focus();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var add = event.target.closest ? event.target.closest( '[data-fe-add]' ) : null;
		if ( add ) {
			event.preventDefault();
			addRow( add.getAttribute( 'data-fe-add' ) );
			return;
		}
		var remove = event.target.closest ? event.target.closest( '[data-fe-remove]' ) : null;
		if ( remove ) {
			event.preventDefault();
			var row = remove.closest( '[data-fe-row]' );
			if ( row ) {
				row.remove();
			}
		}
	} );

	// Dim a service's time, place and zones when it isn't offered that day,
	// and an exception's dates when it takes the product out of the event.
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( 'radio' === target.type && target.closest && target.closest( '.fe-exception' ) ) {
			target.closest( '.fe-exception' ).classList.toggle( 'is-exclude', 'exclude' === target.value && target.checked );
		}
		if ( 'checkbox' === target.type && target.closest && target.parentElement && target.parentElement.classList.contains( 'fe-service__toggle' ) ) {
			target.closest( '.fe-service' ).classList.toggle( 'is-off', ! target.checked );
		}
	} );
}() );
