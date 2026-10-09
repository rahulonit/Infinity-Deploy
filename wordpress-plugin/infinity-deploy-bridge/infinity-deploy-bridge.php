<?php
/**
 * Plugin Name: Infinity Deploy Bridge
 * Plugin URI:  https://rahul.graphics
 * Description: Secure deployment bridge for Infinity Deploy. Supports guarded theme, content, media, database, and site-file synchronization with backups and rollback.
 * Version:     1.0.3
 * Author:      Infinity
 * Author URI:  https://rahul.graphics
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: infinity-deploy
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Tested up to: 7.1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'INFINITY_DEPLOY_VERSION', '1.0.3' );
define( 'INFINITY_DEPLOY_FILE', __FILE__ );
define( 'INFINITY_DEPLOY_PATH', plugin_dir_path( __FILE__ ) );
define( 'INFINITY_DEPLOY_URL', plugin_dir_url( __FILE__ ) );

require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-backups.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-content.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-database.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-safety.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-site-pack.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-api.php';
require_once INFINITY_DEPLOY_PATH . 'includes/class-infinity-deploy-admin.php';

function infinity_deploy_activate() {
	$options = get_option( 'infinity_deploy_settings', array() );
	update_option( 'infinity_deploy_settings', wp_parse_args( $options, array(
		'enabled'           => '1',
		'allowed_themes'    => 'rahul-graphics',
		'content_types'     => array( 'page', 'rg_product', 'rg_service', 'rg_testimonial' ),
		'max_package_mb'    => 200,
		'retained_backups'  => 5,
	) ) );
}
register_activation_hook( __FILE__, 'infinity_deploy_activate' );

function infinity_deploy_boot() {
	Infinity_Deploy_API::instance();
	if ( is_admin() ) { Infinity_Deploy_Admin::instance(); }
}
add_action( 'plugins_loaded', 'infinity_deploy_boot' );
