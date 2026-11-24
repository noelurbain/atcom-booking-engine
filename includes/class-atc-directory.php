<?php
/**
 * The directory link: each provider in the engine owns one company profile in the theme's directory
 * (post type atc_provider, page /directory/company/{slug}/, fields in the `_atc` post meta).
 *
 * - Existing profiles are matched to providers by name, website or phone; new sign-ups get a profile that
 *   stays a draft until we approve them (approval publishes it). Profiles that were already public stay public.
 * - Providers edit their profile in the portal (Directory profile); our team can edit it, link it to another
 *   profile or unlink it from the provider page in Control.
 * - Contacts follow the theme's rules: phone and WhatsApp sit behind "See phone" / "See WhatsApp".
 * - The website shows only while their site links back to us (Settings → Directory, on by default): every
 *   profile is checked once a day, and right away when the website changes. Our team can override it per
 *   profile (always show / never show).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Directory {

	const PT   = 'atc_provider';
	const TAX  = 'atc_place';
	const LINK = '_atc_party_id';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_link_all' ), 99 );
		add_action( 'atc_provider_signed_up', array( __CLASS__, 'on_signup' ) );
		add_action( 'atc_provider_live', array( __CLASS__, 'on_live' ) );
		add_action( 'atc_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'update_option_' . ATC_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	public static function settings_changed( $old, $new ): void {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		if ( ( $old['dir_backlink_required'] ?? null ) !== ( $new['dir_backlink_required'] ?? null ) || ( $old['backlink_domains'] ?? null ) !== ( $new['backlink_domains'] ?? null ) ) {
			self::apply_all();
		}
	}

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/* ---------- Linking ---------- */

	public static function post_id( int $party_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = %s AND p.post_status IN ('publish','draft','pending','private') WHERE m.meta_key = %s AND m.meta_value = %d ORDER BY m.post_id LIMIT 1", self::PT, self::LINK, $party_id ) );
	}

	public static function party_for_post( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::LINK, true );
	}

	/** Links a provider to a profile (0 unlinks). A profile belongs to one provider only. */
	public static function link( int $party_id, int $post_id ) {
		$old = self::post_id( $party_id );
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || self::PT !== $post->post_type ) {
				return new WP_Error( 'atc_dir_post', 'That directory profile does not exist.', array( 'status' => 404 ) );
			}
			$owner = self::party_for_post( $post_id );
			if ( $owner && $owner !== $party_id ) {
				$p = ATC_Catalog::party( $owner );
				return new WP_Error( 'atc_dir_taken', 'That profile is already linked to ' . ( $p ? $p['name'] : 'another provider' ) . '. Unlink it there first.', array( 'status' => 409 ) );
			}
		}
		if ( $old && $old !== $post_id ) {
			delete_post_meta( $old, self::LINK );
		}
		if ( $post_id ) {
			update_post_meta( $post_id, self::LINK, $party_id );
			self::apply( $post_id );
		}
		ATC_Install::log( 'party', $party_id, $post_id ? 'directory_linked' : 'directory_unlinked', array( 'post_id' => $post_id ?: $old ) );
		return self::profile( $party_id );
	}

	private static function norm( string $s ): string {
		$s = remove_accents( strtolower( html_entity_decode( $s, ENT_QUOTES ) ) );
		return preg_replace( '/[^a-z0-9]/', '', $s );
	}

	private static function host( string $url ): string {
		$h = (string) wp_parse_url( ( false === strpos( $url, '//' ) ? 'https://' : '' ) . trim( $url ), PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $h ) );
	}

	private static function digits( string $phone ): string {
		$d = preg_replace( '/\D/', '', $phone );
		return strlen( $d ) >= 8 ? substr( $d, -9 ) : '';
	}

	/** Finds an unlinked profile that is clearly this provider: same name, same website, or same phone. */
	public static function find_match( int $party_id ): int {
		global $wpdb;
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p ) {
			return 0;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_atc'
				WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private')
				AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} l WHERE l.post_id = p.ID AND l.meta_key = %s)",
				self::PT,
				self::LINK
			),
			ARRAY_A
		);
		$name  = self::norm( (string) $p['name'] );
		$host  = $p['website'] ? self::host( (string) $p['website'] ) : '';
		$phone = self::digits( (string) $p['phone'] );
		foreach ( array( 'name', 'host', 'phone' ) as $by ) {
			foreach ( $rows as $r ) {
				$m = maybe_unserialize( (string) $r['meta_value'] );
				$m = is_array( $m ) ? $m : array();
				if ( 'name' === $by && '' !== $name && self::norm( (string) $r['post_title'] ) === $name ) {
					return (int) $r['ID'];
				}
				if ( 'host' === $by && '' !== $host && ! empty( $m['website'] ) && self::host( (string) $m['website'] ) === $host ) {
					return (int) $r['ID'];
				}
				if ( 'phone' === $by && '' !== $phone && ( self::digits( (string) ( $m['phone'] ?? '' ) ) === $phone || self::digits( (string) ( $m['whatsapp'] ?? '' ) ) === $phone ) ) {
					return (int) $r['ID'];
				}
			}
		}
		return 0;
	}

	/** After an upgrade: link every provider to its profile, and give the rest one (draft until live). */
	public static function maybe_link_all(): void {
		if ( ! get_option( 'atc_dir_link_pending' ) || ! post_type_exists( self::PT ) ) {
			return;
		}
		delete_option( 'atc_dir_link_pending' );
		self::link_all();
	}

	public static function link_all(): array {
		global $wpdb;
		$ids  = $wpdb->get_col( 'SELECT id FROM ' . self::t( 'parties' ) . " WHERE type = 'provider' AND status <> 'archived' ORDER BY id" );
		$out  = array( 'linked' => 0, 'created' => 0, 'already' => 0 );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( self::post_id( $id ) ) {
				++$out['already'];
				continue;
			}
			$match = self::find_match( $id );
			if ( $match ) {
				self::link( $id, $match );
				++$out['linked'];
			} else {
				self::create( $id, false ); // a draft: it goes public when they or we save it
				++$out['created'];
			}
		}
		ATC_Install::log( 'directory', 0, 'link_all', $out );
		return $out;
	}

	public static function on_signup( int $party_id ): void {
		if ( self::post_id( $party_id ) ) {
			return;
		}
		$match = self::find_match( $party_id );
		$match ? self::link( $party_id, $match ) : self::create( $party_id );
	}

	/** Approval publishes the profile. */
	public static function on_live( int $party_id ): void {
		$pid = self::post_id( $party_id ) ?: self::create( $party_id );
		if ( $pid && 'publish' !== get_post_status( $pid ) ) {
			wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
		}
	}

	/** A new profile for a provider: name, place and contacts from the sign-up. Draft unless live (always a draft when made in bulk). */
	public static function create( int $party_id, bool $publish_if_live = true ): int {
		$p = ATC_Catalog::party( $party_id );
		if ( ! $p || ! post_type_exists( self::PT ) ) {
			return 0;
		}
		$pid = wp_insert_post(
			array(
				'post_type'    => self::PT,
				'post_title'   => $p['name'],
				'post_status'  => $publish_if_live && 'live' === $p['status'] ? 'publish' : 'draft',
				'post_content' => $p['about'] ? self::paras( self::clean_about( (string) $p['about'] ) ) : '',
			),
			true
		);
		if ( is_wp_error( $pid ) ) {
			return 0;
		}
		update_post_meta( $pid, self::LINK, $party_id );
		update_post_meta( $pid, '_atc_src', 'engine' );
		$m = array(
			'category' => 'Ground Transportation',
			'phone'    => (string) $p['phone'],
			'whatsapp' => (string) $p['phone'],
		);
		update_post_meta( $pid, '_atc', $m );
		if ( $p['website'] ) {
			update_post_meta( $pid, '_atc_site', esc_url_raw( (string) $p['website'] ) );
		}
		self::place( $pid, $party_id );
		self::refresh_seo( $pid );
		ATC_Install::log( 'party', $party_id, 'directory_created', array( 'post_id' => $pid ) );
		return (int) $pid;
	}

	private static function clean_about( string $about ): string {
		return trim( preg_replace( '/^Fleet size:.*\n?/m', '', $about ) );
	}

	private static function paras( string $text ): string {
		$text = trim( $text );
		return '' === $text ? '' : wpautop( esc_html( $text ) );
	}

	/* ---------- The profile ---------- */

	private static function meta( int $post_id ): array {
		$m = get_post_meta( $post_id, '_atc', true );
		return is_array( $m ) ? $m : array();
	}

	private static function country_name( string $cc ): string {
		$cc  = strtoupper( $cc );
		$own = array( 'US' => 'United States of America', 'GB' => 'United Kingdom', 'AE' => 'United Arab Emirates', 'DO' => 'Dominican Republic', 'MX' => 'Mexico', 'CR' => 'Costa Rica' );
		if ( isset( $own[ $cc ] ) ) {
			return $own[ $cc ];
		}
		if ( class_exists( 'Locale' ) ) {
			$n = \Locale::getDisplayRegion( '-' . $cc, 'en' );
			if ( $n && $n !== $cc ) {
				return $n;
			}
		}
		return $cc;
	}

	/** Puts the profile under its country and city in the directory, from the provider's main airport. */
	private static function place( int $post_id, int $party_id ): void {
		$p = ATC_Catalog::party( $party_id );
		$a = $p && $p['home_airport_id'] ? ATC_Catalog::airport( (int) $p['home_airport_id'] ) : null;
		if ( ! $a || ! taxonomy_exists( self::TAX ) ) {
			return;
		}
		$country = self::country_name( (string) $a['country'] );
		$ct      = get_term_by( 'name', $country, self::TAX );
		if ( ! $ct && 'US' === strtoupper( (string) $a['country'] ) ) {
			$ct = get_term_by( 'slug', 'usa', self::TAX );
		}
		if ( ! $ct ) {
			$r  = wp_insert_term( $country, self::TAX );
			$ct = is_wp_error( $r ) ? null : get_term( (int) $r['term_id'], self::TAX );
		}
		if ( ! $ct ) {
			return;
		}
		$city = null;
		foreach ( (array) get_terms( array( 'taxonomy' => self::TAX, 'parent' => $ct->term_id, 'hide_empty' => false ) ) as $t ) {
			if ( $t instanceof WP_Term && self::norm( $t->name ) === self::norm( (string) $a['city'] ) ) {
				$city = $t;
			}
		}
		if ( ! $city ) {
			$r    = wp_insert_term( $a['city'], self::TAX, array( 'parent' => $ct->term_id, 'slug' => sanitize_title( $a['city'] . '-' . $ct->slug ) ) );
			$city = is_wp_error( $r ) ? null : get_term( (int) $r['term_id'], self::TAX );
		}
		wp_set_object_terms( $post_id, array_filter( array( (int) $ct->term_id, $city ? (int) $city->term_id : 0 ) ), self::TAX );
		$m = self::meta( $post_id );
		if ( empty( $m['location'] ) ) {
			$m['location'] = $a['city'] . ', ' . $country;
			update_post_meta( $post_id, '_atc', $m );
		}
	}

	private static function refresh_seo( int $post_id ): void {
		$m     = self::meta( $post_id );
		$name  = get_the_title( $post_id );
		$city  = trim( (string) preg_replace( '/,.*$/', '', (string) ( $m['location'] ?? '' ) ) );
		$where = $city ? ' in ' . $city : '';
		$m['h1'] = $name;
		if ( empty( $m['seo_title'] ) || ! empty( $m['seo_auto'] ) ) {
			$m['seo_title'] = $name . ': airport transfers' . $where;
			$m['seo_desc']  = 'Book a private airport transfer with ' . $name . $where . ( $m['location'] ?? '' ? ', ' . trim( (string) preg_replace( '/^[^,]*,\s*/', '', (string) $m['location'] ) ) : '' ) . ': professional drivers and a fixed price paid online when you book.';
			$m['seo_auto']  = 1;
		}
		update_post_meta( $post_id, '_atc', $m );
	}

	/** What the provider offers in the engine, to fill the profile: vehicle categories and zones. */
	private static function offered( int $party_id ): array {
		global $wpdb;
		$s     = self::t( 'services' );
		$types = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT v.name FROM ' . $s . ' s JOIN ' . self::t( 'vehicle_types' ) . " v ON v.id = s.vehicle_type_id WHERE s.party_id = %d AND s.status <> 'paused' ORDER BY v.sort", $party_id ) );
		$zones = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT z.name FROM ' . $s . ' s JOIN ' . self::t( 'service_zones' ) . ' sz ON sz.service_id = s.id JOIN ' . self::t( 'zones' ) . " z ON z.id = sz.zone_id WHERE s.party_id = %d AND s.status <> 'paused' AND z.status = 'active' ORDER BY z.sort, z.name", $party_id ) );
		return array( 'services' => array_values( $types ), 'destinations' => array_values( $zones ) );
	}

	public static function profile( int $party_id ): array {
		$p   = ATC_Catalog::party( $party_id );
		$pid = self::post_id( $party_id );
		$off = self::offered( $party_id );
		$a   = $p && $p['home_airport_id'] ? ATC_Catalog::airport( (int) $p['home_airport_id'] ) : null;
		$out = array(
			'linked'    => (bool) $pid,
			'post_id'   => $pid,
			'suggest'   => $off + array( 'phone' => (string) ( $p['phone'] ?? '' ), 'website' => (string) ( $p['website'] ?? '' ), 'about' => self::clean_about( (string) ( $p['about'] ?? '' ) ) ),
			'airport'   => $a ? ATC_Catalog::airport_label( $a ) : '',
			'provider_live' => $p && 'live' === $p['status'],
		);
		if ( ! $pid ) {
			$match           = self::find_match( $party_id );
			$out['match']    = $match ? array( 'id' => $match, 'title' => get_the_title( $match ), 'url' => get_permalink( $match ) ) : null;
			return $out;
		}
		$post = get_post( $pid );
		$m    = self::meta( $pid );
		$site = self::site( $pid );
		return $out + array(
			'status'       => $post->post_status,
			'url'          => 'publish' === $post->post_status ? get_permalink( $pid ) : '',
			'edit_url'     => get_edit_post_link( $pid, 'raw' ),
			'title'        => $post->post_title,
			'summary'      => (string) ( $m['summary'] ?? '' ),
			'about'        => trim( html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br />', '<br>' ), "\n", (string) $post->post_content ) ), ENT_QUOTES ) ),
			'logo'         => (string) ( $m['logo'] ?? '' ),
			'services'     => array_values( (array) ( $m['services'] ?? array() ) ),
			'destinations' => array_values( (array) ( $m['destinations'] ?? array() ) ),
			'phone'        => (string) ( $m['phone'] ?? '' ),
			'whatsapp'     => (string) ( $m['whatsapp'] ?? '' ),
			'address'      => (string) ( $m['address'] ?? '' ),
			'website'      => $site,
			'show_website' => ! empty( $m['website'] ),
			'backlink'     => self::backlink( $pid ),
			'location'     => (string) ( $m['location'] ?? '' ),
			'src'          => (string) get_post_meta( $pid, '_atc_src', true ),
		);
	}

	private static function list_in( $v, int $max ): array {
		$items = is_array( $v ) ? $v : preg_split( '/[,\n]+/', (string) $v );
		$out   = array();
		foreach ( $items as $i ) {
			$i = trim( sanitize_text_field( (string) $i ) );
			if ( '' !== $i && ! in_array( mb_strtolower( $i ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = mb_substr( $i, 0, 60 );
			}
		}
		return array_slice( $out, 0, $max );
	}

	/** Saves the profile. $staff: our team (can switch the website on). */
	public static function save( int $party_id, array $in, bool $staff = false ) {
		$pid = self::post_id( $party_id ) ?: self::create( $party_id );
		if ( ! $pid ) {
			return new WP_Error( 'atc_dir_none', 'The directory is not available right now.', array( 'status' => 500 ) );
		}
		$m    = self::meta( $pid );
		$post = array( 'ID' => $pid );
		if ( isset( $in['title'] ) ) {
			$t = trim( sanitize_text_field( (string) $in['title'] ) );
			if ( '' === $t ) {
				return new WP_Error( 'atc_dir_title', 'Enter your company name.', array( 'status' => 400 ) );
			}
			$post['post_title'] = mb_substr( $t, 0, 120 );
		}
		if ( isset( $in['about'] ) ) {
			$post['post_content'] = self::paras( mb_substr( sanitize_textarea_field( (string) $in['about'] ), 0, 3000 ) );
		}
		if ( isset( $in['summary'] ) ) {
			$m['summary'] = mb_substr( trim( sanitize_textarea_field( (string) $in['summary'] ) ), 0, 300 );
		}
		foreach ( array( 'services' => 16, 'destinations' => 40 ) as $k => $max ) {
			if ( isset( $in[ $k ] ) ) {
				$m[ $k ] = self::list_in( $in[ $k ], $max );
			}
		}
		foreach ( array( 'phone', 'whatsapp' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$m[ $k ] = mb_substr( preg_replace( '/[^0-9+ ()\-]/', '', (string) $in[ $k ] ), 0, 30 );
			}
		}
		if ( isset( $in['address'] ) ) {
			$m['address'] = mb_substr( sanitize_text_field( (string) $in['address'] ), 0, 190 );
		}
		$recheck = false;
		if ( isset( $in['website'] ) ) {
			$w   = self::url_in( (string) $in['website'] );
			$was = self::site( $pid );
			update_post_meta( $pid, '_atc_site', $w );
			if ( $w !== $was ) {
				$recheck = true;
				if ( self::host( $w ) !== self::host( $was ) ) {
					// Another site: what we knew about the old one no longer counts.
					foreach ( array( '_atc_bl_status', '_atc_bl_found', '_atc_bl_ever', '_atc_bl_miss', '_atc_bl_checked', '_atc_bl_nofollow' ) as $k ) {
						delete_post_meta( $pid, $k );
					}
				}
			}
		}
		if ( isset( $in['backlink_page'] ) ) {
			$bp   = self::url_in( (string) $in['backlink_page'] );
			$site = isset( $in['website'] ) ? self::url_in( (string) $in['website'] ) : self::site( $pid );
			if ( '' !== $bp && self::host( $bp ) !== self::host( $site ) ) {
				return new WP_Error( 'atc_bl_page', 'The page with the link to us has to be on your own website (' . ( self::host( $site ) ?: 'add your website first' ) . ').', array( 'status' => 400 ) );
			}
			if ( $bp !== (string) get_post_meta( $pid, '_atc_bl_page', true ) ) {
				update_post_meta( $pid, '_atc_bl_page', $bp );
				$recheck = true;
			}
		}
		if ( $staff && isset( $in['site_mode'] ) && in_array( $in['site_mode'], array( 'auto', 'always', 'never' ), true ) ) {
			update_post_meta( $pid, '_atc_site_mode', $in['site_mode'] );
		}
		if ( empty( $m['category'] ) ) {
			$m['category'] = 'Ground Transportation';
		}
		$p = ATC_Catalog::party( $party_id );
		if ( $p && 'live' === $p['status'] ) {
			$post['post_status'] = 'publish';
		}
		$r = wp_update_post( $post, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_post_meta( $pid, '_atc', $m );
		if ( ! has_term( '', self::TAX, $pid ) ) {
			self::place( $pid, $party_id );
		}
		self::refresh_seo( $pid );
		$recheck && '' !== self::site( $pid ) ? self::check( $pid ) : self::apply( $pid );
		ATC_Install::log( 'party', $party_id, 'directory_saved', array( 'post_id' => $pid, 'by' => $staff ? 'team' : 'provider' ) );
		return self::profile( $party_id );
	}

	/* ---------- Website and the link back to us ---------- */

	private static function url_in( string $u ): string {
		$u = trim( $u );
		return '' === $u ? '' : (string) esc_url_raw( false === strpos( $u, '//' ) ? 'https://' . $u : $u, array( 'http', 'https' ) );
	}

	/** The provider's website (kept apart from the profile, which shows it only when allowed). */
	public static function site( int $pid ): string {
		$s = (string) get_post_meta( $pid, '_atc_site', true );
		if ( '' === $s ) {
			$m = self::meta( $pid );
			if ( ! empty( $m['website'] ) ) {
				$s = (string) $m['website'];
				update_post_meta( $pid, '_atc_site', $s );
			}
		}
		return $s;
	}

	/** The domains a link must point to, from Settings. */
	public static function our_domains(): array {
		$list = preg_split( '/[\s,]+/', strtolower( (string) ATC_Settings::get( 'backlink_domains' ) ) );
		$list = array_merge( $list, array( self::host( home_url( '/' ) ) ) );
		return array_values( array_unique( array_filter( array_map( static fn( $d ) => preg_replace( '/^www\./', '', trim( $d ) ), $list ) ) ) );
	}

	/** The link we suggest providers add. */
	public static function link_snippet(): string {
		$d = self::our_domains()[0] ?? 'airporttransportation.com';
		return '<a href="https://' . $d . '/">Airport transfers with Airport Transportation</a>';
	}

	public static function mode( int $pid ): string {
		$m = (string) get_post_meta( $pid, '_atc_site_mode', true );
		return in_array( $m, array( 'always', 'never' ), true ) ? $m : 'auto';
	}

	public static function backlink( int $pid ): array {
		return array(
			'required' => (bool) ATC_Settings::get( 'dir_backlink_required' ),
			'mode'     => self::mode( $pid ),
			'status'   => (string) get_post_meta( $pid, '_atc_bl_status', true ) ?: 'unchecked', // found | missing | error | unchecked
			'checked'  => (string) get_post_meta( $pid, '_atc_bl_checked', true ),
			'found'    => (string) get_post_meta( $pid, '_atc_bl_found', true ),
			'nofollow' => '1' === (string) get_post_meta( $pid, '_atc_bl_nofollow', true ),
			'page'     => (string) get_post_meta( $pid, '_atc_bl_page', true ),
			'error'    => (string) get_post_meta( $pid, '_atc_bl_error', true ),
			'snippet'  => self::link_snippet(),
		);
	}

	/**
	 * Visits the provider's page (the one they named, or their home page) and looks for a link to us.
	 * A page that does not answer counts as nothing: the website keeps its current state until a real answer.
	 */
	public static function check( int $pid ): array {
		$site = self::site( $pid );
		$url  = (string) get_post_meta( $pid, '_atc_bl_page', true ) ?: $site;
		update_post_meta( $pid, '_atc_bl_checked', current_time( 'mysql', true ) );
		if ( '' === $url ) {
			update_post_meta( $pid, '_atc_bl_status', 'unchecked' );
			self::apply( $pid );
			return self::backlink( $pid );
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 5,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; AirportTransportationBot/1.0; +https://airporttransportation.com/directory/)',
				'headers'             => array( 'Accept' => 'text/html' ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || $code >= 400 || $code < 200 ) {
			update_post_meta( $pid, '_atc_bl_status', 'error' );
			update_post_meta( $pid, '_atc_bl_error', is_wp_error( $res ) ? mb_substr( $res->get_error_message(), 0, 160 ) : 'The page answered ' . $code . '.' );
			self::apply( $pid );
			return self::backlink( $pid );
		}
		delete_post_meta( $pid, '_atc_bl_error' );
		$found = self::find_link( (string) wp_remote_retrieve_body( $res ) );
		if ( $found ) {
			update_post_meta( $pid, '_atc_bl_status', 'found' );
			update_post_meta( $pid, '_atc_bl_found', $found['href'] );
			update_post_meta( $pid, '_atc_bl_nofollow', $found['nofollow'] ? '1' : '0' );
			update_post_meta( $pid, '_atc_bl_ever', '1' );
			update_post_meta( $pid, '_atc_bl_miss', 0 );
		} else {
			update_post_meta( $pid, '_atc_bl_status', 'missing' );
			update_post_meta( $pid, '_atc_bl_miss', (int) get_post_meta( $pid, '_atc_bl_miss', true ) + 1 );
			delete_post_meta( $pid, '_atc_bl_found' );
		}
		self::apply( $pid );
		return self::backlink( $pid );
	}

	/** The first <a href> in the HTML that points to one of our domains. */
	public static function find_link( string $html ): ?array {
		if ( ! preg_match_all( '/<a\b([^>]*)>/i', $html, $tags ) ) {
			return null;
		}
		$ours = self::our_domains();
		foreach ( $tags[1] as $attrs ) {
			if ( ! preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $h ) ) {
				continue;
			}
			$href = html_entity_decode( trim( $h[1] . ( $h[2] ?? '' ) . ( $h[3] ?? '' ) ), ENT_QUOTES );
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			}
			if ( ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$host = self::host( $href );
			foreach ( $ours as $d ) {
				if ( $host === $d || str_ends_with( $host, '.' . $d ) ) {
					return array( 'href' => mb_substr( $href, 0, 300 ), 'nofollow' => (bool) preg_match( '/\brel\s*=\s*["\']?[^"\'>]*\b(nofollow|sponsored|ugc)\b/i', $attrs ) );
				}
			}
		}
		return null;
	}

	/**
	 * Puts the website on the public profile or takes it off.
	 * Always / never: our team decided. Automatic: shown when the link check is off in Settings; when it is on,
	 * shown once the link to us is found, and taken off when it is missing (after two checks in a row if it
	 * was there before, so one bad day on their site does not hide it). No answer from their site changes nothing.
	 */
	public static function apply( int $pid ): void {
		$site = self::site( $pid );
		$m    = self::meta( $pid );
		$now  = ! empty( $m['website'] );
		$mode = self::mode( $pid );
		if ( '' === $site || 'never' === $mode ) {
			$show = false;
		} elseif ( 'always' === $mode || ! ATC_Settings::get( 'dir_backlink_required' ) ) {
			$show = true;
		} else {
			$st = (string) get_post_meta( $pid, '_atc_bl_status', true );
			if ( 'found' === $st ) {
				$show = true;
			} elseif ( 'missing' === $st ) {
				$show = $now && get_post_meta( $pid, '_atc_bl_ever', true ) && (int) get_post_meta( $pid, '_atc_bl_miss', true ) < 2;
			} else {
				$show = $now; // not checked yet, or no answer
			}
		}
		if ( $show && ( ! $now || $m['website'] !== $site ) ) {
			$m['website'] = $site;
			update_post_meta( $pid, '_atc', $m );
		} elseif ( ! $show && $now ) {
			unset( $m['website'] );
			update_post_meta( $pid, '_atc', $m );
		}
	}

	/** Every hour, a few profiles whose last check is older than a day (all of them get checked daily). */
	public static function hourly(): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.post_id, c.meta_value AS checked FROM {$wpdb->postmeta} l
				JOIN {$wpdb->posts} p ON p.ID = l.post_id AND p.post_type = %s AND p.post_status IN ('publish','draft')
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = l.post_id AND c.meta_key = '_atc_bl_checked'
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = l.post_id AND s.meta_key = '_atc_site'
				LEFT JOIN {$wpdb->postmeta} a ON a.post_id = l.post_id AND a.meta_key = '_atc'
				WHERE l.meta_key = %s AND ( s.meta_value <> '' OR a.meta_value LIKE %s )
				AND ( c.meta_value IS NULL OR c.meta_value < %s )
				ORDER BY c.meta_value IS NOT NULL, c.meta_value LIMIT 15",
				self::PT,
				self::LINK,
				'%s:7:"website"%',
				gmdate( 'Y-m-d H:i:s', time() - 20 * HOUR_IN_SECONDS )
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			self::check( (int) $r['post_id'] );
		}
	}

	/** Settings changed (the link check switched on or off, or the domains): update every profile, no visits. */
	public static function apply_all(): void {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::LINK ) ) as $pid ) {
			self::apply( (int) $pid );
		}
	}

	/** "Check now" from the portal or Control: once a minute per profile. */
	public static function check_now( int $party_id ) {
		$pid = self::post_id( $party_id );
		if ( ! $pid || '' === self::site( $pid ) ) {
			return new WP_Error( 'atc_bl_nosite', 'Add the website first.', array( 'status' => 400 ) );
		}
		$key = 'atc_blc_' . $pid;
		if ( get_transient( $key ) ) {
			return new WP_Error( 'atc_bl_wait', 'We just checked. Try again in a minute.', array( 'status' => 429 ) );
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		self::check( $pid );
		return self::profile( $party_id );
	}

	/** The logo: a PNG, JPG or WebP image up to 2 MB, into the media library. */
	public static function logo( int $party_id, array $file ) {
		$pid = self::post_id( $party_id ) ?: self::create( $party_id );
		if ( ! $pid ) {
			return new WP_Error( 'atc_dir_none', 'The directory is not available right now.', array( 'status' => 500 ) );
		}
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'atc_logo', 'The upload did not arrive. Try again.', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'atc_logo', 'Use an image up to 2 MB.', array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp' ) );
		if ( empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore
			return new WP_Error( 'atc_logo', 'Use a PNG, JPG or WebP image.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file['name'] = sanitize_title( get_the_title( $pid ) ) . '-logo.' . $check['ext'];
		$att          = media_handle_sideload( $file, $pid, get_the_title( $pid ) . ' logo' );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$old = (int) get_post_meta( $pid, '_atc_logo_id', true );
		if ( $old && $old !== $att ) {
			wp_delete_attachment( $old, true );
		}
		update_post_meta( $pid, '_atc_logo_id', $att );
		$m         = self::meta( $pid );
		$m['logo'] = (string) wp_get_attachment_url( $att );
		update_post_meta( $pid, '_atc', $m );
		ATC_Install::log( 'party', $party_id, 'directory_logo', array( 'post_id' => $pid ) );
		return self::profile( $party_id );
	}

	const CAR_PHOTOS_MAX = 3;

	/**
	 * A photo of one of the provider's cars: resized to 1600 px and saved as WebP, kept in the media library.
	 * The photos make the fleet gallery on the provider's directory profile.
	 */
	public static function car_photo( int $party_id, int $vehicle_id, array $file ) {
		global $wpdb;
		$t   = self::t( 'vehicles' );
		$car = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND party_id = %d AND status <> 'archived'", $vehicle_id, $party_id ), ARRAY_A );
		if ( ! $car ) {
			return new WP_Error( 'atc_car', 'That vehicle is not yours or no longer exists.', array( 'status' => 404 ) );
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) json_decode( (string) $car['photos'], true ) ) ) );
		if ( count( $ids ) >= self::CAR_PHOTOS_MAX ) {
			return new WP_Error( 'atc_car', 'Up to ' . self::CAR_PHOTOS_MAX . ' photos per vehicle. Remove one first.', array( 'status' => 400 ) );
		}
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'atc_car', 'The upload did not arrive. Try again.', array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > 12 * MB_IN_BYTES ) {
			return new WP_Error( 'atc_car', 'Use a photo up to 12 MB.', array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp' ) );
		if ( empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore
			return new WP_Error( 'atc_car', 'Use a JPG, PNG or WebP photo.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$p    = ATC_Catalog::party( $party_id );
		$name = sanitize_title( ( $p ? $p['name'] : 'provider' ) . ' ' . $car['make_model'] );
		// Resize and convert to WebP when the server can; otherwise keep the file as it came.
		$ed = wp_get_image_editor( $file['tmp_name'] );
		if ( ! is_wp_error( $ed ) ) {
			$ed->resize( 1600, 1600, false );
			$ed->set_quality( 82 );
			$out = $ed->save( $file['tmp_name'] . '-atc.webp', 'image/webp' );
			if ( is_wp_error( $out ) ) {
				$out = $ed->save( $file['tmp_name'] . '-atc.jpg', 'image/jpeg' );
			}
			if ( ! is_wp_error( $out ) && ! empty( $out['path'] ) ) {
				$file = array( 'name' => $name . '.' . pathinfo( $out['path'], PATHINFO_EXTENSION ), 'tmp_name' => $out['path'], 'size' => filesize( $out['path'] ), 'error' => 0, 'type' => $out['mime-type'] );
			}
		}
		if ( empty( $file['type'] ) ) {
			$file['name'] = $name . '.' . $check['ext'];
		}
		$att = media_handle_sideload( $file, (int) self::post_id( $party_id ), ( $p ? $p['name'] . ': ' : '' ) . $car['make_model'] );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		update_post_meta( $att, '_wp_attachment_image_alt', ( $p ? $p['name'] . ' ' : '' ) . $car['make_model'] . ' airport transfer vehicle' );
		$ids[] = (int) $att;
		$wpdb->update( $t, array( 'photos' => wp_json_encode( $ids ) ), array( 'id' => $vehicle_id ) );
		ATC_Install::log( 'vehicle', $vehicle_id, 'photo_added', array( 'attachment' => $att ) );
		self::sync_fleet( $party_id );
		return true;
	}

	public static function car_photo_delete( int $party_id, int $vehicle_id, int $attachment ) {
		global $wpdb;
		$t   = self::t( 'vehicles' );
		$car = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND party_id = %d", $vehicle_id, $party_id ), ARRAY_A );
		if ( ! $car ) {
			return new WP_Error( 'atc_car', 'That vehicle is not yours.', array( 'status' => 404 ) );
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) json_decode( (string) $car['photos'], true ) ) ) );
		if ( ! in_array( $attachment, $ids, true ) ) {
			return new WP_Error( 'atc_car', 'That photo is not on this vehicle.', array( 'status' => 404 ) );
		}
		$ids = array_values( array_diff( $ids, array( $attachment ) ) );
		$wpdb->update( $t, array( 'photos' => $ids ? wp_json_encode( $ids ) : null ), array( 'id' => $vehicle_id ) );
		wp_delete_attachment( $attachment, true );
		self::sync_fleet( $party_id );
		return true;
	}

	/** The fleet gallery on the directory profile: every photo of the provider's active cars. */
	public static function sync_fleet( int $party_id ): void {
		$pid = self::post_id( $party_id );
		if ( ! $pid ) {
			return;
		}
		$fleet = array();
		foreach ( ATC_Catalog::vehicles( $party_id ) as $car ) {
			foreach ( $car['photos'] as $ph ) {
				$fleet[] = array( 'url' => $ph['url'], 'thumb' => $ph['thumb'], 'model' => $car['make_model'] . ( $car['year'] ? ' ' . $car['year'] : '' ), 'category' => $car['vehicle_type'] );
			}
		}
		$m          = self::meta( $pid );
		$m['fleet'] = array_slice( $fleet, 0, 24 );
		update_post_meta( $pid, '_atc', $m );
	}

	/** Profiles by name, for linking one by hand in Control. */
	public static function search( string $q ): array {
		$q = trim( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$posts = get_posts( array( 'post_type' => self::PT, 'post_status' => array( 'publish', 'draft' ), 's' => $q, 'posts_per_page' => 12, 'orderby' => 'title', 'order' => 'ASC' ) );
		$out   = array();
		foreach ( $posts as $p ) {
			$owner = self::party_for_post( $p->ID );
			$m     = self::meta( $p->ID );
			$out[] = array(
				'id'       => $p->ID,
				'title'    => $p->post_title,
				'status'   => $p->post_status,
				'location' => (string) ( $m['location'] ?? '' ),
				'url'      => get_permalink( $p->ID ),
				'owner'    => $owner ? ( ATC_Catalog::party( $owner )['name'] ?? '' ) : '',
				'owner_id' => $owner,
			);
		}
		return $out;
	}

	/** Setup step: the profile has a description, a logo and what they offer. */
	public static function step_done( int $party_id ): bool {
		$pid = self::post_id( $party_id );
		if ( ! $pid ) {
			return false;
		}
		$m = self::meta( $pid );
		return ! empty( $m['summary'] ) && ! empty( $m['logo'] ) && ( ! empty( $m['services'] ) || ! empty( $m['destinations'] ) );
	}
}
