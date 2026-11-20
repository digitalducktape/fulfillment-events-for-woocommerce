<?php
/**
 * Locations screen: list, add, edit, delete.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pickup/delivery locations admin page.
 */
class Fe_Locations_Page {

	/**
	 * Hooks the form handlers.
	 */
	public static function init() {
		add_action( 'admin_post_fe_save_location', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_fe_delete_location', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Dies unless the current user can manage the plugin.
	 */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fulfillment-events-for-woocommerce' ), 403 );
		}
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=fe-locations' ) );
	}

	/**
	 * Saves a location.
	 */
	public static function handle_save() {
		check_admin_referer( 'fe_save_location' );
		self::require_cap();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_safe_redirect( self::url( array( 'error' => 'name' ) ) );
			exit;
		}
		Fe_Location_Repo::save(
			array(
				'id'           => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'name'         => $name,
				'address'      => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
				'instructions' => isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '',
				'is_default'   => ! empty( $_POST['is_default'] ),
			)
		);
		Fe_Admin::flush_rate_cache();
		wp_safe_redirect( self::url( array( 'updated' => '1' ) ) );
		exit;
	}

	/**
	 * Deletes a location.
	 */
	public static function handle_delete() {
		$id = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0;
		check_admin_referer( 'fe_delete_location_' . $id );
		self::require_cap();
		if ( $id ) {
			Fe_Location_Repo::delete( $id );
			Fe_Admin::flush_rate_cache();
		}
		wp_safe_redirect( self::url( array( 'deleted' => '1' ) ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		self::require_cap();
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = $edit_id ? Fe_Location_Repo::get( $edit_id ) : null;
		$loc     = $editing ? $editing : array(
			'id'           => 0,
			'name'         => '',
			'address'      => '',
			'instructions' => '',
			'is_default'   => ! Fe_Location_Repo::default_id(),
		);
		?>
		<div class="wrap fe-wrap">
			<div class="fe-header"><h1><?php esc_html_e( 'Locations', 'fulfillment-events-for-woocommerce' ); ?></h1><?php Fe_Admin::support_link(); ?></div>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location saved.', 'fulfillment-events-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Location deleted.', 'fulfillment-events-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'A location needs a name.', 'fulfillment-events-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped fe-locations">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'fulfillment-events-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Address', 'fulfillment-events-for-woocommerce' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( Fe_Location_Repo::all() as $row ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( $row['is_default'] ) : ?>
								<span class="fe-pill fe-pill--default"><?php esc_html_e( 'Default', 'fulfillment-events-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo nl2br( esc_html( $row['address'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( self::url( array( 'edit' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'fulfillment-events-for-woocommerce' ); ?></a>
							|
							<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fe_delete_location&location=' . (int) $row['id'] ), 'fe_delete_location_' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'fulfillment-events-for-woocommerce' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Edit location', 'fulfillment-events-for-woocommerce' ) : esc_html__( 'Add location', 'fulfillment-events-for-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fe_save_location">
				<input type="hidden" name="id" value="<?php echo esc_attr( $loc['id'] ); ?>">
				<?php wp_nonce_field( 'fe_save_location' ); ?>
				<table class="form-table"><tbody>
					<tr><th scope="row"><label for="fe-loc-name"><?php esc_html_e( 'Name', 'fulfillment-events-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="regular-text" id="fe-loc-name" name="name" value="<?php echo esc_attr( $loc['name'] ); ?>" required></td></tr>
					<tr><th scope="row"><label for="fe-loc-address"><?php esc_html_e( 'Address', 'fulfillment-events-for-woocommerce' ); ?></label></th>
						<td><textarea class="large-text" rows="3" id="fe-loc-address" name="address"><?php echo esc_textarea( $loc['address'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fe-loc-instructions"><?php esc_html_e( 'Instructions', 'fulfillment-events-for-woocommerce' ); ?></label></th>
						<td><textarea class="large-text" rows="3" id="fe-loc-instructions" name="instructions"><?php echo esc_textarea( $loc['instructions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown to customers who pick up here, for example where to park.', 'fulfillment-events-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Default', 'fulfillment-events-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="is_default" value="1" <?php checked( ! empty( $loc['is_default'] ) ); ?>> <?php esc_html_e( 'Use for dates that do not pick a location', 'fulfillment-events-for-woocommerce' ); ?></label></td></tr>
				</tbody></table>
				<?php submit_button( $editing ? __( 'Update location', 'fulfillment-events-for-woocommerce' ) : __( 'Add location', 'fulfillment-events-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
