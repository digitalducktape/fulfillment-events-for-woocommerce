<?php
/**
 * Event fields on an order's shipping line in wp-admin.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Lets staff put an order in an event: the fields sit in the shipping line's
 * edit panel, and save with the line ("Save") or the order ("Create" and
 * "Update"), which WooCommerce runs through the same function. Works with
 * HPOS and legacy storage.
 */
class Fe_Order_Event_Admin {

	const FIELD = 'fe_order_event';

	/**
	 * Per-request catalog, see Fe_Order_Event::catalog().
	 *
	 * @var array|null
	 */
	private static $catalog = null;

	/**
	 * Shipping line titles before the save, item id => title.
	 *
	 * @var array<int,string>
	 */
	private static $titles = array();

	/**
	 * Lines whose choice was refused in this request, item id => true.
	 *
	 * @var array<int,bool>
	 */
	private static $refused = array();

	/**
	 * Whether a line with an event was deleted in this request.
	 *
	 * @var bool
	 */
	private static $removed = false;

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_before_order_itemmeta', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'woocommerce_before_save_order_items', array( __CLASS__, 'remember_titles' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( __CLASS__, 'before_delete' ) );
		add_action( 'woocommerce_ajax_order_items_removed', array( __CLASS__, 'after_delete' ), 10, 4 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Why an order's event can't be changed here, '' when it can.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function locked_reason( $order ) {
		if ( $order->get_meta( Fe_Meta::SPLIT_DONE ) || $order->get_meta( Fe_Meta::CHILDREN ) ) {
			return __( 'This order was split. Change the event on the split orders.', 'fulfillment-events-for-woocommerce' );
		}
		if ( 'yes' === $order->get_meta( Fe_Meta::WILL_SPLIT ) ) {
			return __( 'This order will be split once it is paid. Change the event on the split orders.', 'fulfillment-events-for-woocommerce' );
		}
		return '';
	}

	/**
	 * The fe_* meta of a shipping line.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping line.
	 * @return array<string,string>
	 */
	private static function line_meta( $item ) {
		$meta = array();
		foreach ( Fe_Order_Stamper::RATE_KEYS as $key ) {
			$value = (string) $item->get_meta( 'fe_' . $key );
			if ( '' !== $value ) {
				$meta[ 'fe_' . $key ] = $value;
			}
		}
		return $meta;
	}

	/**
	 * A shipping line's fulfillment.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping line.
	 * @return array|null
	 */
	private static function current( $item ) {
		$meta = self::line_meta( $item );
		if ( empty( $meta['fe_date'] ) || empty( $meta['fe_type'] ) ) {
			return null;
		}
		return Fe_Order_Stamper::fulfillment_from_rate_meta( $meta );
	}

	/**
	 * Location rows.
	 *
	 * @return array[]
	 */
	private static function locations() {
		static $rows = null;
		if ( null === $rows ) {
			$rows = Fe_Location_Repo::all();
		}
		return $rows;
	}

	/**
	 * Event methods in shipping zones: {zone_id, name, type, cost, window}.
	 *
	 * @return array[]
	 */
	private static function zones() {
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = array( (int) $zone['zone_id'], (string) $zone['zone_name'], $zone['shipping_methods'] );
		}
		$rest    = new WC_Shipping_Zone( 0 );
		$zones[] = array( 0, __( 'Locations not covered by your other zones', 'fulfillment-events-for-woocommerce' ), $rest->get_shipping_methods() );

		$out = array();
		foreach ( $zones as $zone ) {
			foreach ( (array) $zone[2] as $method ) {
				$type = 0 === strpos( (string) $method->id, 'fe_' ) ? substr( (string) $method->id, 3 ) : '';
				if ( ! in_array( $type, Fe_Meta::TYPES, true ) || ( isset( $method->enabled ) && 'yes' !== $method->enabled ) ) {
					continue;
				}
				$out[] = array(
					'zone_id' => $zone[0],
					'name'    => $zone[1],
					'type'    => $type,
					'cost'    => (float) $method->get_option( 'cost', 0 ),
					'window'  => Fe_Event_Repo::clean_window( (string) $method->get_option( 'window', '' ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Everything staff can choose.
	 *
	 * @return array See Fe_Order_Event::catalog().
	 */
	private static function catalog() {
		if ( null === self::$catalog ) {
			$events = Fe_Event_Repo::all_for_staff( wp_date( 'Y-m-d' ) );
			$rules  = array();
			foreach ( $events as $event ) {
				$rules[ $event['id'] ] = Fe_Zone_Rule_Repo::for_event( $event['id'] );
			}
			$locations = array();
			foreach ( self::locations() as $loc ) {
				$locations[ (int) $loc['id'] ] = $loc;
			}
			self::$catalog = Fe_Order_Event::catalog(
				$events,
				self::zones(),
				$rules,
				$locations,
				Fe_Location_Repo::default_id(),
				wp_date( 'Y-m-d' ),
				array( 'Fe_Zone_Grid', 'short_date' ),
				__( "We'll coordinate the time with you", 'fulfillment-events-for-woocommerce' )
			);
		}
		return self::$catalog;
	}

	/**
	 * An event's name in the list.
	 *
	 * @param array $event Catalog event.
	 * @return string
	 */
	private static function event_label( array $event ) {
		/* translators: %s: event name */
		return $event['draft'] ? sprintf( __( '%s (not published)', 'fulfillment-events-for-woocommerce' ), $event['title'] ) : $event['title'];
	}

	/**
	 * A date's name in the list.
	 *
	 * @param array $date Catalog date.
	 * @return string
	 */
	private static function date_label( array $date ) {
		if ( $date['past'] ) {
			/* translators: %s: date */
			return sprintf( __( '%s (past)', 'fulfillment-events-for-woocommerce' ), $date['label'] );
		}
		if ( $date['closed'] ) {
			/* translators: %s: date */
			return sprintf( __( '%s (closed to customers)', 'fulfillment-events-for-woocommerce' ), $date['label'] );
		}
		return $date['label'];
	}

	/**
	 * Usual fees text.
	 *
	 * @param array[] $fees List of [zone name, cost].
	 * @return string Plain text, '' when no zone offers it.
	 */
	private static function fees_text( array $fees ) {
		if ( ! $fees ) {
			return '';
		}
		$parts = array();
		foreach ( $fees as $fee ) {
			$cost    = $fee[1] > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $fee[1] ) ), ENT_QUOTES, 'UTF-8' ) : __( 'free', 'fulfillment-events-for-woocommerce' );
			$parts[] = $fee[0] . ' ' . $cost;
		}
		/* translators: %s: list of zones and fees, e.g. SF $9.00, Marin $20.00 */
		return sprintf( __( 'Usual fee: %s', 'fulfillment-events-for-woocommerce' ), implode( ', ', $parts ) );
	}

	/**
	 * The catalog as the order screen's script needs it.
	 *
	 * @return array
	 */
	private static function script_data() {
		$events = array();
		foreach ( self::catalog() as $id => $event ) {
			$dates = array();
			foreach ( $event['dates'] as $date => $row ) {
				$types = array();
				foreach ( $row['types'] as $type => $offer ) {
					$types[] = array(
						'type'     => $type,
						'label'    => Fe_Emails::type_label( $type ),
						'window'   => $offer['window'],
						'location' => $offer['location'],
						'fees'     => self::fees_text( $offer['fees'] ),
					);
				}
				$dates[] = array(
					'date'  => $date,
					'label' => self::date_label( $row ),
					'open'  => ! $row['past'] && ! $row['closed'],
					'past'  => $row['past'],
					'types' => $types,
				);
			}
			$events[ 'e' . $id ] = $dates;
		}
		return array( 'events' => (object) $events );
	}

	/**
	 * Loads the script and styles on the order screen.
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! function_exists( 'wc_get_page_screen_id' ) || wc_get_page_screen_id( 'shop-order' ) !== $screen->id ) {
			return;
		}
		// The HPOS orders list shares the screen id.
		if ( 'shop_order' !== $screen->id && ! in_array( isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '', array( 'new', 'edit' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_style( 'fe-admin', plugins_url( 'assets/admin.css', FE_PLUGIN_FILE ), array(), FE_VERSION );
		wp_enqueue_script( 'fe-order-event', plugins_url( 'assets/order-event.js', FE_PLUGIN_FILE ), array( 'jquery' ), FE_VERSION, true );
		wp_add_inline_script( 'fe-order-event', 'window.feOrderEvent = ' . wp_json_encode( self::script_data(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * Prints the event on a shipping line: a summary, and the fields in its
	 * edit panel.
	 *
	 * @param int           $item_id Item id.
	 * @param WC_Order_Item $item    Item.
	 */
	public static function render( $item_id, $item ) {
		if ( ! $item instanceof WC_Order_Item_Shipping ) {
			return;
		}
		$order = $item->get_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$current = self::current( $item );
		$locked  = self::locked_reason( $order );
		$method  = (string) $item->get_method_id();
		$event_m = in_array( $method, array( 'fe_delivery', 'fe_pickup', 'fe_courier' ), true );

		if ( isset( self::$refused[ (int) $item_id ] ) ) {
			echo '<div class="fe-line-error">' . esc_html( self::refused_text() ) . '</div>';
		}
		if ( $current ) {
			$parts = array_filter( array( $current['event_name'], 'pickup' === $current['type'] ? $current['location'] : '' ), 'strlen' );
			echo '<div class="view fe-line-summary">' . esc_html( implode( ' · ', $parts ) ) . '</div>';
			if ( self::is_extra_line( $order, (int) $item_id ) ) {
				echo '<div class="fe-line-error">' . esc_html__( 'An order belongs to one event date. The first event line counts; make a separate order for this one.', 'fulfillment-events-for-woocommerce' ) . '</div>';
			}
		} elseif ( ! $locked && $order->is_editable() && ( '' === $method || $event_m ) ) {
			echo '<div class="view fe-line-summary">' . esc_html__( 'No event yet. Click the pencil to choose one.', 'fulfillment-events-for-woocommerce' ) . '</div>';
		}

		if ( ! $order->is_editable() ) {
			return;
		}
		if ( '' !== $locked ) {
			echo '<div class="edit fe-line-summary" style="display:none">' . esc_html( $locked ) . '</div>';
			return;
		}
		self::render_fields( (int) $item_id, $current );
	}

	/**
	 * Whether an earlier shipping line already puts the order in an event.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Shipping line.
	 * @return bool
	 */
	private static function is_extra_line( $order, $item_id ) {
		foreach ( $order->get_items( 'shipping' ) as $id => $item ) {
			if ( self::current( $item ) ) {
				return (int) $id !== $item_id;
			}
		}
		return false;
	}

	/**
	 * Prints the fields.
	 *
	 * @param int        $item_id Item id.
	 * @param array|null $current Line's fulfillment.
	 */
	private static function render_fields( $item_id, $current ) {
		$catalog  = self::catalog();
		$name     = self::FIELD . '[' . $item_id . ']';
		$event_id = $current ? (int) $current['event_id'] : 0;
		$date     = $current ? (string) $current['date'] : '';
		$type     = $current ? (string) $current['type'] : '';

		$events = array( 0 => __( 'No event', 'fulfillment-events-for-woocommerce' ) );
		foreach ( $catalog as $id => $event ) {
			$events[ $id ] = self::event_label( $event );
		}
		$dates = array();
		$types = array();
		if ( isset( $catalog[ $event_id ] ) ) {
			foreach ( $catalog[ $event_id ]['dates'] as $ymd => $row ) {
				$dates[ $ymd ] = self::date_label( $row );
			}
			if ( isset( $catalog[ $event_id ]['dates'][ $date ] ) ) {
				foreach ( array_keys( $catalog[ $event_id ]['dates'][ $date ]['types'] ) as $t ) {
					$types[ $t ] = Fe_Emails::type_label( $t );
				}
			}
		}
		$places = array();
		foreach ( Fe_Order_Event::location_names( self::locations() ) as $text => $label ) {
			$places[ $text ] = $label;
		}
		// The line's own values stay selectable when the event no longer has them.
		if ( $current ) {
			$events += array( $event_id => '' !== $current['event_name'] ? $current['event_name'] : '#' . $event_id );
			$dates  += array( $date => Fe_Zone_Grid::short_date( $date ) );
			$types  += array( $type => Fe_Emails::type_label( $type ) );
			if ( '' !== $current['location'] ) {
				$places += array( $current['location'] => $current['location'] );
			}
		}
		$offer = isset( $catalog[ $event_id ]['dates'][ $date ]['types'][ $type ] ) ? $catalog[ $event_id ]['dates'][ $date ]['types'][ $type ] : null;
		?>
		<div class="edit fe-line-fields" style="display:none">
			<?php self::select( 'event', $name, __( 'Event', 'fulfillment-events-for-woocommerce' ), $events, $event_id, true ); ?>
			<?php self::select( 'date', $name, __( 'Date', 'fulfillment-events-for-woocommerce' ), $dates, $date, (bool) $current ); ?>
			<?php self::select( 'type', $name, __( 'Service', 'fulfillment-events-for-woocommerce' ), $types, $type, (bool) $current ); ?>
			<label class="fe-line-field" data-fe-field="window" <?php echo $current ? '' : 'hidden'; ?>>
				<span><?php esc_html_e( 'Time', 'fulfillment-events-for-woocommerce' ); ?></span>
				<input type="text" class="fe-line-window" maxlength="100" name="<?php echo esc_attr( $name ); ?>[window]" value="<?php echo esc_attr( $current ? $current['window'] : '' ); ?>">
			</label>
			<?php self::select( 'location', $name, __( 'Place', 'fulfillment-events-for-woocommerce' ), $places, $current ? $current['location'] : '', 'pickup' === $type && (bool) $places ); ?>
			<p class="fe-line-fees"><?php echo esc_html( $offer ? self::fees_text( $offer['fees'] ) : '' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prints one select.
	 *
	 * @param string     $key      Field key.
	 * @param string     $name     Field name prefix.
	 * @param string     $label    Label.
	 * @param array      $options  Value => label.
	 * @param string|int $selected Selected value.
	 * @param bool       $show     Whether it shows.
	 */
	private static function select( $key, $name, $label, array $options, $selected, $show ) {
		echo '<label class="fe-line-field" data-fe-field="' . esc_attr( $key ) . '"' . ( $show ? '' : ' hidden' ) . '><span>' . esc_html( $label ) . '</span>';
		echo '<select class="fe-line-' . esc_attr( $key ) . '" name="' . esc_attr( $name . '[' . $key . ']' ) . '">';
		foreach ( $options as $value => $text ) {
			$value = 0 === $value ? '' : (string) $value;
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, (string) ( 0 === $selected ? '' : $selected ), false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * Keeps the titles of the lines about to be saved, to tell one staff
	 * typed from one that was already there.
	 *
	 * @param int   $order_id Order id.
	 * @param array $items    Posted items.
	 */
	public static function remember_titles( $order_id, $items ) {
		if ( empty( $items[ self::FIELD ] ) || ! is_array( $items[ self::FIELD ] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items( 'shipping' ) as $id => $item ) {
			self::$titles[ (int) $id ] = (string) $item->get_method_title();
		}
	}

	/**
	 * Saves the events posted with an order's items. WooCommerce has checked
	 * the nonce and the capability.
	 *
	 * @param int   $order_id Order id.
	 * @param array $items    Posted items.
	 */
	public static function save( $order_id, $items ) {
		if ( empty( $items[ self::FIELD ] ) || ! is_array( $items[ self::FIELD ] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' !== self::locked_reason( $order ) ) {
			return;
		}
		$posted  = map_deep( wp_unslash( $items[ self::FIELD ] ), 'sanitize_text_field' );
		$names   = Fe_Order_Event::location_names( self::locations() );
		$changed = false;
		foreach ( $order->get_items( 'shipping' ) as $id => $item ) {
			$row = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			$r   = Fe_Order_Event::resolve( $row, self::current( $item ), $row ? self::catalog() : array(), $names );
			if ( 'invalid' === $r['action'] ) {
				self::$refused[ (int) $id ] = true;
				if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
					WC_Admin_Meta_Boxes::add_error( self::refused_text() );
				}
				continue;
			}
			if ( 'same' === $r['action'] ) {
				continue;
			}
			self::apply( $item, $r['fulfillment'], $names );
			$changed = true;
		}
		if ( $changed ) {
			self::sync( $order );
		}
	}

	/**
	 * Text for a choice the event doesn't have.
	 *
	 * @return string
	 */
	private static function refused_text() {
		return __( 'The event was not changed: choose an event, a date and a service it has.', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Writes a fulfillment to a shipping line, or takes it off.
	 *
	 * @param WC_Order_Item_Shipping $item  Shipping line.
	 * @param array|null             $f     Fulfillment, null for no event.
	 * @param array                  $names From Fe_Order_Event::location_names().
	 */
	private static function apply( $item, $f, array $names ) {
		foreach ( array_merge( Fe_Order_Stamper::item_meta_keys(), array( Fe_Meta::GROUP ) ) as $key ) {
			$item->delete_meta_data( $key );
		}
		$before = isset( self::$titles[ $item->get_id() ] ) ? self::$titles[ $item->get_id() ] : '';
		if ( $f ) {
			foreach ( Fe_Order_Event::line_meta( $f ) as $key => $value ) {
				$item->update_meta_data( $key, $value );
			}
			$item->update_meta_data( Fe_Meta::GROUP, 'event:' . (int) $f['event_id'] );
			$where  = isset( $names[ $f['location'] ] ) ? $names[ $f['location'] ] : (string) $f['location'];
			$label  = Fe_Order_Event::label( $f['type'], Fe_Zone_Grid::short_date( $f['date'] ), $where, 'courier' === $f['type'] ? '' : $f['window'] );
			$method = 'fe_' . $f['type'];
		} else {
			$label  = __( 'Shipping', 'fulfillment-events-for-woocommerce' );
			$method = 0 === strpos( (string) $item->get_method_id(), 'fe_' ) ? '' : (string) $item->get_method_id();
		}
		$item->set_method_title( Fe_Order_Event::title( $before, (string) $item->get_method_title(), $label ) );
		if ( (string) $item->get_method_id() !== $method ) {
			$item->set_method_id( $method );
			$item->set_instance_id( '' );
		}
		$item->save();
	}

	/**
	 * Brings the order's contract meta in line with its shipping lines.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function sync( $order ) {
		// Re-read: the lines were saved through their own objects.
		$order = wc_get_order( $order->get_id() );
		$lines = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$lines[] = self::line_meta( $item );
		}
		$before  = fe_get_order_fulfillment( $order );
		$managed = Fe_Meta::contract_keys();
		$current = array();
		foreach ( $managed as $key ) {
			$current[ $key ] = $order->get_meta( $key );
		}
		$changes = Fe_Order_Stamper::meta_changes( $current, Fe_Order_Event::order_meta( $lines, (int) $order->get_meta( Fe_Meta::PARENT_ID ) ), $managed );
		if ( ! $changes['set'] && ! $changes['delete'] ) {
			return;
		}
		foreach ( $changes['delete'] as $key ) {
			$order->delete_meta_data( $key );
		}
		foreach ( $changes['set'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		$after = fe_get_order_fulfillment( $order );
		if ( 'auto-draft' !== $order->get_status() ) {
			$order->add_order_note(
				/* translators: 1: previous event, date and service; 2: new one */
				sprintf( __( 'Fulfillment changed from %1$s to %2$s.', 'fulfillment-events-for-woocommerce' ), self::summary( $before ), self::summary( $after ) ),
				false,
				true
			);
		}
		if ( $after ) {
			do_action( 'fe_order_stamped', $order );
		}
	}

	/**
	 * One-line summary of a fulfillment.
	 *
	 * @param array|null $f Fulfillment.
	 * @return string
	 */
	private static function summary( $f ) {
		if ( ! $f ) {
			return __( 'no event', 'fulfillment-events-for-woocommerce' );
		}
		return implode( ' · ', array_filter( array( $f['event_name'], Fe_Emails::type_label( $f['type'] ), Fe_Zone_Grid::short_date( $f['date'] ), $f['window'], $f['location'] ), 'strlen' ) );
	}

	/**
	 * Notes that staff are deleting a line that carries an event.
	 *
	 * @param int $item_id Item id.
	 */
	public static function before_delete( $item_id ) {
		if ( doing_action( 'wp_ajax_woocommerce_remove_order_item' ) && '' !== (string) wc_get_order_item_meta( $item_id, 'fe_date', true ) ) {
			self::$removed = true;
		}
	}

	/**
	 * Takes the order out of the event whose line staff deleted.
	 *
	 * @param int            $item_id Item id.
	 * @param mixed          $item    Item.
	 * @param mixed          $stock   Stock change.
	 * @param WC_Order|mixed $order   Order.
	 */
	public static function after_delete( $item_id, $item, $stock, $order ) {
		if ( self::$removed && $order instanceof WC_Order && '' === self::locked_reason( $order ) ) {
			self::sync( $order );
		}
	}
}
