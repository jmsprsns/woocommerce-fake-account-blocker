<?php
/**
 * Checks on what a sign-up is made of: the email address, the username and
 * the names. Shared by the sign-up guard and the Spam Accounts scan.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email, name and list checks.
 */
final class WooCommerce_Fake_Account_Blocker_Rules {

	/**
	 * Bundled list of disposable email domains, one per line.
	 */
	const DISPOSABLE_FILE = 'data/disposable-domains.txt';

	/**
	 * Real, permanent mail providers and privacy relays. Never treated as
	 * disposable, even if a future copy of the list gets one wrong.
	 */
	const NEVER_DISPOSABLE = array(
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'live.com',
		'msn.com',
		'yahoo.com',
		'ymail.com',
		'icloud.com',
		'me.com',
		'mac.com',
		'privaterelay.appleid.com',
		'aol.com',
		'proton.me',
		'protonmail.com',
		'pm.me',
		'gmx.com',
		'gmx.de',
		'gmx.net',
		'web.de',
		'mail.com',
		'zoho.com',
		'yandex.com',
		'yandex.ru',
		'mail.ru',
		'qq.com',
		'163.com',
		'fastmail.com',
		'duck.com',
		'mozmail.com',
		'simplelogin.com',
		'tutanota.com',
		'tuta.io',
	);

	/**
	 * Most entries kept from each of the user's lists.
	 */
	const MAX_LIST = 1000;

	/**
	 * Seconds a mail lookup may take before lookups pause for five minutes.
	 */
	const DNS_SLOW = 2;

	/**
	 * Disposable domains as a lookup set, once per request.
	 *
	 * @var array<string,int>|null
	 */
	private static $disposable = null;

	/**
	 * DNS answers already worked out in this request.
	 *
	 * @var array<string,bool|null>
	 */
	private static $dns = array();

	/**
	 * Run every enabled email, word and name check.
	 *
	 * @param array<string,mixed> $signup   Email, username and names.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{reason:string,detail:string}|null Null when nothing is wrong.
	 */
	public static function check( array $signup, array $settings ) {
		$email    = isset( $signup['email'] ) ? (string) $signup['email'] : '';
		$username = isset( $signup['username'] ) ? (string) $signup['username'] : '';
		$names    = isset( $signup['names'] ) && is_array( $signup['names'] ) ? $signup['names'] : array();
		$domain   = is_email( $email ) ? self::email_domain( $email ) : '';
		$allowed  = '' !== $domain && '' !== self::domain_matches( $domain, self::domain_set( (string) $settings['allowed_domains'] ) );

		if ( '' !== $domain && ! $allowed ) {
			$match = self::domain_matches( $domain, self::domain_set( (string) $settings['blocked_domains'] ) );
			if ( '' !== $match ) {
				return self::verdict( 'blocked_domain', $match );
			}

			if ( ! empty( $settings['disposable'] ) ) {
				$match = self::disposable( $domain );
				if ( '' !== $match ) {
					return self::verdict( 'disposable', $match );
				}
			}
		}

		$word = self::blocked_word( array_merge( array( $email, $username ), array_values( $names ) ), (string) $settings['blocked_words'] );
		if ( '' !== $word ) {
			return self::verdict( 'blocked_word', $word );
		}

		if ( ! empty( $settings['spam_names'] ) ) {
			foreach ( $names as $field => $value ) {
				if ( self::spam_name( $value, self::is_strict_field( $field ) ) ) {
					return self::verdict( 'spam_name', (string) $field );
				}
			}

			if ( self::spam_name( $username, true ) ) {
				return self::verdict( 'spam_name', 'username' );
			}
		}

		// Last: the only check that leaves the server.
		if ( '' !== $domain && ! $allowed && ! empty( $settings['mx_check'] ) && false === self::can_receive_mail( $domain ) ) {
			return self::verdict( 'no_mx', $domain );
		}

		return null;
	}

	/**
	 * A reason and its detail.
	 *
	 * @param string $reason Reason code.
	 * @param string $detail What matched.
	 * @return array{reason:string,detail:string}
	 */
	public static function verdict( $reason, $detail = '' ) {
		return array(
			'reason' => (string) $reason,
			'detail' => (string) $detail,
		);
	}

