<?php
/** Bounded, read-only file comparison manifest for permitted wp-content components. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Infinity_Deploy_File_Manifest {
    const MAX_ENTRIES = 1200;
    const HASH_MAX_BYTES = 5242880;
    public static function build( $components ) {
        $allowed = array( 'uploads', 'plugins', 'themes' );
        $components = array_values( array_intersect( $allowed, array_map( 'sanitize_key', is_array( $components ) ? $components : array() ) ) );
        $files = array();
        $truncated = false;
        foreach ( $components as $component ) {
            $root = trailingslashit( WP_CONTENT_DIR ) . $component;
            if ( ! is_dir( $root ) ) { continue; }
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || $file->isLink() ) { continue; }
                $raw = wp_normalize_path( $file->getPathname() );
                $relative = ltrim( substr( $raw, strlen( wp_normalize_path( $root ) ) ), '/' );
                if ( '' === $relative ) { continue; }
                // Same families of ignored content as the release packager.
                if ( preg_match( '#(^|/)(\.git|node_modules|cache|wp-cache|et-cache|w3tc|autoptimize|\.infinity-deploy-backups[^/]*)(/|$)#i', $relative ) ||
                    preg_match( '/(\.DS_Store|\.(tmp|log|bak))$/i', $relative ) ||
                    ( 'plugins' === $component && ( 'infinity-deploy-bridge' === $relative || 0 === strpos( $relative, 'infinity-deploy-bridge/' ) ) ) ) {
                    continue;
                }
                if ( count( $files ) >= self::MAX_ENTRIES ) { $truncated = true; break 2; }
                $size = $file->getSize();
                $files[] = array(
                    'path' => 'wp-content/' . $component . '/' . $relative,
                    'size' => $size,
                    'sha256' => $size <= self::HASH_MAX_BYTES ? hash_file( 'sha256', $file->getPathname() ) : null,
                );
            }
        }
        usort( $files, function( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
        return array( 'ok' => true, 'files' => $files, 'truncated' => $truncated,
            'hash_max_bytes' => self::HASH_MAX_BYTES, 'max_entries' => self::MAX_ENTRIES );
    }
}
