<?php
/**
 * Safety and disaster recovery manager for database and full site operations.
 *
 * @package InfinityDeploy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Infinity_Deploy_Safety {

	/**
	 * Create an automated full database snapshot.
	 *
	 * @param string $label Optional descriptive label (e.g. 'pre-push').
	 * @return array|WP_Error Information about created snapshot.
	 */
	public static function create_db_snapshot( $label = 'snapshot' ) {
		@set_time_limit( 300 );
		$sql_file = Infinity_Deploy_Database::export( array( 'batch_size' => 500 ) );
		if ( is_wp_error( $sql_file ) ) {
			return $sql_file;
		}

		$is_gz = ( false !== strpos( $sql_file, '.sql.gz' ) );
		$ext = $is_gz ? '.sql.gz' : '.sql';
		$key = 'db-' . sanitize_key( $label ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$target_path = trailingslashit( Infinity_Deploy_Backups::directory() ) . $key . $ext;

		if ( ! @rename( $sql_file, $target_path ) ) {
			if ( ! @copy( $sql_file, $target_path ) ) {
				@unlink( $sql_file );
				return new WP_Error( 'snapshot_move_failed', __( 'Could not save database snapshot.', 'infinity-deploy' ), array( 'status' => 500 ) );
			}
			@unlink( $sql_file );
		}

		self::prune_snapshots();

		return array(
			'key' => $key,
			'path' => $target_path,
			'size' => filesize( $target_path ),
			'created_gmt' => gmdate( 'c' ),
		);
	}

	/**
	 * Restore a database snapshot by key.
	 *
	 * @param string $key Snapshot key.
	 * @return array|WP_Error
	 */
	public static function restore_db_snapshot( $key ) {
		$key = sanitize_file_name( $key );
		$dir = trailingslashit( Infinity_Deploy_Backups::directory() );
		$candidate_gz = $dir . $key . '.sql.gz';
		$candidate_sql = $dir . $key . '.sql';

		$path = '';
		if ( file_exists( $candidate_gz ) ) {
			$path = $candidate_gz;
		} elseif ( file_exists( $candidate_sql ) ) {
			$path = $candidate_sql;
		} elseif ( file_exists( $dir . $key ) ) {
			$path = $dir . $key;
		}

		if ( empty( $path ) || ! file_exists( $path ) ) {
			return new WP_Error( 'snapshot_not_found', __( 'Database snapshot not found.', 'infinity-deploy' ), array( 'status' => 404 ) );
		}

		$result = Infinity_Deploy_Database::import( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Infinity_Deploy_API::log( 'db_restore', 'success', array( 'key' => $key ) );

		return array(
			'ok' => true,
			'restored_key' => $key,
			'restored_gmt' => gmdate( 'c' ),
		);
	}

	/**
	 * List available database snapshots.
	 *
	 * @return array
	 */
	public static function list_snapshots() {
		$dir = trailingslashit( Infinity_Deploy_Backups::directory() );
		$files = array_merge(
			glob( $dir . 'db-*.sql.gz' ) ?: array(),
			glob( $dir . 'db-*.sql' ) ?: array()
		);
		if ( ! $files ) {
			return array();
		}

		rsort( $files );
		return array_map( function( $file ) {
			$name = basename( $file );
			$key = preg_replace( '/\.(sql\.gz|sql)$/', '', $name );
			return array(
				'key' => $key,
				'filename' => $name,
				'size' => filesize( $file ),
				'created_gmt' => gmdate( 'c', filemtime( $file ) ),
			);
		}, $files );
	}

	/**
	 * Prune old database snapshots according to retained count.
	 */
	private static function prune_snapshots() {
		$settings = Infinity_Deploy_API::settings();
		$keep = max( 1, absint( $settings['retained_backups'] ?? 5 ) );
		$dir = trailingslashit( Infinity_Deploy_Backups::directory() );
		$files = array_merge(
			glob( $dir . 'db-*.sql.gz' ) ?: array(),
			glob( $dir . 'db-*.sql' ) ?: array()
		);
		if ( ! $files || count( $files ) <= $keep ) {
			return;
		}

		usort( $files, function( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );

		foreach ( array_slice( $files, $keep ) as $file ) {
			wp_delete_file( $file );
		}
	}
}
