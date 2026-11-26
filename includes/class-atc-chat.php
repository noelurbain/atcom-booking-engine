<?php
/**
 * Crisp chat, kept light: our own small chat button, and Crisp's script only loads when someone taps it
 * (or straight away for a visitor who already has a Crisp conversation, so they see replies).
 * On checkout, payment and reservation pages Crisp gets the booking number, name and email, so our team
 * sees who they are talking to.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Chat {

	private static array $context = array();

	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 50 );
	}

	/** Called by the booking pages: who the visitor is, for our team in Crisp. */
	public static function context( array $b ): void {
		$s              = (array) ( $b['snap'] ?? array() );
		self::$context = array_filter(
			array(
				'email'   => (string) ( $b['email'] ?? '' ),
				'name'    => trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) ),
				'phone'   => (string) ( $b['phone'] ?? '' ),
				'booking' => (string) ( $b['number'] ?? '' ),
				'status'  => (string) ( $b['status'] ?? '' ),
				'airport' => (string) ( $s['iata'] ?? '' ),
				'control' => ! empty( $b['number'] ) ? home_url( '/control/bookings/' . $b['number'] . '/' ) : '',
			)
		);
	}

	/** Called by the provider and affiliate portals: Crisp shows our team which partner is writing. */
	public static function partner( string $role, int $party_id ): void {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, contact_name, email, phone FROM ' . ATC_Schema::table( 'parties' ) . ' WHERE id = %d', $party_id ), ARRAY_A );
		$u = wp_get_current_user();
		self::$context = array_filter(
			array(
				'email'   => $u && $u->user_email ? (string) $u->user_email : (string) ( $p['email'] ?? '' ),
				'name'    => $p ? trim( ( $p['contact_name'] ? $p['contact_name'] . ' · ' : '' ) . $p['name'] ) : (string) ( $u->display_name ?? '' ),
				'phone'   => (string) ( $p['phone'] ?? '' ),
				'segment' => $role,
				'company' => (string) ( $p['name'] ?? '' ),
				'control' => $p && 'provider' === $role ? home_url( '/control/providers/' . (int) $p['id'] . '/' ) : '',
			)
		);
	}

	/** The chat button and loader, as a string (the portals print their own page, without wp_footer). */
	public static function markup(): string {
		ob_start();
		self::footer();
		return (string) ob_get_clean();
	}

	public static function footer(): void {
		$id = (string) ATC_Settings::get( 'crisp_id' );
		if ( '' === $id || is_admin() ) {
			return;
		}
		$cfg = array( 'id' => $id, 'ctx' => self::$context );
		?>
<button type="button" class="atc-chatbtn" id="atc-chatbtn" aria-label="Chat with us"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FBA53E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"></path></svg></button>
<style>.atc-chatbtn{position:fixed;right:20px;bottom:20px;z-index:9990;width:56px;height:56px;border-radius:999px;border:0;background:#15171C;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(21,23,28,.25);cursor:pointer;transition:transform .15s ease}.atc-chatbtn:hover{transform:translateY(-2px)}.atc-chatbtn.is-loading{opacity:.6;cursor:progress}.atc-chatbtn[hidden]{display:none}.atc-chatbtn.has-new::after{content:'';position:absolute;top:6px;right:6px;width:12px;height:12px;border-radius:999px;background:#E5484D;border:2px solid #15171C}@media (max-width:900px){html:has(.atc-mbar:not([style*="display: none"])) .atc-chatbtn{bottom:96px}}@media print{.atc-chatbtn{display:none}}</style>
<script>
(function () {
  var C = <?php echo wp_json_encode( $cfg ); ?>, btn = document.getElementById('atc-chatbtn'), started = false;
  function load(open) {
    if (started) {
      if (open && window.$crisp && window.$crisp.is) { btn.hidden = true; btn.classList.remove('has-new'); window.$crisp.push(['do', 'chat:show']); window.$crisp.push(['do', 'chat:open']); }
      return;
    }
    started = true;
    window.$crisp = window.$crisp || [];
    window.CRISP_WEBSITE_ID = C.id;
    var x = C.ctx || {};
    if (x.email) window.$crisp.push(['set', 'user:email', [x.email]]);
    if (x.name) window.$crisp.push(['set', 'user:nickname', [x.name]]);
    if (x.phone) window.$crisp.push(['set', 'user:phone', [x.phone]]);
    var data = [];
    ['booking', 'status', 'airport', 'company', 'control'].forEach(function (k) { if (x[k]) data.push([k, x[k]]); });
    data.push(['page', location.pathname]);
    window.$crisp.push(['set', 'session:data', [data]]);
    var seg = []; if (x.booking) seg.push('booking'); if (x.segment) seg.push(x.segment);
    if (seg.length) window.$crisp.push(['set', 'session:segments', [seg]]);
    // Our button stays the only launcher (it moves above the booking bar on phones); Crisp's own shows only while chatting.
    window.$crisp.push(['on', 'session:loaded', function () {
      btn.classList.remove('is-loading');
      if (open) { btn.hidden = true; window.$crisp.push(['do', 'chat:show']); window.$crisp.push(['do', 'chat:open']); }
      else { window.$crisp.push(['do', 'chat:hide']); }
    }]);
    window.$crisp.push(['on', 'chat:closed', function () { window.$crisp.push(['do', 'chat:hide']); btn.hidden = false; }]);
    window.$crisp.push(['on', 'message:received', function () { if (!btn.hidden) btn.classList.add('has-new'); }]);
    if (open) btn.classList.add('is-loading');
    var s = document.createElement('script'); s.src = 'https://client.crisp.chat/l.js'; s.async = true;
    s.onerror = function () { started = false; btn.classList.remove('is-loading'); };
    document.head.appendChild(s);
  }
  btn.addEventListener('click', function () { btn.classList.remove('has-new'); load(true); });
  window.$crisp = window.$crisp || [];
  window.$crisp.push(['do', 'chat:hide']);
  // A visitor with a conversation already: load now, so they see our replies.
  if (/crisp-client%2Fsession%2F|crisp-client\/session\//.test(document.cookie)) load(false);
})();
</script>
		<?php
	}
}
