<?php
/**
 * Price-list reading and vehicle matching. Run: php tests/prices-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
function remove_accents( $s ) { return iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $s ) ) ), '-' ); }
final class ATC_Catalog {
	public static function vehicle_types(): array {
		$out = array();
		foreach ( array( 'executive-sedan', 'standard-sedan', 'standard-suv', 'luxury-suv', 'premium-suv', 'minivan', 'minibus', 'mini-coach', 'midi-coach', 'coach', 'large-coach' ) as $i => $s ) {
			$out[] = array( 'id' => $i + 1, 'slug' => $s, 'name' => ucwords( str_replace( '-', ' ', $s ) ) );
		}
		return $out;
	}
}
final class FakeDB { public function get_var( $q ) { return null; } public function prepare( $q, ...$a ) { return $q; } }
$GLOBALS['wpdb'] = new FakeDB();
final class ATC_Schema { public static function table( $n ) { return 'wp_atc_' . $n; } }
require __DIR__ . '/../includes/class-atc-prices.php';

$fails = 0;
function check( string $name, $got, $want ): void {
	global $fails;
	if ( $got === $want ) {
		echo "ok   $name\n";
	} else {
		$fails++;
		echo "FAIL $name: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n";
	}
}
$slug = static function ( string $raw ) {
	[ $id ] = ATC_Prices::match_vehicle( 7, $raw );
	return $id ? ATC_Catalog::vehicle_types()[ $id - 1 ]['slug'] : null;
};
check( 'Sedan', $slug( 'Sedan' ), 'standard-sedan' );
check( 'Mercedes E class', $slug( 'Mercedes E-Class' ), 'executive-sedan' );
check( 'Suburban', $slug( 'Chevrolet Suburban' ), 'standard-suv' );
check( 'Escalade', $slug( 'Cadillac Escalade' ), 'luxury-suv' );
check( 'Premium SUV', $slug( 'SUV Premium' ), 'premium-suv' );
check( 'Toyota Hiace', $slug( 'Toyota Hiace (10 pax)' ), 'minibus' );
check( 'Van', $slug( 'Private Van' ), 'minivan' );
check( 'Sprinter', $slug( 'Mercedes Sprinter' ), 'minibus' );
check( 'Coach', $slug( 'Coach 45' ), 'coach' );
check( 'Toyota Coaster', $slug( 'Toyota Coaster' ), 'mini-coach' );
check( 'Exact name', $slug( 'Luxury SUV' ), 'luxury-suv' );
check( 'Unknown', $slug( 'Helicopter' ), null );

// Long layout.
[ $l, $e ] = ATC_Prices::parse_rows(
	array(
		array( 'Our price list 2026', '', '' ),
		array( 'Zone', 'Vehicle', 'One way', 'Round trip' ),
		array( 'Zona Hotelera', 'Van', '$44', '$85' ),
		array( 'Playa del Carmen', 'Suburban', '1,200.50', '' ),
	)
);
check( 'long: no error', $e, null );
check( 'long: 3 prices', count( $l ), 3 );
check( 'long: first', $l[0], array( 'Zona Hotelera', 'Van', 'one_way', 4400 ) );
check( 'long: thousands', $l[2], array( 'Playa del Carmen', 'Suburban', 'one_way', 120050 ) );

// Wide layout with round-trip columns and decimal commas.
[ $l, $e ] = ATC_Prices::parse_rows(
	array(
		array( 'Destination', 'Sedan', 'Sedan RT', 'Minivan', 'Minivan round trip' ),
		array( 'Tulum', '120', '230', '140,50', '' ),
	)
);
check( 'wide: no error', $e, null );
check( 'wide: 3 prices', count( $l ), 3 );
check( 'wide: rt', $l[1], array( 'Tulum', 'Sedan', 'round_trip', 23000 ) );
check( 'wide: comma decimal', $l[2], array( 'Tulum', 'Minivan', 'one_way', 14050 ) );

// Only "one way / round trip" columns, no vehicle named.
[ $l ] = ATC_Prices::parse_rows( array( array( 'Hotel', 'One way', 'Round trip' ), array( 'Riu Palace', '50', '95' ) ) );
check( 'unnamed vehicle', $l[0], array( 'Riu Palace', '', 'one_way', 5000 ) );

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit( $fails ? 1 : 0 );