	/**
	 * The domain of an email address: lowercase, no trailing dot, and in its
	 * ASCII (punycode) form when PHP can convert it.
	 *
	 * @param string $email Email address.
	 * @return string '' when there isn't a usable one.
	 */
	public static function email_domain( $email ) {
		$at = strrpos( (string) $email, '@' );

		return false === $at ? '' : self::normalize_domain( substr( (string) $email, $at + 1 ) );
	}

	/**
	 * A domain (or a list entry like "@example.com", "*.example.com" or
	 * ".ru") in the form every lookup uses.
	 *
	 * @param string $domain Domain.
	 * @return string '' when it isn't a valid domain.
	 */
	public static function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = rtrim( ltrim( $domain, '@*.' ), '.' );

		if ( '' === $domain || strlen( $domain ) > 253 ) {
			return '';
		}

		if ( preg_match( '/[^\x00-\x7F]/', $domain ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) ? @idn_to_ascii( $domain, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "not a domain".
			if ( ! is_string( $ascii ) || '' === $ascii ) {
				return '';
			}
			$domain = strtolower( $ascii );
		}

		return preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $domain ) ? $domain : '';
	}

	/**
	 * A list of domains from a textarea (one per line, or comma-separated),
	 * as a lookup set.
	 *
	 * @param string $raw Raw list.
	 * @return array<string,int>
	 */
	public static function domain_set( $raw ) {
		$set = array();

		foreach ( self::split_list( $raw ) as $entry ) {
			$domain = self::normalize_domain( $entry );
			if ( '' !== $domain ) {
				$set[ $domain ] = 1;
			}
		}

		return $set;
	}

	/**
	 * Clean up a domain list for saving: valid entries only, one per line.
	 *
	 * @param string   $raw     Raw list.
	 * @param string[] $invalid Filled with the entries that were dropped.
	 * @return string
	 */
	public static function sanitize_domain_list( $raw, &$invalid = array() ) {
		$keep    = array();
		$invalid = array();

		foreach ( self::split_list( $raw ) as $entry ) {
			$domain = self::normalize_domain( $entry );
			if ( '' === $domain ) {
				$invalid[] = $entry;
				continue;
			}
			// Keep the leading dot that says "everything ending in this".
			$keep[ $domain ] = 0 === strpos( trim( $entry ), '.' ) ? '.' . $domain : $domain;
		}

		return implode( "\n", array_slice( array_values( $keep ), 0, self::MAX_LIST ) );
	}

	/**
	 * Which entry of a domain set covers a domain: the domain itself or any
	 * domain it's under (so example.com covers mail.example.com, and ru covers
	 * everything ending in .ru).
	 *
	 * @param string            $domain Normalized domain.
	 * @param array<string,int> $set    Domain set.
	 * @return string The matching entry, or ''.
	 */
	public static function domain_matches( $domain, array $set ) {
		if ( '' === $domain || ! $set ) {
			return '';
		}

		$labels = explode( '.', $domain );
		$count  = count( $labels );

		for ( $i = 0; $i < $count; $i++ ) {
			$candidate = implode( '.', array_slice( $labels, $i ) );
			if ( isset( $set[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether a domain belongs to a disposable email service.
	 *
	 * @param string $domain Normalized domain.
	 * @return string The listed domain that matched, or ''.
	 */
	public static function disposable( $domain ) {
		if ( '' !== self::domain_matches( $domain, array_flip( self::NEVER_DISPOSABLE ) ) ) {
			return '';
		}

		return self::domain_matches( $domain, self::disposable_set() );
	}

	/**
	 * How many disposable domains the bundled list holds.
	 *
	 * @return int
	 */
	public static function disposable_count() {
		return count( self::disposable_set() );
	}

	/**
	 * The disposable domain list as a lookup set.
	 *
	 * @return array<string,int>
	 */
	private static function disposable_set() {
		if ( null !== self::$disposable ) {
			return self::$disposable;
		}

		$file    = FAKE_ACCOUNT_BLOCKER_DIR . self::DISPOSABLE_FILE;
		$domains = is_readable( $file ) ? file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
		$domains = is_array( $domains ) ? array_map( 'trim', $domains ) : array();

		/**
		 * Filter the disposable email domains (lowercase, one per entry).
		 *
		 * @param string[] $domains Domains from the bundled list.
		 */
		$domains = apply_filters( 'fake_account_blocker_disposable_domains', $domains );

		self::$disposable = array();
		foreach ( (array) $domains as $domain ) {
			if ( is_string( $domain ) && '' !== $domain && '#' !== $domain[0] ) {
				self::$disposable[ strtolower( $domain ) ] = 1;
			}
		}

		return self::$disposable;
	}

	/**
	 * Whether a domain can receive email: it has mail servers (MX records),
	 * or, failing that, an address of its own (which mail falls back to). A
	 * "null MX" (a domain saying it takes no mail) counts as no.
	 *
	 * @param string $domain Normalized domain.
	 * @return bool|null Null when it couldn't be checked (no DNS functions, or
	 *                   the server's DNS isn't answering), so nobody is refused.
	 */
	public static function can_receive_mail( $domain ) {
		if ( '' === $domain || false === strpos( $domain, '.' ) || ! function_exists( 'checkdnsrr' ) ) {
			return null;
		}

		if ( array_key_exists( $domain, self::$dns ) ) {
			return self::$dns[ $domain ];
		}

		$key    = 'wcfab_mx_' . md5( $domain );
		$cached = get_transient( $key );
		if ( in_array( $cached, array( 'y', 'n', 'u' ), true ) ) {
			self::$dns[ $domain ] = 'u' === $cached ? null : 'y' === $cached;
			return self::$dns[ $domain ];
		}

		// Lookups were slow a moment ago: skip them for now rather than keep
		// visitors (or a bot pointing at a domain that never answers) waiting.
		if ( get_transient( 'wcfab_dns_slow' ) ) {
			return null;
		}

		$started = microtime( true );
		$answer  = self::lookup_mail( $domain );

		if ( microtime( true ) - $started > self::DNS_SLOW ) {
			set_transient( 'wcfab_dns_slow', 1, 5 * MINUTE_IN_SECONDS );
		}

		// Nothing found could mean the server's own DNS is down. Only believe
		// it if a domain that certainly takes mail still resolves.
		if ( false === $answer && ! self::dns_working() ) {
			$answer = null;
		}

		$ttl = array(
			'y' => WEEK_IN_SECONDS,
			'n' => 6 * HOUR_IN_SECONDS,
			'u' => HOUR_IN_SECONDS,
		);
		$tag = null === $answer ? 'u' : ( $answer ? 'y' : 'n' );
		set_transient( $key, $tag, $ttl[ $tag ] );

		self::$dns[ $domain ] = $answer;

		return $answer;
	}

	/**
	 * Ask DNS about a domain's mail: its MX records, or, with none, an
	 * address of its own. One query when the domain has mail servers.
	 *
	 * @param string $domain Domain.
	 * @return bool|null Null when a lookup failed (timed out, or the DNS
	 *                   server gave no answer), so nothing more is asked.
	 */
	private static function lookup_mail( $domain ) {
		$fqdn = $domain . '.';

		if ( ! function_exists( 'dns_get_record' ) ) {
			return checkdnsrr( $fqdn, 'MX' ) || checkdnsrr( $fqdn, 'A' ) || checkdnsrr( $fqdn, 'AAAA' );
		}

		// dns_get_record() returns an empty array for a domain that doesn't
		// exist or has no such records, and false (with a warning) when the
		// lookup itself fails.
		$records = @dns_get_record( $fqdn, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup warns; we handle it.

		if ( false === $records ) {
			return null;
		}

		if ( $records ) {
			foreach ( $records as $record ) {
				$target = isset( $record['target'] ) ? trim( (string) $record['target'], '. ' ) : '';
				if ( '' !== $target ) {
					return true;
				}
			}

			// Only "MX 0 ." (RFC 7505): this domain accepts no mail.
			return false;
		}

		foreach ( array( DNS_A, DNS_AAAA ) as $type ) {
			$records = @dns_get_record( $fqdn, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
			if ( false === $records ) {
				return null;
			}
			if ( $records ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this server's DNS answers at all, checked against domains that
	 * always have mail servers. Remembered for a while either way.
	 *
	 * @return bool
	 */
	private static function dns_working() {
		$state = get_transient( 'wcfab_dns_ok' );
		if ( 'y' === $state || 'n' === $state ) {
			return 'y' === $state;
		}

		$working = checkdnsrr( 'gmail.com.', 'MX' ) || checkdnsrr( 'outlook.com.', 'MX' );

		set_transient( 'wcfab_dns_ok', $working ? 'y' : 'n', $working ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

		return $working;
	}

	/**
	 * The first blocked word found in any of the values, ignoring case.
	 *
	 * @param string[] $values Values to search.
	 * @param string   $raw    Blocked words, one per line.
	 * @return string The word that matched, or ''.
	 */
	public static function blocked_word( array $values, $raw ) {
		$words = self::word_list( $raw );

		if ( ! $words ) {
			return '';
		}

		$haystack = self::lower( implode( "\n", array_map( 'strval', $values ) ) );

		foreach ( $words as $word ) {
			if ( false !== strpos( $haystack, self::lower( $word ) ) ) {
				return $word;
			}
		}

		return '';
	}

	/**
	 * Blocked words from a textarea: one word or phrase per line, at least
	 * two characters, so a stray letter can't block everyone.
	 *
	 * @param string $raw Raw list.
	 * @return string[]
	 */
	public static function word_list( $raw ) {
		$words = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) >= 2 ) {
				$words[ self::lower( $line ) ] = $line;
			}
		}

		return array_slice( array_values( $words ), 0, self::MAX_LIST );
	}

	/**
	 * Whether a name carries a link or web address, the way spam bots fill in
	 * name fields so their ad shows up in your emails.
	 *
	 * @param mixed $value  Field value.
	 * @param bool  $strict Only look for obvious links (http://, www.). For
	 *                      usernames and display names, which are often an
	 *                      email address and so contain a domain.
	 * @return bool
	 */
	public static function spam_name( $value, $strict = false ) {
		$value = is_scalar( $value ) ? substr( (string) $value, 0, 500 ) : '';

		if ( '' === trim( $value ) ) {
			return false;
		}

		// Links, link markup, and link shorteners and chat invites (t.me/...).
		if ( preg_match( '~(?:https?:|ftp:|://|www\.|<\s*a[\s>]|\[/?url|href\s*=|\b(?:t\.me|wa\.me|bit\.ly|tinyurl\.com|goo\.gl|cutt\.ly|is\.gd)/)~i', $value ) ) {
			return true;
		}

		if ( $strict ) {
			return false;
		}

		// A bare web address. Only TLDs that aren't also short words or name
		// endings, so "Ana.de Souza" or "J.R.R." can't trip it.
		return (bool) preg_match( '~(?:^|[^a-z0-9@.-])[a-z0-9-]{2,63}\.(?:com|net|org|info|biz|ru|cn|xyz|top|online|site|club|shop|store|link|click|tk|ml|ga|cf|gq|pw|ws|cc|su|icu|buzz|vip|bid|loan|space|website)(?:$|[^a-z0-9-])~i', $value );
	}

	/**
	 * Name fields that often hold an email address or username.
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	public static function is_strict_field( $field ) {
		return in_array( (string) $field, array( 'display_name', 'nickname', 'account_display_name', 'user_login', 'username' ), true );
	}

	/**
	 * Split a textarea list on newlines, commas and spaces.
	 *
	 * @param string $raw Raw list.
	 * @return string[]
	 */
	public static function split_list( $raw ) {
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

		return array_slice( is_array( $parts ) ? $parts : array(), 0, self::MAX_LIST * 2 );
	}

	/**
	 * Lowercase, multibyte-aware when possible.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}
}
