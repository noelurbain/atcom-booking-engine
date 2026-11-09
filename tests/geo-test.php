<?php
/**
 * Zone matching tests. Run: php tests/geo-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-atc-geo.php';

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

// Rough Hotel Zone strip and downtown Cancún boxes, [lat, lng].
$hotel_zone = array( array( 21.1650, -86.8150 ), array( 21.1650, -86.7650 ), array( 21.0300, -86.7650 ), array( 21.0300, -86.8000 ) );
$downtown   = array( array( 21.2000, -86.9000 ), array( 21.2000, -86.8200 ), array( 21.1200, -86.8200 ), array( 21.1200, -86.9000 ) );

$riu     = array( 21.1349, -86.7470 + 0.0 ); // Blvd. Kukulcán km 9-ish is around -86.75; inside our rough box? -86.747 > -86.765 → outside east edge.
$inside  = array( 21.1000, -86.7800 );
$dt      = array( 21.1600, -86.8500 );
$airport = array( 21.0365, -86.8771 );

check( 'point inside Hotel Zone', ATC_Geo::point_in_polygon( $inside[0], $inside[1], $hotel_zone ), true );
check( 'downtown point not in Hotel Zone', ATC_Geo::point_in_polygon( $dt[0], $dt[1], $hotel_zone ), false );
check( 'east of the box is outside', ATC_Geo::point_in_polygon( $riu[0], $riu[1], $hotel_zone ), false );
check( 'point on an edge counts as inside', ATC_Geo::point_in_polygon( 21.1650, -86.7900, $hotel_zone ), true );
check( 'corner counts as inside', ATC_Geo::point_in_polygon( 21.1650, -86.7650, $hotel_zone ), true );
check( 'fewer than 3 points never matches', ATC_Geo::point_in_polygon( 21.1, -86.78, array( array( 21, -86 ), array( 22, -87 ) ) ), false );

// Concave (L-shaped) zone.
$l_shape = array( array( 0, 0 ), array( 0, 4 ), array( 2, 4 ), array( 2, 2 ), array( 4, 2 ), array( 4, 0 ) );
check( 'L-shape: inside the foot', ATC_Geo::point_in_polygon( 3, 1, $l_shape ), true );
check( 'L-shape: in the notch is outside', ATC_Geo::point_in_polygon( 3, 3, $l_shape ), false );

$zones = array(
	array( 'id' => 7, 'sort' => 20, 'polygon' => json_encode( $downtown ) ),
	array( 'id' => 3, 'sort' => 10, 'polygon' => json_encode( $hotel_zone ) ),
);
check( 'match Hotel Zone', ATC_Geo::match_zone( $inside[0], $inside[1], $zones ), 3 );
check( 'match downtown', ATC_Geo::match_zone( $dt[0], $dt[1], $zones ), 7 );
check( 'airport is in no zone', ATC_Geo::match_zone( $airport[0], $airport[1], $zones ), null );

// Overlap: the zone with the lower sort wins.
$big = array( 'id' => 9, 'sort' => 5, 'polygon' => json_encode( array( array( 22, -88 ), array( 22, -86 ), array( 20, -86 ), array( 20, -88 ) ) ) );
check( 'overlap: lower sort wins', ATC_Geo::match_zone( $inside[0], $inside[1], array_merge( $zones, array( $big ) ) ), 9 );

check( 'clean polygon keeps valid points', ATC_Geo::clean_polygon( '[[21.1,-86.8],[21.2,-86.8],[21.2,-86.7]]' ), array( array( 21.1, -86.8 ), array( 21.2, -86.8 ), array( 21.2, -86.7 ) ) );
check( 'clean polygon refuses 2 points', ATC_Geo::clean_polygon( array( array( 1, 1 ), array( 2, 2 ) ) ), null );
check( 'clean polygon refuses bad latitude', ATC_Geo::clean_polygon( array( array( 91, 1 ), array( 2, 2 ), array( 3, 3 ) ) ), null );
check( 'clean polygon refuses text', ATC_Geo::clean_polygon( array( array( 'a', 1 ), array( 2, 2 ), array( 3, 3 ) ) ), null );

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit( $fails ? 1 : 0 );
