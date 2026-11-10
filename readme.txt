=== ATCOM Booking Engine ===
Version: 0.3.0 (provider portal, part 1)

New in 0.3.0: provider portal at /portal
- /portal/signup: transport companies create an account (company, contact, email, WhatsApp, airport,
  a few words about the company). /portal/login to sign in. Providers never see wp-admin.
- Setup checklist: company details, vehicle categories and vehicles, zones served (map), documents
  (private uploads), payout details (encrypted, only last 4 digits shown), provider agreement.
- Control panel: setup status, documents to approve or reject, payout summary, "Approve and set live".
- Emails: welcome, new sign-up and new document alerts to our team, document rejected, you are live.

New in 0.2.3:
- "Find all hotels inside": searches Google for every hotel inside a zone's shape, part by part, and lists them.
- "Check any hotel or address": shows which zone (and so which price) a traveler would get. Any address
  inside a zone is bookable; the hotel list is optional (hand-placed hotels near borders, suggestions).

New in 0.2.2:
- "Suggest a shape": type or pick a zone name and the plugin proposes ready-made outlines from
  OpenStreetMap (real boundaries, when the area has one) and Google (the place's map box).
  Flip through the options, drag points to adjust, then "Use this shape".

New in 0.2.1:
- The 23 Cancún zones come preloaded by name (as on resortstours.com), with the Cancun Hotel Zone and
  Playa Mujeres shapes copied over. Pick a zone, then draw or adjust its shape. "Add a zone" for new ones.

New in 0.2.0:
- Control panel at /control (our team only): Zones and hotels, Providers.
- Zones and hotels: Google map, draw and reshape zones, add hotels from Google search or a pasted list
  (up to 25 at a time); each hotel goes into the zone that contains it, or can be placed by hand.
- Providers: details, commission, payout method, vehicle categories and zones served at CUN, vehicles.
- Status page checks the Google Maps keys and can test the Server key.

Milestone 1 (0.1.0):
- Creates the engine's 21 database tables (prefix atc_): providers and affiliates, airports, zones, hotels,
  vehicle categories, vehicles, drivers, services, prices, extras, bookings, trip legs, ledger, payouts,
  coupons, tickets and the audit log. Money is stored in cents.
- Adds Cancun Airport (CUN) and the six vehicle categories.
- Adds the roles: Booking staff, Transport provider, Affiliate, Travel agent.
- Adds Booking Engine > Status and Booking Engine > Settings in wp-admin (commission 25%, affiliate 8%,
  referral 2%, payout day 10, auto-complete 24 h, service code 4 characters / 5 tries).
- REST API: /wp-json/atc/v1/ping (public) and /wp-json/atc/v1/status (staff only).

Nothing is shown to travelers yet. Deactivating keeps all data.

Stripe secret keys go in wp-config.php, never in the database:
define( 'ATC_STRIPE_TEST_SECRET', 'sk_test_...' );
define( 'ATC_STRIPE_TEST_WEBHOOK_SECRET', 'whsec_...' );

Google Maps keys also go in wp-config.php:
define( 'ATC_MAPS_BROWSER_KEY', '...' );
define( 'ATC_MAPS_SERVER_KEY', '...' );

Tests: php tests/money-test.php && php tests/geo-test.php
