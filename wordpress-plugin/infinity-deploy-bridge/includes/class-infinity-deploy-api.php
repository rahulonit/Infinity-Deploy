<?php
/** Authenticated REST deployment API. @package InfinityDeploy */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Infinity_Deploy_API {
	private static $instance;
	const NS = 'infinity-deploy/v1';

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'determine_current_user', array( $this, 'authenticate_basic_auth' ), 20 );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_context', '__return_true' );
	}

	/**
	 * Authenticate REST API requests via a WordPress Application Password.
	 */
	public function authenticate_basic_auth( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$username = '';
		$password = '';

		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$username = $_SERVER['PHP_AUTH_USER'];
			$password = $_SERVER['PHP_AUTH_PW'];
		} else {
			$auth_header = '';
			if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
			} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			} elseif ( function_exists( 'getallheaders' ) ) {
				$headers = getallheaders();
				foreach ( $headers as $key => $val ) {
					if ( 0 === strcasecmp( $key, 'authorization' ) ) {
						$auth_header = $val;
						break;
					}
				}
			}

			if ( ! empty( $auth_header ) && 0 === stripos( $auth_header, 'basic ' ) ) {
				$decoded = base64_decode( trim( substr( $auth_header, 6 ) ) );
				if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
					list( $username, $password ) = explode( ':', $decoded, 2 );
				}
			}
		}

		if ( '' === $username || '' === $password ) {
			return $user_id;
		}

		// Primary account passwords are intentionally not accepted by this bridge.
		if ( function_exists( 'wp_authenticate_application_password' ) ) {
			$app_user = wp_authenticate_application_password( null, $username, $password );
			if ( ! is_wp_error( $app_user ) && $app_user instanceof WP_User ) {
				return $app_user->ID;
			}
		}

		return $user_id;
	}

	public static function settings() {
		return wp_parse_args( get_option( 'infinity_deploy_settings', array() ), array(
			'enabled' => '1', 'allowed_themes' => 'rahul-graphics', 'content_types' => array( 'page', 'rg_product', 'rg_service', 'rg_testimonial' ), 'max_package_mb' => 200, 'retained_backups' => 5,
		) );
	}

	public function routes() {
		$routes = array(
			'/status' => array( 'GET', 'status', 'can_read' ),
			'/content/export' => array( 'GET', 'export_content', 'can_export' ),
			'/content/manifest' => array( 'GET', 'content_manifest', 'can_export' ),
			'/content/sync' => array( 'POST', 'sync_content', 'can_deploy' ),
			'/media/sync' => array( 'POST', 'sync_media', 'can_deploy' ),
			'/theme/deploy' => array( 'POST', 'deploy_theme', 'can_deploy_theme' ),
			'/theme/rollback' => array( 'POST', 'rollback_theme', 'can_deploy_theme' ),
			'/db/export' => array( 'POST', 'export_database', 'can_deploy' ),
			'/db/import' => array( 'POST', 'import_database', 'can_deploy' ),
			'/db/snapshot' => array( 'POST', 'create_db_snapshot', 'can_deploy' ),
			'/db/restore' => array( 'POST', 'restore_db_snapshot', 'can_deploy' ),
			'/db/snapshots' => array( 'GET', 'list_db_snapshots', 'can_read' ),
			'/site/export' => array( 'POST', 'export_site_pack', 'can_deploy' ),
			'/site/import' => array( 'POST', 'import_site_pack', 'can_deploy' ),
			'/package/download' => array( 'GET', 'download_package', 'can_deploy' ),
			'/package/cleanup' => array( 'POST', 'cleanup_package', 'can_deploy' ),
			'/maintenance' => array( 'POST', 'maintenance', 'can_deploy' ),
			'/logs' => array( 'GET', 'logs', 'can_read' ),
		);
		foreach ( $routes as $route => $config ) {
			register_rest_route( self::NS, $route, array(
				'methods' => $config[0],
				'callback' => array( $this, $config[1] ),
				'permission_callback' => array( $this, $config[2] ),
			) );
		}
	}

	public function can_read() { return current_user_can( 'manage_options' ); }
	public function can_export() { return $this->enabled() && current_user_can( 'edit_posts' ); }
	public function can_deploy() { return $this->enabled() && current_user_can( 'manage_options' ); }
	public function can_deploy_theme() { return $this->enabled() && current_user_can( 'update_themes' ) && current_user_can( 'install_themes' ); }
	private function enabled() { return ! empty( self::settings()['enabled'] ); }

	public function status() {
		$theme = wp_get_theme();
		return rest_ensure_response( array(
			'ok' => true,
			'bridge_version' => INFINITY_DEPLOY_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'site_url' => site_url( '/' ),
			'home_url' => home_url( '/' ),
			'active_theme' => array( 'slug' => get_stylesheet(), 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
			'deployment_enabled' => $this->enabled(),
			'allowed_themes' => $this->allowed_themes(),
			'content_types' => Infinity_Deploy_Content::allowed_types(),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'zip_available' => class_exists( 'ZipArchive' ),
			'max_package_mb' => absint( self::settings()['max_package_mb'] ),
			'backups' => Infinity_Deploy_Backups::list_backups(),
			'db_snapshots' => Infinity_Deploy_Safety::list_snapshots(),
		) );
	}

	public function export_content() { return rest_ensure_response( Infinity_Deploy_Content::export() ); }
	public function content_manifest() { return rest_ensure_response( Infinity_Deploy_Content::manifest() ); }

	public function sync_content( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		$source_url = sanitize_text_field( (string) $request->get_param( 'source_url' ) );
		$result = Infinity_Deploy_Content::sync( $items, $source_url );
		if ( is_wp_error( $result ) ) { return $result; }
		self::log( 'content_sync', 'success', array( 'items' => count( $result ) ) );
		return rest_ensure_response( array( 'ok' => true, 'results' => $result ) );
	}

	public function sync_media( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['media'] ) ) { return new WP_Error( 'missing_media', __( 'No media file was uploaded.', 'infinity-deploy' ), array( 'status' => 400 ) ); }
		$result = Infinity_Deploy_Content::sync_media( $files['media'], $request->get_params() );
		if ( is_wp_error( $result ) ) { self::log( 'media_sync', 'failed', array( 'code' => $result->get_error_code() ) ); return $result; }
		self::log( 'media_sync', 'success', array( 'uuid' => $result['uuid'], 'status' => $result['status'] ) );
		return rest_ensure_response( array( 'ok' => true, 'media' => $result ) );
	}

	public function deploy_theme( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$params = $request->get_params();
		$slug = isset( $params['theme_slug'] ) ? sanitize_key( $params['theme_slug'] ) : '';
		if ( ! in_array( $slug, $this->allowed_themes(), true ) ) { return new WP_Error( 'theme_not_allowed', __( 'This theme is not on the deployment allowlist.', 'infinity-deploy' ), array( 'status' => 403 ) ); }
		if ( empty( $files['package'] ) || UPLOAD_ERR_OK !== intval( $files['package']['error'] ) ) { return new WP_Error( 'missing_package', __( 'No valid theme package was uploaded.', 'infinity-deploy' ), array( 'status' => 400 ) ); }

		$file = $files['package'];
		$max = max( 1, absint( self::settings()['max_package_mb'] ) ) * MB_IN_BYTES;
		if ( intval( $file['size'] ) > $max ) { return new WP_Error( 'package_too_large', __( 'The theme package exceeds the configured size limit.', 'infinity-deploy' ), array( 'status' => 413 ) ); }
		if ( 'zip' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) { return new WP_Error( 'invalid_package_type', __( 'Only ZIP theme packages are accepted.', 'infinity-deploy' ), array( 'status' => 400 ) ); }

		$checksum = sanitize_text_field( (string) $request->get_header( 'x-infinity-checksum' ) );
		$actual = hash_file( 'sha256', $file['tmp_name'] );
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $checksum ) || ! hash_equals( strtolower( $checksum ), strtolower( $actual ) ) ) { return new WP_Error( 'checksum_mismatch', __( 'The uploaded package checksum is missing or does not match.', 'infinity-deploy' ), array( 'status' => 400 ) ); }
		$valid = Infinity_Deploy_Backups::validate_zip( $file['tmp_name'], $slug );
		if ( is_wp_error( $valid ) ) { return $valid; }

		$backup = Infinity_Deploy_Backups::create_theme_backup( $slug );
		if ( is_wp_error( $backup ) ) { return $backup; }
		$was_active = get_stylesheet() === $slug;
		self::log( 'theme_deploy', 'started', array( 'theme' => $slug, 'checksum' => $actual, 'backup' => $backup['key'] ) );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->install( $file['tmp_name'], array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) || ! $result ) {
			Infinity_Deploy_Backups::restore( $backup['key'], $slug );
			$code = is_wp_error( $result ) ? $result->get_error_code() : 'theme_upgrade_failed';
			self::log( 'theme_deploy', 'rolled_back', array( 'theme' => $slug, 'code' => $code ) );
			return is_wp_error( $result ) ? $result : new WP_Error( 'theme_upgrade_failed', __( 'WordPress could not install the theme package.', 'infinity-deploy' ), array( 'status' => 500 ) );
		}

		wp_clean_themes_cache( true );
		if ( $was_active && get_stylesheet() !== $slug ) { switch_theme( $slug ); }
		$new_theme = wp_get_theme( $slug );
		if ( ! $new_theme->exists() || $new_theme->errors() ) {
			Infinity_Deploy_Backups::restore( $backup['key'], $slug );
			return new WP_Error( 'theme_verification_failed', __( 'The installed theme failed verification and the previous backup was restored.', 'infinity-deploy' ), array( 'status' => 500 ) );
		}
		// Establish a conventional clean URL structure before the loopback checks.
		if ( ! get_option( 'permalink_structure' ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			update_option( 'permalink_structure', '/%postname%/' );
		}
		flush_rewrite_rules( false );

		// Allow newly installed theme or custom integrations to run post-deployment actions
		do_action( 'infinity_deploy_theme_deployed', $slug, $new_theme );

		$health = $this->health_check();
		if ( ! $health['ok'] ) {
			Infinity_Deploy_Backups::restore( $backup['key'], $slug );
			self::log( 'theme_deploy', 'rolled_back', array( 'theme' => $slug, 'reason' => 'health_check' ) );
			return new WP_Error( 'health_check_failed', __( 'A critical health check failed. The previous theme was restored.', 'infinity-deploy' ), array( 'status' => 500, 'checks' => $health['checks'] ) );
		}

		self::log( 'theme_deploy', 'success', array( 'theme' => $slug, 'version' => $new_theme->get( 'Version' ), 'backup' => $backup['key'] ) );
		return rest_ensure_response( array( 'ok' => true, 'theme' => array( 'slug' => $slug, 'name' => $new_theme->get( 'Name' ), 'version' => $new_theme->get( 'Version' ) ), 'backup_key' => $backup['key'], 'health' => $health ) );
	}

	public function rollback_theme( WP_REST_Request $request ) {
		$key = sanitize_file_name( (string) $request->get_param( 'backup_key' ) );
		$slug = sanitize_key( (string) $request->get_param( 'theme_slug' ) );
		if ( ! in_array( $slug, $this->allowed_themes(), true ) ) { return new WP_Error( 'theme_not_allowed', __( 'This theme is not on the deployment allowlist.', 'infinity-deploy' ), array( 'status' => 403 ) ); }
		$result = Infinity_Deploy_Backups::restore( $key, $slug );
		if ( is_wp_error( $result ) ) { self::log( 'theme_rollback', 'failed', array( 'theme' => $slug, 'backup' => $key, 'code' => $result->get_error_code() ) ); return $result; }
		self::log( 'theme_rollback', 'success', array( 'theme' => $slug, 'backup' => $key ) );
		return rest_ensure_response( array( 'ok' => true, 'theme_slug' => $slug, 'backup_key' => $key, 'health' => $this->health_check() ) );
	}

	public function export_database( WP_REST_Request $request ) {
		$params = $request->get_params();
		$exclude = array();
		if ( ! empty( $params['exclude_users'] ) ) {
			global $wpdb;
			$exclude = array( $wpdb->users, $wpdb->usermeta );
		}
		$file = Infinity_Deploy_Database::export( array( 'exclude_tables' => $exclude ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$size = filesize( $file );
		$filename = basename( $file );
		$is_gz = ( false !== strpos( $filename, '.gz' ) );

		self::log( 'db_export', 'success', array( 'file' => $filename, 'size' => $size ) );

		return rest_ensure_response( array(
			'ok' => true,
			'file_key' => $filename,
			'filename' => $filename,
			'size' => $size,
			'compressed' => $is_gz,
			'site_url' => site_url(),
			'site_path' => ABSPATH,
			'prefix' => $GLOBALS['wpdb']->prefix,
		) );
	}

	public function import_database( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$params = $request->get_params();

		// Create automated safety snapshot before overwriting database
		$safety_snapshot = Infinity_Deploy_Safety::create_db_snapshot( 'auto-pre-import' );
		if ( is_wp_error( $safety_snapshot ) ) {
			return $safety_snapshot;
		}

		$sql_file = '';
		$is_temp = false;

		if ( ! empty( $files['database']['tmp_name'] ) && is_uploaded_file( $files['database']['tmp_name'] ) ) {
			$sql_file = $files['database']['tmp_name'];
		} elseif ( ! empty( $params['file_key'] ) ) {
			$candidate = trailingslashit( Infinity_Deploy_Backups::directory() ) . self::sanitize_package_key( $params['file_key'] );
			if ( file_exists( $candidate ) ) {
				$sql_file = $candidate;
				$is_temp = true;
			}
		} elseif ( ! empty( $params['sql_gz'] ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( base64_decode( $params['sql_gz'] ) );
			$sql_file = trailingslashit( Infinity_Deploy_Backups::directory() ) . 'import-' . wp_generate_password( 6, false, false ) . '.sql';
			file_put_contents( $sql_file, $decoded );
			$is_temp = true;
		} elseif ( ! empty( $params['sql'] ) ) {
			$decoded = base64_decode( $params['sql'] );
			$sql_file = trailingslashit( Infinity_Deploy_Backups::directory() ) . 'import-' . wp_generate_password( 6, false, false ) . '.sql';
			file_put_contents( $sql_file, $decoded );
			$is_temp = true;
		}

		if ( empty( $sql_file ) || ! file_exists( $sql_file ) ) {
			return new WP_Error( 'missing_sql', __( 'No database package received.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}
		$max = max( 1, absint( self::settings()['max_package_mb'] ) ) * MB_IN_BYTES;
		if ( filesize( $sql_file ) > $max ) {
			if ( $is_temp ) { @unlink( $sql_file ); }
			return new WP_Error( 'package_too_large', __( 'The database package exceeds the configured size limit.', 'infinity-deploy' ), array( 'status' => 413 ) );
		}

		$preserve = array();
		if ( ! empty( $params['preserve_users'] ) ) {
			global $wpdb;
			$preserve = array( $wpdb->users, $wpdb->usermeta );
		}

		$replacements = array(
			'source_url' => isset( $params['source_url'] ) ? esc_url_raw( $params['source_url'] ) : '',
			'target_url' => site_url(),
			'source_path' => isset( $params['source_path'] ) ? sanitize_text_field( $params['source_path'] ) : '',
			'target_path' => ABSPATH,
			'source_prefix' => isset( $params['source_prefix'] ) ? sanitize_key( $params['source_prefix'] ) : '',
		);

		$result = Infinity_Deploy_Database::import( $sql_file, $replacements, $preserve );
		if ( $is_temp ) {
			@unlink( $sql_file );
		}

		if ( is_wp_error( $result ) ) {
			// Auto rollback to pre-import snapshot
			Infinity_Deploy_Safety::restore_db_snapshot( $safety_snapshot['key'] );
			self::log( 'db_import', 'rolled_back', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		$health = $this->health_check();
		if ( ! $health['ok'] ) {
			// Auto rollback if health check fails
			Infinity_Deploy_Safety::restore_db_snapshot( $safety_snapshot['key'] );
			self::log( 'db_import', 'rolled_back', array( 'reason' => 'health_check' ) );
			return new WP_Error( 'db_health_failed', __( 'Database imported but critical health checks failed. Restored to pre-import snapshot.', 'infinity-deploy' ), array( 'status' => 500 ) );
		}

		self::log( 'db_import', 'success', array( 'snapshot' => $safety_snapshot['key'] ) );
		return rest_ensure_response( array(
			'ok' => true,
			'safety_snapshot' => $safety_snapshot['key'],
			'import' => $result,
			'health' => $health,
		) );
	}

	public function create_db_snapshot( WP_REST_Request $request ) {
		$label = sanitize_key( (string) $request->get_param( 'label' ) ?: 'manual' );
		$result = Infinity_Deploy_Safety::create_db_snapshot( $label );
		if ( is_wp_error( $result ) ) { return $result; }
		self::log( 'db_snapshot', 'success', array( 'key' => $result['key'] ) );
		return rest_ensure_response( array( 'ok' => true, 'snapshot' => $result ) );
	}

	public function restore_db_snapshot( WP_REST_Request $request ) {
		$key = sanitize_file_name( (string) $request->get_param( 'snapshot_key' ) );
		if ( empty( $key ) ) { return new WP_Error( 'missing_key', __( 'Snapshot key is required.', 'infinity-deploy' ), array( 'status' => 400 ) ); }
		$result = Infinity_Deploy_Safety::restore_db_snapshot( $key );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( array( 'ok' => true, 'result' => $result, 'health' => $this->health_check() ) );
	}

	public function list_db_snapshots() {
		return rest_ensure_response( array( 'ok' => true, 'snapshots' => Infinity_Deploy_Safety::list_snapshots() ) );
	}

	public function export_site_pack( WP_REST_Request $request ) {
		$components = $request->get_param( 'components' );
		if ( empty( $components ) || ! is_array( $components ) ) {
			$components = array( 'uploads' );
		}
		$package = Infinity_Deploy_Site_Pack::package( $components );
		if ( is_wp_error( $package ) ) { return $package; }

		$filename = basename( $package['path'] );
		self::log( 'site_export', 'success', array( 'components' => $components, 'file' => $filename, 'size' => $package['size'] ) );

		return rest_ensure_response( array(
			'ok' => true,
			'file_key' => $filename,
			'filename' => $filename,
			'size' => $package['size'],
		) );
	}

	public static function sanitize_package_key( $key ) {
		$key = basename( trim( (string) $key ) );
		$key = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $key );
		return ltrim( $key, '.' );
	}

	public function import_site_pack( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$params = $request->get_params();
		$zip_file = '';
		$is_temp = false;

		if ( ! empty( $files['package']['tmp_name'] ) && is_uploaded_file( $files['package']['tmp_name'] ) ) {
			$zip_file = $files['package']['tmp_name'];
		} elseif ( ! empty( $params['file_key'] ) ) {
			$candidate = trailingslashit( Infinity_Deploy_Backups::directory() ) . self::sanitize_package_key( $params['file_key'] );
			if ( file_exists( $candidate ) ) {
				$zip_file = $candidate;
				$is_temp = true;
			}
		} elseif ( ! empty( $params['zip'] ) ) {
			$decoded = base64_decode( $params['zip'] );
			$zip_file = trailingslashit( Infinity_Deploy_Backups::directory() ) . 'pack-' . wp_generate_password( 6, false, false ) . '.zip';
			file_put_contents( $zip_file, $decoded );
			$is_temp = true;
		}

		if ( empty( $zip_file ) || ! file_exists( $zip_file ) ) {
			return new WP_Error( 'missing_package', __( 'No site package ZIP received.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}
		$max = max( 1, absint( self::settings()['max_package_mb'] ) ) * MB_IN_BYTES;
		if ( filesize( $zip_file ) > $max ) {
			if ( $is_temp ) { @unlink( $zip_file ); }
			return new WP_Error( 'package_too_large', __( 'The site package exceeds the configured size limit.', 'infinity-deploy' ), array( 'status' => 413 ) );
		}

		$result = Infinity_Deploy_Site_Pack::extract( $zip_file );
		if ( $is_temp ) {
			@unlink( $zip_file );
		}

		if ( is_wp_error( $result ) ) {
			self::log( 'site_import', 'failed', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		self::log( 'site_import', 'success', array() );
		return rest_ensure_response( array( 'ok' => true, 'health' => $this->health_check() ) );
	}

	public function download_package( WP_REST_Request $request ) {
		$file_key = self::sanitize_package_key( $request->get_param( 'file_key' ) );
		if ( empty( $file_key ) ) {
			return new WP_Error( 'missing_file_key', __( 'File key is required.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}

		// Security: prevent any path traversal
		if ( false !== strpos( $file_key, '..' ) || false !== strpos( $file_key, '/' ) || false !== strpos( $file_key, '\\' ) ) {
			return new WP_Error( 'invalid_key', __( 'Invalid package key.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}

		$backup_dir = Infinity_Deploy_Backups::directory();
		$file_path = trailingslashit( $backup_dir ) . $file_key;

		// Fallback check for extensions or mangled names
		if ( ! file_exists( $file_path ) ) {
			if ( file_exists( $file_path . '.gz' ) ) {
				$file_path = $file_path . '.gz';
			} elseif ( file_exists( str_replace( '.sql_.gz', '.sql.gz', $file_path ) ) ) {
				$file_path = str_replace( '.sql_.gz', '.sql.gz', $file_path );
			} elseif ( file_exists( str_replace( '.sql.gz', '.sql_.gz', $file_path ) ) ) {
				$file_path = str_replace( '.sql.gz', '.sql_.gz', $file_path );
			}
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Package file not found or unauthorized.', 'infinity-deploy' ), array( 'status' => 404 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filesize = filesize( $file_path );
		$filename = basename( $file_path );

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . $filesize );

		$handle = fopen( $file_path, 'rb' );
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 65536 );
				flush();
			}
			fclose( $handle );
		}
		exit;
	}

	public function cleanup_package( WP_REST_Request $request ) {
		$file_key = self::sanitize_package_key( $request->get_param( 'file_key' ) );
		if ( empty( $file_key ) ) {
			return new WP_Error( 'missing_file_key', __( 'File key is required.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}

		if ( false !== strpos( $file_key, '..' ) || false !== strpos( $file_key, '/' ) || false !== strpos( $file_key, '\\' ) ) {
			return new WP_Error( 'invalid_key', __( 'Invalid package key.', 'infinity-deploy' ), array( 'status' => 400 ) );
		}

		$backup_dir = Infinity_Deploy_Backups::directory();
		$file_path = trailingslashit( $backup_dir ) . $file_key;

		if ( ! file_exists( $file_path ) ) {
			if ( file_exists( $file_path . '.gz' ) ) {
				$file_path = $file_path . '.gz';
			} elseif ( file_exists( str_replace( '.sql_.gz', '.sql.gz', $file_path ) ) ) {
				$file_path = str_replace( '.sql_.gz', '.sql.gz', $file_path );
			}
		}

		if ( file_exists( $file_path ) ) {
			@unlink( $file_path );
			return rest_ensure_response( array( 'ok' => true, 'deleted' => basename( $file_path ) ) );
		}

		return rest_ensure_response( array( 'ok' => false, 'message' => 'File not found or already deleted' ) );
	}

	public function maintenance() {
		// Generic extensibility hook for themes and plugins
		do_action( 'infinity_deploy_maintenance' );

		// Legacy theme lifecycle support
		if ( function_exists( 'rahul_graphics_activate_defaults' ) ) { rahul_graphics_activate_defaults(); }
		if ( function_exists( 'rahul_graphics_seed_services_content' ) ) { rahul_graphics_seed_services_content(); }
		if ( function_exists( 'rahul_graphics_upgrade_content' ) ) { rahul_graphics_upgrade_content(); }
		flush_rewrite_rules( false );
		wp_clean_themes_cache( true );
		wp_cache_flush();
		$health = $this->health_check();
		self::log( 'maintenance', $health['ok'] ? 'success' : 'warning', array( 'checks' => $health['checks'] ) );
		return rest_ensure_response( array( 'ok' => $health['ok'], 'health' => $health ) );
	}

	public function logs() { return rest_ensure_response( array( 'logs' => array_reverse( (array) get_option( 'infinity_deploy_logs', array() ) ) ) ); }

	public static function log( $action, $status, $context = array() ) {
		$logs = (array) get_option( 'infinity_deploy_logs', array() );
		$logs[] = array( 'time_gmt' => gmdate( 'c' ), 'user_id' => get_current_user_id(), 'action' => sanitize_key( $action ), 'status' => sanitize_key( $status ), 'context' => map_deep( $context, 'sanitize_text_field' ) );
		if ( count( $logs ) > 200 ) { $logs = array_slice( $logs, -200 ); }
		update_option( 'infinity_deploy_logs', $logs, false );
	}

	private function allowed_themes() { return array_values( array_filter( array_map( 'sanitize_key', preg_split( '/\s*,\s*/', (string) self::settings()['allowed_themes'] ) ) ) ); }

	private function health_check() {
		$default_paths = array( '/', '/about/', '/contact/', '/products/', '/services/' );
		$paths = (array) apply_filters( 'infinity_deploy_health_check_paths', $default_paths );
		$critical_paths = (array) apply_filters( 'infinity_deploy_critical_health_check_paths', array( '/' ) );
		$sslverify = (bool) apply_filters( 'infinity_deploy_health_check_sslverify', ! ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE ) );
		$checks = array();
		$ok = true;
		foreach ( $paths as $path ) {
			$response = wp_remote_get( home_url( $path ), array( 'timeout' => 12, 'redirection' => 3, 'sslverify' => $sslverify, 'user-agent' => 'InfinityDeploy/' . INFINITY_DEPLOY_VERSION ) );
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$pass = $code >= 200 && $code < 400;
			if ( in_array( $path, $critical_paths, true ) && ! $pass ) { $ok = false; }
			$checks[] = array( 'path' => $path, 'status' => $code, 'pass' => $pass, 'error' => is_wp_error( $response ) ? $response->get_error_message() : '' );
		}
		return array( 'ok' => $ok, 'checks' => $checks, 'checked_gmt' => gmdate( 'c' ) );
	}
}
