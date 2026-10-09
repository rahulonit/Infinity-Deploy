<?php
/** Selective, conflict-aware content synchronization. @package InfinityDeploy */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Infinity_Deploy_Content {
	const UUID_META = '_infinity_deploy_uuid';
	const MEDIA_UUID_META = '_infinity_deploy_media_uuid';

	public static function allowed_types() {
		$settings = Infinity_Deploy_API::settings();
		$types = isset( $settings['content_types'] ) && is_array( $settings['content_types'] ) ? $settings['content_types'] : array();
		$sanitized = array_values( array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' ) );
		return apply_filters( 'infinity_deploy_allowed_post_types', $sanitized );
	}

	public static function allowed_meta( $type ) {
		$map = array(
			'page' => array( '_wp_page_template' ),
			'rg_product' => array(
				'rg_short_description', 'rg_role', 'rg_timeline', 'rg_tech_stack', 'rg_status', 'rg_price', 'rg_features',
				'rg_problem', 'rg_target_users', 'rg_constraints', 'rg_solution', 'rg_design_process', 'rg_verified_outcomes',
				'rg_featured', 'rg_testimonial_quote', 'rg_testimonial_author', 'rg_testimonial_role',
				'rg_testimonial_rating', 'rg_testimonial_verified', 'rg_privacy_policy', 'rg_privacy_verified', 'rg_privacy_effective_date',
				'rg_privacy_email', 'rg_app_store_url', 'rg_play_store_url', 'rg_microsoft_store_url', 'rg_website_url', 'rg_purchase_url',
				'rg_github_url', 'rg_download_url',
			),
			'rg_service' => array( 'rg_process', 'rg_deliverables', 'rg_ideal_customer' ),
			'rg_testimonial' => array( 'rg_client_role', 'rg_client_url' ),
		);
		$meta = isset( $map[ $type ] ) ? $map[ $type ] : array();
		return apply_filters( 'infinity_deploy_allowed_meta', $meta, $type );
	}

	public static function export() {
		$items = array();
		$media = array();
		foreach ( self::allowed_types() as $type ) {
			$query = new WP_Query( array(
				'post_type' => $type,
				'post_status' => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby' => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				'no_found_rows' => true,
			) );
			foreach ( $query->posts as $post ) {
				$item = self::post_payload( $post );
				$item['hash'] = self::hash( $item );
				$items[] = $item;
				foreach ( self::post_media_ids( $post ) as $media_id ) {
					$payload = self::media_payload( $media_id );
					if ( $payload ) { $media[ $payload['uuid'] ] = $payload; }
				}
			}
		}
		return array( 'items' => $items, 'media' => array_values( $media ), 'generated_gmt' => gmdate( 'c' ) );
	}

	public static function manifest() {
		$items = array();
		foreach ( self::allowed_types() as $type ) {
			$query = new WP_Query( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 500, 'no_found_rows' => true ) );
			foreach ( $query->posts as $post ) {
				$uuid = get_post_meta( $post->ID, self::UUID_META, true );
				if ( ! $uuid ) { continue; }
				$payload = self::post_payload( $post, false );
				$items[ $uuid ] = array( 'id' => $post->ID, 'type' => $post->post_type, 'slug' => $post->post_name, 'title' => $post->post_title, 'hash' => self::hash( $payload ), 'modified_gmt' => get_post_modified_time( 'c', true, $post ) );
			}
		}
		$media = array();
		$media_query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1000, 'meta_key' => self::MEDIA_UUID_META, 'no_found_rows' => true ) );
		foreach ( $media_query->posts as $attachment ) {
			$uuid = get_post_meta( $attachment->ID, self::MEDIA_UUID_META, true );
			$path = get_attached_file( $attachment->ID );
			$media[ $uuid ] = array( 'id' => $attachment->ID, 'url' => wp_get_attachment_url( $attachment->ID ), 'hash' => $path && file_exists( $path ) ? hash_file( 'sha256', $path ) : '' );
		}
		return array( 'items' => $items, 'media' => $media, 'generated_gmt' => gmdate( 'c' ) );
	}

	public static function sync( $items, $source_url = '' ) {
		if ( ! is_array( $items ) || count( $items ) > 200 ) { return new WP_Error( 'invalid_items', __( 'A maximum of 200 content items can be synchronized per request.', 'infinity-deploy' ), array( 'status' => 400 ) ); }
		$results = array();
		foreach ( $items as $raw ) {
			$result = self::sync_one( is_array( $raw ) ? $raw : array(), $source_url );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'uuid' => isset( $raw['uuid'] ) ? sanitize_text_field( $raw['uuid'] ) : '', 'status' => 'error', 'code' => $result->get_error_code(), 'message' => $result->get_error_message() );
			} else { $results[] = $result; }
		}
		// Resolve product relationships only after every item in this batch exists.
		foreach ( $items as $raw ) {
			if ( is_array( $raw ) ) { self::sync_relationships( $raw ); }
		}
		return $results;
	}

	private static function sync_relationships( $item ) {
		if ( 'rg_product' !== ( isset( $item['type'] ) ? sanitize_key( $item['type'] ) : '' ) || empty( $item['uuid'] ) || ! isset( $item['related_product_uuids'] ) ) { return; }
		$posts = get_posts( array( 'post_type' => 'rg_product', 'post_status' => 'any', 'meta_key' => self::UUID_META, 'meta_value' => sanitize_text_field( $item['uuid'] ), 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( ! $posts || ! current_user_can( 'edit_post', $posts[0] ) ) { return; }
		$related_ids = array();
		foreach ( (array) $item['related_product_uuids'] as $related_uuid ) {
			$related = get_posts( array( 'post_type' => 'rg_product', 'post_status' => 'any', 'meta_key' => self::UUID_META, 'meta_value' => sanitize_text_field( $related_uuid ), 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( $related ) { $related_ids[] = absint( $related[0] ); }
		}
		update_post_meta( absint( $posts[0] ), 'rg_related_products', implode( ',', array_values( array_unique( $related_ids ) ) ) );
	}

	public static function rewrite_urls( $text, $source_url, $target_url ) {
		if ( empty( $text ) || empty( $source_url ) || empty( $target_url ) || ! is_string( $text ) ) {
			return $text;
		}
		$clean_source = untrailingslashit( $source_url );
		$clean_target = untrailingslashit( $target_url );
		if ( $clean_source === $clean_target ) {
			return $text;
		}
		// 1. Literal origin replacement
		$text = str_replace( $clean_source, $clean_target, $text );

		// 2. Escaped JSON origin replacement (e.g. in Gutenberg block attributes or serialized options)
		$json_source = str_replace( '/', '\/', $clean_source );
		$json_target = str_replace( '/', '\/', $clean_target );
		$text = str_replace( $json_source, $json_target, $text );

		return $text;
	}

	private static function sync_one( $item, $source_url = '' ) {
		$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : '';
		$uuid = isset( $item['uuid'] ) ? sanitize_text_field( $item['uuid'] ) : '';
		if ( ! in_array( $type, self::allowed_types(), true ) || ! preg_match( '/^[a-f0-9-]{20,64}$/i', $uuid ) ) { return new WP_Error( 'invalid_content_item', __( 'The content type or synchronization ID is invalid.', 'infinity-deploy' ) ); }

		$existing = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'meta_key' => self::UUID_META, 'meta_value' => $uuid, 'numberposts' => 1, 'fields' => 'ids' ) );
		$post_id = $existing ? absint( $existing[0] ) : 0;
		if ( ! $post_id && ! empty( $item['slug'] ) ) {
			$by_slug = get_posts( array(
				'post_type'   => $type,
				'name'        => sanitize_title( $item['slug'] ),
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			) );
			if ( $by_slug ) {
				$post_id = absint( $by_slug[0] );
			}
		}
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) { return new WP_Error( 'forbidden_item', __( 'You cannot update this content item.', 'infinity-deploy' ) ); }

		$expected = isset( $item['expected_remote_hash'] ) ? sanitize_text_field( $item['expected_remote_hash'] ) : '';
		if ( $post_id && $expected ) {
			$current = self::hash( self::post_payload( get_post( $post_id ), false ) );
			if ( ! hash_equals( $current, $expected ) ) { return new WP_Error( 'content_conflict', __( 'The live item changed after the deployment preview. Refresh the comparison before pushing.', 'infinity-deploy' ) ); }
		}

		$content = isset( $item['content'] ) ? wp_kses_post( $item['content'] ) : '';
		$excerpt = isset( $item['excerpt'] ) ? wp_kses_post( $item['excerpt'] ) : '';
		if ( ! empty( $source_url ) ) {
			$target_url = home_url();
			$content = self::rewrite_urls( $content, $source_url, $target_url );
			$excerpt = self::rewrite_urls( $excerpt, $source_url, $target_url );
		}

		$postarr = array(
			'post_type' => $type,
			'post_title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
			'post_name' => isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : '',
			'post_status' => isset( $item['status'] ) && in_array( $item['status'], array( 'publish', 'draft', 'private' ), true ) ? $item['status'] : 'draft',
			'post_excerpt' => $excerpt,
			'post_content' => $content,
			'menu_order' => isset( $item['menu_order'] ) ? intval( $item['menu_order'] ) : 0,
		);
		if ( $post_id ) { $postarr['ID'] = $post_id; }
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }
		update_post_meta( $post_id, self::UUID_META, $uuid );

		$meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		foreach ( self::allowed_meta( $type ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) { continue; }
			$value = $meta[ $key ];
			if ( is_string( $value ) && ! empty( $source_url ) ) {
				$value = self::rewrite_urls( $value, $source_url, home_url() );
			}
			if ( preg_match( '/_url$/', $key ) || in_array( $key, array( 'rg_website_url', 'rg_purchase_url', 'rg_github_url', 'rg_download_url', 'rg_client_url' ), true ) ) { $value = esc_url_raw( $value ); }
			elseif ( in_array( $key, array( 'rg_privacy_policy' ), true ) ) { $value = wp_kses_post( $value ); }
			elseif ( is_string( $value ) ) { $value = sanitize_textarea_field( $value ); }
			update_post_meta( $post_id, $key, $value );
		}

		if ( ! empty( $item['terms'] ) && is_array( $item['terms'] ) ) {
			foreach ( $item['terms'] as $taxonomy => $terms ) {
				$taxonomy = sanitize_key( $taxonomy );
				if ( taxonomy_exists( $taxonomy ) && is_object_in_taxonomy( $type, $taxonomy ) ) { wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', (array) $terms ), $taxonomy, false ); }
			}
		}

		if ( ! empty( $item['featured_media_uuid'] ) ) {
			$media_id = self::media_id_by_uuid( $item['featured_media_uuid'] );
			if ( $media_id ) { set_post_thumbnail( $post_id, $media_id ); }
		}
		if ( ! empty( $item['gallery_media_uuids'] ) && 'rg_product' === $type ) {
			$ids = array_filter( array_map( array( __CLASS__, 'media_id_by_uuid' ), (array) $item['gallery_media_uuids'] ) );
			update_post_meta( $post_id, 'rg_gallery', implode( ',', $ids ) );
		}
		if ( ! empty( $item['app_icon_media_uuid'] ) && 'rg_product' === $type ) {
			$icon_id = self::media_id_by_uuid( $item['app_icon_media_uuid'] );
			if ( $icon_id ) { update_post_meta( $post_id, 'rg_app_icon_url', wp_get_attachment_url( $icon_id ) ); }
		}

		$payload = self::post_payload( get_post( $post_id ), false );
		return array( 'uuid' => $uuid, 'id' => $post_id, 'status' => 'synced', 'hash' => self::hash( $payload ), 'url' => get_permalink( $post_id ) );
	}

	public static function sync_media( $file, $data ) {
		@set_time_limit( 120 );
		$uuid = isset( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{20,64}$/i', $uuid ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return new WP_Error( 'invalid_media', __( 'The media upload is invalid.', 'infinity-deploy' ), array( 'status' => 400 ) ); }
		$expected_hash = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
		$actual_hash = hash_file( 'sha256', $file['tmp_name'] );
		if ( $expected_hash && ! hash_equals( $expected_hash, $actual_hash ) ) { return new WP_Error( 'media_checksum_mismatch', __( 'The uploaded media checksum does not match.', 'infinity-deploy' ), array( 'status' => 400 ) ); }

		$existing = self::media_id_by_uuid( $uuid );
		if ( $existing ) {
			$path = get_attached_file( $existing );
			if ( $path && file_exists( $path ) && hash_equals( hash_file( 'sha256', $path ), $actual_hash ) ) { return array( 'uuid' => $uuid, 'id' => $existing, 'status' => 'unchanged', 'url' => wp_get_attachment_url( $existing ) ); }
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$handled = wp_handle_sideload( $file, array( 'test_form' => false, 'mimes' => get_allowed_mime_types() ) );
		if ( isset( $handled['error'] ) ) { return new WP_Error( 'media_upload_failed', sanitize_text_field( $handled['error'] ) ); }
		$filetype = wp_check_filetype( wp_basename( $handled['file'] ), null );
		$attachment_id = wp_insert_attachment( array(
			'guid' => $handled['url'],
			'post_mime_type' => $filetype['type'],
			'post_title' => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
			'post_content' => '',
			'post_status' => 'inherit',
		), $handled['file'], 0, true );
		if ( is_wp_error( $attachment_id ) ) { wp_delete_file( $handled['file'] ); return $attachment_id; }
		$metadata = wp_generate_attachment_metadata( $attachment_id, $handled['file'] );
		if ( $metadata ) { wp_update_attachment_metadata( $attachment_id, $metadata ); }
		update_post_meta( $attachment_id, self::MEDIA_UUID_META, $uuid );
		if ( isset( $data['alt'] ) ) { update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt'] ) ); }
		// Keep the previous attachment as a recovery copy until its references are
		// updated by content sync, but transfer the deployment UUID to this version.
		if ( $existing && $existing !== $attachment_id ) { delete_post_meta( $existing, self::MEDIA_UUID_META ); }
		return array( 'uuid' => $uuid, 'id' => $attachment_id, 'status' => 'uploaded', 'url' => wp_get_attachment_url( $attachment_id ) );
	}

	private static function post_payload( $post, $create_uuid = true ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META, true );
		if ( ! $uuid && $create_uuid ) { $uuid = wp_generate_uuid4(); update_post_meta( $post->ID, self::UUID_META, $uuid ); }
		$meta = array();
		foreach ( self::allowed_meta( $post->post_type ) as $key ) { $meta[ $key ] = get_post_meta( $post->ID, $key, true ); }
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$values = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $values ) && $values ) { $terms[ $taxonomy ] = $values; }
		}
		$featured = get_post_thumbnail_id( $post->ID );
		$gallery_ids = 'rg_product' === $post->post_type ? array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post->ID, 'rg_gallery', true ) ) ) ) : array();
		$related_ids = 'rg_product' === $post->post_type ? array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post->ID, 'rg_related_products', true ) ) ) ) : array();
		$icon_id = 0;
		if ( 'rg_product' === $post->post_type ) {
			$icon_url = get_post_meta( $post->ID, 'rg_app_icon_url', true );
			if ( $icon_url ) { $icon_id = attachment_url_to_postid( $icon_url ); }
		}
		return array(
			'uuid' => $uuid,
			'type' => $post->post_type,
			'title' => $post->post_title,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			'excerpt' => $post->post_excerpt,
			'content' => $post->post_content,
			'menu_order' => intval( $post->menu_order ),
			'meta' => $meta,
			'terms' => $terms,
			'featured_media_uuid' => $featured ? self::media_uuid( $featured, $create_uuid ) : '',
			'gallery_media_uuids' => array_values( array_filter( array_map( function( $id ) use ( $create_uuid ) { return self::media_uuid( $id, $create_uuid ); }, $gallery_ids ) ) ),
			'app_icon_media_uuid' => $icon_id ? self::media_uuid( $icon_id, $create_uuid ) : '',
			'related_product_uuids' => array_values( array_filter( array_map( function( $id ) use ( $create_uuid ) {
				$uuid = get_post_meta( $id, self::UUID_META, true );
				if ( ! $uuid && $create_uuid && 'rg_product' === get_post_type( $id ) ) { $uuid = wp_generate_uuid4(); update_post_meta( $id, self::UUID_META, $uuid ); }
				return $uuid;
			}, $related_ids ) ) ),
		);
	}

	private static function post_media_ids( $post ) {
		$ids = array_filter( array( get_post_thumbnail_id( $post->ID ) ) );
		if ( 'rg_product' === $post->post_type ) {
			$ids = array_merge( $ids, array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post->ID, 'rg_gallery', true ) ) ) ) );
			$icon_url = get_post_meta( $post->ID, 'rg_app_icon_url', true );
			if ( $icon_url ) { $ids[] = attachment_url_to_postid( $icon_url ); }
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private static function media_payload( $id ) {
		$path = get_attached_file( $id );
		$url = wp_get_attachment_url( $id );
		if ( ! $path || ! file_exists( $path ) || ! $url ) { return null; }
		return array(
			'uuid' => self::media_uuid( $id, true ),
			'url' => $url,
			'filename' => wp_basename( $path ),
			'mime' => get_post_mime_type( $id ),
			'title' => get_the_title( $id ),
			'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'hash' => hash_file( 'sha256', $path ),
			'size' => filesize( $path ),
		);
	}

	private static function media_uuid( $id, $create = true ) {
		$uuid = get_post_meta( $id, self::MEDIA_UUID_META, true );
		if ( ! $uuid && $create ) { $uuid = wp_generate_uuid4(); update_post_meta( $id, self::MEDIA_UUID_META, $uuid ); }
		return $uuid;
	}

	public static function media_id_by_uuid( $uuid ) {
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => self::MEDIA_UUID_META, 'meta_value' => sanitize_text_field( $uuid ), 'numberposts' => 1, 'fields' => 'ids' ) );
		return $ids ? absint( $ids[0] ) : 0;
	}

	public static function hash( $payload ) {
		unset( $payload['expected_remote_hash'] );
		return hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
