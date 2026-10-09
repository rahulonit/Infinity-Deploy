<?php
/**
 * Database export, import, and serialized search-and-replace engine.
 *
 * @package InfinityDeploy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Infinity_Deploy_Database {

	/**
	 * Export database tables to a SQL string or file.
	 *
	 * @param array $options Configuration options: 'tables', 'exclude_tables', 'batch_size'.
	 * @return string|WP_Error Path to exported SQL file or error.
	 */
	public static function export( $options = array() ) {
		@set_time_limit( 600 );
		@ini_set( 'memory_limit', '512M' );
		global $wpdb;

		$prefix = $wpdb->prefix;
		$all_tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! $all_tables ) {
			return new WP_Error( 'no_tables', __( 'No tables found in the database.', 'infinity-deploy' ) );
		}

		$exclude = isset( $options['exclude_tables'] ) && is_array( $options['exclude_tables'] ) ? $options['exclude_tables'] : array();
		$include = isset( $options['tables'] ) && is_array( $options['tables'] ) && ! empty( $options['tables'] ) ? $options['tables'] : $all_tables;

		$tables_to_dump = array();
		foreach ( $all_tables as $table ) {
			if ( in_array( $table, $exclude, true ) ) {
				continue;
			}
			if ( in_array( $table, $include, true ) ) {
				$tables_to_dump[] = $table;
			}
		}

		$gz_available = function_exists( 'gzopen' );
		$ext = $gz_available ? '.sql.gz' : '.sql';
		$temp_filename = 'db-export-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . $ext;
		$temp_file = trailingslashit( Infinity_Deploy_Backups::directory() ) . $temp_filename;

		$handle = $gz_available ? gzopen( $temp_file, 'wb9' ) : fopen( $temp_file, 'wb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_error', __( 'Could not open temporary SQL export file.', 'infinity-deploy' ) );
		}

		$site_url = untrailingslashit( site_url() );
		$home_url = untrailingslashit( home_url() );
		$site_path = untrailingslashit( ABSPATH );

		// Header metadata
		$header = "-- Infinity Deploy Database Export\n"
			. "-- Version: " . INFINITY_DEPLOY_VERSION . "\n"
			. "-- Generated: " . gmdate( 'c' ) . "\n"
			. "-- Prefix: " . $prefix . "\n"
			. "-- SiteURL: " . $site_url . "\n"
			. "-- HomeURL: " . $home_url . "\n"
			. "-- Path: " . $site_path . "\n"
			. "SET foreign_key_checks = 0;\n"
			. "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

		if ( $gz_available ) {
			gzwrite( $handle, $header );
		} else {
			fwrite( $handle, $header );
		}

		$batch_size = isset( $options['batch_size'] ) ? max( 100, absint( $options['batch_size'] ) ) : 500;

		foreach ( $tables_to_dump as $table ) {
			@set_time_limit( 300 );

			// Write DROP & CREATE TABLE
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! $create || empty( $create[1] ) ) {
				continue;
			}

			$table_header = "\n-- Table structure for `{$table}`\n"
				. "DROP TABLE IF EXISTS `{$table}`;\n"
				. $create[1] . ";\n\n";

			if ( $gz_available ) {
				gzwrite( $handle, $table_header );
			} else {
				fwrite( $handle, $table_header );
			}

			// Count rows
			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( 0 === $total_rows ) {
				continue;
			}

			$data_comment = "-- Dumping data for `{$table}`\n";
			if ( $gz_available ) {
				gzwrite( $handle, $data_comment );
			} else {
				fwrite( $handle, $data_comment );
			}

			for ( $offset = 0; $offset < $total_rows; $offset += $batch_size ) {
				@set_time_limit( 300 );
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A );
				if ( ! $rows ) {
					break;
				}

				$insert_values = array();
				foreach ( $rows as $row ) {
					$escaped_values = array();
					foreach ( $row as $val ) {
						if ( null === $val ) {
							$escaped_values[] = 'NULL';
						} else {
							$escaped = esc_sql( $val );
							$escaped = str_replace( array( "\r", "\n" ), array( "\\r", "\\n" ), $escaped );
							$escaped_values[] = "'" . $escaped . "'";
						}
					}
					$insert_values[] = '(' . implode( ', ', $escaped_values ) . ')';
				}

				if ( ! empty( $insert_values ) ) {
					$columns = array_map( function( $col ) { return "`{$col}`"; }, array_keys( $rows[0] ) );
					$sql = "INSERT INTO `{$table}` (" . implode( ', ', $columns ) . ") VALUES\n" . implode( ",\n", $insert_values ) . ";\n";
					if ( $gz_available ) {
						gzwrite( $handle, $sql );
					} else {
						fwrite( $handle, $sql );
					}
				}
			}
		}

		$footer = "\nSET foreign_key_checks = 1;\n";
		if ( $gz_available ) {
			gzwrite( $handle, $footer );
			gzclose( $handle );
		} else {
			fwrite( $handle, $footer );
			fclose( $handle );
		}

		return $temp_file;
	}

	/**
	 * Import SQL file into database and perform serialized search-replace.
	 *
	 * @param string $file_path Absolute path to SQL file.
	 * @param array  $replacements Associative array: source_url, target_url, source_path, target_path, source_prefix.
	 * @param array  $preserve_tables Tables to keep unchanged (e.g. users).
	 * @return array|WP_Error
	 */
	public static function import( $file_path, $replacements = array(), $preserve_tables = array() ) {
		@set_time_limit( 600 );
		global $wpdb;

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'SQL import file not found.', 'infinity-deploy' ) );
		}

		// 1. Temporarily backup preserved tables if specified
		$preserved_data = array();
		if ( ! empty( $preserve_tables ) ) {
			foreach ( $preserve_tables as $table ) {
				$full_table = 0 === strpos( $table, $wpdb->prefix ) ? $table : $wpdb->prefix . $table;
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) ) === $full_table ) {
					$preserved_data[ $full_table ] = $wpdb->get_results( "SELECT * FROM `{$full_table}`", ARRAY_A );
				}
			}
		}

		// Detect gzip
		$is_gz = false;
		$probe = fopen( $file_path, 'rb' );
		if ( $probe ) {
			$magic = fread( $probe, 2 );
			fclose( $probe );
			$is_gz = ( "\x1f\x8b" === $magic ) && function_exists( 'gzopen' );
		}

		$open_fn = $is_gz ? 'gzopen' : 'fopen';
		$gets_fn = $is_gz ? 'gzgets' : 'fgets';
		$close_fn = $is_gz ? 'gzclose' : 'fclose';

		// 2. Read metadata headers from SQL file
		$file_handle = $open_fn( $file_path, 'r' );
		$source_prefix = $wpdb->prefix;
		$source_site_url = '';
		$source_home_url = '';
		$source_path = '';

		while ( ( $line = $gets_fn( $file_handle ) ) !== false ) {
			if ( 0 !== strpos( $line, '--' ) ) {
				break;
			}
			if ( preg_match( '/^-- Prefix:\s*(.+)$/', trim( $line ), $m ) ) {
				$source_prefix = trim( $m[1] );
			} elseif ( preg_match( '/^-- SiteURL:\s*(.+)$/', trim( $line ), $m ) ) {
				$source_site_url = trim( $m[1] );
			} elseif ( preg_match( '/^-- HomeURL:\s*(.+)$/', trim( $line ), $m ) ) {
				$source_home_url = trim( $m[1] );
			} elseif ( preg_match( '/^-- Path:\s*(.+)$/', trim( $line ), $m ) ) {
				$source_path = trim( $m[1] );
			}
		}
		$close_fn( $file_handle );

		// Allow override from request
		if ( ! empty( $replacements['source_prefix'] ) ) {
			$source_prefix = $replacements['source_prefix'];
		}
		if ( ! empty( $replacements['source_url'] ) ) {
			$source_site_url = $replacements['source_url'];
		}
		if ( ! empty( $replacements['source_path'] ) ) {
			$source_path = $replacements['source_path'];
		}

		$target_prefix = $wpdb->prefix;
		$target_site_url = untrailingslashit( ! empty( $replacements['target_url'] ) ? $replacements['target_url'] : site_url() );
		$target_path = untrailingslashit( ! empty( $replacements['target_path'] ) ? $replacements['target_path'] : ABSPATH );

		// 3. Execute SQL line-by-line / statement-by-statement
		$handle = $open_fn( $file_path, 'r' );
		$query = '';
		$deferred_views = array();
		$view_names = array();
		$wpdb->query( 'SET foreign_key_checks = 0' );

		// Views can prevent their underlying tables from being replaced. Remove
		// existing destination views first; exported definitions are recreated
		// after every base table has been imported.
		$existing_views = $wpdb->get_results( "SHOW FULL TABLES WHERE Table_type = 'VIEW'", ARRAY_N );
		foreach ( (array) $existing_views as $view_row ) {
			if ( ! empty( $view_row[0] ) ) {
				$view_name = str_replace( '`', '``', $view_row[0] );
				$wpdb->query( "DROP VIEW IF EXISTS `{$view_name}`" );
			}
		}

		while ( ( $line = $gets_fn( $handle ) ) !== false ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
				continue;
			}

			// Table prefix replacement on the fly if prefixes differ
			if ( $source_prefix !== $target_prefix ) {
				$line = preg_replace( '/`' . preg_quote( $source_prefix, '/' ) . '([a-zA-Z0-9_]+)`/', '`' . $target_prefix . '$1`', $line );
			}

			$query .= $line;
			if ( ';' === substr( $trimmed, -1 ) ) {
				if ( preg_match( '/\bVIEW\s+`([^`]+)`/i', $query, $view_match ) ) {
					// Shared-host exports include source-only DEFINER accounts and
					// qualified database names. Neither is valid in the Local database.
					$view_query = preg_replace( '/\s+DEFINER=`[^`]*`@`[^`]*`/i', '', $query );
					$view_query = preg_replace( '/`[^`]+`\.`([^`]+)`/', '`$1`', $view_query );
					$view_names[ strtolower( $view_match[1] ) ] = true;
					$deferred_views[] = $view_query;
				} elseif ( preg_match( '/^\s*INSERT\s+INTO\s+`([^`]+)`/i', $query, $insert_match ) && isset( $view_names[ strtolower( $insert_match[1] ) ] ) ) {
					// View rows are derived from base tables and must never be inserted.
				} else {
					$wpdb->query( $query );
				}
				$query = '';
			}
		}
		$close_fn( $handle );

		// Resolve dependencies between views by retrying the definitions that
		// could not be created on an earlier pass.
		$pending_views = $deferred_views;
		$previous_suppress = $wpdb->suppress_errors( true );
		for ( $pass = 0; $pass < count( $deferred_views ) && ! empty( $pending_views ); $pass++ ) {
			$next_pending = array();
			foreach ( $pending_views as $view_query ) {
				if ( false === $wpdb->query( $view_query ) ) {
					$next_pending[] = $view_query;
				}
			}
			if ( count( $next_pending ) === count( $pending_views ) ) {
				break;
			}
			$pending_views = $next_pending;
		}
		$wpdb->suppress_errors( $previous_suppress );
		$wpdb->query( 'SET foreign_key_checks = 1' );

		// 4. Restore preserved tables
		if ( ! empty( $preserved_data ) ) {
			foreach ( $preserved_data as $table => $rows ) {
				if ( ! empty( $rows ) ) {
					$wpdb->query( "TRUNCATE TABLE `{$table}`" );
					foreach ( $rows as $row ) {
						$wpdb->insert( $table, $row );
					}
				}
			}
		}

		// 5. Update table prefix user_roles and capabilities if prefix changed
		if ( $source_prefix !== $target_prefix ) {
			self::remap_prefix_options( $source_prefix, $target_prefix );
		}

		// 6. Run recursive serialized search and replace across all tables
		$pairs = array();
		if ( ! empty( $source_site_url ) && $source_site_url !== $target_site_url ) {
			$pairs[ $source_site_url ] = $target_site_url;
			// Also match without protocol or www
			$src_host = preg_replace( '#^https?://#', '', $source_site_url );
			$tgt_host = preg_replace( '#^https?://#', '', $target_site_url );
			if ( $src_host !== $tgt_host ) {
				$pairs[ '//' . $src_host ] = '//' . $tgt_host;
			}
		}
		if ( ! empty( $source_path ) && $source_path !== $target_path ) {
			$pairs[ $source_path ] = $target_path;
		}

		$sr_stats = array();
		if ( ! empty( $pairs ) ) {
			$sr_stats = self::search_replace( $pairs );
		}

		// 7. Ensure crucial WordPress URLs point to target site
		update_option( 'siteurl', $target_site_url );
		update_option( 'home', $target_site_url );

		wp_cache_flush();

		return array(
			'ok' => true,
			'search_replace' => $sr_stats,
			'site_url' => $target_site_url,
			'prefix' => $target_prefix,
		);
	}

	/**
	 * Remap prefix-dependent keys in options and usermeta.
	 */
	public static function remap_prefix_options( $old_prefix, $new_prefix ) {
		global $wpdb;

		// 1. User roles option in options table
		$old_roles_key = $old_prefix . 'user_roles';
		$new_roles_key = $new_prefix . 'user_roles';
		$roles = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $old_roles_key ) );
		if ( $roles ) {
			update_option( $new_roles_key, maybe_unserialize( $roles ) );
			delete_option( $old_roles_key );
		}

		// 2. Usermeta capabilities and user_level
		$usermeta_table = $wpdb->usermeta;
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$usermeta_table}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
			$new_prefix,
			strlen( $old_prefix ) + 1,
			$wpdb->esc_like( $old_prefix ) . '%'
		) );
	}

	/**
	 * Safe recursive serialized search & replace across all WordPress tables.
	 * Paginated in chunks of 500 rows to prevent PHP memory exhaustion.
	 *
	 * @param array $pairs Associative array of [search => replace].
	 * @return array Counts of changes per table.
	 */
	public static function search_replace( $pairs ) {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$stats = array();

		foreach ( $tables as $table ) {
			@set_time_limit( 300 );
			$cols_info = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
			if ( ! $cols_info ) {
				continue;
			}

			// Identify primary key and text columns
			$pk_col = '';
			$text_columns = array();
			foreach ( $cols_info as $col ) {
				if ( ! empty( $col['Key'] ) && 'PRI' === $col['Key'] && empty( $pk_col ) ) {
					$pk_col = $col['Field'];
				}
				$type = strtolower( $col['Type'] );
				if ( false !== strpos( $type, 'char' ) || false !== strpos( $type, 'text' ) || false !== strpos( $type, 'blob' ) ) {
					$text_columns[] = $col['Field'];
				}
			}

			if ( empty( $text_columns ) || empty( $pk_col ) ) {
				continue;
			}

			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( 0 === $total_rows ) {
				continue;
			}

			$count = 0;
			$batch = 500;

			for ( $offset = 0; $offset < $total_rows; $offset += $batch ) {
				@set_time_limit( 300 );
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch}", ARRAY_A );
				if ( ! $rows ) {
					break;
				}

				foreach ( $rows as $row ) {
					$updates = array();
					foreach ( $text_columns as $col ) {
						$original = $row[ $col ];
						if ( empty( $original ) || ! is_string( $original ) ) {
							continue;
						}

						$modified = self::recursive_replace( $original, $pairs );
						if ( $modified !== $original ) {
							$updates[ $col ] = $modified;
						}
					}

					if ( ! empty( $updates ) && isset( $row[ $pk_col ] ) ) {
						$wpdb->update( $table, $updates, array( $pk_col => $row[ $pk_col ] ) );
						$count += count( $updates );
					}
				}
			}

			if ( $count > 0 ) {
				$stats[ $table ] = $count;
			}
		}

		return $stats;
	}

	/**
	 * Recursive search & replace handling strings, arrays, and serialized data.
	 */
	public static function recursive_replace( $data, $pairs ) {
		if ( is_string( $data ) ) {
			// Check if it's serialized
			if ( is_serialized( $data ) ) {
				$unserialized = @unserialize( $data );
				if ( false !== $unserialized || 'b:0;' === $data ) {
					try {
						$replaced = self::recursive_replace( $unserialized, $pairs );
						return serialize( $replaced );
					} catch ( Throwable $error ) {
						// Saved settings can contain objects from plugins that are not
						// installed locally. PHP exposes those as __PHP_Incomplete_Class,
						// whose mangled private/protected properties cannot be assigned.
						// Rewrite serialized string tokens directly while recalculating
						// their byte lengths so the value remains valid and loadable.
						return self::replace_serialized_strings( $data, $pairs );
					}
				}
			}

			// Check if JSON
			if ( ( 0 === strpos( $data, '{' ) || 0 === strpos( $data, '[' ) ) ) {
				$decoded = json_decode( $data, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					$replaced = self::recursive_replace( $decoded, $pairs );
					return wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			}

			// Plain string replacement
			foreach ( $pairs as $search => $replace ) {
				$data = str_replace( $search, $replace, $data );
			}
			return $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::recursive_replace( $v, $pairs );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			$clone = clone $data;
			foreach ( get_object_vars( $clone ) as $k => $v ) {
				$clone->$k = self::recursive_replace( $v, $pairs );
			}
			return $clone;
		}

		return $data;
	}

	/**
	 * Replace string tokens inside serialized data without instantiating objects.
	 *
	 * @param string $serialized Serialized PHP value.
	 * @param array  $pairs      Search and replacement pairs.
	 * @return string
	 */
	private static function replace_serialized_strings( $serialized, $pairs ) {
		$output = '';
		$offset = 0;
		$total  = strlen( $serialized );

		while ( $offset < $total && preg_match( '/s:(\d+):"/', $serialized, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$token_start = $match[0][1];
			$value_start = $token_start + strlen( $match[0][0] );
			$value_size  = (int) $match[1][0];
			$value_end   = $value_start + $value_size;

			// Only rewrite a structurally valid serialized string token.
			if ( $value_end + 2 > $total || '";' !== substr( $serialized, $value_end, 2 ) ) {
				$output .= substr( $serialized, $offset, $token_start - $offset + 2 );
				$offset = $token_start + 2;
				continue;
			}

			$value = substr( $serialized, $value_start, $value_size );
			foreach ( $pairs as $search => $replace ) {
				$value = str_replace( $search, $replace, $value );
			}

			$output .= substr( $serialized, $offset, $token_start - $offset );
			$output .= 's:' . strlen( $value ) . ':"' . $value . '";';
			$offset = $value_end + 2;
		}

		return $output . substr( $serialized, $offset );
	}
}
