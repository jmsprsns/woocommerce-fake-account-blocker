<?php
/**
 * Bot checks on the sign-up forms: a honeypot field, a signed timestamp and
 * a JavaScript check, all printed into the form and checked when it's sent.
 *
 * Everything works with page caching: the fields are the same for every
 * visitor, and a cached copy only makes the form look older, never newer.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sign-up form fields.
 */
final class WooCommerce_Fake_Account_Blocker_Form {

	/**
	 * The honeypot: a text field people never see and bots fill in. Its name
	 * means nothing on purpose: a name like "website" or "url" invites
	 * browser and password-manager autofill, which would refuse real people.
	 */
	const HONEYPOT = 'wcfab_xf';

	/**
	 * When the form was printed, signed so it can't be faked.
	 */
	const TOKEN = 'wcfab_token';

	/**
	 * Filled in by the inline script; bots that don't run JavaScript leave it empty.
	 */
	const JS = 'wcfab_js';

	/**
	 * Option recording which forms have printed the fields at least once.
	 */
	const SEEN_OPTION = 'fake_account_blocker_forms_seen';

	/**
	 * Print the fields into a sign-up form.
	 *
	 * @param string $context "account" (WooCommerce My Account) or "wordpress" (wp-login.php).
	 * @return void
	 */
	public static function render( $context ) {
		$token = self::token( $context );
		$id    = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'wcfab-xf-' ) : 'wcfab-xf-' . wp_rand();

