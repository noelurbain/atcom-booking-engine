<?php
/**
 * Price and split math tests. Run: php tests/money-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-atc-money.php';
require __DIR__ . '/../includes/class-atc-codes.php';

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

// The requirements doc examples.
check( 'USD 100 at 25% pays provider 75', ATC_Money::provider_from_retail( 10000, 2500 ), 7500 );
check( 'provider types 75 → retail 100', ATC_Money::retail_from_provider( 7500, 2500 ), 10000 );
check( 'provider types 80 → retail rounded up to 107', ATC_Money::retail_from_provider( 8000, 2500 ), 10700 );
check( 'retail 107 pays provider at least 80', ATC_Money::provider_from_retail( 10700, 2500 ) >= 8000, true );
check( 'Minivan 44 → provider 33', ATC_Money::provider_from_retail( 4400, 2500 ), 3300 );
check( 'Luxury SUV 79 → provider 59.25', ATC_Money::provider_from_retail( 7900, 2500 ), 5925 );
check( 'Premium SUV 525 → provider 393.75', ATC_Money::provider_from_retail( 52500, 2500 ), 39375 );
check( 'odd cents round down for the provider', ATC_Money::provider_from_retail( 3333, 2500 ), 2499 );

$s = ATC_Money::split( 10000, 2500 );
check( 'direct sale: we keep 25', array( $s['provider'], $s['platform'] ), array( 7500, 2500 ) );

$s = ATC_Money::split( 10000, 2500, 800 );
check( 'affiliate 8: we keep 17', array( $s['provider'], $s['affiliate'], $s['platform'] ), array( 7500, 800, 1700 ) );

$s = ATC_Money::split( 10000, 2500, 800, 200 );
check( 'with referrer 2: we keep 15', array( $s['affiliate'], $s['referrer'], $s['platform'] ), array( 800, 200, 1500 ) );

$s = ATC_Money::split( 10000, 2500, 800, 0, 500, 'affiliate' );
check( 'affiliate 5% coupon leaves affiliate 3', array( $s['traveler_pays'], $s['affiliate'], $s['platform'] ), array( 9500, 300, 1700 ) );

$s = ATC_Money::split( 10000, 2500, 0, 0, 1000, 'platform' );
check( 'our coupon comes out of our share', array( $s['traveler_pays'], $s['provider'], $s['platform'] ), array( 9000, 7500, 1500 ) );

$s = ATC_Money::split( 3333, 2500, 800, 200 );
check( 'split always adds up', $s['provider'] + $s['affiliate'] + $s['referrer'] + $s['platform'], 3333 );

check( 'agency net rate pays 92', 10000 - intdiv( 10000 * 800, 10000 ), 9200 );
check( 'round typed retail', ATC_Money::round_retail( 4449 ), 4400 );
check( 'format', ATC_Money::format( 39375 ), 'USD 393.75' );

$threw = false;
try { ATC_Money::split( 10000, 2500, 800, 0, 900, 'affiliate' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'affiliate coupon above 8% refused', $threw, true );

// Service codes.
$code = ATC_Codes::service_code( 4 );
check( 'service code length 4', strlen( $code ), 4 );
check( 'service code uses clear letters only', (bool) preg_match( '/^[' . ATC_Codes::ALPHABET . ']{4}$/', $code ), true );
check( 'code match ignores case and spaces', ATC_Codes::code_matches( ' k7-m4 ', 'K7M4' ), true );
check( 'wrong code fails', ATC_Codes::code_matches( 'K7M5', 'K7M4' ), false );
check( 'empty stored code never matches', ATC_Codes::code_matches( '', '' ), false );
check( 'booking number shape', (bool) preg_match( '/^AT[' . ATC_Codes::ALPHABET . ']{6}$/', ATC_Codes::booking_number() ), true );

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit( $fails ? 1 : 0 );
