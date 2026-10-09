<?php
/** Backup and restore helpers. @package InfinityDeploy */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Infinity_Deploy_Backups {
	public static function directory() {
		// Keep release archives outside the public media library.
		// Use a secret hash suffix so backups cannot be guessed on Nginx hosts where .htaccess is ignored.
		$secret = get_option( 'infinity_deploy_backup_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 24, false, false );
			update_option( 'infinity_deploy_backup_secret', $secret, false );
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . '.infinity-deploy-backups-' . $secret;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Migrate any legacy un-salted backups
			$legacy = trailingslashit( WP_CONTENT_DIR ) . '.infinity-deploy-backups';
			if ( is_dir( $legacy ) ) {
				$old_files = glob( trailingslashit( $legacy ) . '*.zip' );
				if ( $old_files ) {
					foreach ( $old_files as $old_file ) {
						@rename( $old_file, trailingslashit( $dir ) . basename( $old_file ) );
					}
				}
			}
		}
		if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/web.config', "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	public static function create_theme_backup( $theme_slug ) {
		$theme_slug = sanitize_key( $theme_slug );
		$source = trailingslashit( get_theme_root() ) . $theme_slug;
		if ( ! is_dir( $source ) ) { return new WP_Error( 'theme_missing', __( 'The installed theme directory was not found.', 'infinity-deploy' ) ); }
		if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip_unavailable', __( 'PHP ZipArchive is required for safe backups.', 'infinity-deploy' ) ); }

		$key = $theme_slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$path = trailingslashit( self::directory() ) . $key . '.zip';
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { return new WP_Error( 'backup_open_failed', __( 'The backup archive could not be created.', 'infinity-deploy' ) ); }

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) { continue; }
			$relative = $theme_slug . '/' . ltrim( str_replace( wp_normalize_path( $source ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
			$zip->addFile( $file->getPathname(), $relative );
		}
		$zip->close();
		self::prune();
		return array( 'key' => $key, 'path' => $path, 'size' => filesize( $path ) );
	}

	public static function restore( $key, $theme_slug ) {
		$key = sanitize_file_name( $key );
		$theme_slug = sanitize_key( $theme_slug );
		$archive = trailingslashit( self::directory() ) . $key . '.zip';
		if ( ! file_exists( $archive ) ) { return new WP_Error( 'backup_missing', __( 'Backup archive not found.', 'infinity-deploy' ) ); }
		$valid = self::validate_zip( $archive, $theme_slug );
		if ( is_wp_error( $valid ) ) { return $valid; }

		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) { return new WP_Error( 'filesystem_unavailable', __( 'WordPress could not access the theme filesystem.', 'infinity-deploy' ) ); }
		$destination = trailingslashit( get_theme_root() ) . $theme_slug;
		if ( $wp_filesystem->is_dir( $destination ) && ! $wp_filesystem->delete( $destination, true ) ) { return new WP_Error( 'restore_delete_failed', __( 'The current theme directory could not be replaced.', 'infinity-deploy' ) ); }
		$result = unzip_file( $archive, get_theme_root() );
		wp_clean_themes_cache();
		return is_wp_error( $result ) ? $result : true;
	}

	public static function validate_zip( $path, $expected_slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip_unavailable', __( 'PHP ZipArchive is required.', 'infinity-deploy' ) ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { return new WP_Error( 'invalid_zip', __( 'The uploaded file is not a readable ZIP archive.', 'infinity-deploy' ) ); }
		$has_style = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = str_replace( '\\', '/', $zip->getNameIndex( $i ) );
			if ( ! $name || 0 === strpos( $name, '/' ) || false !== strpos( $name, '../' ) || false !== strpos( $name, "\0" ) ) { $zip->close(); return new WP_Error( 'unsafe_zip', __( 'The ZIP contains an unsafe path.', 'infinity-deploy' ) ); }
			if ( $expected_slug . '/style.css' === rtrim( $name, '/' ) ) { $has_style = true; }
			if ( 0 !== strpos( $name, $expected_slug . '/' ) ) { $zip->close(); return new WP_Error( 'wrong_theme_slug', __( 'The ZIP top-level folder does not match the allowed theme slug.', 'infinity-deploy' ) ); }
		}
		$zip->close();
		return $has_style ? true : new WP_Error( 'missing_stylesheet', __( 'The theme ZIP does not contain style.css.', 'infinity-deploy' ) );
	}

	public static function list_backups() {
		$files = glob( trailingslashit( self::directory() ) . '*.zip' );
		if ( ! $files ) { return array(); }
		rsort( $files );
		return array_map( function( $file ) { return array( 'key' => basename( $file, '.zip' ), 'size' => filesize( $file ), 'created_gmt' => gmdate( 'c', filemtime( $file ) ) ); }, $files );
	}

	private static function prune() {
		$settings = Infinity_Deploy_API::settings();
		$keep = max( 1, absint( $settings['retained_backups'] ) );
		$files = glob( trailingslashit( self::directory() ) . '*.zip' );
		if ( ! $files || count( $files ) <= $keep ) { return; }
		usort( $files, function( $a, $b ) { return filemtime( $b ) - filemtime( $a ); } );
		foreach ( array_slice( $files, $keep ) as $file ) { wp_delete_file( $file ); }
	}
}
