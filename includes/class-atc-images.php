<?php
/**
 * Page images: make the hero photo of a destination (route) page with OpenAI's image model,
 * save it as {page-slug}.webp in the Media Library and set it as the page's featured image.
 *
 * The OpenAI key never touches the database: it is read from wp-config.php (ATC_OPENAI_KEY).
 * Control → Page images lists the pages still on a placeholder photo and makes them one by one.
 *
 * @package ATCOM_Booking_Engine
 */

defined( 'ABSPATH' ) || exit;

class ATC_Images {

	const NS    = 'atc/v1';
	const MODEL = 'gpt-image-1';
	const SIZE  = '1536x1024';
	const META  = '_atc_img';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function key(): string {
		return defined( 'ATC_OPENAI_KEY' ) ? trim( (string) ATC_OPENAI_KEY ) : '';
	}

	public static function routes(): void {
		$team = array( 'ATC_Rest_Control', 'can' );
		register_rest_route( self::NS, '/control/images', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $team ) );
		register_rest_route( self::NS, '/control/images/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_make' ), 'permission_callback' => $team ) );
		register_rest_route( self::NS, '/control/images/(?P<id>\d+)/prompt', array( 'methods' => 'GET', 'callback' => static fn( WP_REST_Request $r ) => rest_ensure_response( array( 'prompt' => self::prompt( (int) $r['id'] ) ) ), 'permission_callback' => $team ) );
	}

	/* ---------- Which pages need a photo ---------- */

	/** A page has its own photo when its featured image file is named after the page. */
	public static function has_own( int $post_id ): bool {
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb ) {
			return false;
		}
		$slug = get_post_field( 'post_name', $post_id );
		$file = basename( (string) get_attached_file( $thumb ) );
		return '' !== $slug && 0 === strpos( $file, $slug );
	}

	private static function airport_label( string $title ): string {
		$p = stripos( $title, ' Airport to ' );
		return false === $p ? '' : trim( substr( $title, 0, $p ) );
	}

	private static function destination( int $post_id ): string {
		$m = get_post_meta( $post_id, '_atc', true );
		$t = is_array( $m ) && ! empty( $m['h1'] ) ? (string) $m['h1'] : get_the_title( $post_id );
		$p = stripos( $t, ' to ' );
		return html_entity_decode( false === $p ? $t : trim( substr( $t, $p + 4 ) ), ENT_QUOTES );
	}

	public static function rest_list( WP_REST_Request $r ): WP_REST_Response {
		$show = in_array( $r['show'], array( 'missing', 'done', 'all' ), true ) ? $r['show'] : 'missing';
		$ids  = get_posts(
			array(
				'post_type'      => 'atc_route',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$rows     = array();
		$airports = array();
		$missing  = 0;
		foreach ( $ids as $id ) {
			$own = self::has_own( $id );
			if ( ! $own ) {
				++$missing;
			}
			$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES );
			$ap    = self::airport_label( $title );
			if ( $ap ) {
				$airports[ $ap ] = true;
			}
			if ( ( 'missing' === $show && $own ) || ( 'done' === $show && ! $own ) ) {
				continue;
			}
			if ( $r['airport'] && $ap !== (string) $r['airport'] ) {
				continue;
			}
			$thumb  = (int) get_post_thumbnail_id( $id );
			$made   = get_post_meta( $id, self::META, true );
			$rows[] = array(
				'id'          => $id,
				'title'       => $title,
				'airport'     => $ap,
				'slug'        => get_post_field( 'post_name', $id ),
				'status'      => get_post_status( $id ),
				'link'        => get_permalink( $id ),
				'thumb'       => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'medium' ) : '',
				'own'         => $own,
				'made'        => is_array( $made ) ? (string) ( $made['at'] ?? '' ) : '',
			);
		}
		$airports = array_keys( $airports );
		sort( $airports );
		return rest_ensure_response(
			array(
				'key_set'  => '' !== self::key(),
				'missing'  => $missing,
				'total'    => count( $ids ),
				'airports' => $airports,
				'pages'    => $rows,
			)
		);
	}

	/* ---------- The prompt ---------- */

	public static function prompt( int $post_id ): string {
		$m    = get_post_meta( $post_id, '_atc', true );
		$m    = is_array( $m ) ? $m : array();
		$dest = self::destination( $post_id );
		$sub  = trim( (string) ( $m['subtitle'] ?? '' ) );
		$intro = wp_strip_all_tags( (string) ( $m['intro'] ?? '' ) );
		// The intro names the country ("... in Masaya, between Managua and Granada."): keep its last clause as context.
		$where = '';
		if ( preg_match( '/\bin ' . preg_quote( $dest, '/' ) . ',?\s*([^.]+)\./iu', $intro, $mm ) ) {
			$where = trim( $mm[1] );
		}
		$parts = array(
			'Photorealistic travel photograph of ' . $dest . ( $where ? ' (' . $where . ')' : '' ) . '.',
			$sub ? 'Show what the place is known for: ' . rtrim( $sub, '.' ) . '.' : 'Show the place\'s best-known view.',
			'Wide landscape shot, eye level or gentle high angle, natural daylight, true-to-life colors, sharp detail, the kind of photo a traveler would take on a clear day.',
			'The scene must look like the real ' . $dest . ': real architecture, landscape and vegetation of the region, no invented landmarks.',
			'No text, no signs with readable words, no logos, no watermarks, no frames. No close-up faces; people only small in the distance. No airplanes and no cars as the main subject.',
		);
		return implode( ' ', $parts );
	}

	/* ---------- Make and attach ---------- */

	public static function rest_make( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( 'atc_route' !== get_post_type( $id ) ) {
			return new WP_Error( 'atc_not_found', 'Page not found.', array( 'status' => 404 ) );
		}
		$body   = (array) $r->get_json_params();
		$prompt = trim( (string) ( $body['prompt'] ?? '' ) );
		$res    = self::make( $id, '' !== $prompt ? $prompt : self::prompt( $id ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return array|WP_Error */
	public static function make( int $post_id, string $prompt ) {
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'atc_no_key', 'Add your OpenAI key to wp-config.php first: define( \'ATC_OPENAI_KEY\', \'sk-...\' );', array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$resp = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 200,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'              => self::MODEL,
						'prompt'             => $prompt,
						'size'               => self::SIZE,
						'quality'            => 'medium',
						'output_format'      => 'webp',
						'output_compression' => 82,
						'n'                  => 1,
					)
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'atc_openai', 'OpenAI did not answer: ' . $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || empty( $data['data'][0]['b64_json'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'atc_openai', 'OpenAI refused the image: ' . $msg, array( 'status' => 502 ) );
		}
		$bytes = base64_decode( (string) $data['data'][0]['b64_json'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! $bytes ) {
			return new WP_Error( 'atc_openai', 'The image could not be read.', array( 'status' => 502 ) );
		}

		$slug = get_post_field( 'post_name', $post_id );
		$up   = wp_upload_bits( $slug . '.webp', null, $bytes );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'atc_upload', 'Saving the image failed: ' . $up['error'], array( 'status' => 500 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$dest  = self::destination( $post_id );
		$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES );
		$att   = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/webp',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$up['file'],
			$post_id
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return new WP_Error( 'atc_upload', 'The image could not be added to the Media Library.', array( 'status' => 500 ) );
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		update_post_meta( $att, '_wp_attachment_image_alt', $dest );

		$old = (int) get_post_thumbnail_id( $post_id );
		set_post_thumbnail( $post_id, $att );
		update_post_meta(
			$post_id,
			self::META,
			array(
				'at'       => current_time( 'mysql', true ),
				'by'       => get_current_user_id(),
				'prompt'   => $prompt,
				'previous' => $old,
				'media'    => $att,
			)
		);
		ATC_Install::log( 'route', $post_id, 'image_made', array( 'media' => $att, 'previous' => $old ) );
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		return array(
			'id'     => $post_id,
			'media'  => $att,
			'file'   => basename( $up['file'] ),
			'url'    => (string) wp_get_attachment_image_url( $att, 'medium' ),
			'full'   => $up['url'],
			'prompt' => $prompt,
		);
	}
}
