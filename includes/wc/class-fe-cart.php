<?php
/**
 * Cart behaviour: purchasability, and (in later tasks) packages and notices.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * WooCommerce cart integration.
 */
class Fe_Cart {

	/**
	 * Per-request cache of product status.
	 *
	 * @var array<int, array>
	 */
	private static $status_cache = array();

	/**
	 * Per-request cache of open events.
	 *
	 * @var array[]|null
	 */
	private static $open_events = null;

	/**
	 * Registers the purchasable filters.
	 */
	public static function init() {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'packages' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 10, 2 );
		add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 10, 3 );
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_rate_note' ), 10, 2 );
		add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'no_rates_html' ) );
		add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'no_rates_html' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'combined_delivery_fee' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_product_note' ), 25 );
		add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'append_note_to_price_block' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_dates' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_store_api_dates' ), 10, 2 );
	}

	/**
	 * Store-local today, Y-m-d.
	 *
	 * @return string
	 */
	private static function today() {
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Pure: one error per chosen event rate whose date is before today.
	 * A rate chosen yesterday can still sit in the session; it must not be
	 * ordered. Rates without a date (an unresolved guest add-on) are skipped.
	 *
	 * @param array  $metas List of rate meta arrays (fe_date, fe_event_name).
	 * @param string $today Y-m-d, store-local.
	 * @return string[] Messages.
	 */
	public static function past_date_errors( array $metas, $today ) {
		$errors = array();
		foreach ( $metas as $meta ) {
			$date = isset( $meta['fe_date'] ) ? (string) $meta['fe_date'] : '';
			if ( '' === $date || '' === (string) $today || $date >= (string) $today ) {
				continue;
			}
			$name = isset( $meta['fe_event_name'] ) && '' !== (string) $meta['fe_event_name'] ? (string) $meta['fe_event_name'] : __( 'your order', 'fulfillment-events-for-woocommerce' );
			/* translators: %s: event name. */
			$errors[ $name ] = sprintf( __( 'The date you chose for %s has passed. Choose another date.', 'fulfillment-events-for-woocommerce' ), $name );
		}
		return array_values( $errors );
	}

	/**
	 * Pure: meta of the chosen rate in each package, for this plugin's rates only.
	 *
	 * @param array $packages Calculated packages (with rates).
	 * @param array $chosen   Package key => chosen rate id.
	 * @return array List of meta arrays.
	 */
	public static function chosen_fe_metas( $packages, $chosen ) {
		$out = array();
		foreach ( (array) $packages as $key => $package ) {
			if ( ! isset( $chosen[ $key ] ) || 0 !== strpos( (string) $chosen[ $key ], 'fe_' ) ) {
				continue;
			}
			$rate = isset( $package['rates'][ $chosen[ $key ] ] ) ? $package['rates'][ $chosen[ $key ] ] : null;
			if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
				$out[] = (array) $rate->get_meta_data();
			}
		}
		return $out;
	}

	/**
	 * Classic checkout: rejects a chosen event date that has passed.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_classic_dates( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! is_object( $errors ) ) {
			return;
		}
		$chosen = isset( $data['shipping_method'] ) ? (array) $data['shipping_method'] : array();
		foreach ( self::past_date_errors( self::chosen_fe_metas( WC()->shipping()->get_packages(), $chosen ), self::today() ) as $i => $message ) {
			$errors->add( 'fe_date_passed_' . $i, esc_html( $message ) );
		}
	}

	/**
	 * Blocks checkout: rejects a chosen event date that has passed. Runs on
	 * place-order (POST) only, not on the PUT updates while the customer types.
	 *
	 * @param WC_Order        $order   Draft order.
	 * @param WP_REST_Request $request Checkout request.
	 * @throws Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a date has passed.
	 */
	public static function validate_store_api_dates( $order, $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_method' ) && 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$metas = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 0 !== strpos( (string) $item->get_method_id(), 'fe_' ) ) {
				continue;
			}
			$metas[] = array(
				'fe_date'       => (string) $item->get_meta( 'fe_date' ),
				'fe_event_name' => (string) $item->get_meta( 'fe_event_name' ),
			);
		}
		$errors = self::past_date_errors( $metas, self::today() );
		if ( $errors ) {
			// The Store API returns the message as JSON text; the blocks UI escapes it when rendering.
			throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'fe_date_passed', implode( ' ', $errors ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Pure: one-line availability note for an event, e.g.
	 * "Available for delivery or pickup on Dec 10 or Dec 18". Courier pickup is
	 * implied and not listed. Dates before $today are skipped; returns '' when
	 * no offered date is left.
	 *
	 * @param array       $event Event from Fe_Event_Repo::get().
	 * @param string|null $today Y-m-d, store-local. Defaults to today.
	 * @return string
	 */
	public static function availability_note( array $event, $today = null ) {
		if ( null === $today ) {
			$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		$delivery = false;
		$pickup   = false;
		$labels   = array();
		foreach ( isset( $event['dates'] ) ? (array) $event['dates'] : array() as $row ) {
			$date = isset( $row['date'] ) ? (string) $row['date'] : '';
			$d    = ! empty( $row['delivery'] );
			$p    = ! empty( $row['pickup'] );
			$c    = ! empty( $row['courier'] );
			if ( '' === $date || $date < $today || ( ! $d && ! $p && ! $c ) ) {
				continue;
			}
			$delivery = $delivery || $d;
			$pickup   = $pickup || $p;
			$labels[] = self::short_date( $date );
		}
		if ( ! $labels ) {
			return '';
		}
		if ( ! $delivery && ! $pickup ) {
			$types = __( 'courier', 'fulfillment-events-for-woocommerce' );
		} elseif ( $delivery && $pickup ) {
			$types = __( 'delivery or pickup', 'fulfillment-events-for-woocommerce' );
		} elseif ( $delivery ) {
			$types = __( 'delivery', 'fulfillment-events-for-woocommerce' );
		} else {
			$types = __( 'pickup', 'fulfillment-events-for-woocommerce' );
		}
		$last = array_pop( $labels );
		if ( $labels ) {
			$dates = sprintf(
				/* translators: 1: comma-separated dates, 2: last date, e.g. "Dec 3, Dec 10" and "Dec 18". */
				__( '%1$s or %2$s', 'fulfillment-events-for-woocommerce' ),
				implode( ', ', $labels ),
				$last
			);
		} else {
			$dates = $last;
		}
		/* translators: 1: fulfillment types, e.g. "delivery or pickup", 2: dates, e.g. "Dec 10 or Dec 18". */
		return sprintf( __( 'Available for %1$s on %2$s', 'fulfillment-events-for-woocommerce' ), $types, $dates );
	}

	/**
	 * Pure: the offered events with their dates cut down to each offer's
	 * allowed dates (an offer with null dates keeps all of them).
	 *
	 * @param array   $events_by_id Event id => event.
	 * @param array[] $offers       Offers list.
	 * @return array[]
	 */
	public static function trim_to_offers( array $events_by_id, array $offers ) {
		$out = array();
		foreach ( Fe_Cart_Grouper::to_offers( $offers ) as $offer ) {
			if ( ! isset( $events_by_id[ $offer['event_id'] ] ) ) {
				continue;
			}
			$event = $events_by_id[ $offer['event_id'] ];
			if ( is_array( $offer['dates'] ) ) {
				$allowed        = $offer['dates'];
				$event['dates'] = array_values(
					array_filter(
						(array) $event['dates'],
						function ( $row ) use ( $allowed ) {
							return isset( $row['date'] ) && in_array( (string) $row['date'], $allowed, true );
						}
					)
				);
			}
			$out[] = $event;
		}
		return $out;
	}

	/**
	 * Pure: one pseudo-event holding every date of the given events, sorted,
	 * with a date's delivery and pickup flags combined when events share it.
	 *
	 * @param array[] $events Events from Fe_Event_Repo::get().
	 * @return array{dates:array[]}
	 */
	public static function merged_dates( array $events ) {
		$by_date = array();
		foreach ( $events as $event ) {
			foreach ( isset( $event['dates'] ) ? (array) $event['dates'] : array() as $row ) {
				$date = isset( $row['date'] ) ? (string) $row['date'] : '';
				if ( '' === $date ) {
					continue;
				}
				if ( ! isset( $by_date[ $date ] ) ) {
					$by_date[ $date ] = array(
						'date'     => $date,
						'delivery' => false,
						'pickup'   => false,
						'courier'  => false,
					);
				}
				$by_date[ $date ]['delivery'] = $by_date[ $date ]['delivery'] || ! empty( $row['delivery'] );
				$by_date[ $date ]['pickup']   = $by_date[ $date ]['pickup'] || ! empty( $row['pickup'] );
				$by_date[ $date ]['courier']  = $by_date[ $date ]['courier'] || ! empty( $row['courier'] );
			}
		}
		ksort( $by_date );
		return array( 'dates' => array_values( $by_date ) );
	}

	/**
	 * Month and day, e.g. "Dec 10".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function short_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd );
		return $dt ? $dt->format( 'M j' ) : (string) $ymd;
	}

	/**
	 * Note markup for a product, or '' when it has no open event or doesn't
	 * ship.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private static function product_note_html( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product ) {
			return '';
		}
		$st = self::status_for_product( $product );
		if ( 'active' !== $st['status'] || empty( $st['event_ids'] ) ) {
			return '';
		}
		$events = array();
		foreach ( $st['event_ids'] as $id ) {
			$event = Fe_Event_Repo::get( (int) $id );
			if ( $event ) {
				$events[ (int) $id ] = $event;
			}
		}
		$note = self::availability_note( self::merged_dates( self::trim_to_offers( $events, $st['offers'] ) ) );
		return '' === $note ? '' : '<p class="fe-availability">' . esc_html( $note ) . '</p>';
	}

	/**
	 * Classic themes: prints the note under the price.
	 */
	public static function render_product_note() {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		echo wp_kses_post( self::product_note_html( (int) $product->get_id() ) );
	}

	/**
	 * Block themes: appends the note to the main product's price block.
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 * @param object $instance Block instance (WP_Block).
	 * @return string
	 */
	public static function append_note_to_price_block( $content, $block = array(), $instance = null ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $content;
		}
		$context = is_object( $instance ) && isset( $instance->context ) && is_array( $instance->context ) ? $instance->context : array();
		$attrs   = is_array( $block ) && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$queried = (int) get_queried_object_id();
		if ( ! self::should_append_to_price_block( $context, $attrs, $queried ) ) {
			return $content;
		}
		return $content . self::product_note_html( $queried );
	}

	/**
	 * Pure: whether a price block is the main product's (not a related or
	 * collection card).
	 *
	 * @param array $context    Block context (postId, queryId).
	 * @param array $attrs      Block attributes (productId).
	 * @param int   $queried_id Product being viewed.
	 * @return bool
	 */
	public static function should_append_to_price_block( array $context, array $attrs, $queried_id ) {
		if ( ! empty( $context['queryId'] ) || (int) $queried_id <= 0 ) {
			return false;
		}
		$id = 0;
		if ( ! empty( $context['postId'] ) ) {
			$id = (int) $context['postId'];
		} elseif ( ! empty( $attrs['productId'] ) ) {
			$id = (int) $attrs['productId'];
		}
		return $id === (int) $queried_id;
	}

	/**
	 * Chosen event rates as plain lines for Fe_Fee_Dedupe::credit(). Packages
	 * the plugin did not build are skipped.
	 *
	 * @param array $packages Calculated shipping packages (with rates).
	 * @param array $chosen   Package key => chosen rate id.
	 * @return array List of {group, type, date, cost_cents}.
	 */
	public static function chosen_rate_lines( $packages, $chosen ) {
		$lines = array();
		foreach ( (array) $packages as $key => $package ) {
			if ( ! is_array( $package ) || empty( $package['fe_group'] ) || ! isset( $chosen[ $key ] ) ) {
				continue;
			}
			$rate_id = (string) $chosen[ $key ];
			$rate    = isset( $package['rates'][ $rate_id ] ) ? $package['rates'][ $rate_id ] : null;
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
				continue;
			}
			$meta = (array) $rate->get_meta_data();
			if ( empty( $meta['fe_type'] ) || empty( $meta['fe_date'] ) ) {
				continue;
			}
			$lines[] = array(
				'group'      => (string) $package['fe_group'],
				'type'       => (string) $meta['fe_type'],
				'date'       => (string) $meta['fe_date'],
				'cost_cents' => Fe_Split_Math::to_cents( $rate->get_cost() ),
			);
		}
		return $lines;
	}

	/**
	 * Adds a negative "Combined delivery" fee when two packages deliver on the
	 * same date, so the customer pays that date's delivery once.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function combined_delivery_fee( $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! is_object( $cart ) ) {
			return;
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen ) || ! is_array( $chosen ) ) {
			return;
		}
		$credit = Fe_Fee_Dedupe::credit( self::chosen_rate_lines( WC()->shipping()->get_packages(), $chosen ) );
		if ( $credit > 0 ) {
			$cart->add_fee( __( 'Combined delivery', 'fulfillment-events-for-woocommerce' ), -$credit / 100, false );
		}
	}

	/**
	 * Package last passed to the package-name filter, i.e. the one the classic
	 * templates are rendering.
	 *
	 * @var array|null
	 */
	private static $rendering_package = null;

	/**
	 * Classic checkout: prints a courier rate's instructions under it. The
	 * block checkout shows them from the rate's description instead.
	 *
	 * @param WC_Shipping_Rate $rate  Rate.
	 * @param int              $index Package index.
	 */
	public static function render_rate_note( $rate, $index = 0 ) {
		if ( ! is_object( $rate ) || ! is_callable( array( $rate, 'get_method_id' ) ) || 'fe_courier' !== $rate->get_method_id() ) {
			return;
		}
		$note = is_callable( array( $rate, 'get_description' ) ) ? (string) $rate->get_description() : fe_courier_instructions();
		if ( '' !== trim( $note ) ) {
			echo '<p class="fe-rate-note" style="margin:0.25em 0 0.5em;font-size:0.875em;opacity:0.8">' . esc_html( $note ) . '</p>';
		}
	}

	/**
	 * Splits the cart into one package per event plus a regular package.
	 * Returns the input untouched when no item resolves to an event.
	 *
	 * @param array $packages Packages built by WooCommerce.
	 * @return array
	 */
	public static function packages( $packages ) {
		$out = self::build_packages(
			$packages,
			function ( $product_id ) {
				$st = self::product_event_status( (int) $product_id );
				return $st['offers'];
			},
			function ( $event_id ) {
				$event = Fe_Event_Repo::get( (int) $event_id );
				return $event ? (string) $event['title'] : '';
			},
			null,
			function ( $event_id ) {
				$event = Fe_Event_Repo::get( (int) $event_id );
				return $event ? array_column( (array) $event['dates'], 'date' ) : array();
			}
		);
		if ( ! is_array( $out ) ) {
			return $out;
		}
		// Part of the package, so a rate cached on one checkout type is not
		// reused on the other.
		$guest_addon = Fe_Addon_Field::guest_addon_available() ? 'yes' : 'no';
		foreach ( $out as $i => $package ) {
			if ( isset( $package['fe_group'] ) ) {
				$out[ $i ]['fe_guest_addon'] = $guest_addon;
				// WooCommerce caches rates by package contents. Without this, a
				// cart rated before the customer's order existed keeps rates
				// with no "Add to my order" option.
				$out[ $i ]['fe_addon_targets'] = self::addon_targets_key( isset( $package['fe_customer_id'] ) ? (int) $package['fe_customer_id'] : 0 );
			}
		}
		return $out;
	}

	/**
	 * Open orders of a customer, memoised per request.
	 *
	 * @var array<int,string>
	 */
	private static $addon_targets = array();

	/**
	 * Ids and statuses of a logged-in customer's open orders, as a string that
	 * changes whenever an order could become (or stop being) an add-on target.
	 *
	 * @param int $customer_id Customer id; 0 for guests.
	 * @return string
	 */
	private static function addon_targets_key( $customer_id ) {
		if ( $customer_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}
		if ( ! isset( self::$addon_targets[ $customer_id ] ) ) {
			$ids                                 = wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'status'      => Fe_Addon_Rules::OPEN_STATUSES,
					'limit'       => 20,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'type'        => 'shop_order',
					'return'      => 'ids',
				)
			);
			self::$addon_targets[ $customer_id ] = implode( ',', array_map( 'intval', (array) $ids ) );
		}
		return self::$addon_targets[ $customer_id ];
	}

	/**
	 * Pure package builder behind packages().
	 *
	 * @param array    $packages          Packages built by WooCommerce.
	 * @param callable $event_for_product Product id => offers (see Fe_Cart_Grouper::to_offers), event ids, an id, or null.
	 * @param callable $event_name        Event id => event name.
	 * @param string   $today             Y-m-d, store-local; defaults to today. Stored on
	 *                                    each package so WooCommerce's rate cache rolls daily.
	 * @param callable $event_dates       Optional event id => its dates (Y-m-d). When given,
	 *                                    event packages come soonest date first.
	 * @return array
	 */
	public static function build_packages( $packages, $event_for_product, $event_name, $today = null, $event_dates = null ) {
		if ( ! is_array( $packages ) || empty( $packages ) ) {
			return $packages;
		}
		if ( null === $today ) {
			$today = self::today();
		}

		$contents = array();
		foreach ( $packages as $package ) {
			if ( ! empty( $package['contents'] ) && is_array( $package['contents'] ) ) {
				// Union by key; later packages never overwrite earlier ones.
				$contents = $contents + $package['contents'];
			}
		}

		$items     = array();
		$has_event = false;
		foreach ( $contents as $key => $item ) {
			$offers = array();
			if ( ! empty( $item['product_id'] ) ) {
				$offers = Fe_Cart_Grouper::to_offers( call_user_func( $event_for_product, (int) $item['product_id'] ) );
			}
			if ( $offers ) {
				$has_event = true;
			}
			$items[] = array(
				'key'            => (string) $key,
				'needs_shipping' => isset( $item['data'] ) && is_object( $item['data'] ) && $item['data']->needs_shipping(),
				'offers'         => $offers,
			);
		}
		if ( ! $has_event ) {
			return $packages;
		}

		$first  = reset( $packages );
		$groups = Fe_Cart_Grouper::group( $items );
		if ( is_callable( $event_dates ) ) {
			$groups = self::sort_groups( $groups, $event_dates, (string) $today );
		}
		$result = array();
		foreach ( $groups as $group ) {
			$group_contents = array();
			$cost           = 0;
			$subtotal       = 0;
			foreach ( $group['item_keys'] as $key ) {
				$line                   = $contents[ $key ];
				$group_contents[ $key ] = $line;
				$cost                  += isset( $line['line_total'] ) ? (float) $line['line_total'] : 0;
				$subtotal              += ( isset( $line['line_subtotal'] ) ? (float) $line['line_subtotal'] : 0 ) + ( isset( $line['line_subtotal_tax'] ) ? (float) $line['line_subtotal_tax'] : 0 );
			}
			$user    = isset( $first['user'] ) ? $first['user'] : array( 'ID' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
			$package = $first;
			unset( $package['rates'] );
			$package['contents']        = $group_contents;
			$package['contents_cost']   = $cost;
			$package['cart_subtotal']   = $subtotal;
			$package['applied_coupons'] = isset( $first['applied_coupons'] ) ? $first['applied_coupons'] : array();
			$package['user']            = $user;
			$package['destination']     = isset( $first['destination'] ) ? $first['destination'] : array();
			$package['fe_group']        = $group['group'];
			$package['fe_event_id']     = $group['event_id'];
			$package['fe_event_ids']    = $group['event_ids'];
			$package['fe_offers']       = $group['offers'];
			$package['fe_event_name']   = self::package_heading( $group, $event_name );
			$package['fe_customer_id']  = isset( $user['ID'] ) ? (int) $user['ID'] : 0;
			$package['fe_today']        = (string) $today;
			$result[]                   = $package;
		}
		return $result;
	}

	/**
	 * Pure: event groups by the first date they can still ship on, so
	 * checkout lists Dec 3 before Dec 10 whatever order items were added in.
	 * Ties and groups with no upcoming date keep cart order; the regular
	 * group stays last.
	 *
	 * @param array[]  $groups      From Fe_Cart_Grouper::group().
	 * @param callable $event_dates Event id => its dates.
	 * @param string   $today       Y-m-d.
	 * @return array[]
	 */
	private static function sort_groups( array $groups, $event_dates, $today ) {
		$keyed = array();
		foreach ( $groups as $i => $group ) {
			$first = 'regular' === $group['group'] ? '~regular' : '~none';
			foreach ( $group['offers'] as $offer ) {
				$dates = is_array( $offer['dates'] ) ? $offer['dates'] : (array) call_user_func( $event_dates, (int) $offer['event_id'] );
				foreach ( $dates as $date ) {
					$date = (string) $date;
					if ( $date >= $today && $date < $first ) {
						$first = $date;
					}
				}
			}
			$keyed[] = array( $first, $i, $group );
		}
		usort(
			$keyed,
			function ( $a, $b ) {
				return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
			}
		);
		return array_column( $keyed, 2 );
	}

	/**
	 * Pure: event ids a package offers, soonest first. Reads fe_event_ids and
	 * falls back to the single fe_event_id of older packages.
	 *
	 * @param array $package Package.
	 * @return int[]
	 */
	public static function package_event_ids( $package ) {
		if ( ! is_array( $package ) ) {
			return array();
		}
		if ( ! empty( $package['fe_offers'] ) && is_array( $package['fe_offers'] ) ) {
			return array_column( Fe_Cart_Grouper::to_offers( $package['fe_offers'] ), 'event_id' );
		}
		if ( ! empty( $package['fe_event_ids'] ) && is_array( $package['fe_event_ids'] ) ) {
			return array_values( array_unique( array_filter( array_map( 'intval', $package['fe_event_ids'] ) ) ) );
		}
		return empty( $package['fe_event_id'] ) ? array() : array( (int) $package['fe_event_id'] );
	}

	/**
	 * Pure: the dates a package may offer for one of its events, or null for
	 * all of the event's dates.
	 *
	 * @param array $package  Package.
	 * @param int   $event_id Event id.
	 * @return string[]|null
	 */
	public static function package_allowed_dates( $package, $event_id ) {
		$offers = is_array( $package ) && isset( $package['fe_offers'] ) ? Fe_Cart_Grouper::to_offers( $package['fe_offers'] ) : array();
		foreach ( $offers as $offer ) {
			if ( (int) $offer['event_id'] === (int) $event_id ) {
				return $offer['dates'];
			}
		}
		return null;
	}

	/**
	 * Pure: package heading. Event names joined with " / "; a date-limited
	 * package adds its dates, so checkout tells the two shipping choices
	 * apart.
	 *
	 * @param array    $group      Group from Fe_Cart_Grouper::group().
	 * @param callable $event_name Event id => event name.
	 * @return string
	 */
	private static function package_heading( array $group, $event_name ) {
		$name  = self::joined_names( $group['event_ids'], $event_name );
		$dates = array();
		foreach ( $group['offers'] as $offer ) {
			if ( is_array( $offer['dates'] ) ) {
				$dates = array_merge( $dates, $offer['dates'] );
			}
		}
		if ( ! $dates || '' === $name ) {
			return $name;
		}
		$dates = array_unique( $dates );
		sort( $dates );
		return $name . ' · ' . implode( ', ', array_map( array( __CLASS__, 'short_date' ), $dates ) );
	}

	/**
	 * Pure: the rate-id suffix for an event date. A package with one event
	 * keeps the plain date, as before; with several, the event id is added
	 * so two events on the same date don't collide.
	 *
	 * @param int    $event_id    Event id.
	 * @param string $suffix      Date (or other spec id).
	 * @param int    $event_count Events in the package.
	 * @return string
	 */
	public static function rate_suffix( $event_id, $suffix, $event_count ) {
		return $event_count > 1 ? (int) $event_id . '-' . $suffix : (string) $suffix;
	}

	/**
	 * Package heading for one or more events, e.g. "Holiday Pre-order 2026"
	 * or "Christmas Eve / Christmas Day".
	 *
	 * @param int[]    $event_ids  Event ids.
	 * @param callable $event_name Event id => event name.
	 * @return string
	 */
	private static function joined_names( array $event_ids, $event_name ) {
		$names = array();
		foreach ( $event_ids as $id ) {
			$name = (string) call_user_func( $event_name, $id );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return implode( ' / ', array_unique( $names ) );
	}

	/**
	 * Whether a destination is filled in enough to judge rate availability.
	 *
	 * @param array $destination Package destination.
	 * @return bool
	 */
	public static function destination_is_complete( $destination ) {
		if ( ! is_array( $destination ) ) {
			return false;
		}
		$has = function ( $k ) use ( $destination ) {
			return isset( $destination[ $k ] ) && '' !== trim( (string) $destination[ $k ] );
		};
		return $has( 'postcode' ) || ( $has( 'country' ) && $has( 'city' ) );
	}

	/**
	 * Keeps only this plugin's rates on event packages, and only other rates on
	 * the regular package. Packages the plugin did not build are untouched.
	 *
	 * @param array $rates   Rates keyed by id.
	 * @param array $package Package being rated.
	 * @return array
	 */
	public static function filter_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! is_array( $package ) || empty( $package['fe_group'] ) ) {
			return $rates;
		}
		$is_event = ! empty( $package['fe_event_id'] );
		foreach ( $rates as $id => $rate ) {
			$method = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
			$ours   = 0 === strpos( $method, 'fe_' );
			if ( $is_event !== $ours ) {
				unset( $rates[ $id ] );
			}
		}
		return $rates;
	}

	/**
	 * Names event packages after their event.
	 *
	 * @param string $name    Default name.
	 * @param int    $i       Package index.
	 * @param array  $package Package.
	 * @return string
	 */
	public static function package_name( $name, $i, $package ) {
		self::$rendering_package = is_array( $package ) ? $package : null;
		if ( is_array( $package ) && ! empty( $package['fe_event_name'] ) ) {
			return $package['fe_event_name'];
		}
		return $name;
	}

	/**
	 * Message shown when an event package has no rates.
	 *
	 * @param string $event_name Event name.
	 * @param string $postcode   Destination postcode, may be empty.
	 * @return string
	 */
	public static function zero_rates_message( $event_name, $postcode ) {
		if ( '' === trim( (string) $postcode ) ) {
			/* translators: %s: event name. */
			return sprintf( __( "%s items aren't available for this address. Try a different address or remove them from your cart.", 'fulfillment-events-for-woocommerce' ), $event_name );
		}
		/* translators: 1: event name, 2: postcode. */
		return sprintf( __( "%1\$s items aren't available for %2\$s. Try a different address or remove them from your cart.", 'fulfillment-events-for-woocommerce' ), $event_name, $postcode );
	}

	/**
	 * Event packages that ended up with no rates.
	 *
	 * @return array[]
	 */
	private static function empty_event_packages() {
		$out = array();
		foreach ( (array) WC()->shipping()->get_packages() as $package ) {
			if ( ! empty( $package['fe_event_id'] ) && empty( $package['rates'] ) ) {
				$out[] = $package;
			}
		}
		return $out;
	}

	/**
	 * Message for a package, using its destination postcode.
	 *
	 * @param array $package Event package.
	 * @return string
	 */
	private static function message_for( array $package ) {
		$postcode = isset( $package['destination']['postcode'] ) ? (string) $package['destination']['postcode'] : '';
		return self::zero_rates_message( (string) $package['fe_event_name'], $postcode );
	}

	/**
	 * Classic cart/checkout "no shipping available" text for event packages.
	 *
	 * @param string $html Default markup.
	 * @return string
	 */
	public static function no_rates_html( $html ) {
		$package                 = self::$rendering_package;
		self::$rendering_package = null;
		if ( ! is_array( $package ) || empty( $package['fe_event_id'] ) ) {
			if ( null !== $package ) {
				return $html;
			}
			$empty = self::empty_event_packages();
			if ( empty( $empty ) ) {
				return $html;
			}
			$package = $empty[0];
		}
		return wp_kses_post( wpautop( esc_html( self::message_for( $package ) ) ) );
	}

	/**
	 * Blocks checkout: one error per event package with no rates.
	 *
	 * @param WP_Error $errors Errors collector.
	 * @param WC_Cart  $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart ) {
		foreach ( self::empty_event_packages() as $package ) {
			if ( ! self::destination_is_complete( isset( $package['destination'] ) ? $package['destination'] : array() ) ) {
				continue;
			}
			$errors->add( 'fe_no_rates_' . (int) $package['fe_event_id'], self::message_for( $package ) );
		}
	}

	/**
	 * Event status of a product, computed once per request.
	 *
	 * @param int $product_id Product id (a variation id resolves via its parent in the links layer).
	 * @return array{status:string, event_id:?int, event_ids:int[], offers:array[]}
	 */
	public static function product_event_status( $product_id ) {
		$product_id = (int) $product_id;
		if ( isset( self::$status_cache[ $product_id ] ) ) {
			return self::$status_cache[ $product_id ];
		}

		$ids = Fe_Product_Links::event_ids_for_product( $product_id );
		if ( empty( $ids ) ) {
			$result = array(
				'status'    => 'none',
				'event_id'  => null,
				'event_ids' => array(),
				'offers'    => array(),
			);
		} else {
			if ( null === self::$open_events ) {
				self::$open_events = Fe_Event_Repo::open_events( current_time( 'mysql' ) );
			}
			$offers   = Fe_Event_Resolver::open_offers( $ids, self::$open_events, current_time( 'Y-m-d' ), $product_id );
			$open     = array_column( $offers, 'event_id' );
			$resolved = $open ? $open[0] : null;
			$result   = array(
				'status'    => Fe_Event_Resolver::status( $ids, $resolved ),
				'event_id'  => $resolved,
				'event_ids' => $open,
				'offers'    => $offers,
			);
		}
		self::$status_cache[ $product_id ] = $result;
		return $result;
	}

	/**
	 * Pure: events only govern shipping, so a product that doesn't ship
	 * (virtual, e.g. a class ticket) behaves as if it were in no event, even
	 * when its category is linked to one.
	 *
	 * @param bool  $needs_shipping Whether the product ships.
	 * @param array $status         Result of product_event_status().
	 * @return array{status:string, event_id:?int}
	 */
	public static function status_for_shipping( $needs_shipping, array $status ) {
		if ( $needs_shipping ) {
			return $status;
		}
		return array(
			'status'    => 'none',
			'event_id'  => null,
			'event_ids' => array(),
			'offers'    => array(),
		);
	}

	/**
	 * Event status for a product object, honoring status_for_shipping().
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{status:string, event_id:?int}
	 */
	private static function status_for_product( $product ) {
		$id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$ships = method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true;
		return self::status_for_shipping( $ships, self::product_event_status( $id ) );
	}

	/**
	 * Blocks purchase of shippable products whose events are all closed.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product or variation.
	 * @return bool
	 */
	public static function filter_purchasable( $purchasable, $product ) {
		if ( ! $purchasable ) {
			return $purchasable;
		}
		$st = self::status_for_product( $product );
		return 'closed' === $st['status'] ? false : $purchasable;
	}
}
