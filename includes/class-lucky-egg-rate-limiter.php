<?php
/**
 * Server-side, multi-layer rate limiting (cookie + IP + user ID + phone).
 *
 * Added file: separates rate-limit policy from entry persistence and AJAX transport.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Rate_Limiter
 */
class Lucky_Egg_Rate_Limiter {

	const COOKIE         = 'lucky_egg_vid';
	const RETENTION_DAYS = 400;

	/**
	 * Visitor token for this request.
	 *
	 * @var string|null
	 */
	private $token = null;

	/**
	 * Whether the token came from the request cookie (true) or was generated now (false).
	 *
	 * @var bool
	 */
	private $token_from_request = false;

	/**
	 * Named locks currently held by this request (lock name => true).
	 *
	 * @var array
	 */
	private $held_locks = array();

	/**
	 * Whether the shutdown safety net is registered.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'lucky_egg_rate_limits';
	}

	/**
	 * Resolves client IP. Proxy headers are only honored when explicitly enabled by the admin.
	 *
	 * @return string
	 */
	public function get_ip() {
		$ip       = '';
		$settings = Lucky_Egg::get_settings();

		if ( ! empty( $settings['trust_proxy'] ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$parts = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) );
				/*
				 * X-Forwarded-For: the LEFTMOST value is fully client-controlled. The rightmost
				 * value is the one appended by the (single, trusted) proxy in front of the site.
				 */
				$candidate = 'HTTP_X_FORWARDED_FOR' === $header ? (string) end( $parts ) : (string) reset( $parts );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		$filtered = (string) apply_filters( 'lucky_egg_client_ip', $ip );
		return filter_var( $filtered, FILTER_VALIDATE_IP ) ? $filtered : $ip;
	}

