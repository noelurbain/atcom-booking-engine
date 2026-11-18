=== ATCOM Booking Engine ===
Version: 0.20.1 (price list upload first; zones found by name)

New in 0.20.1:
- New photos: Coach and Mini Coach (every vehicle category now has its picture).

New in 0.20.0:
- Portal → Upload price list (also offered on the Setup page, before Vehicles): one file sets up vehicles,
  zones and prices. Their vehicle names are matched to our categories (they confirm each), their place names
  to zones (ours or theirs); a place we don't have becomes a new zone of theirs, drawn from its name near
  their airport (OpenStreetMap outline or Google's box; a 2 km circle when only the point is known). No
  approval from our team. Places not found on the map are listed for them to draw.
- Portal → Zones you serve: "Find an area by name" shows ready-made outlines (previous/next), adjustable by
  dragging the points, then saved as their zone.

New in 0.19.0:
- Providers draw their own zones on the map (portal → Zones you serve → Draw a zone): click around the area,
  name it, save. No approval: it is served at once by all their vehicle categories and needs prices next.
  Rename, reshape (drag the points) or delete. They can still tick the zones we drew.
- Search: where an address is inside a provider's own zone, that provider is priced by it (its smallest zone
  if several); everyone else by our zones. Addresses outside our zones but inside a provider's zone now sell.
  The booking keeps the zone it was priced by.
- Prices is a setup step: done when every zone they serve has a one-way price for every vehicle category they
  sell. The portal lists what is missing; Control warns before approving a provider that is not finished.
- Control → provider: their own zones show in the zone list, marked "drawn by them".

New in 0.18.1:
- Provider websites show on their directory profile only while their site links back to us. Checked
  automatically: every profile once a day (15 an hour), and right away when the website or the link page changes.
  A link to any of our domains counts (Settings → Directory; subdomains too, nofollow too, flagged in Control).
- Switch it on or off in Settings → Directory (on by default; off = every website shows). Per provider, Control
  can force "always show" or "never show".
- If a link that was there goes missing, the website comes off after two checks in a row; a site that does not
  answer changes nothing. Profiles not checked yet keep what they show today.
- Portal and Control: the link to copy, an optional "page with the link to us", the last check, and Check now.

New in 0.18.0:
- Each provider is linked to its company profile in the directory (/directory/company/...). On the first page
  load after the update, existing profiles are matched by name, website or phone (164 of 204 providers match);
  the rest get a draft profile. Profiles that were public stay public; nothing is unpublished.
- Provider portal → Directory profile: logo upload, company name, short description, about, services,
  destinations (one tap to fill from their vehicle categories and zones), phone, WhatsApp, address, website.
  New step "Directory profile" in the setup list.
- New sign-ups get a draft profile (or are linked to their existing one); approving them publishes it.
- Control → provider: Directory profile card (view, edit as them, link another profile, unlink, make one).
  Their website follows the automatic link-back check (0.18.1).

New in 0.17.0:
- Extras catalog (Control → Extras): infant, child and booster seats, a supermarket stop, beers, water and sodas,
  sparkling wine, snacks, flowers, a celebration kit, a custom name sign; rename, turn off or add more.
  Charged per booking, per ride (twice on a round trip) or each, with a maximum per booking.
- Providers switch on what they offer and set the price (portal → Extras; our team: provider → Edit their extras),
  as Suggested retail price / Amount you will get paid with the usual commission.
- Step 2 shows the provider's extras with steppers or a checkbox; the total on the side follows. Prices come from
  the provider's offer on the server. Discount codes apply on top.
- Extras show on the payment review, the confirmation page, all booking emails (with the provider's amount in
  theirs), the portal booking ("Extras to bring"), Control's booking and the money report. The provider's share
  is paid with the last ride (ledger line "Extras").


New in 0.16.0:
- Every results page is logged (airport, destination, offers found; visitor as a monthly-rotating hash, no personal
  data; our team and robots left out). Control → Searches: searches, unique visitors, searches with nothing to
  sell, airports searched with no provider, per day, funnel (searches → checkout → details → paid), by airport
  with ready providers ("None yet"), top destinations, airports we don't serve, top airports, providers and
  affiliates by bookings. 1 / 7 / 30 / 90 / 365 days.
- Traveler count: a base (Settings or Control → Searches) or the real 30-day searchers once higher; for the theme
  atc_traveler_count() / atc_traveler_count('7d'|'24h') and the shortcode [atc_travelers].
- Control → Money report (finance only): day, week or month, by trip or booking date. Per booking: price,
  discount and code, extra payments, charged, Stripe fee, affiliate commission, refunded, provider gets, we keep;
  cancelled/refunded/no-show apart; totals and per day; download for Excel (CSV).
- Analytics (Settings → Analytics): PostHog (no cookies, page views + server events atcom_transfer_searched,
  atcom_booking_placed, atcom_payment_captured), GA4 page views and purchase, Google Ads conversion with value,
  once per booking on the confirmation. Signed-in staff are not tracked.


New in 0.15.2:
- Discount code field on step 2 (Flight and details), applied when the traveler continues: the payment page
  opens with the discounted total. A wrong code is explained there.
- On the payment page a code can still be added or removed without reloading: the Stripe amount and the totals
  update and the card details typed so far stay.


New in 0.15.1:
- Fix: the airport search did not send the airport's position, so the drop-off field said "Choose your airport
  first" even with an airport chosen. Hotel suggestions now come from around the chosen airport again.


New in 0.15.0:
- Discount codes on the payment step ("Have a discount code?"); the total, the Stripe amount and the pay button
  follow. Kept when the hold is renewed (dropped if it no longer applies). Each use counts when paid.
- Control → Discount codes: ours (1–20% or a fixed amount, out of our share; minimum booking, dates, number of
  uses, on/off) and affiliates' codes.
- Affiliate panel → Discount codes: up to 5 codes of up to their own rate; the discount comes out of their
  commission (5% code on an 8% rate leaves 3%), and a booking with their code is theirs even without a click.
- Bookings show the code and who paid the discount.


New in 0.14.1:
- wp-login.php and signed-out visits to wp-admin go to /login. The pages that stay there (forgot password,
  check your email, new password) carry our logo, colors and font; no WordPress logo or language picker.
  Escape hatch: wp-login.php?native=1.
- WordPress traces removed: generator tag, version numbers on files, RSD/manifest/shortlink/REST/oEmbed links,
  emoji script, X-Pingback, XML-RPC, author pages and ?author= lookups, the public user list in the API.
  WordPress's own emails come from "Airport Transportation".


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
