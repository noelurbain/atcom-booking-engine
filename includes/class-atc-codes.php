<?php
/**
 * Booking numbers and service codes.
 * No 0/O, 1/I/L, 5/S, 2/Z, 8/B so codes read clearly over a car window.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Codes {

	const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

	/** Booking number shown to travelers, e.g. AT7K2M9Q. */
	public static function booking_number(): string {
		return 'AT' . self::random( 6 );
	}

	/** Service code the traveler gives the driver at pickup, e.g. K7M4. */
	public static function service_code( int $length = 4 ): string {
		return self::random( max( 4, min( 6, $length ) ) );
	}

	/** Compare a typed code with the stored one, ignoring case, spaces and dashes. */
	public static function code_matches( string $typed, string $stored ): bool {
		$clean = static fn( string $s ): string => strtoupper( preg_replace( '/[\s\-]/', '', $s ) );
		return '' !== $stored && hash_equals( $clean( $stored ), $clean( $typed ) );
	}

	private static function random( int $length ): string {
		$max = strlen( self::ALPHABET ) - 1;
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $out;
	}
}
