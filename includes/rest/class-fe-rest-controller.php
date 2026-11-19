<?php
/**
 * Read-only REST endpoints: fe/v1/events.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes events and their zone rules to users who can manage WooCommerce.
 */
class Fe_Rest_Controller {

	const NAMESPACE_V1 = 'fe/v1';

	/**
	 * Hooks route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_events' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/events/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_event' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /events.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_events() {
		$ids = get_posts(
			array(
				'post_type'      => Fe_Event_Repo::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => Fe_Event_Repo::MAX_EVENTS, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$event = Fe_Event_Repo::get( (int) $id );
			if ( $event ) {
				$out[] = $event;
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /events/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_event( $request ) {
		$event = Fe_Event_Repo::get( (int) $request['id'] );
		if ( ! $event ) {
			return new WP_Error( 'fe_event_not_found', __( 'Event not found.', 'fulfillment-events-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$event['zone_rules'] = Fe_Zone_Rule_Repo::for_event( $event['id'] );
		return rest_ensure_response( $event );
	}
}
