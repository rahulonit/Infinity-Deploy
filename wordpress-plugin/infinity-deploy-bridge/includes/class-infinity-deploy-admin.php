<?php
/** WordPress administrator settings. @package InfinityDeploy */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Infinity_Deploy_Admin {
	private static $instance;
	public static function instance() { if ( ! self::$instance ) { self::$instance = new self(); } return self::$instance; }
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_management_page( __( 'Infinity Deploy', 'infinity-deploy' ), __( 'Infinity Deploy', 'infinity-deploy' ), 'manage_options', 'infinity-deploy', array( $this, 'render' ) );
	}

	public function settings() {
		register_setting( 'infinity_deploy', 'infinity_deploy_settings', array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public static function get_available_post_types() {
		$types = array(
			'page' => __( 'Pages', 'infinity-deploy' ),
			'post' => __( 'Posts', 'infinity-deploy' ),
		);
		$custom = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		foreach ( $custom as $slug => $obj ) {
			$types[ $slug ] = ! empty( $obj->labels->name ) ? $obj->labels->name : ucfirst( str_replace( array( 'rg_', '_' ), array( '', ' ' ), $slug ) );
		}
		// Guarantee presence of custom portfolio types even if theme is temporarily inactive
		foreach ( array( 'rg_product' => 'Products', 'rg_service' => 'Services', 'rg_testimonial' => 'Testimonials' ) as $legacy_slug => $legacy_label ) {
			if ( ! isset( $types[ $legacy_slug ] ) ) {
				$types[ $legacy_slug ] = $legacy_label;
			}
		}
		return apply_filters( 'infinity_deploy_admin_available_post_types', $types );
	}

	public function sanitize( $input ) {
		$current = Infinity_Deploy_API::settings();
		$available = array_keys( self::get_available_post_types() );
		return array(
			'enabled' => isset( $input['enabled'] ) ? '1' : '0',
			'allowed_themes' => implode( ',', array_values( array_filter( array_map( 'sanitize_key', preg_split( '/\s*,\s*/', (string) ( $input['allowed_themes'] ?? $current['allowed_themes'] ) ) ) ) ) ),
			'content_types' => array_values( array_intersect( $available, array_map( 'sanitize_key', (array) ( $input['content_types'] ?? array() ) ) ) ),
			'max_package_mb' => min( 2048, max( 1, absint( $input['max_package_mb'] ?? 200 ) ) ),
			'retained_backups' => min( 20, max( 1, absint( $input['retained_backups'] ?? 5 ) ) ),
		);
	}

	public function assets( $hook ) {
		if ( 'tools_page_infinity-deploy' !== $hook ) { return; }
		wp_enqueue_style( 'infinity-deploy-admin', INFINITY_DEPLOY_URL . 'assets/admin.css', array(), INFINITY_DEPLOY_VERSION );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$settings = Infinity_Deploy_API::settings();
		$logs = array_slice( array_reverse( (array) get_option( 'infinity_deploy_logs', array() ) ), 0, 20 );
		?>
		<div class="wrap infinity-deploy-wrap">
			<header class="infinity-deploy-hero"><span class="infinity-deploy-mark" aria-hidden="true">∞</span><div><h1><?php esc_html_e( 'Infinity Deploy', 'infinity-deploy' ); ?></h1><p><?php esc_html_e( 'A guarded connection between Local and this WordPress site.', 'infinity-deploy' ); ?></p></div></header>
			<div class="infinity-deploy-grid">
				<section class="infinity-card">
					<h2><?php esc_html_e( 'Bridge settings', 'infinity-deploy' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'infinity_deploy' ); ?>
						<label class="infinity-toggle"><input type="checkbox" name="infinity_deploy_settings[enabled]" value="1" <?php checked( '1', $settings['enabled'] ); ?>><span><strong><?php esc_html_e( 'Enable authenticated deployments', 'infinity-deploy' ); ?></strong><small><?php esc_html_e( 'Keep disabled whenever Local is not being used to deploy.', 'infinity-deploy' ); ?></small></span></label>
						<label><span><?php esc_html_e( 'Allowed theme slugs', 'infinity-deploy' ); ?></span><input class="regular-text" type="text" name="infinity_deploy_settings[allowed_themes]" value="<?php echo esc_attr( $settings['allowed_themes'] ); ?>"><small><?php esc_html_e( 'Comma-separated. Infinity Deploy rejects every other theme.', 'infinity-deploy' ); ?></small></label>
						<fieldset><legend><?php esc_html_e( 'Content allowed to sync', 'infinity-deploy' ); ?></legend><?php foreach ( self::get_available_post_types() as $type => $label ) : ?><label class="infinity-check"><input type="checkbox" name="infinity_deploy_settings[content_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['content_types'], true ) ); ?>><?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
						<div class="infinity-inline"><label><span><?php esc_html_e( 'Maximum uploaded package size (MB)', 'infinity-deploy' ); ?></span><input type="number" min="1" max="2048" name="infinity_deploy_settings[max_package_mb]" value="<?php echo esc_attr( $settings['max_package_mb'] ); ?>"></label><label><span><?php esc_html_e( 'Backups retained', 'infinity-deploy' ); ?></span><input type="number" min="1" max="20" name="infinity_deploy_settings[retained_backups]" value="<?php echo esc_attr( $settings['retained_backups'] ); ?>"></label></div>
						<?php submit_button( __( 'Save bridge settings', 'infinity-deploy' ) ); ?>
					</form>
				</section>
				<aside class="infinity-card infinity-connection">
					<h2><?php esc_html_e( 'Connect Local', 'infinity-deploy' ); ?></h2>
					<ol><li><?php esc_html_e( 'Create a dedicated administrator or deployment user.', 'infinity-deploy' ); ?></li><li><?php esc_html_e( 'Open that user’s Profile and create an Application Password named “Infinity Deploy”.', 'infinity-deploy' ); ?></li><li><?php esc_html_e( 'Enter the site URL, username, and generated Application Password in the Local add-on.', 'infinity-deploy' ); ?></li><li><?php esc_html_e( 'Enable deployments above, then test the connection from Local.', 'infinity-deploy' ); ?></li></ol>
					<dl><dt><?php esc_html_e( 'REST endpoint', 'infinity-deploy' ); ?></dt><dd><code><?php echo esc_html( rest_url( Infinity_Deploy_API::NS . '/' ) ); ?></code></dd><dt><?php esc_html_e( 'PHP ZIP support', 'infinity-deploy' ); ?></dt><dd><?php echo class_exists( 'ZipArchive' ) ? '<span class="infinity-good">Available</span>' : '<span class="infinity-bad">Missing</span>'; ?></dd><dt><?php esc_html_e( 'Environment', 'infinity-deploy' ); ?></dt><dd><?php echo esc_html( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ); ?></dd></dl>
					<p class="description"><?php esc_html_e( 'Application Passwords are individually revocable. Never enter the main WordPress password in the Local add-on.', 'infinity-deploy' ); ?></p>
				</aside>
			</div>
			<?php $db_snapshots = Infinity_Deploy_Safety::list_snapshots(); ?>
			<?php if ( $db_snapshots ) : ?>
			<section class="infinity-card infinity-log" style="margin-top:20px;">
				<h2><?php esc_html_e( 'Database Safety Snapshots', 'infinity-deploy' ); ?></h2>
				<div class="infinity-table"><table><thead><tr><th><?php esc_html_e( 'Snapshot Key', 'infinity-deploy' ); ?></th><th><?php esc_html_e( 'Created (GMT)', 'infinity-deploy' ); ?></th><th><?php esc_html_e( 'Size', 'infinity-deploy' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_slice( $db_snapshots, 0, 10 ) as $snap ) : ?>
					<tr><td><code><?php echo esc_html( $snap['key'] ); ?></code></td><td><?php echo esc_html( $snap['created_gmt'] ); ?></td><td><?php echo esc_html( size_format( $snap['size'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			</section>
			<?php endif; ?>
			<section class="infinity-card infinity-log"><h2><?php esc_html_e( 'Recent deployment activity', 'infinity-deploy' ); ?></h2><?php if ( $logs ) : ?><div class="infinity-table"><table><thead><tr><th><?php esc_html_e( 'Time (GMT)', 'infinity-deploy' ); ?></th><th><?php esc_html_e( 'Action', 'infinity-deploy' ); ?></th><th><?php esc_html_e( 'Status', 'infinity-deploy' ); ?></th><th><?php esc_html_e( 'Details', 'infinity-deploy' ); ?></th></tr></thead><tbody><?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( $log['time_gmt'] ?? '' ); ?></td><td><?php echo esc_html( $log['action'] ?? '' ); ?></td><td><span class="infinity-status infinity-status-<?php echo esc_attr( $log['status'] ?? '' ); ?>"><?php echo esc_html( $log['status'] ?? '' ); ?></span></td><td><code><?php echo esc_html( wp_json_encode( $log['context'] ?? array() ) ); ?></code></td></tr><?php endforeach; ?></tbody></table></div><?php else : ?><p><?php esc_html_e( 'No deployments have been recorded yet.', 'infinity-deploy' ); ?></p><?php endif; ?></section>
		</div>
		<?php
	}
}
