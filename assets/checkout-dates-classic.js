/**
 * Classic checkout and cart: saves the picked date, then refreshes the
 * shipping options.
 */
jQuery( function ( $ ) {
	$( document.body ).on( 'change', 'select.fe-date-select', function () {
		var $select = $( this );
		$select.prop( 'disabled', true );
		$.post( window.feCheckoutDates.ajaxUrl, {
			action: 'fe_set_date',
			nonce: window.feCheckoutDates.nonce,
			group: $select.data( 'group' ),
			date: $select.val(),
		} ).always( function () {
			if ( $( 'form.checkout' ).length ) {
				$( document.body ).trigger( 'update_checkout' );
			} else {
				$( document.body ).trigger( 'wc_update_cart' );
			}
		} );
	} );
} );
