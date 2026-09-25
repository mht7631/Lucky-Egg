<?php
/**
 * AJAX endpoints.
 *
 * Identity model: WordPress is the only authentication system.
 *   - Logged-in visitor: identified exclusively by is_user_logged_in() / get_current_user_id();
 *     the entry is owned by that user_id. Name/phone come from the WP (or WooCommerce) profile.
 *   - Guest (when the campaign allows guests): plays as a guest. Name/phone are collected,
 *     validated and kept in a short-lived server-side guest session bound to the visitor
 *     cookie. No WordPress account is created and nobody is ever logged in by this plugin.
 *     A phone number is contact data and a rate-limit key, never a credential.
 *   - Guest on a members-only campaign: told to log in through the standard WordPress login
 *     (login_url is returned). After login the page reloads and WordPress state is re-read.
 *
 * Contract (all endpoints, POST to admin-ajax.php):
 *   Common input : action, nonce (action "lucky_egg_front"), campaign (campaign key)
 *   Success      : { "success": true,  "data": { "step": "...", ... } }
 *   Error        : { "success": false, "data": { "code": "...", "message": "Persian text", ["retry_at": int], ["login_url": string] } }
 *
 *   lucky_egg_nonce    -> { nonce } (fresh nonce for pages served from a full-page cache)
 *   lucky_egg_prepare  -> step "collect-user" (+prefill.name) | "ready"
 *   lucky_egg_register -> input name, phone -> step "ready"
 *   lucky_egg_crack    -> step "result", code, sms ("queued"|"disabled")
 *
 *   Error codes: invalid_nonce, campaign_unavailable, not_allowed, login_required, rate_limited,
 *   invalid_name, invalid_phone, registration_throttled, info_required, phone_required,
 *   busy, server_error.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Ajax
 */
class Lucky_Egg_Ajax {

	const NONCE_ACTION      = 'lucky_egg_front';
	const ACTION_PREPARE    = 'lucky_egg_prepare';
	const ACTION_REGISTER   = 'lucky_egg_register';
	const ACTION_CRACK      = 'lucky_egg_crack';
	const ACTION_NONCE      = 'lucky_egg_nonce';
	const GUEST_SESSION_TTL = 1800;

	/** @var Lucky_Egg_Campaign */
	private $campaigns;

	/** @var Lucky_Egg_Entry */
	private $entries;

	/** @var Lucky_Egg_Rate_Limiter */
	private $limiter;

	/** @var Lucky_Egg_User */
	private $users;

	/** @var Lucky_Egg_SMS */
	private $sms;

	/**
	 * Constructor.
	 *
	 * @param Lucky_Egg_Campaign     $campaigns Campaigns.
	 * @param Lucky_Egg_Entry        $entries   Entries.
	 * @param Lucky_Egg_Rate_Limiter $limiter   Rate limiter.
	 * @param Lucky_Egg_User         $users     Users.
	 * @param Lucky_Egg_SMS          $sms       SMS.
	 */
	public function __construct( Lucky_Egg_Campaign $campaigns, Lucky_Egg_Entry $entries, Lucky_Egg_Rate_Limiter $limiter, Lucky_Egg_User $users, Lucky_Egg_SMS $sms ) {
		$this->campaigns = $campaigns;
		$this->entries   = $entries;
		$this->limiter   = $limiter;
		$this->users     = $users;
		$this->sms       = $sms;
	}

