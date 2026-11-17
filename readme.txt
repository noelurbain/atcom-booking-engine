=== ATCOM Booking Engine ===
Version: 0.14.0 (tickets and dispute holds)

New in 0.14.0:
- Tickets (Control → Tickets, with a counter of tickets waiting on us). Opened by our team (also from a booking),
  by the traveler (Report a problem on the reservation page, small link), by the provider (portal: Tickets, and
  "Report it to our team" on a booking), and automatically on a Stripe dispute and on ratings of 2 stars or less.
- Conversation per ticket: internal notes, replies to the traveler (email, answered on the reservation page) and
  to the provider (email + portal). Status, assignee, urgent; first reply due in 48 h, 8 h when urgent (trip in
  the next 48 h, disputes); overdue ones are flagged.
- Money holds: a ticket can hold the booking's provider and affiliate money (kept out of payouts, shown as held).
  Resolve: provider not at fault (paid), at fault (not paid, affiliate commission reversed), partly (set amount),
  or no money change; optional refund to the traveler's card from the same screen.
- Stripe disputes: charge.dispute.created opens an urgent ticket with the money on hold and the evidence due date;
  charge.dispute.closed adds the result (a lost dispute is recorded as money returned).
  Add both events to the Stripe webhook.


New in 0.13.0:
- Affiliates. ?ref=CODE on any page remembers the affiliate for 30 days (last click wins) and counts the click.
  Paid bookings carry the affiliate (8% by default, own rate per affiliate) and the affiliate who invited them
  (2%); commission lines go pending → earned when the trip completes → paid with the monthly payouts, follow
  refunds and extra payments, and are cancelled with the booking. No commission on an affiliate's own trips.
- Affiliate panel at /portal: overview (clicks, bookings, sales, commission), link maker, book for a client,
  bookings, invitation link for other affiliates, payout details (PayPal, Wise or bank) and payouts, account.
- Control → Affiliates: list with clicks, bookings and commission; approve (sends the panel invitation),
  decline, pause, own commission rate. The theme's affiliate form now also saves a pending affiliate.
- Book for a client (affiliates and our team): book on the site while signed in; the pay page can email the
  payment link to the client. Control → Bookings has "New booking for a client".
- Payouts include affiliates (minimum USD 100). Bookings show the affiliate, the referrer and who booked.
- Stripe's fee is saved on each payment, for the money report.


New in 0.12.0:
- Extra payments. Changing a ride to a service that sells for more shows the difference, and the team either
  asks the traveler to pay it (email with a card link, paid on Stripe's page) or absorbs it; our share after
  the change is shown, in red when negative. Control → booking → Extra payments: ask for any amount (extra
  stop, waiting time), see open/paid requests, cancel an open one. The reservation page shows an "Amount to
  pay" bar. Paid amounts add to the total and our share (Stripe webhook or the return page). Refunds and
  traveler cancellations also return extra payments.
- Import, second file (Control → import, or JSON body {data, dry_run}): traveler names, emails, phones,
  flights, notes and luggage of old reservations; old provider applications as pending providers (no portal
  account, invite from Providers); old affiliate applications as affiliates with their AFF code; old paid
  reservations whose rides are past are marked completed (no money lines, never in payouts), and the hourly
  job keeps doing that for the rest.
- Old-site reservations get no reminder, review or abandoned-booking emails here and can't be cancelled
  online here.


New in 0.11.0:
- Control → booking: Resend confirmation (traveler only); Refund or cancel (any amount up to what is left,
  through Stripe; ticking cancel also cancels rides and provider pay and emails traveler and provider);
  Unlock service code after too many wrong tries; per ride, Change provider (providers at that airport, their
  price for the zone suggested, the old provider and optionally the traveler are emailed; a handed-back ride
  shows "needs a new one"); per no-show, Accept (provider paid) or Reject. Ride events show in "What happened".
- Control → Payouts: the run for a month (default last month, paid on the payout day): per provider rides,
  held no-shows, to pay, method; under the minimum rolls over; no payout details flagged. Approve run ties
  the lines to a payout; Download payment list (admins only, full bank/Wise details); Mark paid with the bank
  reference emails the provider a statement with every ride.
- Control → Airports: search and edit code, name, city, country, time zone, position, shown or hidden.
- Refunds are recorded on the booking and in the money lines, including the traveler's own cancellations.

Also in 0.10.2: Control → Meeting your driver loads and saves again (it called a wrong address); every Select
button on the results page is the same orange.

Also in 0.10.1: hotel and address suggestions reach 600 km from the airport.

Also in 0.10.0:
- Portal → Bookings: every ride the provider operates, filtered by To confirm, Next 24 h, Upcoming, Past and
  Cancelled, with a CSV download and a counter in the menu. Each booking: confirm it, choose driver and
  vehicle (add drivers on the spot), type the traveler's service code to complete the ride (5 tries, then
  locked), mark completed without code after pickup, report a no-show (with a note, our team is emailed), or
  hand the ride back more than 2 hours before pickup (our team is emailed to reassign it). Traveler's phone,
  WhatsApp and note.
