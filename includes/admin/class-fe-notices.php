<?php
/**
 * Admin hint for products that look like tickets or classes but are not in
 * any event (and so need no shipping handling).
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Possible no-shipping product notice.
 */
class Fe_Notices {

	const USER_META = 'fe_dismissed_ticket_hint';
	const TRANSIENT = 'fe_ticket_hint_products';
	const MAX_LIST  = 10;

	/**
	 * Hooks the notice.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_fe_dismiss_ticket_hint', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'clear_cache' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'clear_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Pure: whether a product name suggests a class, ticket or lesson.
	 *
	 * @param string $name Product name.
	 * @return bool
	 */
	public static function looks_like_ticket( $name ) {
		return 1 === preg_match( '/\b(class|workshop|ticket|lesson)(es|s)?\b/i', (string) $name );
	}

	/**
	 * Drops the cached product list.
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Matching products: published, physical, in no event. Cached 12 hours.
	 *
	 * @return array[] Rows of {id, name}, at most MAX_LIST.
	 */
	public static function matching_products() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found    = array();
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'virtual' => false,
				'limit'   => 200,
			)
		);
		foreach ( (array) $products as $product ) {
			if ( count( $found ) >= self::MAX_LIST ) {
				break;
			}
			if ( ! self::looks_like_ticket( $product->get_name() ) ) {
				continue;
			}
			if ( Fe_Product_Links::event_ids_for_product( (int) $product->get_id() ) ) {
				continue;
			}
			$found[] = array(
				'id'   => (int) $product->get_id(),
				'name' => (string) $product->get_name(),
			);
		}
		set_transient( self::TRANSIENT, $found, 12 * HOUR_IN_SECONDS );
		return $found;
	}

	/**
	 * Whether the current screen is a Fulfillment screen or the products list.
	 *
	 * @return bool
	 */
	private static function on_relevant_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return 'edit-product' === $screen->id
			|| Fe_Event_Repo::POST_TYPE === $screen->post_type
			|| false !== strpos( (string) $screen->id, 'fe-locations' )
			|| false !== strpos( (string) $screen->id, 'fe-settings' );
	}

	/**
	 * Prints the notice.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::on_relevant_screen() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::USER_META, true ) ) {
			return;
		}
		$products = self::matching_products();
		if ( ! $products ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=fe_dismiss_ticket_hint' ), 'fe_dismiss_ticket_hint' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'These products look like tickets or classes. If they need no shipping, mark them virtual so checkout skips delivery options. If they are sold for specific dates, add them to an event.', 'fulfillment-events-for-woocommerce' ) . '</p><ul>';
		foreach ( $products as $row ) {
			echo '<li><a href="' . esc_url( get_edit_post_link( $row['id'] ) ) . '">' . esc_html( $row['name'] ) . '</a></li>';
		}
		echo '</ul><p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss this hint', 'fulfillment-events-for-woocommerce' ) . '</a></p></div>';
	}

	/**
	 * Dismisses the hint for the current user.
	 */
	public static function handle_dismiss() {
		check_admin_referer( 'fe_dismiss_ticket_hint' );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, 1 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
