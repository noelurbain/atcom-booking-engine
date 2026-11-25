<?php
/**
 * Old airporttransportation.com addresses that no longer exist on the new site, sent to the right page.
 * Runs only when WordPress is about to answer "page not found", so it never overrides a real page.
 *
 * English pages: 301 (permanent). Spanish pages (/es/...): 302 (temporary), because the Spanish site comes
 * back after launch and should be able to use those addresses again. Remove the /es part then.
 * Built from Ahrefs (pages with traffic and pages with backlinks) and the old sitemap, 2026-11-25.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Redirects {

	/** Old English address → new one (paths without slashes at the ends). */
	const MAP = array(
		'partners/affiliate-marketing-program'        => 'partners/affiliate',
		'partners/affiliate-program'                  => 'partners/affiliate',
		'partners/transportation-provider-partners'   => 'partners/provider',
		'contact-us'                                  => 'contact',
		'reservation'                                 => 'manage-booking',
		'blog/archive'                                => 'blog',
		'los-cabos-airport-transportation'            => 'cabo-airport-transportation',
		'palma-de-mallorca-airport-transportation'    => 'palma-airport-transportation',
		'london-heathrow-airport-transportation'      => 'heathrow-airport-transportation',
		'cannes-mandelieu-airport-transportation'     => 'nice-airport-transportation',
		'changi-airport-transportation'               => 'singapore-airport-transportation',
		'singapore-changi-airport-transportation'     => 'singapore-airport-transportation',
		'viru-viru-airport-transportation'            => 'santa-cruz-de-la-sierra-airport-transportation',
		'alexandroupolis-airport-transportation'      => 'alexandroupoli-airport-transportation',
		'belgrade-nikola-tesla-airport-transportation' => 'belgrade-airport-transportation',
		'toronto-pearson-international-airport-transportation' => 'toronto-airport-transportation',
		'quebec-city-jean-lesage-airport-transportation' => 'quebec-city-airport-transportation',
		'dubai-international-airport-transportation'  => 'dubai-airport-transportation',
		'denver-international-airport-transportation' => 'denver-airport-transportation',
		'detroit-metro-airport-transportation'        => 'detroit-airport-transportation',
		'jfk-international-airport-transportation'    => 'jfk-airport-transportation',
		'lax-los-angeles-international-airport-to-los-angeles' => 'lax-airport-transportation',
		'403.shtml'                                   => '',
		'allinq.htm'                                  => '',
	);

	/** Old Spanish words in addresses → English ones. */
	const ES = array(
		'directorio' => 'directory', 'empresa' => 'company', 'aeropuertos' => 'airports', 'aerolineas' => 'airlines',
		'destinos' => 'destinations', 'galeria' => 'gallery', 'socios' => 'partners', 'proveedor' => 'provider',
		'afiliado' => 'affiliate', 'en-linea' => 'online', 'terminos-y-condiciones' => 'terms-conditions',
		'contacto' => 'contact', 'sobre-nosotros' => 'about', 'preguntas-frecuentes' => 'faqs',
		'politicas-de-reservacion' => 'reservation-policies', 'aviso-de-privacidad' => 'privacy-policy',
	);

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	public static function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$path = strtolower( trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ) ); // phpcs:ignore
		$path = rawurldecode( $path );
		if ( '' === $path ) {
			return;
		}
		$spanish = 'es' === $path || 0 === strpos( $path, 'es/' );
		$to      = $spanish ? self::spanish( $path ) : self::english( $path );
		if ( null === $to || trim( $to, '/' ) === $path ) {
			return;
		}
		$url = home_url( '' === $to ? '/' : '/' . $to . '/' );
		$url = str_replace( '/#', '#', $url );
		wp_safe_redirect( $url, $spanish ? 302 : 301, 'ATCOM redirects' );
		exit;
	}

	/** Where an old English address goes now; null when we have nothing better than "not found". */
	public static function english( string $path ): ?string {
		if ( array_key_exists( $path, self::MAP ) ) {
			return self::MAP[ $path ];
		}
		if ( 0 === strpos( $path, 'cgi-sys/' ) ) {
			return '';
		}
		$seg = explode( '/', $path );

		// Very old pages: /airport-shuttle-atlanta.htm
		if ( preg_match( '#^airport-shuttle-([a-z0-9-]+)\.html?$#', $path, $m ) ) {
			return self::booking( $m[1] ) ?? 'destinations';
		}
		// Old route pages: /dubrovnik-airport-to-cilipi → that airport's page.
		if ( 1 === count( $seg ) && preg_match( '#^(.+?)-airport-to-.+$#', $path, $m ) ) {
			return self::booking( $m[1] ) ?? 'destinations';
		}
		// A booking page under another name, or with an old hotel page under it.
		if ( preg_match( '#^([a-z0-9-]+)-airport-transportation$#', $seg[0], $m ) ) {
			$b = self::exists_booking( $seg[0] ) ? $seg[0] : self::booking( $m[1] );
			if ( ! $b ) {
				return 'destinations';
			}
			if ( isset( $seg[1] ) && 'services' === $seg[1] ) {
				return $b . '/services';
			}
			return $b;
		}
		switch ( $seg[0] ) {
			case 'services':
				return 'services';
			case 'airports':
				return 'airports';
			case 'airlines':
				return 'airlines';
			case 'blog':
				return 'blog';
			case 'directory':
				return 'directory';
			case 'partners':
				return 'partners';
		}
		// /japan → Airports in Japan; /paraguay/asuncion → the Asunción page.
		if ( get_term_by( 'slug', $seg[0], 'atc_country' ) ) {
			if ( isset( $seg[1] ) ) {
				return self::booking( $seg[1] ) ?? 'directory/' . $seg[0] . '/' . $seg[1];
			}
			return 'airports-in-' . $seg[0];
		}
		return null;
	}

	/** Old Spanish address → the English page for now. */
	public static function spanish( string $path ): string {
		$rest = trim( substr( $path, 2 ), '/' );
		if ( '' === $rest ) {
			return '';
		}
		$seg = explode( '/', $rest );
		if ( preg_match( '#^transporte-aeropuerto-([a-z0-9-]+)$#', $seg[0], $m ) ) {
			return self::booking( $m[1] ) ?? 'destinations';
		}
		if ( preg_match( '#^aeropuertos-en-([a-z0-9-]+)$#', $seg[0], $m ) ) {
			return 'airports-in-' . $m[1];
		}
		$seg = array_map( static fn( $s ) => self::ES[ $s ] ?? $s, $seg );
		$en  = implode( '/', $seg );
		if ( self::exists( $en ) ) {
			return $en;
		}
		return self::english( $en ) ?? ( self::exists( $seg[0] ) ? $seg[0] : '' );
	}

	/** The booking page for a place name, trying shorter names: "atlanta-international" → "atlanta". */
	private static function booking( string $name ): ?string {
		$parts = explode( '-', $name );
		while ( $parts ) {
			$slug = implode( '-', $parts ) . '-airport-transportation';
			if ( self::exists_booking( $slug ) ) {
				return $slug;
			}
			array_pop( $parts );
		}
		return null;
	}

	private static function exists_booking( string $slug ): bool {
		return (bool) get_page_by_path( $slug, OBJECT, 'atc_booking' );
	}

	/** Is there a page at this path on the new site? */
	private static function exists( string $path ): bool {
		$seg = explode( '/', $path );
		if ( 'blog' === $seg[0] && isset( $seg[1] ) ) {
			return (bool) get_page_by_path( $seg[1], OBJECT, 'post' );
		}
		if ( 'airports' === $seg[0] && isset( $seg[1] ) ) {
			return (bool) get_page_by_path( $seg[1], OBJECT, 'atc_airport' );
		}
		if ( 'airlines' === $seg[0] && isset( $seg[1] ) ) {
			return (bool) get_page_by_path( $seg[1], OBJECT, 'atc_airline' );
		}
		if ( 'directory' === $seg[0] && isset( $seg[1] ) ) {
			return 'company' === $seg[1] ? isset( $seg[2] ) && (bool) get_page_by_path( $seg[2], OBJECT, 'atc_provider' ) : (bool) get_term_by( 'slug', end( $seg ), 'atc_place' );
		}
		if ( in_array( $seg[0], array( 'airports', 'airlines' ), true ) ) {
			return true;
		}
		return (bool) get_page_by_path( $path, OBJECT, 'page' ) || ( 1 === count( $seg ) && self::exists_booking( $seg[0] ) );
	}
}