	/**
	 * IP key used for rate limiting. IPv6 clients usually own a whole /64 and can rotate
	 * addresses inside it at will, so IPv6 is grouped by its /64 prefix.
	 *
	 * @return string
	 */
	public function get_rate_ip() {
		$ip = $this->get_ip();
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		// IPv4-mapped (::ffff:a.b.c.d): rate-limit by the embedded IPv4 address.
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$v4 = inet_ntop( substr( $packed, 12 ) );
			return false === $v4 ? $ip : $v4;
		}
		$prefix = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
		return false === $prefix ? $ip : $prefix . '/64';
	}

	/**
	 * Whether the request already carried a valid visitor cookie (before this request set one).
	 *
	 * @return bool
	 */
	public function has_visitor_cookie() {
		$this->get_visitor_token();
		return $this->token_from_request;
	}

	/**
	 * Returns (and sets if missing) the random visitor token cookie.
	 *
	 * @return string
	 */
	public function get_visitor_token() {
		if ( null !== $this->token ) {
			return $this->token;
		}

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( preg_match( '/^[a-f0-9]{32}$/', $raw ) ) {
			$this->token              = $raw;
			$this->token_from_request = true;
			return $this->token;
		}

		try {
			$this->token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$this->token = md5( wp_generate_password( 64, true, true ) );
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$this->token,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $this->token;

		return $this->token;
	}

	/**
	 * HMAC of the visitor token (raw token is never stored).
	 *
	 * @return string
	 */
	public function get_cookie_hash() {
		return hash_hmac( 'sha256', $this->get_visitor_token(), wp_salt( 'nonce' ) );
	}

	/**
	 * Window length in seconds.
	 *
	 * @param array $campaign Campaign.
	 * @return int
	 */
	public function get_window_seconds( array $campaign ) {
		$period = max( 1, (int) $campaign['settings']['rate_limit_period'] );
		$unit   = 'days' === $campaign['settings']['rate_limit_unit'] ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		return $period * $unit;
	}

	/**
	 * Builds "identity OR" clause: cookie hash, IP key, user ID (when logged in) and phone.
	 * A match on ANY identity counts, so deleting the cookie, changing IP or switching
	 * between guest/member alone does not bypass the limit.
	 * Empty/NULL values are never matched because each condition is only added for a
	 * non-empty value and uses equality.
	 *
	 * @param int    $user_id User ID.
	 * @param string $phone   Canonical phone ('' = unknown).
	 * @return array array( string $sql, array $params )
	 */
	private function identity_clause( $user_id, $phone ) {
		$conds  = array( 'cookie_hash = %s' );
		$params = array( $this->get_cookie_hash() );

		$ip = $this->get_rate_ip();
		if ( '' !== $ip ) {
			$conds[]  = 'ip = %s';
			$params[] = $ip;
		}
		if ( $user_id > 0 ) {
			$conds[]  = 'user_id = %d';
			$params[] = (int) $user_id;
		}
		if ( Lucky_Egg_User::is_valid_phone( $phone ) ) {
			$conds[]  = 'phone = %s';
			$params[] = $phone;
		}
		return array( '(' . implode( ' OR ', $conds ) . ')', $params );
	}

	/**
	 * Checks whether the current visitor may crack in this campaign.
	 *
	 * @param array  $campaign Campaign.
	 * @param int    $user_id  User ID (0 for guests).
	 * @param string $phone    Canonical phone when known.
	 * @return array allowed(bool), retry_at(int), error(bool)
	 */
	public function check( array $campaign, $user_id, $phone = '' ) {
		global $wpdb;

		$limit  = max( 1, (int) $campaign['settings']['rate_limit_count'] );
		$window = $this->get_window_seconds( $campaign );
		$since  = gmdate( 'Y-m-d H:i:s', time() - $window );

		list( $clause, $params ) = $this->identity_clause( (int) $user_id, (string) $phone );
		$base_params             = array_merge( array( (int) $campaign['id'], $since ), $params );
		$table                   = $this->table();

		$hits = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND last_crack > %s AND {$clause}", $base_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $hits && '' !== $wpdb->last_error ) {
			Lucky_Egg_Logger::error( 'Rate limit lookup failed.', array( 'db_error' => $wpdb->last_error ) );
			return array(
				'allowed'  => false,
				'retry_at' => 0,
				'error'    => true,
			);
		}

		$hits = (int) $hits;
		if ( $hits < $limit ) {
			return array(
				'allowed'  => true,
				'retry_at' => 0,
				'error'    => false,
			);
		}

		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT last_crack FROM {$table} WHERE campaign_id = %d AND last_crack > %s AND {$clause} ORDER BY last_crack ASC LIMIT 1 OFFSET %d", array_merge( $base_params, array( $hits - $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$retry = $oldest ? strtotime( $oldest . ' UTC' ) + $window : time() + $window;

		return array(
			'allowed'  => false,
			'retry_at' => (int) $retry,
			'error'    => false,
		);
	}

	/**
	 * Records a crack.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $user_id     User ID.
	 * @param int    $entry_id    Entry ID.
	 * @param string $phone       Canonical phone.
	 * @return bool
	 */
	public function record( $campaign_id, $user_id, $entry_id, $phone = '' ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->insert(
			$this->table(),
			array(
				'campaign_id' => absint( $campaign_id ),
				'user_id'     => $user_id > 0 ? (int) $user_id : null,
				'ip'          => $this->get_rate_ip(),
				'cookie_hash' => $this->get_cookie_hash(),
				'phone'       => Lucky_Egg_User::is_valid_phone( $phone ) ? $phone : '',
				'entry_id'    => absint( $entry_id ),
				'last_crack'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( ! $result ) {
			Lucky_Egg_Logger::error( 'Rate limit record failed.', array( 'db_error' => $error ) );
			return false;
		}
		return true;
	}

	/**
	 * Acquires a per-campaign MySQL named lock so that check+insert is serialized.
	 *
	 * The lock lives on $wpdb's single connection (same session as the following queries).
	 * It is released explicitly by the caller on every path, by a shutdown safety net if the
	 * request dies in between, and by MySQL itself when the connection closes.
	 * Note: with read/write-splitting DB drop-ins (HyperDB/LudicrousDB) route GET_LOCK to the
	 * primary, or disable it with the "lucky_egg_use_db_lock" filter at your own risk.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $timeout     Seconds to wait.
	 * @return bool
	 */
	public function acquire_lock( $campaign_id, $timeout = 5 ) {
		global $wpdb;
		if ( ! apply_filters( 'lucky_egg_use_db_lock', true ) ) {
			return true;
		}
		$name = $this->lock_name( $campaign_id );
		if ( isset( $this->held_locks[ $name ] ) ) {
			return true;
		}
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, (int) $timeout ) );
		if ( '1' !== (string) $result ) {
			Lucky_Egg_Logger::warning( 'Could not acquire crack lock.', array( 'campaign_id' => (int) $campaign_id ) );
			return false;
		}
		$this->held_locks[ $name ] = true;
		if ( ! $this->shutdown_registered ) {
			$this->shutdown_registered = true;
			register_shutdown_function( array( $this, 'release_all_locks' ) );
		}
		return true;
	}

	/**
	 * Releases the named lock (no-op when not held by this request).
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function release_lock( $campaign_id ) {
		global $wpdb;
		$name = $this->lock_name( $campaign_id );
		if ( ! isset( $this->held_locks[ $name ] ) ) {
			return;
		}
		unset( $this->held_locks[ $name ] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Shutdown safety net: rolls back an unfinished transaction and releases leftover locks
	 * (relevant with persistent DB connections, where a lock would otherwise outlive the request).
	 */
	public function release_all_locks() {
		global $wpdb;
		if ( empty( $this->held_locks ) || ! $wpdb ) {
			return;
		}
		$wpdb->query( 'ROLLBACK' );
		foreach ( array_keys( $this->held_locks ) as $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		$this->held_locks = array();
	}

	/**
	 * Lock name unique per database/prefix/campaign (max 64 chars).
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	private function lock_name( $campaign_id ) {
		global $wpdb;
		return 'lucky_egg_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . absint( $campaign_id ) );
	}

	/**
	 * Deletes rate-limit rows of a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool
	 */
	public function delete_by_campaign( $campaign_id ) {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'campaign_id' => absint( $campaign_id ) ), array( '%d' ) );
	}

	/**
	 * Removes rows older than the maximum possible window.
	 */
	public function cleanup() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE last_crack < %s LIMIT 10000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
