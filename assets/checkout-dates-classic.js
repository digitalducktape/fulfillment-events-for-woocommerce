/**
 * Classic checkout and cart: puts each section's date picker above its
 * shipping options, saves the picked date, then refreshes the options.
 */
jQuery( function ( $ ) {
	function place() {
		$( '.fe-date-picker' ).each( function () {
			var $picker = $( this );
			var $list = $picker.closest( 'ul' );
			if ( $list.length ) {
				$picker.insertBefore( $list );
			}
		} );
	}
	place();
	$( document.body ).on( 'updated_checkout updated_cart_totals updated_shipping_method updated_wc_div', place );

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
