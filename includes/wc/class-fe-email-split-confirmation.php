<?php
/**
 * Customer email sent once when an order is split.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One confirmation listing every order the purchase was split into.
 */
class Fe_Email_Split_Confirmation extends WC_Email {

	/**
	 * Child orders listed in the email.
	 *
	 * @var WC_Order[]
	 */
	public $children = array();

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		$this->id             = 'fe_split_confirmation';
		$this->customer_email = true;
		$this->title          = __( 'Split order confirmation', 'fulfillment-events-for-woocommerce' );
		$this->description    = __( 'Sent to the customer once when an order is split by date. It replaces the per-order processing and on-hold emails.', 'fulfillment-events-for-woocommerce' );
		$this->template_base  = FE_PLUGIN_DIR . 'templates/';
		$this->template_html  = 'emails/split-confirmation.php';
		$this->template_plain = 'emails/plain/split-confirmation.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is confirmed', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thanks for your order', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Sends the email.
	 *
	 * @param WC_Order   $parent   Split parent order.
	 * @param WC_Order[] $children Child orders.
	 */
	public function trigger( $parent, $children = array() ) {
		if ( ! $parent instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $parent;
		$this->children                       = is_array( $children ) ? $children : array();
		$this->recipient                      = $parent->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $parent->get_date_created() );
		$this->placeholders['{order_number}'] = $parent->get_order_number();

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * Template arguments shared by both formats.
	 *
	 * @param bool $plain Plain text format.
	 * @return array
	 */
	private function template_args( $plain ) {
		return array(
			'order'              => $this->object,
			'children'           => $this->children,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Each order above is prepared and fulfilled separately.', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Label for a fulfillment type.
	 *
	 * @param string $type delivery|pickup|courier.
	 * @return string
	 */
	public static function type_label( $type ) {
		return Fe_Emails::type_label( $type );
	}

	/**
	 * Formats a stored Y-m-d date for display.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function format_date( $ymd ) {
		$ts = strtotime( $ymd . ' 12:00:00' );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : (string) $ymd;
	}
}
