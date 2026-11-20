/**
 * Block checkout and cart: a date dropdown for each event section with more
 * than one date. Picking a date asks the server to recalculate, and the
 * section's options then show what runs on that date.
 */
( function () {
	var wp = window.wp || {};
	var wc = window.wc || {};
	var bc = wc.blocksCheckout;
	if ( ! wp.plugins || ! wp.element || ! wp.data || ! bc || ! bc.ExperimentalOrderShippingPackages || ! bc.extensionCartUpdate ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;

	function DatePickers() {
		var sections = useSelect( function ( select ) {
			var cart = select( 'wc/store/cart' ).getCartData();
			var ext = cart && cart.extensions ? cart.extensions[ 'fulfillment-events' ] : null;
			return ext && ext.sections ? ext.sections : [];
		}, [] );
		var busy = useState( false );
		if ( ! sections.length ) {
			return null;
		}
		return el(
			'div',
			{ className: 'fe-date-pickers', style: { marginBottom: '16px' } },
			sections.map( function ( section ) {
				var id = 'fe-date-' + section.package_id;
				return el(
					'div',
					{ key: section.group, className: 'fe-date-picker', style: { marginBottom: '12px' } },
					el( 'label', { htmlFor: id, style: { display: 'block', fontWeight: 600, marginBottom: '4px' } }, section.label ),
					el(
						'select',
						{
							id: id,
							value: section.selected,
							disabled: busy[ 0 ],
							style: { width: '100%', padding: '10px 12px', fontSize: 'inherit', borderRadius: '4px', border: '1px solid currentColor' },
							onChange: function ( event ) {
								busy[ 1 ]( true );
								Promise.resolve(
									bc.extensionCartUpdate( {
										namespace: 'fulfillment-events',
										data: { group: section.group, date: event.target.value },
									} )
								).finally( function () {
									busy[ 1 ]( false );
								} );
							},
						},
						section.options.map( function ( option ) {
							return el( 'option', { key: option.value, value: option.value }, option.label );
						} )
					)
				);
			} )
		);
	}

	wp.plugins.registerPlugin( 'fulfillment-events-dates', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( bc.ExperimentalOrderShippingPackages, null, el( DatePickers ) );
		},
	} );
} )();
