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
	const RX   = '#(?:https?://cdn-atcom\.reflektlab\.ai/|https?://airporttransportation\.com/static/|(?<=["\'(])/static/img/)[^\s"\'()<>\\\\]+#i';
	const OLD  = 'https://airporttransportation.com';

	/** Where to download a found reference from (old-site paths may be relative). */
	private static function src( string $u ): string {
		return 0 === strpos( $u, '/static/' ) ? self::OLD . $u : $u;
	}

	/** Does this text still point at an old image host? */
	private static function has_old( string $t ): bool {
		return (bool) preg_match( self::RX, $t );
	}

	public static function init(): void {
		add_action( 'atc_media_import_tick', array( __CLASS__, 'tick' ) );
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
					WHERE p.post_type NOT IN ('revision','attachment') AND ( p.post_content LIKE %s OR m.meta_value LIKE %s OR p.post_content LIKE '%%/static/img/%%' OR m.meta_value LIKE '%%/static/img/%%' ) ORDER BY p.ID LIMIT %d",
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
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_atc' WHERE p.post_type NOT IN ('revision','attachment') AND ( p.post_content LIKE %s OR m.meta_value LIKE %s OR p.post_content LIKE '%%/static/img/%%' OR m.meta_value LIKE '%%/static/img/%%' )", $like, $like ) );
	}

	/** Background: keep going every minute (WP-Cron) until nothing is left. */
	public static function tick(): void {
		$r = self::run( 45 );
		if ( $r['posts_left'] > 0 && ( ! empty( $r['busy'] ) || $r['posts_done'] > 0 || $r['images_copied'] > 0 ) ) {
			wp_schedule_single_event( time() + 20, 'atc_media_import_tick' );
		}
		update_option( 'atc_cdn_last', array( 'at' => gmdate( 'c' ), 'result' => array_diff_key( $r, array( 'failed' => 1 ) ) ), false );
	}

	public static function run( int $seconds = 20 ): array {
		// Only the sizes the site shows (faster import, less disk).
		$sizes = static fn( $s ) => array_intersect_key( $s, array_flip( array( 'medium', 'large' ) ) );
		add_filter( 'intermediate_image_sizes_advanced', $sizes, 99 );
		add_filter( 'big_image_size_threshold', '__return_false' );
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
		if ( get_transient( 'atc_cdn_lock' ) ) {
			return array( 'busy' => true, 'posts_done' => 0, 'images_copied' => 0, 'failed' => array(), 'posts_left' => self::left(), 'images_total' => count( array_filter( $map ) ) );
		}
		set_transient( 'atc_cdn_lock', 1, 90 );
		$ids = array_values( array_diff( self::next_ids( 200 ), $skip ) );
		$pre = self::prefetch( $ids, $map, $until );
		foreach ( $ids as $id ) {
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
				if ( isset( $pre[ $u ] ) ) {
					$file = array( 'name' => wp_basename( (string) wp_parse_url( self::src( $u ), PHP_URL_PATH ) ), 'tmp_name' => $pre[ $u ] );
					$att  = media_handle_sideload( $file, $id );
					unset( $pre[ $u ] );
					if ( ! is_wp_error( $att ) ) {
						update_post_meta( $att, '_source_url', $u );
					}
				} else {
					$att = self::sideload( $u, $id );
				}
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
			if ( self::has_old( strtr( $post->post_content . ' ' . wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ), $swap ) ) ) {
				$skip[] = $id; // Some images failed: leave their old links, move on.
			}
			++$done;
		}
		if ( microtime( true ) < $until ) {
			$copied += self::options( $map );
		}
		update_option( 'atc_cdn_skip', array_values( array_unique( $skip ) ), false );
		foreach ( $pre as $tmp ) {
			wp_delete_file( $tmp );
		}
		delete_transient( 'atc_cdn_lock' );
		remove_filter( 'intermediate_image_sizes_advanced', $sizes, 99 );
		if ( ! wp_next_scheduled( 'atc_media_import_tick' ) && ! doing_action( 'atc_media_import_tick' ) && self::left() > count( $skip ) ) {
			wp_schedule_single_event( time() + 20, 'atc_media_import_tick' );
		}
		return array(
			'posts_done'   => $done,
			'images_copied' => $copied,
			'failed'       => $failed,
			'posts_left'   => max( 0, self::left() - count( $skip ) ),
			'images_total' => count( array_filter( $map ) ),
		);
	}

	/** Download the next posts' missing images 16 at a time, into temp files. @return array url => temp path */
	private static function prefetch( array $ids, array $map, float $until ): array {
		$want = array();
		foreach ( array_slice( $ids, 0, 25 ) as $id ) {
			$post = get_post( $id );
			preg_match_all( self::RX, $post->post_content . ' ' . wp_json_encode( get_post_meta( $id, '_atc', true ), JSON_UNESCAPED_SLASHES ), $m );
			foreach ( $m[0] as $u ) {
				$u = rtrim( $u, '.,;' );
				if ( ! isset( $map[ $u ] ) ) {
					$want[ $u ] = true;
				}
			}
			if ( count( $want ) >= 96 ) {
				break;
			}
		}
		$out = array();
		foreach ( array_chunk( array_keys( $want ), 16 ) as $chunk ) {
			if ( microtime( true ) > $until - 10 || ! class_exists( 'WpOrg\\Requests\\Requests' ) ) {
				break;
			}
			$reqs = array();
			foreach ( $chunk as $u ) {
				$tmp        = wp_tempnam( $u );
				$reqs[ $u ] = array( 'url' => self::src( $u ), 'options' => array( 'filename' => $tmp, 'timeout' => 30 ) );
				$out[ $u ]  = $tmp;
			}
			$res = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 30, 'connect_timeout' => 10 ) );
			foreach ( $res as $u => $r ) {
				if ( ! ( $r instanceof \WpOrg\Requests\Response ) || 200 !== (int) $r->status_code || ! @filesize( $out[ $u ] ) ) {
					wp_delete_file( $out[ $u ] );
					unset( $out[ $u ] ); // Falls back to a normal download later.
				}
			}
		}
		return $out;
	}

	/** media_sideload_image() wants a URL ending in an image extension: strip ?v=… and resolve old-site paths. */
	private static function sideload( string $u, int $parent ) {
		$url = self::src( $u );
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$att = media_handle_sideload( array( 'name' => wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), 'tmp_name' => $tmp ), $parent );
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			return $att;
		}
		update_post_meta( $att, '_source_url', $url );
		return $att;
	}

	/** The site-wide default images (settings) and the homepage's Featured Image. @return int images copied */
	private static function options( array &$map ): int {
		$n = 0;
		foreach ( array( 'atc_img_default', 'atc_img_airports', 'atc_img_airlines' ) as $opt ) {
			$v = (string) get_option( $opt, '' );
			if ( '' === $v || ! self::has_old( $v ) ) {
				continue;
			}
			if ( ! isset( $map[ $v ] ) ) {
				$att       = self::sideload( $v, 0 );
				$map[ $v ] = is_wp_error( $att ) ? false : array( 'id' => (int) $att, 'url' => (string) wp_get_attachment_url( $att ) );
				$n        += is_wp_error( $att ) ? 0 : 1;
				update_option( self::MAP, $map, false );
			}
			if ( ! empty( $map[ $v ]['url'] ) ) {
				update_option( $opt, $map[ $v ]['url'] );
			}
		}
		$home = (int) get_option( 'page_on_front' );
		$pick = self::OLD . '/static/img/slide/airport-transportation-pickup.webp';
		if ( $home && ! has_post_thumbnail( $home ) && ! empty( $map[ $pick ]['id'] ) ) {
			set_post_thumbnail( $home, $map[ $pick ]['id'] );
		}
		return $n;
	}
}