	/**
	 * Hooks (same handler for logged-in and guest; the handler decides server-side from WordPress).
	 */
	public function register() {
		$map = array(
			self::ACTION_PREPARE  => 'handle_prepare',
			self::ACTION_REGISTER => 'handle_register',
			self::ACTION_CRACK    => 'handle_crack',
			self::ACTION_NONCE    => 'handle_nonce',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------- */

	/**
	 * Returns a fresh nonce for the CURRENT WordPress session. Needed when the page (and the
	 * nonce embedded in it) was served from a full-page cache. The response is same-origin
	 * only (admin-ajax sends no CORS headers) and admin-ajax sends no-cache headers.
	 */
	public function handle_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * First click: decides whether contact info is needed or the egg can crack.
	 */
	public function handle_prepare() {
		$this->verify_nonce();
		$campaign = $this->resolve_campaign();
		$user_id  = $this->current_user_id();

		$this->check_visibility( $campaign, $user_id );
		$this->limiter->get_visitor_token();

		if ( $user_id ) {
			$profile = $this->users->get_profile( $user_id );
			$this->enforce_rate_limit( $campaign, $user_id, $profile['phone'] );

			if ( ! Lucky_Egg_User::is_valid_phone( $profile['phone'] ) ) {
				wp_send_json_success(
					array(
						'step'    => 'collect-user',
						'prefill' => array( 'name' => $profile['name'] ),
					)
				);
			}
			wp_send_json_success( array( 'step' => 'ready' ) );
		}

		$session = $this->get_guest_session( (int) $campaign['id'] );
		$this->enforce_rate_limit( $campaign, 0, $session ? $session['phone'] : '' );

		if ( $session ) {
			wp_send_json_success( array( 'step' => 'ready' ) );
		}

		wp_send_json_success(
			array(
				'step'    => 'collect-user',
				'prefill' => array( 'name' => '' ),
			)
		);
	}

	/**
	 * Modal submit: validates name/phone.
	 *   Logged-in user without a phone: stores it on their own profile.
	 *   Guest: stores it in a server-side guest session. No account, no login.
	 */
	public function handle_register() {
		$this->verify_nonce();
		$campaign = $this->resolve_campaign();
		$user_id  = $this->current_user_id();

		$this->check_visibility( $campaign, $user_id );
		$this->limiter->get_visitor_token();

		if ( ! $user_id && $this->users->is_guest_submission_throttled( $this->limiter->get_rate_ip() ) ) {
			$this->error( 'registration_throttled', __( 'تعداد ثبت‌نام‌ها از این شبکه زیاد است. لطفاً کمی بعد دوباره تلاش کنید.', 'lucky-egg' ), 429 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify_nonce().
		$name  = Lucky_Egg_User::sanitize_name( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );
		$phone = Lucky_Egg_User::normalize_phone( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '' );
		// phpcs:enable

		if ( ! Lucky_Egg_User::is_valid_name( $name ) ) {
			$this->error( 'invalid_name', __( 'لطفاً نام خود را به‌درستی وارد کنید (۲ تا ۶۰ حرف).', 'lucky-egg' ), 422 );
		}
		if ( ! Lucky_Egg_User::is_valid_phone( $phone ) ) {
			$this->error( 'invalid_phone', __( 'شماره موبایل معتبر نیست. نمونه صحیح: ۰۹۱۲۳۴۵۶۷۸۹', 'lucky-egg' ), 422 );
		}

		$this->enforce_rate_limit( $campaign, $user_id, $phone );

		if ( $user_id ) {
			if ( ! $this->users->save_contact( $user_id, $phone, $name ) ) {
				$this->error( 'server_error', __( 'ثبت اطلاعات با مشکل مواجه شد. لطفاً دوباره تلاش کنید.', 'lucky-egg' ), 500 );
			}
			wp_send_json_success( array( 'step' => 'ready' ) );
		}

		$this->users->bump_guest_submission( $this->limiter->get_rate_ip() );
		$this->set_guest_session( (int) $campaign['id'], $name, $phone );

		wp_send_json_success( array( 'step' => 'ready' ) );
	}

	/**
	 * Second click: creates the entry and returns the code. SMS never blocks or hides the code.
	 */
	public function handle_crack() {
		global $wpdb;

		$this->verify_nonce();
		$campaign    = $this->resolve_campaign();
		$user_id     = $this->current_user_id();
		$campaign_id = (int) $campaign['id'];

		$this->check_visibility( $campaign, $user_id );
		$this->limiter->get_visitor_token();

		if ( $user_id ) {
			$contact = $this->users->get_profile( $user_id );
			if ( ! Lucky_Egg_User::is_valid_phone( $contact['phone'] ) ) {
				$this->error( 'phone_required', __( 'لطفاً ابتدا شماره موبایل خود را وارد کنید.', 'lucky-egg' ), 422 );
			}
		} else {
			$contact = $this->get_guest_session( $campaign_id );
			if ( ! $contact ) {
				$this->error( 'info_required', __( 'اطلاعات شما یافت نشد یا منقضی شده است. لطفاً دوباره نام و شماره موبایل را وارد کنید (کوکی مرورگر باید فعال باشد).', 'lucky-egg' ), 422 );
			}
		}

		$ip = $this->limiter->get_ip();

		if ( ! $this->limiter->acquire_lock( $campaign_id ) ) {
			$this->error( 'busy', __( 'سرور مشغول است؛ چند لحظه دیگر دوباره تلاش کنید.', 'lucky-egg' ), 503 );
		}

		$check = $this->limiter->check( $campaign, $user_id, $contact['phone'] );
		if ( ! $check['allowed'] ) {
			$this->limiter->release_lock( $campaign_id );
			if ( $check['error'] ) {
				$this->error( 'server_error', __( 'خطای موقت سرور. لطفاً دوباره تلاش کنید.', 'lucky-egg' ), 500 );
			}
			$this->rate_limited_error( $check['retry_at'] );
		}

		$sms_enabled = $this->sms->is_enabled_for( $campaign );
		$entry       = null;

		Lucky_Egg_Logger::defer();

		try {
			$wpdb->query( 'START TRANSACTION' );

			$entry = $this->entries->create_entry(
				$campaign,
				$user_id,
				$contact['name'],
				$contact['phone'],
				$ip,
				$sms_enabled ? Lucky_Egg_Entry::SMS_PENDING : Lucky_Egg_Entry::SMS_DISABLED
			);

			if ( ! is_wp_error( $entry ) && ! $this->limiter->record( $campaign_id, $user_id, $entry['id'], $contact['phone'] ) ) {
				$entry = new WP_Error( 'record_failed', 'record_failed' );
			}
		} catch ( Throwable $e ) {
			Lucky_Egg_Logger::error( 'Crack failed with an exception.', array( 'error' => get_class( $e ) . ': ' . $e->getMessage() ) );
			$entry = new WP_Error( 'exception', 'exception' );
		}

		if ( ! is_array( $entry ) ) {
			$wpdb->query( 'ROLLBACK' );
			Lucky_Egg_Logger::flush();
			$this->limiter->release_lock( $campaign_id );
			$this->error( 'server_error', __( 'ثبت نتیجه با مشکل مواجه شد. لطفاً دوباره تلاش کنید.', 'lucky-egg' ), 500 );
		}

		$wpdb->query( 'COMMIT' );
		Lucky_Egg_Logger::flush();
		$this->limiter->release_lock( $campaign_id );

		if ( ! $user_id ) {
			$this->delete_guest_session( $campaign_id );
		}

		do_action( 'lucky_egg_entry_created', $entry['id'], $campaign, $user_id );

		$data = array(
			'step' => 'result',
			'code' => $entry['code'],
			'sms'  => $sms_enabled ? 'queued' : 'disabled',
		);

		$this->respond_then_dispatch( $data, $sms_enabled ? $entry['id'] : 0 );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Current WordPress user (0 for guests). WordPress is the single source of truth.
	 *
	 * @return int
	 */
	private function current_user_id() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}
		$user = wp_get_current_user();
		return ( $user instanceof WP_User && $user->exists() ) ? (int) $user->ID : 0;
	}

	/**
	 * Sends the success response first, then the SMS (when the SAPI allows early flush);
	 * otherwise queues SMS via WP-Cron. The code is never delayed or hidden by the gateway.
	 *
	 * @param array $data     Response data.
	 * @param int   $entry_id Entry ID (0 = no SMS).
	 */
	private function respond_then_dispatch( array $data, $entry_id ) {
		if ( ! $entry_id ) {
			wp_send_json_success( $data );
		}

		$finisher = '';
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$finisher = 'fastcgi_finish_request';
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			$finisher = 'litespeed_finish_request';
		}

		if ( '' === $finisher || ! apply_filters( 'lucky_egg_sms_after_response', true ) ) {
			$this->sms->schedule( $entry_id );
			wp_send_json_success( $data );
		}

		$json = wp_json_encode(
			array(
				'success' => true,
				'data'    => $data,
			)
		);

		ignore_user_abort( true );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		}
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode.
		flush();
		call_user_func( $finisher );

		// If this worker dies now, the entry stays "pending" and the hourly sweeper re-queues it.
		$this->sms->dispatch_entry( $entry_id );
		exit;
	}

	/**
	 * Nonce check with a JSON (not "-1") failure response.
	 */
	private function verify_nonce() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->error( 'invalid_nonce', __( 'نشست شما منقضی شده است. لطفاً صفحه را دوباره بارگذاری کنید.', 'lucky-egg' ), 403 );
		}
	}

	/**
	 * Resolves the campaign on the server from its key.
	 *
	 * @return array
	 */
	private function resolve_campaign() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce().
		$key      = isset( $_POST['campaign'] ) ? sanitize_key( wp_unslash( $_POST['campaign'] ) ) : '';
		$campaign = '' !== $key ? $this->campaigns->get_by_key( $key ) : null;

		if ( ! Lucky_Egg_Campaign::is_active( $campaign ) ) {
			$this->error( 'campaign_unavailable', __( 'این کمپین در حال حاضر فعال نیست.', 'lucky-egg' ), 404 );
		}
		return $campaign;
	}

	/**
	 * Enforces the campaign visibility rule.
	 *
	 * @param array $campaign Campaign.
	 * @param int   $user_id  User ID.
	 */
	private function check_visibility( array $campaign, $user_id ) {
		$visibility = $campaign['settings']['visibility'];

		if ( 'members' === $visibility && ! $user_id ) {
			$this->error(
				'login_required',
				__( 'این کمپین فقط برای اعضای سایت است. لطفاً ابتدا وارد حساب کاربری خود شوید.', 'lucky-egg' ),
				401,
				array( 'login_url' => $this->login_url() )
			);
		}

		if ( 'guests' === $visibility && $user_id ) {
			$this->error( 'not_allowed', __( 'این کمپین فقط برای کاربران مهمان است.', 'lucky-egg' ), 403 );
		}
	}

	/**
	 * Standard WordPress login URL that returns the visitor to the page they came from.
	 *
	 * @return string
	 */
	private function login_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce().
		$raw      = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		$redirect = wp_validate_redirect( $raw, home_url( '/' ) );
		return esc_url_raw( (string) apply_filters( 'lucky_egg_login_url', wp_login_url( $redirect ), $redirect ) );
	}

	/**
	 * Enforces rate limit or exits with an error (advisory; the locked check in crack is authoritative).
	 *
	 * @param array  $campaign Campaign.
	 * @param int    $user_id  User ID.
	 * @param string $phone    Phone when known.
	 */
	private function enforce_rate_limit( array $campaign, $user_id, $phone = '' ) {
		$check = $this->limiter->check( $campaign, $user_id, $phone );
		if ( $check['allowed'] ) {
			return;
		}
		if ( $check['error'] ) {
			$this->error( 'server_error', __( 'خطای موقت سرور. لطفاً دوباره تلاش کنید.', 'lucky-egg' ), 500 );
		}
		$this->rate_limited_error( $check['retry_at'] );
	}

	/**
	 * Rate-limited error response.
	 *
	 * @param int $retry_at Unix timestamp.
	 */
	private function rate_limited_error( $retry_at ) {
		$when = $retry_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $retry_at ) : '';
		$msg  = $when
			/* translators: %s: date and time of the next chance */
			? sprintf( __( 'شما قبلاً در این کمپین شرکت کرده‌اید. فرصت بعدی شما: %s', 'lucky-egg' ), $when )
			: __( 'شما قبلاً در این کمپین شرکت کرده‌اید.', 'lucky-egg' );

		$this->error( 'rate_limited', $msg, 429, array( 'retry_at' => (int) $retry_at ) );
	}

	/* ---------------------------------------------------------------------
	 * Guest session (server-side; the browser only holds the random visitor cookie)
	 * ------------------------------------------------------------------- */

	/**
	 * Transient key bound to visitor cookie + campaign. Unguessable without the cookie.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	private function guest_session_key( $campaign_id ) {
		$hash = hash_hmac( 'sha256', $this->limiter->get_visitor_token() . '|' . (int) $campaign_id, wp_salt( 'nonce' ) );
		return 'lucky_egg_gs_' . substr( $hash, 0, 40 );
	}

	/**
	 * Stores validated guest contact info.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $name        Name.
	 * @param string $phone       Phone.
	 */
	private function set_guest_session( $campaign_id, $name, $phone ) {
		set_transient(
			$this->guest_session_key( $campaign_id ),
			array(
				'name'  => (string) $name,
				'phone' => (string) $phone,
			),
			self::GUEST_SESSION_TTL
		);
	}

	/**
	 * Reads (and re-validates) the guest session. Requires the visitor cookie from the request.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null name, phone
	 */
	private function get_guest_session( $campaign_id ) {
		if ( ! $this->limiter->has_visitor_cookie() ) {
			return null;
		}
		$data = get_transient( $this->guest_session_key( $campaign_id ) );
		if ( ! is_array( $data ) || ! isset( $data['name'], $data['phone'] ) ) {
			return null;
		}
		$name  = Lucky_Egg_User::sanitize_name( $data['name'] );
		$phone = Lucky_Egg_User::normalize_phone( $data['phone'] );
		if ( ! Lucky_Egg_User::is_valid_name( $name ) || ! Lucky_Egg_User::is_valid_phone( $phone ) ) {
			return null;
		}
		return array(
			'name'  => $name,
			'phone' => $phone,
		);
	}

	/**
	 * Consumes the guest session (single use: prevents replaying the same submission).
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	private function delete_guest_session( $campaign_id ) {
		delete_transient( $this->guest_session_key( $campaign_id ) );
	}

	/**
	 * Uniform error response (exits).
	 *
	 * @param string $code    Machine code.
	 * @param string $message Persian user-facing message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra safe data.
	 */
	private function error( $code, $message, $status = 400, array $extra = array() ) {
		wp_send_json_error(
			array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
			$status
		);
	}
}
