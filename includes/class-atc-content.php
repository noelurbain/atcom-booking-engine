<?php
/**
 * Content: our pages seen from what the engine can sell.
 * One row per airport: its booking page, its providers (and whether they can be booked), the destinations
 * (zones) with live prices, the destination pages we have, the price table on the page and the hotels.
 * The detail of one airport goes destination by destination.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Content {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	/** Pages of a post type, published or draft, with their _atc meta. */
	private static function pages( string $type ): array {
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$out   = array();
		foreach ( $posts as $p ) {
			$m     = get_post_meta( $p->ID, '_atc', true );
			$out[] = array( 'post' => $p, 'm' => is_array( $m ) ? $m : array() );
		}
		return $out;
	}

	/** Rows of the price table in a page's content (the table imported from the old site), header excluded. */
	private static function table_rows( string $html ): int {
		$n = substr_count( strtolower( $html ), '<tr' );
		return $n > 0 ? $n - 1 : 0;
	}

	private static function norm( string $s ): string {
		$s = remove_accents( strtolower( $s ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/** Per airport id: providers, sellable providers, zones, zones with a live price, hotels. */
	private static function engine(): array {
		global $wpdb;
		$s = self::t( 'services' );
		$p = self::t( 'parties' );
		$x = self::t( 'prices' );
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT id, iata, name, city, country FROM ' . self::t( 'airports' ), ARRAY_A ) as $a ) { // phpcs:ignore
			$out[ (int) $a['id'] ] = $a + array( 'providers' => 0, 'sellable' => 0, 'zones' => 0, 'priced' => 0, 'hotels' => 0, 'from' => null );
		}
		$set = static function ( string $sql, string $key ) use ( $wpdb, &$out ) {
			foreach ( $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore
				if ( isset( $out[ (int) $r['a'] ] ) ) {
					$out[ (int) $r['a'] ][ $key ] = (int) $r['n'];
				}
			}
		};
		$set( "SELECT airport_id a, COUNT(DISTINCT party_id) n FROM {$s} GROUP BY airport_id", 'providers' );
		$set( "SELECT s.airport_id a, COUNT(DISTINCT s.party_id) n FROM {$s} s JOIN {$p} p ON p.id = s.party_id AND p.status = 'live' JOIN {$x} x ON x.service_id = s.id AND x.valid_from IS NULL WHERE s.status = 'live' GROUP BY s.airport_id", 'sellable' );
		$set( 'SELECT airport_id a, COUNT(*) n FROM ' . self::t( 'zones' ) . " WHERE status = 'active' GROUP BY airport_id", 'zones' );
		$set( "SELECT s.airport_id a, COUNT(DISTINCT x.zone_id) n FROM {$s} s JOIN {$p} p ON p.id = s.party_id AND p.status = 'live' JOIN {$x} x ON x.service_id = s.id AND x.valid_from IS NULL WHERE s.status = 'live' GROUP BY s.airport_id", 'priced' );
		$set( 'SELECT airport_id a, COUNT(*) n FROM ' . self::t( 'places' ) . " WHERE status = 'active' GROUP BY airport_id", 'hotels' );
		$set( "SELECT s.airport_id a, MIN(x.retail_cents) n FROM {$s} s JOIN {$p} p ON p.id = s.party_id AND p.status = 'live' JOIN {$x} x ON x.service_id = s.id AND x.valid_from IS NULL AND x.direction = 'one_way' WHERE s.status = 'live' GROUP BY s.airport_id", 'from' );
		return $out;
	}

	/** Destination pages (atc_route) grouped by their booking page path. */
	private static function routes_by_parent(): array {
		$by = array();
		foreach ( self::pages( 'atc_route' ) as $r ) {
			$parent = trim( (string) ( $r['m']['parent'] ?? '' ), '/' );
			$by[ $parent ][] = $r;
		}
		return $by;
	}

	public static function overview(): array {
		$eng    = self::engine();
		$by_iata = array();
		foreach ( $eng as $id => $a ) {
			if ( ! empty( $a['iata'] ) ) {
				$by_iata[ strtoupper( $a['iata'] ) ] = $id;
			}
		}
		$routes = self::routes_by_parent();
		$rows   = array();
		$seen   = array();
		foreach ( self::pages( 'atc_booking' ) as $b ) {
			$post = $b['post'];
			$iata = strtoupper( (string) ( $b['m']['iata'] ?? '' ) );
			$aid  = $iata && isset( $by_iata[ $iata ] ) ? $by_iata[ $iata ] : 0;
			$e    = $aid ? $eng[ $aid ] : array();
			if ( $aid ) {
				$seen[ $aid ] = true;
			}
			$rows[] = self::row( $e, $aid, $iata, (string) ( $b['m']['country'] ?? ( $e['country'] ?? '' ) ), array(
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'status'  => $post->post_status,
				'table'   => self::table_rows( $post->post_content ),
				'from'    => isset( $b['m']['from'] ) ? (float) $b['m']['from'] : null,
				'routes'  => count( $routes[ $post->post_name ] ?? array() ),
			) );
		}
		// Airports where providers sell, or zones are drawn, but we have no booking page.
		foreach ( $eng as $aid => $e ) {
			if ( isset( $seen[ $aid ] ) || ( ! $e['providers'] && ! $e['zones'] ) ) {
				continue;
			}
			$rows[] = self::row( $e, $aid, strtoupper( (string) $e['iata'] ), (string) $e['country'], null );
		}
		return $rows;
	}

	private static function row( array $e, int $aid, string $iata, string $country, ?array $page ): array {
		$prov = (int) ( $e['providers'] ?? 0 );
		$sell = (int) ( $e['sellable'] ?? 0 );
		return array(
			'airport_id' => $aid,
			'iata'       => $iata,
			'airport'    => (string) ( $e['name'] ?? '' ),
			'city'       => (string) ( $e['city'] ?? '' ),
			'country'    => $country,
			'page'       => $page,
			'providers'  => $prov,
			'sellable'   => $sell,
			'zones'      => (int) ( $e['zones'] ?? 0 ),
			'priced'     => (int) ( $e['priced'] ?? 0 ),
			'hotels'     => (int) ( $e['hotels'] ?? 0 ),
			'from_cents' => isset( $e['from'] ) ? (int) $e['from'] : null,
			'state'      => ! $page ? 'no_page' : ( $sell ? 'sellable' : ( $prov ? 'not_sellable' : 'no_provider' ) ),
		);
	}

	/** One airport, destination by destination. */
	public static function airport( int $airport_id ) {
		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT id, iata, name, city, country FROM ' . self::t( 'airports' ) . ' WHERE id = %d', $airport_id ), ARRAY_A );
		if ( ! $a ) {
			return new WP_Error( 'atc_not_found', 'Airport not found.', array( 'status' => 404 ) );
		}
		$s = self::t( 'services' );
		$p = self::t( 'parties' );
		$x = self::t( 'prices' );

		// The booking page and its destination pages.
		$page  = null;
		$pages = array();
		foreach ( self::pages( 'atc_booking' ) as $b ) {
			if ( $a['iata'] && strtoupper( (string) ( $b['m']['iata'] ?? '' ) ) === strtoupper( $a['iata'] ) ) {
				$page = array( 'id' => $b['post']->ID, 'title' => get_the_title( $b['post'] ), 'url' => get_permalink( $b['post'] ), 'status' => $b['post']->post_status, 'table' => self::table_rows( $b['post']->post_content ), 'slug' => $b['post']->post_name );
				break;
			}
		}
		if ( $page ) {
			foreach ( self::routes_by_parent()[ $page['slug'] ] ?? array() as $r ) {
				$title   = get_the_title( $r['post'] );
				$pages[] = array( 'id' => $r['post']->ID, 'title' => $title, 'dest' => self::norm( preg_replace( '/^.*\bto\s+/i', '', $title ) ), 'url' => get_permalink( $r['post'] ), 'status' => $r['post']->post_status );
			}
		}

		// Providers at this airport.
		$provs = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.name, p.status, SUM(s.status = 'live') live_services, COUNT(s.id) services FROM {$s} s JOIN {$p} p ON p.id = s.party_id WHERE s.airport_id = %d GROUP BY p.id ORDER BY p.name", $airport_id ), ARRAY_A ) as $r ) { // phpcs:ignore
			$provs[ (int) $r['id'] ] = array( 'id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'services' => (int) $r['services'], 'live_services' => (int) $r['live_services'], 'zones' => 0 );
		}

		// Zones (destinations) with who prices them.
		$zones = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT z.id, z.name, z.party_id, (SELECT COUNT(*) FROM ' . self::t( 'places' ) . " pl WHERE pl.zone_id = z.id AND pl.status = 'active') hotels FROM " . self::t( 'zones' ) . " z WHERE z.airport_id = %d AND z.status = 'active' ORDER BY z.sort, z.name", $airport_id ), ARRAY_A ) as $z ) { // phpcs:ignore
			$zones[ (int) $z['id'] ] = array( 'id' => (int) $z['id'], 'name' => $z['name'], 'owner' => $z['party_id'] ? ( $provs[ (int) $z['party_id'] ]['name'] ?? 'a provider' ) : '', 'hotels' => (int) $z['hotels'], 'priced_by' => array(), 'from_cents' => null, 'page' => null );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.zone_id, s.party_id, MIN(IF(x.direction = 'one_way', x.retail_cents, NULL)) cents, MAX(s.status = 'live' AND p.status = 'live') sellable FROM {$x} x JOIN {$s} s ON s.id = x.service_id JOIN {$p} p ON p.id = s.party_id WHERE s.airport_id = %d AND x.valid_from IS NULL GROUP BY x.zone_id, s.party_id", $airport_id ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$zid = (int) $r['zone_id'];
			if ( ! isset( $zones[ $zid ] ) ) {
				continue;
			}
			$pid                        = (int) $r['party_id'];
			$zones[ $zid ]['priced_by'][] = array( 'id' => $pid, 'name' => $provs[ $pid ]['name'] ?? '#' . $pid, 'sellable' => (bool) $r['sellable'], 'from_cents' => null === $r['cents'] ? null : (int) $r['cents'] );
			if ( $r['sellable'] && null !== $r['cents'] && ( null === $zones[ $zid ]['from_cents'] || (int) $r['cents'] < $zones[ $zid ]['from_cents'] ) ) {
				$zones[ $zid ]['from_cents'] = (int) $r['cents'];
			}
			if ( isset( $provs[ $pid ] ) ) {
				++$provs[ $pid ]['zones'];
			}
		}
		// Match destination pages to zones by name ("Cancún Airport to Tulum" ↔ zone "Tulum"): exact names first, then one name inside the other.
		$used = array();
		foreach ( array( true, false ) as $exact ) {
			foreach ( $zones as &$z ) {
				if ( $z['page'] ) {
					continue;
				}
				$zn = self::norm( $z['name'] );
				foreach ( $pages as $i => $pg ) {
					if ( isset( $used[ $i ] ) || '' === $zn || '' === $pg['dest'] ) {
						continue;
					}
					if ( $exact ? $pg['dest'] === $zn : ( false !== strpos( $zn, $pg['dest'] ) || false !== strpos( $pg['dest'], $zn ) ) ) {
						$z['page']  = array( 'title' => $pg['title'], 'url' => $pg['url'], 'status' => $pg['status'] );
						$used[ $i ] = true;
						break;
					}
				}
			}
			unset( $z );
		}
		$orphans = array();
		foreach ( $pages as $i => $pg ) {
			if ( ! isset( $used[ $i ] ) ) {
				$orphans[] = array( 'title' => $pg['title'], 'url' => $pg['url'], 'status' => $pg['status'] );
			}
		}
		return array(
			'airport'   => $a,
			'page'      => $page,
			'providers' => array_values( $provs ),
			'zones'     => array_values( $zones ),
			'orphans'   => $orphans,
		);
	}

	/**
	 * One-off: write the Cancún destination pages from data/cancun-routes.json. New pages are made as drafts for
	 * review. Pages that already exist are only rewritten when $existing is true (their image is kept).
	 */
	public static function seed_routes( bool $existing = false ): array {
		$file = ATC_DIR . 'data/cancun-routes.json';
		$rows = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore
		$out  = array();
		// The maps key is not stored in the plugin: take it from an existing route page's map.
		global $wpdb;
		$key = '';
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'atc_route' WHERE m.meta_key = '_atc' LIMIT 20" ) as $v ) { // phpcs:ignore
			$v = maybe_unserialize( $v );
			if ( is_array( $v ) && ! empty( $v['map'] ) && preg_match( '/[?&]key=([^&]+)/', $v['map'], $mm ) ) {
				$key = $mm[1];
				break;
			}
		}
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['meta']['map'] ) ) {
				$r['meta']['map'] = $key ? str_replace( '{key}', $key, $r['meta']['map'] ) : '';
			}
			$post = get_page_by_path( $r['slug'], OBJECT, 'atc_route' );
			if ( $post && ! $existing ) {
				$out[] = array( 'slug' => $r['slug'], 'id' => $post->ID, 'done' => 'skipped (exists)' );
				continue;
			}
			$meta = (array) $r['meta'];
			if ( $post ) {
				$old = get_post_meta( $post->ID, '_atc', true );
				$old = is_array( $old ) ? $old : array();
				foreach ( array( 'image', 'og_image' ) as $k ) {
					if ( ! empty( $old[ $k ] ) ) {
						$meta[ $k ] = $old[ $k ];
					}
				}
				wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $r['content'] ) ) );
				$id = $post->ID;
			} else {
				$id = wp_insert_post( array( 'post_type' => 'atc_route', 'post_status' => 'draft', 'post_name' => $r['slug'], 'post_title' => wp_slash( $r['title'] ), 'post_content' => wp_slash( $r['content'] ) ), true );
				if ( is_wp_error( $id ) ) {
					$out[] = array( 'slug' => $r['slug'], 'done' => $id->get_error_message() );
					continue;
				}
			}
			update_post_meta( $id, '_atc', wp_slash( $meta ) );
			$out[] = array( 'slug' => $r['slug'], 'id' => $id, 'done' => $post ? 'updated' : 'created (draft)' );
		}
		return $out;
	}
}
