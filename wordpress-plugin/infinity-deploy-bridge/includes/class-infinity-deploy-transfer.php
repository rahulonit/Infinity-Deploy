<?php
/**
 * Resumable package receiver. Sessions are bound to the authenticated WP user.
 * Chunks are fixed-offset and checksummed; completion verifies the full digest.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Infinity_Deploy_Transfer {
    const CHUNK_BYTES = 4 * 1024 * 1024;
    const TTL = 86400;

    private static function paths( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
            return new WP_Error( 'transfer_id_invalid', 'Invalid transfer ID.', array( 'status' => 400 ) );
        }
        $base = trailingslashit( Infinity_Deploy_Backups::directory() ) . 'transfer-' . $id;
        return array( 'meta' => $base . '.json', 'part' => $base . '.part' );
    }

    private static function read( $id ) {
        $paths = self::paths( $id );
        if ( is_wp_error( $paths ) ) { return $paths; }
        if ( ! is_file( $paths['meta'] ) ) { return new WP_Error( 'transfer_missing', 'Transfer session not found.', array( 'status' => 404 ) ); }
        $meta = json_decode( file_get_contents( $paths['meta'] ), true );
        if ( ! is_array( $meta ) || (int) $meta['user_id'] !== get_current_user_id() ) {
            return new WP_Error( 'transfer_forbidden', 'Transfer session is not owned by this user.', array( 'status' => 403 ) );
        }
        if ( time() - (int) $meta['created'] > self::TTL ) {
            return new WP_Error( 'transfer_expired', 'Transfer session expired; start a new transfer.', array( 'status' => 410 ) );
        }
        return array( $paths, $meta );
    }

    public static function start( WP_REST_Request $request ) {
        $size = (int) $request->get_param( 'size' );
        $hash = strtolower( (string) $request->get_param( 'sha256' ) );
        $filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
        $max = max( 1, absint( Infinity_Deploy_API::settings()['max_package_mb'] ) ) * MB_IN_BYTES;
        if ( $size < 1 || $size > $max || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! preg_match( '/\\.(zip|sql|sql\\.gz)$/i', $filename ) ) {
            return new WP_Error( 'transfer_invalid', 'Invalid size, checksum or package filename.', array( 'status' => 400 ) );
        }
        $id = bin2hex( random_bytes( 16 ) );
        $paths = self::paths( $id );
        $meta = array( 'user_id' => get_current_user_id(), 'created' => time(), 'size' => $size, 'sha256' => $hash, 'filename' => $filename );
        if ( false === file_put_contents( $paths['part'], '' ) || false === file_put_contents( $paths['meta'], wp_json_encode( $meta ) ) ) {
            return new WP_Error( 'transfer_storage', 'Unable to create transfer session.', array( 'status' => 500 ) );
        }
        return array( 'id' => $id, 'offset' => 0, 'chunk_size' => self::CHUNK_BYTES );
    }

    public static function status( WP_REST_Request $request ) {
        $session = self::read( $request->get_param( 'id' ) );
        if ( is_wp_error( $session ) ) { return $session; }
        list( $paths, $meta ) = $session;
        if ( ! is_file( $paths['part'] ) ) { return new WP_Error( 'transfer_missing', 'Transfer data missing.', array( 'status' => 404 ) ); }
        return array( 'id' => $request->get_param( 'id' ), 'offset' => filesize( $paths['part'] ), 'size' => $meta['size'], 'chunk_size' => self::CHUNK_BYTES );
    }

    public static function chunk( WP_REST_Request $request ) {
        $session = self::read( $request->get_param( 'id' ) );
        if ( is_wp_error( $session ) ) { return $session; }
        list( $paths, $meta ) = $session;
        $files = $request->get_file_params();
        $file = isset( $files['chunk'] ) ? $files['chunk'] : array();
        $offset = (int) $request->get_param( 'offset' );
        $hash = strtolower( (string) $request->get_param( 'sha256' ) );
        if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'transfer_chunk_missing', 'Chunk upload missing.', array( 'status' => 400 ) );
        }
        $length = filesize( $file['tmp_name'] );
        if ( $offset < 0 || $length < 1 || $length > self::CHUNK_BYTES || $offset + $length > $meta['size'] ||
            ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! hash_equals( $hash, hash_file( 'sha256', $file['tmp_name'] ) ) ) {
            return new WP_Error( 'transfer_chunk_invalid', 'Chunk bounds or digest mismatch.', array( 'status' => 400 ) );
        }
        $dest = fopen( $paths['part'], 'c+b' );
        if ( ! $dest ) { return new WP_Error( 'transfer_storage', 'Transfer storage unavailable.', array( 'status' => 500 ) ); }
        if ( ! flock( $dest, LOCK_EX ) ) { fclose( $dest ); return new WP_Error( 'transfer_locked', 'Transfer is busy.', array( 'status' => 409 ) ); }
        $current = fstat( $dest )['size'];
        if ( $offset !== $current ) {
            flock( $dest, LOCK_UN ); fclose( $dest );
            return new WP_Error( 'transfer_offset', 'Unexpected chunk offset. Resume from status.', array( 'status' => 409, 'offset' => $current ) );
        }
        $source = fopen( $file['tmp_name'], 'rb' );
        fseek( $dest, 0, SEEK_END );
        $written = $source ? stream_copy_to_stream( $source, $dest ) : false;
        if ( $source ) { fclose( $source ); }
        fflush( $dest );
        if ( $written !== $length ) { ftruncate( $dest, $current ); }
        flock( $dest, LOCK_UN );
        fclose( $dest );
        if ( $written !== $length ) { return new WP_Error( 'transfer_write', 'Unable to persist complete chunk.', array( 'status' => 500 ) ); }
        return array( 'offset' => $current + $written );
    }

    public static function complete( WP_REST_Request $request ) {
        $session = self::read( $request->get_param( 'id' ) );
        if ( is_wp_error( $session ) ) { return $session; }
        list( $paths, $meta ) = $session;
        if ( ! is_file( $paths['part'] ) || filesize( $paths['part'] ) !== $meta['size'] ||
            ! hash_equals( $meta['sha256'], hash_file( 'sha256', $paths['part'] ) ) ) {
            return new WP_Error( 'transfer_checksum', 'Incomplete transfer or SHA-256 mismatch.', array( 'status' => 400 ) );
        }
        $extension = preg_match( '/\\.sql\\.gz$/i', $meta['filename'] ) ? '.sql.gz' : ( preg_match( '/\\.sql$/i', $meta['filename'] ) ? '.sql' : '.zip' );
        $key = 'upload-' . $request->get_param( 'id' ) . $extension;
        $dest = trailingslashit( Infinity_Deploy_Backups::directory() ) . $key;
        if ( ! rename( $paths['part'], $dest ) ) {
            return new WP_Error( 'transfer_finalize', 'Unable to finalize upload.', array( 'status' => 500 ) );
        }
        @unlink( $paths['meta'] );
        return array( 'file_key' => $key, 'size' => $meta['size'], 'sha256' => $meta['sha256'] );
    }

    public static function cancel( WP_REST_Request $request ) {
        $session = self::read( $request->get_param( 'id' ) );
        if ( is_wp_error( $session ) ) { return $session; }
        list( $paths ) = $session;
        @unlink( $paths['part'] );
        @unlink( $paths['meta'] );
        return array( 'ok' => true );
    }
}
