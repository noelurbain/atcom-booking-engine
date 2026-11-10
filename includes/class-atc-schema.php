<?php
/**
 * Database tables. Money is stored in cents (bigint), rates in basis points, times in UTC.
 * Tables for later milestones (affiliates, agencies, sites, tickets) exist from day one so
 * switching them on is configuration, not a rebuild.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Schema {

	/** Table names without prefix. */
	public static function tables(): array {
		return array(
			'sites', 'parties', 'party_users', 'airports', 'zones', 'places', 'vehicle_types',
			'vehicles', 'drivers', 'services', 'service_zones', 'prices', 'extras', 'bookings',
			'booking_legs', 'booking_extras', 'ledger', 'payouts', 'coupons', 'tickets', 'events', 'documents',
		);
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'atc_' . $name;
	}

	public static function sql(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'atc_';

		$sql = array();

		$sql[] = "CREATE TABLE {$p}sites (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(190) NOT NULL,
  name varchar(190) NOT NULL,
  settings longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain)
) $c;";

		// Providers, affiliates, agencies and white-label partners.
		$sql[] = "CREATE TABLE {$p}parties (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  name varchar(190) NOT NULL,
  legal_name varchar(190) NULL,
  country char(2) NULL,
  email varchar(190) NULL,
  phone varchar(40) NULL,
  languages varchar(100) NULL,
  parent_id bigint(20) unsigned NULL,
  ref_code varchar(40) NULL,
  commission_bp int(11) NULL,
  affiliate_bp int(11) NULL,
  min_notice_minutes int(11) NULL,
  payout_method varchar(20) NOT NULL DEFAULT 'manual',
  payout_currency char(3) NOT NULL DEFAULT 'USD',
  payout_details longtext NULL,
  agreement_accepted_at datetime NULL,
  agreement_version varchar(20) NULL,
  agreement_ip varchar(45) NULL,
  contact_name varchar(190) NULL,
  website varchar(190) NULL,
  about text NULL,
  home_airport_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_status (type,status),
  KEY parent_id (parent_id),
  UNIQUE KEY ref_code (ref_code)
) $c;";

		$sql[] = "CREATE TABLE {$p}party_users (
  party_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL DEFAULT 'owner',
  PRIMARY KEY  (party_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}airports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  iata char(3) NOT NULL,
  name varchar(190) NOT NULL,
  city varchar(100) NOT NULL,
  country char(2) NOT NULL,
  timezone varchar(64) NOT NULL,
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY iata (iata)
) $c;";

		$sql[] = "CREATE TABLE {$p}zones (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  airport_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  polygon longtext NULL,
  sort int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY airport_slug (airport_id,slug)
) $c;";

		// Hotels and addresses, matched to a zone.
		$sql[] = "CREATE TABLE {$p}places (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  airport_id bigint(20) unsigned NOT NULL,
  zone_id bigint(20) unsigned NULL,
  type varchar(20) NOT NULL DEFAULT 'hotel',
  name varchar(190) NOT NULL,
  address varchar(255) NULL,
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  google_place_id varchar(190) NULL,
  matched_by varchar(20) NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY airport_zone (airport_id,zone_id),
  KEY google_place_id (google_place_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}vehicle_types (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(60) NOT NULL,
  name varchar(100) NOT NULL,
  max_passengers int(11) NOT NULL,
  max_bags int(11) NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;";

		$sql[] = "CREATE TABLE {$p}vehicles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  vehicle_type_id bigint(20) unsigned NOT NULL,
  make_model varchar(100) NOT NULL,
  year smallint(6) NULL,
  plate varchar(20) NULL,
  seats int(11) NULL,
  insurance_until date NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY party_id (party_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}drivers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  phone varchar(40) NULL,
  languages varchar(100) NULL,
  license_until date NULL,
  photo_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY party_id (party_id)
) $c;";

		// One provider's vehicle category at one airport.
		$sql[] = "CREATE TABLE {$p}services (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  airport_id bigint(20) unsigned NOT NULL,
  vehicle_type_id bigint(20) unsigned NOT NULL,
  max_passengers int(11) NOT NULL,
  max_bags int(11) NOT NULL,
  inclusions longtext NULL,
  free_wait_minutes int(11) NOT NULL DEFAULT 60,
  min_notice_minutes int(11) NOT NULL DEFAULT 1440,
  status varchar(20) NOT NULL DEFAULT 'draft',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY party_airport_type (party_id,airport_id,vehicle_type_id),
  KEY airport_status (airport_id,status)
) $c;";

		$sql[] = "CREATE TABLE {$p}service_zones (
  service_id bigint(20) unsigned NOT NULL,
  zone_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (service_id,zone_id),
  KEY zone_id (zone_id)
) $c;";

		// Suggested retail price and Amount you will get paid, per service x zone x direction.
		$sql[] = "CREATE TABLE {$p}prices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL,
  zone_id bigint(20) unsigned NOT NULL,
  direction varchar(20) NOT NULL,
  retail_cents bigint(20) NOT NULL,
  provider_cents bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  valid_from date NULL,
  valid_to date NULL,
  updated_by bigint(20) unsigned NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY service_zone_dir (service_id,zone_id,direction)
) $c;";

		// Extras carry the same two prices; party_id NULL means we fulfil it.
		$sql[] = "CREATE TABLE {$p}extras (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NULL,
  airport_id bigint(20) unsigned NULL,
  name longtext NOT NULL,
  description longtext NULL,
  unit varchar(20) NOT NULL DEFAULT 'per_booking',
  retail_cents bigint(20) NOT NULL,
  provider_cents bigint(20) NOT NULL,
  max_qty int(11) NOT NULL DEFAULT 1,
  notice_minutes int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY party_airport (party_id,airport_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}bookings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(20) NOT NULL,
  site_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'held',
  channel varchar(20) NOT NULL DEFAULT 'web',
  trip_type varchar(20) NOT NULL DEFAULT 'one_way',
  affiliate_id bigint(20) unsigned NULL,
  referrer_id bigint(20) unsigned NULL,
  agency_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  adults int(11) NOT NULL DEFAULT 1,
  children int(11) NOT NULL DEFAULT 0,
  lang varchar(10) NOT NULL DEFAULT 'en',
  currency char(3) NOT NULL DEFAULT 'USD',
  retail_cents bigint(20) NOT NULL DEFAULT 0,
  discount_cents bigint(20) NOT NULL DEFAULT 0,
  total_cents bigint(20) NOT NULL DEFAULT 0,
  coupon_code varchar(40) NULL,
  snapshot longtext NULL,
  service_code varchar(10) NOT NULL,
  service_code_used_at datetime NULL,
  service_code_used_by bigint(20) unsigned NULL,
  service_code_attempts int(11) NOT NULL DEFAULT 0,
  stripe_payment_intent varchar(100) NULL,
  hold_expires_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY number (number),
  KEY status (status),
  KEY email (email),
  KEY affiliate_id (affiliate_id),
  KEY stripe_payment_intent (stripe_payment_intent)
) $c;";

		// Arrival and departure legs; each leg belongs to one provider.
		$sql[] = "CREATE TABLE {$p}booking_legs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  direction varchar(20) NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  party_id bigint(20) unsigned NOT NULL,
  zone_id bigint(20) unsigned NULL,
  pickup_at datetime NULL,
  flight_airline varchar(100) NULL,
  flight_number varchar(20) NULL,
  flight_time datetime NULL,
  from_place varchar(255) NOT NULL DEFAULT '',
  to_place varchar(255) NOT NULL DEFAULT '',
  suitcases int(11) NOT NULL DEFAULT 0,
  carry_ons int(11) NOT NULL DEFAULT 0,
  notes text NULL,
  driver_id bigint(20) unsigned NULL,
  vehicle_id bigint(20) unsigned NULL,
  retail_cents bigint(20) NOT NULL DEFAULT 0,
  provider_cents bigint(20) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'held',
  acknowledged_at datetime NULL,
  completed_at datetime NULL,
  completed_how varchar(20) NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY party_pickup (party_id,pickup_at),
  KEY status_pickup (status,pickup_at)
) $c;";

		$sql[] = "CREATE TABLE {$p}booking_extras (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  leg_id bigint(20) unsigned NULL,
  extra_id bigint(20) unsigned NOT NULL,
  qty int(11) NOT NULL DEFAULT 1,
  retail_cents bigint(20) NOT NULL,
  provider_cents bigint(20) NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id)
) $c;";

		// Who gets what from each booking. Signed cents; reversals are new lines.
		$sql[] = "CREATE TABLE {$p}ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  leg_id bigint(20) unsigned NULL,
  party_id bigint(20) unsigned NULL,
  role varchar(20) NOT NULL,
  amount_cents bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  status varchar(20) NOT NULL DEFAULT 'pending',
  service_month char(7) NULL,
  payout_id bigint(20) unsigned NULL,
  note varchar(255) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY party_status (party_id,status),
  KEY payout_id (payout_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}payouts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  period char(7) NOT NULL,
  amount_cents bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  method varchar(20) NOT NULL DEFAULT 'manual',
  reference varchar(190) NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  paid_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY party_period (party_id,period)
) $c;";

		$sql[] = "CREATE TABLE {$p}coupons (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(40) NOT NULL,
  owner varchar(20) NOT NULL DEFAULT 'platform',
  party_id bigint(20) unsigned NULL,
  percent_bp int(11) NULL,
  amount_cents bigint(20) NULL,
  valid_from date NULL,
  valid_to date NULL,
  max_uses int(11) NULL,
  uses int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) $c;";

		// Provider documents (permit, registration, insurance). Files live outside public URLs.
		$sql[] = "CREATE TABLE {$p}documents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  file varchar(255) NOT NULL,
  original_name varchar(190) NOT NULL,
  mime varchar(100) NOT NULL,
  size int(11) NOT NULL DEFAULT 0,
  expires_on date NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  note varchar(255) NULL,
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY party_type (party_id,type),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$p}tickets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NULL,
  party_id bigint(20) unsigned NULL,
  type varchar(30) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  subject varchar(255) NOT NULL,
  crisp_session varchar(100) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY status (status)
) $c;";

		// Audit log: every change to a booking, price, payout or party, and who made it.
		$sql[] = "CREATE TABLE {$p}events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(30) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  action varchar(50) NOT NULL,
  user_id bigint(20) unsigned NULL,
  acting_as bigint(20) unsigned NULL,
  data longtext NULL,
  ip varchar(45) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY created_at (created_at)
) $c;";

		return $sql;
	}

	/** Which tables are missing right now. */
	public static function missing(): array {
		global $wpdb;
		$missing = array();
		foreach ( self::tables() as $t ) {
			$name = self::table( $t );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
				$missing[] = $t;
			}
		}
		return $missing;
	}
}