- Money: a completed ride moves the provider's amount to "earned" (next payout); a no-show waits for our team.
- Every hour: providers who have not confirmed a booking after 2 hours get a reminder and our team a copy;
  rides with no code and no no-show complete on their own 24 hours after pickup.
- The traveler shows the service code to the driver at pickup (voucher and emails say so).
- Hotel suggestions: by distance from the airport (600 km since 0.10.1), any country; no airport chosen yet = a hint
  instead of worldwide results.

Also in 0.9.3: hotel to airport. A swap button between From and To turns the search around (one way;
Round trip switches back to airport first). Labels become Pickup date / Pickup time. Step 2 asks for the
pickup (hotel booking name), the flight (optional) and the hotel pickup time, suggested from the flight.
The booking has one 'departure' ride; confirmation, emails, reminders and Manage booking follow it.

Also in 0.9.2:
- Hotel and address suggestions only come from around the chosen airport (about 220 km, same country).
- Stripe's payment form uses our font (Manrope) and borders; the traveler's mobile is prefilled.
- Confirmation: "Copy link" button, and "Change it here" jumps to Manage your booking on the same page.

Also in 0.9.1: usual market capacities (passengers / suitcases): Standard Sedan 3/3, Executive Sedan 3/2,
Standard SUV 4/4, Premium SUV 6/6, Luxury SUV 6/5, Minivan 6/6, Minibus 14/14, Mini Coach 25, Midi Coach 35,
Coach 49, Large Coach 57; limousines 8 to 18. Services still on the old placeholder numbers get them too;
any capacity a provider or our team changed is kept.

Also in 0.9.0:
- Emails: traveler confirmation (trip, service code, meeting steps, provider phone and WhatsApp, free
  cancellation date, link to the booking); new booking for the provider (with what they get paid) and a copy
  for our team; changes and cancellations to both sides. Provider emails go to "invite_test_to" while it is set.
- Every hour: "your transfer isn't booked yet" (once, 1 to 24 h after an unpaid start), "your pickup is
  tomorrow" (per ride, with meeting steps), and "how was your ride?" a day after the last ride.
- Stripe webhook at /wp-json/atc/v1/stripe/webhook (payment_intent.succeeded confirms a booking even if the
  traveler closed the page; charge.refunded in Stripe cancels it). Needs ATC_STRIPE_TEST_WEBHOOK_SECRET.
- Manage booking: /manage-booking/ sends the booking link to the email on it (number + email). On the booking
  page: change flights, times, return pickup, luggage, notes; cancel with a full Stripe refund until 24 h
  before pickup; rate the provider after the ride (stars show from 3 reviews).
- The booking's private key is kept sealed (encrypted with the site's salt) so emails can link to it.

Also in 0.8.1 (checkout, part 2: details, payment, confirmation)

New in 0.8.1:
- Mobile number: every country (242), searchable by name or code, with flags; the most used first.
- Reservation policies and privacy policy open in a pop-up on the payment page (with "Open in a new tab").
- Confirmation page with more color: green Confirmed band, pickup tiles, the service code in orange,
  free cancellation in green.
- Meeting your driver: our steps per airport (Control → Meeting your driver) and each provider's own steps per
  airport (Portal → Meeting travelers), both optionally split by terminal. Travelers see the provider's steps,
  else ours, else general ones.

Also in 0.8.0:
- Select on the results page goes straight to step 2 (Flight and details).
- Step 2: contact (name, email, mobile with country code, WhatsApp), arrival flight (or "I don't have my flight
  yet"), drop-off with the name of the hotel reservation, return (hotel pickup, departure flight, suggested pickup
  3 h before an international flight or 2 h before a domestic one, editable), suitcases, carry-ons, notes.
  The booking is saved (status held) as soon as the contact details are in, so a traveler who leaves can get a
  reminder later; the page address then carries the booking number and its private key, so a reload resumes it.
- Step 3 (/checkout/pay): Stripe Payment Element (card, Apple Pay, Google Pay, Link as Stripe offers them),
  review with Edit links, policies checkbox, and a 15-minute price hold; when it runs out the price is checked
  again. The price is always worked out again on the server.
- /reservation/{number}/?k=...: Stripe's answer is checked on the server, the booking is confirmed once,
  its trips wait for the provider, and the money lines are written (provider pending, our share). The page shows
  the voucher with the service code, trip details, meeting steps, the provider, Add to calendar and Print.
- Uses the Stripe secret key from wp-config.php and the publishable key from Settings (test mode).

Also in 0.7.5: the real logo (from the theme) in the control panel and partner portal top bars.

Also in 0.7.4: our vehicle photos (assets/vehicles/<slug>.webp, 1600x1000) on the results page and on the
provider's Vehicles page; 6 limousine categories (Lincoln, Chrysler 300, Escalade, Suburban and Hummer H2 stretch
limousines, Sprinter limousine) shown to providers under "Other categories". Mini Coach has no photo yet; Coach uses the
Large Coach photo for now.

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
