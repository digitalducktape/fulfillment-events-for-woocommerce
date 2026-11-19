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
		add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'no_rates_html' ) );
		add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'no_rates_html' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
	}

	/**
	 * Package last passed to the package-name filter, i.e. the one the classic
	 * templates are rendering.
	 *
	 * @var array|null
	 */
	private static $rendering_package = null;

	/**
	 * Splits the cart into one package per event plus a regular package.
	 * Returns the input untouched when no item resolves to an event.
	 *
	 * @param array $packages Packages built by WooCommerce.
	 * @return array
	 */
	public static function packages( $packages ) {
		return self::build_packages(
			$packages,
			function ( $product_id ) {
				$st = self::product_event_status( (int) $product_id );
				return $st['event_id'];
			},
			function ( $event_id ) {
				$event = Fe_Event_Repo::get( (int) $event_id );
				return $event ? (string) $event['title'] : '';
			}
		);
	}

	/**
	 * Pure package builder behind packages().
	 *
	 * @param array    $packages          Packages built by WooCommerce.
	 * @param callable $event_for_product Product id => event id or null.
	 * @param callable $event_name        Event id => event name.
	 * @return array
	 */
	public static function build_packages( $packages, $event_for_product, $event_name ) {
		if ( ! is_array( $packages ) || empty( $packages ) ) {
			return $packages;
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
			$event_id = null;
			if ( ! empty( $item['product_id'] ) ) {
				$event_id = call_user_func( $event_for_product, (int) $item['product_id'] );
			}
			if ( null !== $event_id ) {
				$has_event = true;
			}
			$items[] = array(
				'key'            => (string) $key,
				'needs_shipping' => isset( $item['data'] ) && is_object( $item['data'] ) && $item['data']->needs_shipping(),
				'event_id'       => $event_id,
			);
		}
		if ( ! $has_event ) {
			return $packages;
		}

		$first  = reset( $packages );
		$groups = Fe_Cart_Grouper::group( $items );
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
			$package['fe_event_name']   = null !== $group['event_id'] ? (string) call_user_func( $event_name, $group['event_id'] ) : '';
			$package['fe_customer_id']  = isset( $user['ID'] ) ? (int) $user['ID'] : 0;
			$result[]                   = $package;
		}
		return $result;
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
		$package = self::$rendering_package;
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
	 * @return array{status:string, event_id:?int}
	 */
	public static function product_event_status( $product_id ) {
		$product_id = (int) $product_id;
		if ( isset( self::$status_cache[ $product_id ] ) ) {
			return self::$status_cache[ $product_id ];
		}

		$ids = Fe_Product_Links::event_ids_for_product( $product_id );
		if ( empty( $ids ) ) {
			$result = array(
				'status'   => 'none',
				'event_id' => null,
			);
		} else {
			if ( null === self::$open_events ) {
				self::$open_events = Fe_Event_Repo::open_events( current_time( 'mysql' ) );
			}
			$resolved = Fe_Event_Resolver::resolve( $ids, self::$open_events, current_time( 'Y-m-d' ) );
			$result   = array(
				'status'   => Fe_Event_Resolver::status( $ids, $resolved ),
				'event_id' => $resolved,
			);
		}
		self::$status_cache[ $product_id ] = $result;
		return $result;
	}

	/**
	 * Blocks purchase of products whose events are all closed.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product or variation.
	 * @return bool
	 */
	public static function filter_purchasable( $purchasable, $product ) {
		if ( ! $purchasable ) {
			return $purchasable;
		}
		$id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$st = self::product_event_status( $id );
		return 'closed' === $st['status'] ? false : $purchasable;
	}
}
