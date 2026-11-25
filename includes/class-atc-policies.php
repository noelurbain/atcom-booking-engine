<?php
/**
 * Reservation Policies: the terms a traveler accepts at checkout. Key points first, the full text below.
 * Numbers come from the live settings, so the page always matches what the booking engine does.
 * Kept in line with the Provider Agreement (waiting time, delays, no-shows, service failures).
 * Shortcode: [atc_reservation_policies] (page /reservation-policies/, also shown in the checkout pop-up).
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Policies {

	const VERSION = '2026-11-25';

	public static function init(): void {
		add_shortcode( 'atc_reservation_policies', array( __CLASS__, 'html' ) );
	}

	public static function policies(): array {
		$fc    = (int) ATC_Settings::get( 'free_cancel_hours' );
		$h     = $fc . ' hour' . ( 1 === $fc ? '' : 's' );
		$email = 'hello@airporttransportation.com';
		$contact = home_url( '/contact/' );

		return array(
			'version' => self::VERSION,
			'updated' => 'November 25, 2026',
			'key'     => array(
				'Prices are per vehicle, not per person, in US dollars, with taxes, tolls, airport fees and your free waiting time included. Nothing more to pay on the day unless you add something.',
				'Free cancellation up to ' . $h . ' before pickup, refunded in full to the card you paid with. Later than that, or if you do not show up, the ride is not refunded.',
				'Change your times, flights, luggage, passengers, vehicle, address and extras online under Manage booking up to ' . $h . ' before pickup. You only pay, or get back, any difference in price.',
				'We track your flight. Your driver waits from the time you actually land, not the time in your booking, for the free waiting time shown on your booking.',
				'Your ride is private: only your group, door to door, in the vehicle category you booked or a better one.',
				'If your driver does not show up, you get a full refund.',
				'Tell us about any problem within 30 days and we answer within 2 business days.',
			),
			'sections' => array(
				array( '1. Who we are', array(
					'AirportTransportation.com is run by Airport Transportation LLC, a Wyoming (USA) company ("we", "us"). We sell private transfers that are carried out by local, licensed transportation companies ("providers") in each city.',
					'When you book, your contract for the booking is with us: we take your payment, send your confirmation, assign the provider, and are your contact for changes, refunds and complaints. The provider is responsible for driving you safely and for its drivers, vehicles, licenses and insurance.',
					'These policies apply to every booking made on AirportTransportation.com and on the sites, apps and partners that sell through it. You accept them when you book. The version on the page when you booked is the one that applies to that booking.',
				) ),
				array( '2. Prices and what they include', array(
					'Prices are per vehicle, not per person, and shown in US dollars. They include the driver, fuel, tolls, parking and airport access fees, taxes, flight tracking and the free waiting time shown on your booking.',
					'The only extras are the ones you add when you book or later through us (for example a child seat, an extra stop or meet and greet with a name sign). Neither the driver nor the provider may ask you for any other payment.',
					'Tips are not included and are never required. If you want to thank your driver, it is up to you.',
					'The price is fixed when you book. It does not change because of traffic, the route taken or a delayed flight. It only changes if you change the booking in a way that needs a different vehicle, zone or extra, and we always show you the new price first.',
				) ),
				array( '3. Booking and payment', array(
					'You pay the full price by card when you book. Your booking is confirmed once the payment goes through, and we email your confirmation, voucher and service code at once.',
					'Payments are processed securely by Stripe. We never see or keep your full card number. Your bank may add its own fee for payments in US dollars; that fee is not ours.',
					'Make sure your name, email, phone number (with WhatsApp if you have it), flight and addresses are correct. We send everything about your ride to the email you give us, and the driver uses your phone number if they cannot find you.',
					'Some routes need a minimum notice before pickup. The booking form only offers times that a provider can still accept. For a ride sooner than that, contact us and we will try to help.',
					'You must be 18 or older to book. Children travel only with an adult.',
				) ),
				array( '4. Your confirmation and service code', array(
					'Your confirmation shows your booking number, the pickup details, the meeting instructions and a short service code.',
					'Show the service code to your driver when you meet. It confirms that the right driver found the right passenger. Do not share it before then.',
					'Before your ride we send you the provider\'s name and contact. You can also see them at any time under Manage booking.',
				) ),
				array( '5. Changes', array(
					'Under Manage booking you can change your pickup times, flights, luggage and notes for the driver, and also the number of passengers, the vehicle, the address and the extras, up to ' . $h . ' before your first pickup. There is no fee for changing.',
					'Changing the passengers, vehicle, address or extras can change the price. We show you the new total and the difference before anything changes. If it costs more, you pay the difference by card to confirm the change; if it costs less, we refund the difference to your card at once.',
					'A change can move your booking to another provider when yours does not offer the new vehicle or address. We tell you before you confirm, and send you the new provider\'s contact.',
					'Within ' . $h . ' of pickup, contact us. We will try to make the change with the provider, but we cannot promise it.',
					'Drivers cannot take changes. Always make them through us, so the booking, the provider and your confirmation stay the same.',
				) ),
				array( '6. Cancellations and refunds', array(
					'Cancel online under Manage booking up to ' . $h . ' before your first pickup and you get a full refund to the card you paid with. There is no cancellation fee.',
					'For a round trip, you can cancel only the return ride by contacting us at least ' . $h . ' before the return pickup. We refund that ride in full.',
					'Rides cancelled less than ' . $h . ' before pickup, and rides you do not show up for, are not refunded, because the provider has already set aside the vehicle and driver.',
					'Refunds go back to the original card. They are sent the same day; your bank usually shows them within 5 to 10 business days.',
					'If we or the provider have to cancel your ride for any reason, you get a full refund. We will first try to find you another provider at the same price.',
				) ),
				array( '7. Flights, delays and waiting time', array(
					'Give us your flight number. We track arrivals, and your driver adjusts to the real landing time, so a delayed flight is not a no-show.',
					'At airports, your free waiting time starts when your flight actually lands. The time is shown on your booking and is usually 60 minutes, enough for immigration, luggage and customs at most airports.',
					'At hotels, homes, ports and other addresses, the driver waits 15 minutes after the booked pickup time. Please be ready in the lobby or at the door.',
					'If you will be later than the free waiting time (for example a long immigration line or lost luggage), message or call the driver or us as soon as you can. The driver will wait longer when they can; if they cannot, we will do our best to send another car.',
					'If your flight is cancelled, diverted or rescheduled to another day, contact us as soon as you know. We will move your ride to the new time when a provider can take it, at no charge. If no provider can, we refund you in full.',
					'For departures, choose a pickup time that leaves enough time for traffic and check-in. The booking form suggests one. We are not responsible for a missed flight caused by a pickup time you chose, unless the driver was late.',
				) ),
				array( '8. Meeting your driver', array(
					'Follow the meeting instructions in your confirmation. They say exactly where the driver waits (for example outside the terminal, at a numbered post or pillar) and whether the driver holds a sign with your name.',
					'Keep your phone on, with mobile data or Wi-Fi, from landing until you meet your driver. Many airports have free Wi-Fi.',
					'At some airports, people in the terminal offer transport or say they are your driver. Your driver knows your name and booking. Check the service code before you get in.',
					'If you cannot find your driver, call or message the provider first, then us, before leaving the airport or taking other transport. Without that contact we may not be able to refund you.',
				) ),
				array( '9. No-shows', array(
					'You are a no-show if you are not at the meeting point by the end of the free waiting time and the driver, the provider and we cannot reach you by phone, WhatsApp or email.',
					'A no-show ride is not refunded, and the driver may leave. If you have a return ride in the same booking, it stays valid: tell us that you still need it.',
				) ),
				array( '10. If the service is not as booked', array(
					'If your driver does not show up, you get a full refund of that ride.',
					'If your driver is late, the vehicle is smaller or of a lower category than you booked, you are put in a shared ride, or the service is otherwise clearly worse than described, tell us within 30 days. We refund part or all of that ride according to what happened.',
					'If you missed your flight because the driver was late, we refund the ride and pay your reasonable, documented extra costs (for example a change fee), up to 150% of the price of that ride.',
					'We may upgrade you to a larger or better vehicle at no cost. That is never a reason for a charge.',
				) ),
				array( '11. Luggage', array(
					'Your booking shows how many suitcases the vehicle takes. Tell us the right number when you book (you can change it online later); a standard suitcase is up to 158 cm (length + width + height) and 23 kg. Small items such as a handbag, a backpack or a camera bag do not count.',
					'Tell us in advance about anything larger: golf bags, surfboards, bicycles, strollers, wheelchairs, musical instruments, extra boxes. Some need a larger vehicle.',
					'If you bring more or larger luggage than you booked and it does not fit, the driver may need a second vehicle at your cost or may not be able to take you. In that case the ride is not refunded.',
					'Look after your own valuables and check that you have everything before the driver leaves.',
				) ),
				array( '12. Children and child seats', array(
					'Every child counts as a passenger, babies included. Tell us the right number of children when you book.',
					'Where we offer child seats, add them as an extra with your child\'s age. If you booked a seat and it is not in the vehicle, we refund it. You may also bring your own seat.',
					'Child seat laws are different in each country. In some places, taxis and private transfers are exempt; in others they are not. Where a seat is required by law and you have none, the driver may refuse the ride.',
				) ),
				array( '13. Accessibility, pets and special requests', array(
					'Tell us in the pickup notes, or before you book, if anyone needs help getting in or out, travels with a wheelchair or walker, or has any other need. We will tell you honestly whether a provider can meet it.',
					'Guide and assistance dogs always ride free. Other pets need our confirmation before travel, in a closed carrier.',
				) ),
				array( '14. On board', array(
					'Everyone wears a seat belt where the vehicle has them. Smoking and vaping are not allowed in the vehicle.',
					'The driver may refuse to take, or ask to leave, anyone who is violent, dangerous, heavily intoxicated or who breaks local law. In that case the ride is not refunded.',
					'If you or your group damage the vehicle or leave it so dirty that it needs special cleaning, the provider may ask us to charge you the reasonable, documented cost, up to USD 150. We show you the evidence first.',
				) ),
				array( '15. Lost items', array(
					'Tell us as soon as possible if you leave something in the vehicle. We ask the provider to look for it and help you get it back.',
					'Any delivery or shipping cost is yours. Items not claimed within 30 days may be given away or thrown out.',
				) ),
				array( '16. Events beyond our control', array(
					'Hurricanes, storms, floods, earthquakes, strikes, road closures, airport or government orders and similar events can stop a ride. If the ride cannot happen, you get a full refund, or a new date if you prefer.',
					'We are not responsible for delays or losses caused by these events beyond refunding the ride.',
				) ),
				array( '17. Our responsibility', array(
					'The provider is responsible for the ride itself, its drivers and vehicles, and carries the insurance required by local law.',
					'Our responsibility for a booking is limited to the amount you paid for it, plus what section 10 says. We are not responsible for losses we could not foresee, or for indirect losses such as missed connections, tours or hotel nights, except where section 10 says otherwise.',
					'Nothing in these policies limits your rights as a consumer that the law of your country does not allow to be limited, or our responsibility for death or injury caused by our negligence.',
				) ),
				array( '18. Complaints and reviews', array(
					'If something goes wrong during your ride, tell us right away so we can fix it while it is happening. Our contact details are on the <a href="' . esc_url( $contact ) . '">contact page</a>.',
					'For anything else, write to ' . $email . ' within 30 days of your ride, with your booking number. We answer within 2 business days and aim to settle every case within 14 days.',
					'After your ride we invite you to rate your provider. Reviews are only open to travelers who booked and rode, and we publish them as written, good or bad, unless they are abusive or contain personal data.',
					'Please do not dispute a payment with your bank before contacting us. We can usually solve the problem faster.',
				) ),
				array( '19. Your data', array(
					'We use your details only to provide your ride: the provider receives your name, phone, flight and pickup details. See our <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">privacy policy</a> for the rest.',
				) ),
				array( '20. Changes to these policies and law', array(
					'We may update these policies. The version in force when you booked applies to that booking.',
					'These policies are governed by the laws of the State of Wyoming, USA, without taking away the protection of the consumer laws of your country of residence.',
				) ),
			),
		);
	}

	/** Allowed inline HTML in the texts: links only. */
	private static function kses( string $t ): string {
		return wp_kses( $t, array( 'a' => array( 'href' => true ) ) );
	}

	public static function html(): string {
		$a   = self::policies();
		$out = '<div class="atc-agreement atc-policies"><p><em>Reservation Policies · version ' . esc_html( $a['version'] ) . ' · last updated ' . esc_html( $a['updated'] ) . '</em></p>';
		$out .= '<div class="atc-keypoints" style="background:#F7F5F1;border:1px solid #ECE8E1;border-radius:14px;padding:18px 22px;margin:0 0 28px"><h2 style="margin-top:0">The key points</h2><ul>';
		foreach ( $a['key'] as $k ) {
			$out .= '<li>' . esc_html( $k ) . '</li>';
		}
		$out .= '</ul><p><small>These points summarize the policies below. The full text is what applies.</small></p></div><h2>The full policies</h2>';
		foreach ( $a['sections'] as [ $t, $paras ] ) {
			$out .= '<h3>' . esc_html( $t ) . '</h3>';
			foreach ( $paras as $para ) {
				$out .= '<p>' . self::kses( $para ) . '</p>';
			}
		}
		return $out . '</div>';
	}
}