		// Off-screen rather than display:none, which some bots know to skip.
		// Password managers are told to leave it alone.
		?>
		<div class="wcfab-field" aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Leave this field empty', 'fake-account-blocker' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
		</div>
		<input type="hidden" name="<?php echo esc_attr( self::TOKEN ); ?>" value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="<?php echo esc_attr( self::JS ); ?>" value="" data-wcfab="<?php echo esc_attr( self::js_value( $token ) ); ?>">
		<?php
		self::print_script();
		self::mark_seen( $context );
	}

	/**
	 * Check the fields of a sent form.
	 *
	 * Skipped for a form that has never printed them on this site, so a theme
	 * with a custom sign-up form that leaves out WordPress's hook can't lock
	 * everyone out.
	 *
	 * @param string              $context  "account" or "wordpress".
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{reason:string,detail:string}|null Null when the form looks human.
	 */
	public static function check( $context, array $settings ) {
		if ( ! self::seen( $context ) ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only checks on a form WordPress and WooCommerce verify themselves.
		$honeypot = isset( $_POST[ self::HONEYPOT ] ) && is_string( $_POST[ self::HONEYPOT ] ) ? trim( wp_unslash( $_POST[ self::HONEYPOT ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only tested for emptiness.
		$token    = isset( $_POST[ self::TOKEN ] ) && is_string( $_POST[ self::TOKEN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN ] ) ) : '';
		$js       = isset( $_POST[ self::JS ] ) && is_string( $_POST[ self::JS ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::JS ] ) ) : '';
		// phpcs:enable

		if ( ! empty( $settings['honeypot'] ) && '' !== $honeypot ) {
			return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'honeypot' );
		}

		if ( ! empty( $settings['timing'] ) ) {
			$issued = self::verify_token( $token, $context );

			if ( null === $issued ) {
				return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'no_token' );
			}

			$age = time() - $issued;

			if ( $age > self::lifetime() ) {
				return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'expired', self::duration( $age ) );
			}

			$min = max( 0, (int) $settings['min_seconds'] );
			if ( $age < $min ) {
				/* translators: %d: seconds between the form loading and being sent. */
				return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'too_fast', sprintf( _n( '%d second', '%d seconds', max( 0, $age ), 'fake-account-blocker' ), max( 0, $age ) ) );
			}
		}

		if ( ! empty( $settings['js_check'] ) && ! hash_equals( self::js_value( $token ), $js ) ) {
			return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'no_js' );
		}

		return null;
	}

	/**
	 * Whether a form has printed the fields at least once.
	 *
	 * @param string $context Form.
	 * @return bool
	 */
	public static function seen( $context ) {
		$seen = get_option( self::SEEN_OPTION, array() );

		return is_array( $seen ) && ! empty( $seen[ $context ] );
	}

	/**
	 * Forget which forms print the fields, after a theme change: the new
	 * theme's forms have to show they include them before they're checked.
	 *
	 * @return void
	 */
	public static function forget_seen() {
		delete_option( self::SEEN_OPTION );
	}

	/**
	 * Remember that a form prints the fields. Written once per form, ever.
	 *
	 * @param string $context Form.
	 * @return void
	 */
	private static function mark_seen( $context ) {
		$seen = get_option( self::SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();

		if ( empty( $seen[ $context ] ) ) {
			$seen[ $context ] = time();
			update_option( self::SEEN_OPTION, $seen, true );
		}
	}

	/**
	 * A signed token holding the time the form was printed.
	 *
	 * @param string $context Form.
	 * @return string
	 */
	private static function token( $context ) {
		$now = time();

		return $now . '.' . self::sign( $now, $context );
	}

	/**
	 * When a token was issued, if its signature is good.
	 *
	 * @param string $token   Token from the form.
	 * @param string $context Form it should belong to.
	 * @return int|null Unix time, or null for a missing, forged or future token.
	 */
	private static function verify_token( $token, $context ) {
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{32})$/', (string) $token, $parts ) ) {
			return null;
		}

		$issued = (int) $parts[1];

		// A few minutes' leeway for servers behind a load balancer whose clocks differ.
		if ( ! hash_equals( self::sign( $issued, $context ), $parts[2] ) || $issued > time() + 5 * MINUTE_IN_SECONDS ) {
			return null;
		}

		return $issued;
	}

	/**
	 * The token's signature.
	 *
	 * @param int    $issued  Unix time.
	 * @param string $context Form.
	 * @return string
	 */
	private static function sign( $issued, $context ) {
		return substr( hash_hmac( 'sha256', 'wcfab|' . $context . '|' . (int) $issued, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * What the inline script puts in the JavaScript field for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function js_value( $token ) {
		return substr( hash_hmac( 'sha256', 'wcfab-js|' . (string) $token, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * How long a printed form stays valid. Longer than WooCommerce's own
	 * form nonce, so this is never the thing that expires first.
	 *
	 * @return int Seconds.
	 */
	private static function lifetime() {
		/**
		 * Filter how long a sign-up form stays valid after it's printed.
		 *
		 * @param int $seconds Default two days.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'fake_account_blocker_form_lifetime', 2 * DAY_IN_SECONDS ) );
	}

	/**
	 * A rough age like "3 days".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		return human_time_diff( time() - (int) $seconds, time() );
	}

	/**
	 * The inline script: copies each field's expected value in, now and again
	 * when any form is focused or sent (for forms added to the page later).
	 *
	 * Marked so WP Rocket, Cloudflare Rocket Loader, LiteSpeed, Autoptimize,
	 * Interaction-Delayed Scripts and similar optimizers run it straight away
	 * (WP Rocket also gets the filters in the guard).
	 *
	 * @return void
	 */
	private static function print_script() {
		$js = '(function(w,d){function f(){var l=d.querySelectorAll("input[data-wcfab]");for(var i=0;i<l.length;i++){l[i].value=l[i].getAttribute("data-wcfab");}}f();if(w.wcfabOn){return;}w.wcfabOn=1;d.addEventListener("focusin",f,true);d.addEventListener("submit",f,true);})(window,document);';

		$attributes = array(
			'class'                   => 'wcfab-js',
			'data-cfasync'            => 'false',
			'data-no-optimize'        => '1',
			'data-noptimize'          => '1',
			'data-no-defer'           => '1',
			'data-no-minify'          => '1',
			'data-no-delay'           => '1',
			'data-pagespeed-no-defer' => true,
			'nowprocket'              => true,
		);

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $js, $attributes );
			return;
		}

		echo '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1">' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed script.
	}
}
