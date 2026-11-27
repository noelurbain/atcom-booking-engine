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
			'booking_legs', 'booking_extras', 'ledger', 'payouts', 'coupons', 'tickets', 'events', 'documents', 'aliases', 'legacy', 'reviews', 'demand', 'meeting', 'clicks', 'ticket_messages', 'searches', 'extra_types', 'blocks',
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
  fleet text NULL,
  home_airport_id bigint(20) unsigned NULL,
  extra_airports varchar(255) NULL,
  invited_at datetime NULL,
  invite_count int(11) NOT NULL DEFAULT 0,
  rating_avg decimal(3,2) NULL,
  rating_count int(11) NOT NULL DEFAULT 0,
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
  iata char(3) NULL,
  name varchar(190) NOT NULL,
  city varchar(100) NOT NULL,
  country char(2) NOT NULL,
  timezone varchar(64) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  google_place_id varchar(190) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY iata (iata),
  KEY google_place_id (google_place_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}zones (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  airport_id bigint(20) unsigned NOT NULL,
  party_id bigint(20) unsigned NULL,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  polygon longtext NULL,
  source varchar(20) NULL,
  sort int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY airport_slug (airport_id,slug),
  KEY airport_party (airport_id,party_id)
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
  category varchar(20) NOT NULL DEFAULT 'standard',
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
  photos text NULL,
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
		// Extras catalog (ours) and each provider's offer of them, with its price.
		$sql[] = "CREATE TABLE {$p}extra_types (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(60) NOT NULL,
  name varchar(190) NOT NULL,
  grp varchar(40) NOT NULL DEFAULT 'comfort',
  description varchar(255) NULL,
  unit varchar(20) NOT NULL DEFAULT 'per_booking',
  max_qty int(11) NOT NULL DEFAULT 1,
  suggested_cents bigint(20) NOT NULL DEFAULT 0,
  sort int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;";
		$sql[] = "CREATE TABLE {$p}extras (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NULL,
  airport_id bigint(20) unsigned NULL,
  extra_type_id bigint(20) unsigned NULL,
  name longtext NOT NULL,
  description longtext NULL,
  unit varchar(20) NOT NULL DEFAULT 'per_booking',
  retail_cents bigint(20) NOT NULL,
  provider_cents bigint(20) NOT NULL,
  max_qty int(11) NOT NULL DEFAULT 1,
  notice_minutes int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  KEY party_airport (party_id,airport_id),
  KEY party_type (party_id,extra_type_id)
) $c;";
		$sql[] = "CREATE TABLE {$p}booking_extras (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  leg_id bigint(20) unsigned NULL,
  extra_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  unit varchar(20) NOT NULL DEFAULT 'per_booking',
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
  min_cents bigint(20) NULL,
  valid_from date NULL,
  valid_to date NULL,
  max_uses int(11) NULL,
  uses int(11) NOT NULL DEFAULT 0,
  note varchar(190) NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY party_id (party_id)
) $c;";

		// A provider's own names for our zones and vehicle types ("Zona 1", "Van 10"), so uploads match by themselves.
		$sql[] = "CREATE TABLE {$p}aliases (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  alias varchar(190) NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY party_kind_alias (party_id,kind,alias),
  KEY target (kind,target_id)
) $c;";

		// Ids from the old airporttransportation.com, so the migration can run again without duplicates.
		$sql[] = "CREATE TABLE {$p}legacy (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(20) NOT NULL,
  object_type varchar(30) NOT NULL,
  legacy_id varchar(64) NOT NULL,
  new_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_object_legacy (source,object_type,legacy_id),
  KEY new_id (object_type,new_id)
) $c;";

		// What travelers searched for and we could not sell: airports we don't have, addresses outside our zones.
		$sql[] = "CREATE TABLE {$p}demand (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL,
  airport_id bigint(20) unsigned NOT NULL DEFAULT 0,
  term varchar(190) NOT NULL,
  lat decimal(10,7) NULL,
  lng decimal(10,7) NULL,
  hits int(11) NOT NULL DEFAULT 1,
  first_at datetime NOT NULL,
  last_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  PRIMARY KEY  (id),
  UNIQUE KEY kind_airport_term (kind,airport_id,term),
  KEY last_at (last_at)
) $c;";

		// Every search on the results page (no personal data: the visitor is a monthly-rotating hash).
		$sql[] = "CREATE TABLE {$p}searches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  site varchar(60) NOT NULL DEFAULT '',
  airport_id bigint(20) unsigned NOT NULL DEFAULT 0,
  airport_text varchar(120) NOT NULL DEFAULT '',
  dest varchar(190) NOT NULL DEFAULT '',
  zone_id bigint(20) unsigned NULL,
  results int(11) NOT NULL DEFAULT 0,
  reason varchar(20) NULL,
  trip varchar(20) NOT NULL DEFAULT 'one_way',
  pax int(11) NOT NULL DEFAULT 0,
  lead_days int(11) NULL,
  visitor char(16) NOT NULL DEFAULT '',
  affiliate_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY airport_created (airport_id,created_at)
) $c;";

		// Affiliate link clicks, counted per affiliate, day and landing page (no personal data).
		$sql[] = "CREATE TABLE {$p}clicks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  landing varchar(190) NOT NULL DEFAULT '',
  clicks int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY party_day_landing (party_id,day,landing)
) $c;";

		// How travelers meet their driver at an airport: ours (party_id 0) or a provider's own, per terminal ('' = all).
		$sql[] = "CREATE TABLE {$p}meeting (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  airport_id bigint(20) unsigned NOT NULL,
  party_id bigint(20) unsigned NOT NULL DEFAULT 0,
  terminal varchar(60) NOT NULL DEFAULT '',
  sort int(11) NOT NULL DEFAULT 0,
  steps longtext NOT NULL,
  updated_by bigint(20) unsigned NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY airport_party (airport_id,party_id)
) $c;";

		// Traveler ratings of a provider after a completed trip (1-5). Stars show from 3 published reviews.
		$sql[] = "CREATE TABLE {$p}reviews (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  party_id bigint(20) unsigned NOT NULL,
  rating tinyint(4) NOT NULL,
  comment text NULL,
  status varchar(20) NOT NULL DEFAULT 'published',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY booking_party (booking_id,party_id),
  KEY party_status (party_id,status)
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
  opened_by varchar(20) NOT NULL DEFAULT 'team',
  user_id bigint(20) unsigned NULL,
  assigned_to bigint(20) unsigned NULL,
  urgent tinyint(1) NOT NULL DEFAULT 0,
  money_cents bigint(20) NOT NULL DEFAULT 0,
  holds longtext NULL,
  outcome varchar(30) NULL,
  stripe_dispute varchar(100) NULL,
  crisp_session varchar(100) NULL,
  due_at datetime NULL,
  last_reply_at datetime NULL,
  last_reply_by varchar(20) NULL,
  resolved_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY party_id (party_id),
  KEY status (status),
  KEY stripe_dispute (stripe_dispute)
) $c;";

		// Messages on a ticket. 'audience': who can read it besides our team (traveler, provider, or internal).
		$sql[] = "CREATE TABLE {$p}ticket_messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ticket_id bigint(20) unsigned NOT NULL,
  author varchar(20) NOT NULL,
  user_id bigint(20) unsigned NULL,
  author_name varchar(190) NOT NULL DEFAULT '',
  audience varchar(20) NOT NULL DEFAULT 'internal',
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ticket_id (ticket_id)
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

		// A provider's unavailable periods: no new bookings with pickup inside them. vehicle_type_id NULL = every category.
		$sql[] = "CREATE TABLE {$p}blocks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  party_id bigint(20) unsigned NOT NULL,
  vehicle_type_id bigint(20) unsigned NULL,
  starts_at datetime NOT NULL,
  ends_at datetime NOT NULL,
  note varchar(190) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY party_time (party_id,ends_at)
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
