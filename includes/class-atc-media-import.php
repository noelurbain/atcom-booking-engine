<?php
/**
 * One-off: copy the images hosted on the old CDN (cdn-atcom.reflektlab.ai) into the Media Library and
 * point every post, page and booking page at the local copies (content and imported _atc meta).
 * Posts without a Featured Image get their imported main image as one.
 *
 * Run in timed batches: POST /wp-json/atc/v1/media-import (admins). Each call works ~20 s and says
 * how many posts are left. Safe to repeat: images already copied are reused.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Media_Import {

	const HOST = 'cdn-atcom.reflektlab.ai';
	const MAP  = 'atc_cdn_map';
	const RX   = '#https?://cdn-atcom\.reflektlab\.ai/[^\s"\'()<>\\\\]+#i';

	public static function init(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'atc/v1',
					'/media-import',
					array(
						'methods'             => 'POST',
						'permission_callback' => static fn() => current_user_can( 'manage_options' ),
						'callback'            => static fn( WP_REST_Request $r ) => rest_ensure_response( self::run( (int) ( $r['seconds'] ?? 20 ) ) ),
					)
				);
			}
		);
	}

	private static function next_ids( int $limit ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::HOST ) . '%';
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_atc'
					WHERE p.post_type NOT IN ('revision','attachment') AND ( p.post_content LIKE %s OR m.meta_value LIKE %s ) ORDER BY p.ID LIMIT %d",
					$like,
					$like,
					$limit
				)
			)
		);
	}

	public static function left(): int {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::HOST ) . '%';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_atc' WHERE p.post_type NOT IN ('revision','attachment') AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )", $like, $like ) );
	}

	public static function run( int $seconds = 20 ): array {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		@set_time_limit( 120 ); // phpcs:ignore
		$until  = microtime( true ) + max( 5, min( 50, $seconds ) );
		$map    = (array) get_option( self::MAP, array() );
		$done   = 0;
		$copied = 0;
		$failed = array();
		$skip   = (array) get_option( 'atc_cdn_skip', array() ); // Posts whose images all failed: don't loop on them.
		foreach ( self::next_ids( 200 ) as $id ) {
			if ( microtime( true ) > $until ) {
				break;
			}
			if ( in_array( $id, $skip, true ) ) {
				continue;
			}
			$post = get_post( $id );
			$meta = get_post_meta( $id, '_atc', true );
			preg_match_all( self::RX, $post->post_content . ' ' . wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ), $m );
			$urls = array_unique( array_map( static fn( $u ) => rtrim( $u, '.,;' ), $m[0] ) );
			$ok   = true;
			foreach ( $urls as $u ) {
				if ( isset( $map[ $u ] ) ) {
					continue;
				}
				if ( microtime( true ) > $until ) {
					$ok = false;
					break;
				}
				$att = media_sideload_image( $u, $id, null, 'id' );
				if ( is_wp_error( $att ) ) {
					$map[ $u ] = false;
					$failed[]  = $u;
					continue;
				}
				$map[ $u ] = array( 'id' => (int) $att, 'url' => (string) wp_get_attachment_url( $att ) );
				++$copied;
			}
			update_option( self::MAP, $map, false );
			if ( ! $ok ) {
				break;
			}
			$swap = array();
			foreach ( $urls as $u ) {
				if ( ! empty( $map[ $u ]['url'] ) ) {
					$swap[ $u ] = $map[ $u ]['url'];
				}
			}
			if ( ! $swap ) {
				$skip[] = $id;
				continue;
			}
			uksort( $swap, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) ); // Longest first.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => strtr( $post->post_content, $swap ) ), array( 'ID' => $id ) );
			if ( is_array( $meta ) ) {
				array_walk_recursive(
					$meta,
					static function ( &$v ) use ( $swap ) {
						if ( is_string( $v ) ) {
							$v = strtr( $v, $swap );
						}
					}
				);
				update_post_meta( $id, '_atc', $meta );
				$main = (string) ( $meta['image'] ?? $meta['og_image'] ?? '' );
				foreach ( $map as $u => $a ) {
					if ( ! has_post_thumbnail( $id ) && is_array( $a ) && $a['url'] === $main ) {
						set_post_thumbnail( $id, $a['id'] );
					}
				}
			}
			clean_post_cache( $id );
			if ( strpos( strtr( $post->post_content . wp_json_encode( $meta ), $swap ), self::HOST ) !== false ) {
				$skip[] = $id; // Some images failed: leave their old links, move on.
			}
			++$done;
		}
		update_option( 'atc_cdn_skip', array_values( array_unique( $skip ) ), false );
		return array(
			'posts_done'   => $done,
			'images_copied' => $copied,
			'failed'       => $failed,
			'posts_left'   => max( 0, self::left() - count( $skip ) ),
			'images_total' => count( array_filter( $map ) ),
		);
	}
}
