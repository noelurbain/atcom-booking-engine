=== ATCOM Booking Engine ===
Version: 0.7.4 (checkout, part 1: search and results)

New in 0.7.4: Luxury SUV is the top SUV class (Escalade, Navigator) and Premium SUV the one below (Suburban,
Tahoe, Yukon); Premium now lists before Luxury. Providers who priced Premium above Luxury at an airport have
their two SUV categories traded, prices included (Cancun Airport Transportation, Dual Amara and Liberty Destiny
at SIN, Los Cabos Airport Transportation, Nextlevel at CPH). Price-list matching follows the same classes.

New in 0.7.3:
- Cancún Hotel Zone gets a full outline (Playa Linda bridge to Punta Nizuc, including Punta Cancún), replacing
  the rough first one, unless it was redrawn by hand.
- The airport box no longer keeps "Pick your airport from the list" after you type again.
- The trip map draws the road route with Google's Routes API (new Google projects), falling back to the older
  Directions API, then to a straight line.

New in 0.7.2: Dashboard → "What travelers asked for and we don't sell yet": airports typed in the search box
that we don't have, addresses outside every zone (with a map link), and zones nobody has priced, each with
how many searches and when last. Dismiss hides one for good.

New in 0.7.1: the airport box finds airports by any mix of words ("cancun airport", "aeropuerto de cancún",
"CUN"): words like airport, international or aeropuerto are ignored, accents don't matter. A typed airport
that was not picked from the list is still found on the results page.

New in 0.7.0:
- Search box (atc_engine_search_box(), used by the theme's booking bar): One way / Round trip, From (only
  airports we serve), To (Google places near the airport; typing an address without picking also works),
  landing date and time, Passengers with Adults and Children, return date for round trips.
- /search, step 1 "Choose your vehicle": the search summary with Edit search, one card per provider and
  vehicle (two providers with a Minivan = two cards; capacity and minimum notice respected), the provider's
  name under each card with its stars (from 3 traveler reviews), sorted by lowest price, "Best rated" when any
  result has stars, vehicle type filters, the trip map with the road route, time and distance, "Your transfer"
  with Continue.
- Reviews table and provider rating (filled by traveler reviews after completed trips, coming with the emails).
- When nothing can be booked it says why: address outside our zones (with the nearest places we serve and
  their from-prices), no prices for that area yet, pickup too soon (earliest time), or a group too big.
- The theme's older forms (sticky bar, mobile sheet) also land on /search.
- /checkout shows the chosen vehicle; details and payment come in the next update.

New in 0.6.0:
- Bookings in the control panel: the list (All, Coming up, No provider, Paid, Not paid, Cancelled; find by
  reference) and the booking page as in the mockup: each trip leg, traveler, what happened with internal notes,
  the money both ways, payment and where it came from. Old-site bookings say they are run from the old site.
- The price screen follows the approved mockup: One way / Round trip switch, each cell shows the Suggested
  retail price and what the provider gets, click a cell to edit it in the side panel (type either amount),
  Remove, "Only zones with missing prices", Download Excel (re-uploadable), Upload Excel or CSV.

New in 0.5.1: the control panel gets the layout from the approved mockups: menu on the left (Dashboard,
Bookings, Providers, Prices, Zones and hotels, Payouts, Tickets, Crisp inbox, Settings, Status, WordPress;
the ones not built yet are marked "soon"), and a search box in the top bar (providers for now).

New in 0.5.0: price editor and price-list upload
- Provider portal → Prices, and Control panel → provider record → Prices: a grid per airport, zones as rows
  and vehicle categories as columns, one way and round trip. Type what the provider gets paid or what
  travelers pay; the other fills in (25% commission, retail rounded up to whole dollars). "Add a zone",
  "Add a vehicle category". Unusual amounts (under USD 10 or over USD 5,000) are flagged.
- Upload a price list (Excel, CSV, or pasted from Google Sheets), in the provider's own words: one column per
  vehicle, or columns for place, vehicle, one way, round trip. Their vehicles are matched to our categories
  and their places to our zones (their earlier names, our zone names, then Google: inside a zone shape takes
  that zone). They check the matches before saving. Their names are remembered for the next upload.
- A place outside every zone becomes a proposed zone: its prices are saved and wait ("Zone pending"). Our
  team gets an email and approves or rejects it from the Dashboard; approved zones then need a shape.

New in 0.4.1:
- /control opens on a Dashboard: paid bookings, sales and our share (last 30 days and all time), paid trips
  nobody is driving yet, trips coming up (airport local time), latest bookings, top airports, providers and
  portal status, new sign-ups and documents to review, zones without a shape, prices left out of the import.
- The import also brings the old site's bookings (29), without traveler names, emails or phones, and without
  street addresses. Their money is what happened on the old site (30% commission); no payouts are created.
  Run the import again with the new file: what is already here is skipped.

New in 0.4.0: import from the old airporttransportation.com
- Control panel → Providers → "Import from the old site": upload atcom-migration.json, "Check first"
  (nothing saved, shows what would happen), then "Import now". Safe to run again: nothing is doubled.
- Brings providers (details, about, status), the airports they serve, their coverage areas (as zones:
  fills empty shapes, never replaces a shape drawn here, adds new zones) and their prices.
  Travelers pay what they paid before; providers get 75% (new 25% commission).
  Prices that look like another currency or a typo (under USD 10 or over USD 5,000) are left out and listed.
- Their area names are kept as aliases, so their price lists match our zones later.
- 5 new vehicle categories: Standard SUV, Mini Coach, Midi Coach, Coach, Large Coach.
- Providers list: portal status (Not invited, Invited, Active), "Send sign-up invitation" / "Resend
  invitation", and "Invite everyone not invited yet" (40 per click). The email links to a page where they
  choose a password. While Settings → "Send test invitations to" has an address, every invitation goes
  there instead of to the providers.
- Provider record and provider portal show the prices (read-only; the price editor comes next).

New in 0.3.4: provider sign-up asks for the provider's main airport with a search box (city, airport name or
code) instead of a list with only Cancún. Our airports come first; any other airport can be picked from Google
and is added when the account is created, with a notice on the form and in our new sign-up email. Airports
added this way have no IATA code and no zones until our team sets them up. Needs ATC_MAPS_SERVER_KEY.

New in 0.3.3: one sign-in page at /login for providers, affiliates and our team (each goes to the right place);
"WordPress" link in /control and "Control panel" in the WordPress toolbar and Booking Engine menu.

New in 0.3.0: provider portal at /portal
- Sign-up runs from the theme form on /partners/provider/ (via admin-post, with its spam checks); /portal/signup redirects there. Companies create an account (company, contact, email, WhatsApp, airport,
  a few words about the company). /portal/login to sign in. Providers never see wp-admin.
- Setup checklist: company details, vehicle categories and vehicles, zones served (map), documents
  (private uploads), payout details (encrypted, only last 4 digits shown), provider agreement.
- Account page: change the sign-in email (confirmed by a link sent to the new address) and the password.
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
