<?php
/**
 * Admin shell: the Fulfillment menu, settings page, assets and shared helpers.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the menu and the settings screen.
 */
class Fe_Admin {

	const MENU_SLUG = 'fe-fulfillment';

	/**
	 * Hooks the admin screens.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_parent_menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'add_submenus' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_parent_duplicate' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_fe_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		Fe_Event_Editor::init();
		Fe_Locations_Page::init();
		Fe_Product_Fields::init();
	}

	/**
	 * Invalidates WooCommerce's cached shipping rates so edits apply at once.
	 */
	public static function flush_rate_cache() {
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * Prints the "Support this plugin" link.
	 */
	public static function support_link() {
		echo '<a class="fe-support" href="' . esc_url( FE_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support this plugin', 'fulfillment-events-for-woocommerce' ) . '</a>';
	}

	/**
	 * Top-level menu.
	 */
	public static function add_parent_menu() {
		add_menu_page(
			__( 'Fulfillment', 'fulfillment-events-for-woocommerce' ),
			__( 'Fulfillment', 'fulfillment-events-for-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'redirect_to_events' ),
			'dashicons-calendar-alt',
			56
		);
	}

	/**
	 * Locations and Settings under the menu (Events is the CPT list).
	 */
	public static function add_submenus() {
		add_submenu_page( self::MENU_SLUG, __( 'Locations', 'fulfillment-events-for-woocommerce' ), __( 'Locations', 'fulfillment-events-for-woocommerce' ), 'manage_woocommerce', 'fe-locations', array( 'Fe_Locations_Page', 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Settings', 'fulfillment-events-for-woocommerce' ), __( 'Settings', 'fulfillment-events-for-woocommerce' ), 'manage_woocommerce', 'fe-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * WordPress adds a self-titled first submenu; drop it so Events leads.
	 */
	public static function remove_parent_duplicate() {
		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
	}

	/**
	 * Fallback if the parent slug is opened directly.
	 */
	public static function redirect_to_events() {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Fe_Event_Repo::POST_TYPE ) );
		exit;
	}

	/**
	 * Loads CSS/JS on plugin screens only (and the products list for the badge).
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_fe  = ( $screen && ( Fe_Event_Repo::POST_TYPE === $screen->post_type || 'edit-product' === $screen->id ) )
			|| false !== strpos( (string) $hook, 'fe-locations' ) || false !== strpos( (string) $hook, 'fe-settings' );
		if ( ! $is_fe ) {
			return;
		}
		wp_enqueue_style( 'fe-admin', plugins_url( 'assets/admin.css', FE_PLUGIN_FILE ), array(), FE_VERSION );
		if ( $screen && Fe_Event_Repo::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_script( 'fe-admin', plugins_url( 'assets/admin.js', FE_PLUGIN_FILE ), array( 'jquery', 'wc-enhanced-select' ), FE_VERSION, true );
		}
	}

	/**
	 * Reads a yes/no option as a bool.
	 *
	 * @param string $name    Option name.
	 * @param bool   $default Default when unset.
	 * @return bool
	 */
	public static function flag( $name, $default ) {
		return 'yes' === get_option( $name, $default ? 'yes' : 'no' );
	}

	/**
	 * Settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fulfillment-events-for-woocommerce' ), 403 );
		}
		?>
		<div class="wrap fe-wrap">
			<div class="fe-header"><h1><?php esc_html_e( 'Fulfillment settings', 'fulfillment-events-for-woocommerce' ); ?></h1><?php self::support_link(); ?></div>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'fulfillment-events-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fe_save_settings">
				<?php wp_nonce_field( 'fe_save_settings' ); ?>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Staff emails', 'fulfillment-events-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="fe_staff_email_per_child" value="1" <?php checked( self::flag( 'fe_staff_email_per_child', true ) ); ?>> <?php esc_html_e( 'Send a new-order email for each order created when a multi-date order is split', 'fulfillment-events-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'When unticked, staff get one new-order email for the whole order instead.', 'fulfillment-events-for-woocommerce' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="fe_courier_instructions"><?php esc_html_e( 'Courier instructions', 'fulfillment-events-for-woocommerce' ); ?></label></th>
						<td><textarea id="fe_courier_instructions" name="fe_courier_instructions" rows="3" class="large-text"><?php echo esc_textarea( fe_courier_instructions() ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown under each courier option at checkout. Leave empty to show nothing.', 'fulfillment-events-for-woocommerce' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'fulfillment-events-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="fe_delete_data_on_uninstall" value="1" <?php checked( self::flag( 'fe_delete_data_on_uninstall', false ) ); ?>> <?php esc_html_e( 'Delete events, locations and zone rules when the plugin is deleted', 'fulfillment-events-for-woocommerce' ); ?></label></td>
					</tr>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
			<div class="fe-about">
				<p>
					<?php esc_html_e( 'Fulfillment Events is open source.', 'fulfillment-events-for-woocommerce' ); ?>
					<a href="<?php echo esc_url( FE_REPO_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See the project on GitHub', 'fulfillment-events-for-woocommerce' ); ?></a>
				</p>
				<?php // A plain link styled like the Buy Me a Coffee button: the official button loads a script from their servers on every settings visit. ?>
				<a class="fe-bmc" href="<?php echo esc_url( FE_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">❤️</span> <?php esc_html_e( 'Support this plugin', 'fulfillment-events-for-woocommerce' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the settings form.
	 */
	public static function handle_save_settings() {
		check_admin_referer( 'fe_save_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fulfillment-events-for-woocommerce' ), 403 );
		}
		update_option( 'fe_staff_email_per_child', isset( $_POST['fe_staff_email_per_child'] ) ? 'yes' : 'no' );
		update_option( 'fe_delete_data_on_uninstall', isset( $_POST['fe_delete_data_on_uninstall'] ) ? 'yes' : 'no' );
		update_option( 'fe_courier_instructions', isset( $_POST['fe_courier_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fe_courier_instructions'] ) ) : '' );
		self::flush_rate_cache();
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=fe-settings' ) ) );
		exit;
	}
}
