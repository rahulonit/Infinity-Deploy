<?php
/**
 * Site content files (wp-content) archiving, packaging, and extraction.
 *
 * @package InfinityDeploy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Infinity_Deploy_Site_Pack {

	/**
	 * Package wp-content subdirectories (e.g. uploads, plugins, themes).
	 *
	 * @param array $components Array of components to package: 'uploads', 'plugins', 'themes'.
	 * @return array|WP_Error Path and info for the generated zip archive.
	 */
	public static function package( $components = array( 'uploads' ) ) {
		@set_time_limit( 600 );
		@ini_set( 'memory_limit', '512M' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'PHP ZipArchive is required to package site files.', 'infinity-deploy' ) );
		}

		$key = 'site-pack-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$zip_path = trailingslashit( Infinity_Deploy_Backups::directory() ) . $key . '.zip';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create site package ZIP archive.', 'infinity-deploy' ) );
		}

		$content_dir = WP_CONTENT_DIR;
		$file_count = 0;

		$allowed_components = array( 'uploads', 'plugins', 'themes' );
		foreach ( $components as $comp ) {
			$comp = sanitize_key( $comp );
			if ( ! in_array( $comp, $allowed_components, true ) ) {
				continue;
			}
			$source_dir = trailingslashit( $content_dir ) . $comp;
			if ( ! is_dir( $source_dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isLink() || ! $file->isFile() ) {
					continue;
				}

				$filepath = $file->getPathname();
				$normalized = wp_normalize_path( $filepath );

				// Exclusions: version control, cache folders, temp files, bridge plugin backups
				if ( false !== strpos( $filepath, '.DS_Store' ) ||
					 false !== strpos( $filepath, '/.git/' ) ||
					 false !== strpos( $filepath, '/node_modules/' ) ||
					 false !== strpos( $filepath, 'infinity-deploy-bridge' ) ||
					 false !== strpos( $filepath, '.infinity-deploy-backups' ) ||
					 false !== strpos( $normalized, '/cache/' ) ||
					 false !== strpos( $normalized, '/wp-cache/' ) ||
					 false !== strpos( $normalized, '/et-cache/' ) ||
					 false !== strpos( $normalized, '/w3tc/' ) ||
					 false !== strpos( $normalized, '/autoptimize/' ) ||
					 preg_match( '/\.(tmp|log|bak)$/i', $filepath ) ) {
					continue;
				}

				$relative = 'wp-content/' . $comp . '/' . ltrim( str_replace( wp_normalize_path( $source_dir ), '', $normalized ), '/' );
				$zip->addFile( $filepath, $relative );
				$file_count++;

				if ( 0 === ( $file_count % 100 ) ) {
					@set_time_limit( 300 );
				}
			}
		}

		$zip->close();

		return array(
			'key' => $key,
			'path' => $zip_path,
			'size' => filesize( $zip_path ),
			'created_gmt' => gmdate( 'c' ),
		);
	}

	/**
	 * Extract an uploaded site package ZIP.
	 *
	 * @param string $archive_path Absolute path to ZIP.
	 * @return true|WP_Error
	 */
	public static function extract( $archive_path ) {
		@set_time_limit( 300 );
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'PHP ZipArchive is required.', 'infinity-deploy' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'invalid_zip', __( 'Could not open site package archive.', 'infinity-deploy' ) );
		}

		// Security scan: packages may only write to the three supported
		// wp-content component directories and may never replace this bridge.
		$allowed_roots = array( 'wp-content/uploads/', 'wp-content/plugins/', 'wp-content/themes/' );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = str_replace( '\\', '/', $zip->getNameIndex( $i ) );
			if ( ! $name || 0 === strpos( $name, '/' ) || false !== strpos( $name, '../' ) || false !== strpos( $name, "\0" ) ) {
				$zip->close();
				return new WP_Error( 'unsafe_zip', __( 'Site package contains unsafe paths.', 'infinity-deploy' ) );
			}
			$in_allowed_root = false;
			foreach ( $allowed_roots as $root ) {
				if ( 0 === strpos( $name, $root ) ) {
					$in_allowed_root = true;
					break;
				}
			}
			if ( ! $in_allowed_root || false !== strpos( $name, 'wp-content/plugins/infinity-deploy-bridge/' ) ) {
				$zip->close();
				return new WP_Error( 'unsafe_zip_scope', __( 'Site package contains a path outside the permitted wp-content directories.', 'infinity-deploy' ) );
			}

			// Reject symbolic links stored in Unix-created ZIP archives.
			$attributes = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) && ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) {
				$zip->close();
				return new WP_Error( 'unsafe_zip_symlink', __( 'Site package contains a symbolic link.', 'infinity-deploy' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$result = unzip_file( $archive_path, ABSPATH );
		$zip->close();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
