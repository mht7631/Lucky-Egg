<?php
/**
 * SMS abstraction layer and built-in gateways (Kavenegar, Melipayamak, SMS.ir).
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every gateway (built-in or custom via the "lucky_egg_sms_gateway" filter) must implement.
 */
interface Lucky_Egg_SMS_Gateway_Interface {

	/**
	 * Gateway ID.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Sends a message.
	 *
	 * @param string $phone   Canonical 09xxxxxxxxx phone.
	 * @param string $message Message.
	 * @return true|WP_Error
	 */
	public function send( $phone, $message );
}

/**
 * Shared HTTP helpers. Never exposes or logs request URLs/credentials.
 */
abstract class Lucky_Egg_SMS_Gateway_Base implements Lucky_Egg_SMS_Gateway_Interface {

	/**
	 * Global settings (server-side only).
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Setting accessor.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected function setting( $key ) {
		return isset( $this->settings[ $key ] ) ? trim( (string) $this->settings[ $key ] ) : '';
	}

	/**
	 * Performs a POST request and decodes JSON.
	 *
	 * @param string $url  URL.
	 * @param array  $args wp_remote_post args.
	 * @return array|WP_Error array( 'code' => int, 'body' => array|null )
	 */
	protected function post( $url, array $args ) {
		$args     = array_merge(
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'user-agent'  => 'LuckyEgg/' . LUCKY_EGG_VERSION . '; WordPress',
			),
			$args
		);
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sms_http', $this->get_id() . ' HTTP error: ' . $response->get_error_code() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => $code,
			'body' => is_array( $body ) ? $body : null,
		);
	}
}

/**
 * Kavenegar gateway.
 */
class Lucky_Egg_SMS_Kavenegar extends Lucky_Egg_SMS_Gateway_Base {

	/** {@inheritDoc} */
	public function get_id() {
		return 'kavenegar';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return __( 'کاوه‌نگار', 'lucky-egg' );
	}

	/** {@inheritDoc} */
	public function send( $phone, $message ) {
		$key = $this->setting( 'kavenegar_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'sms_config', 'Kavenegar API key is not configured.' );
		}

		$body = array(
			'receptor' => $phone,
			'message'  => $message,
		);
		if ( '' !== $this->setting( 'kavenegar_sender' ) ) {
			$body['sender'] = $this->setting( 'kavenegar_sender' );
		}

		$result = $this->post( 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json', array( 'body' => $body ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = isset( $result['body']['return']['status'] ) ? (int) $result['body']['return']['status'] : 0;
		if ( 200 === $status ) {
			return true;
		}
		$msg = isset( $result['body']['return']['message'] ) ? sanitize_text_field( (string) $result['body']['return']['message'] ) : '';
		return new WP_Error( 'sms_gateway', 'Kavenegar rejected (HTTP ' . $result['code'] . ', status ' . $status . ') ' . $msg );
	}
}

/**
 * Melipayamak REST gateway.
 */
class Lucky_Egg_SMS_Melipayamak extends Lucky_Egg_SMS_Gateway_Base {

	/** {@inheritDoc} */
	public function get_id() {
		return 'melipayamak';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return __( 'ملی پیامک', 'lucky-egg' );
	}

	/** {@inheritDoc} */
	public function send( $phone, $message ) {
		$username = $this->setting( 'melipayamak_username' );
		$password = $this->setting( 'melipayamak_password' );
		$from     = $this->setting( 'melipayamak_from' );
		if ( '' === $username || '' === $password || '' === $from ) {
			return new WP_Error( 'sms_config', 'Melipayamak credentials or sender line are not configured.' );
		}

		$result = $this->post(
			'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
			array(
				'body' => array(
					'username' => $username,
					'password' => $password,
					'to'       => $phone,
					'from'     => $from,
					'text'     => $message,
					'isflash'  => 'false',
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ret = isset( $result['body']['RetStatus'] ) ? (int) $result['body']['RetStatus'] : 0;
		if ( 1 === $ret ) {
			return true;
		}
		$msg = isset( $result['body']['StrRetStatus'] ) ? sanitize_text_field( (string) $result['body']['StrRetStatus'] ) : '';
		return new WP_Error( 'sms_gateway', 'Melipayamak rejected (HTTP ' . $result['code'] . ', RetStatus ' . $ret . ') ' . $msg );
	}
}

/**
 * SMS.ir (API v1) gateway.
 */
class Lucky_Egg_SMS_Smsir extends Lucky_Egg_SMS_Gateway_Base {

	/** {@inheritDoc} */
	public function get_id() {
		return 'smsir';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return 'SMS.ir';
	}

	/** {@inheritDoc} */
	public function send( $phone, $message ) {
		$key  = $this->setting( 'smsir_api_key' );
		$line = $this->setting( 'smsir_line_number' );
		if ( '' === $key || '' === $line ) {
			return new WP_Error( 'sms_config', 'SMS.ir API key or line number is not configured.' );
		}

		$line_value = ( ctype_digit( $line ) && PHP_INT_SIZE >= 8 ) ? (int) $line : $line;

		$result = $this->post(
			'https://api.sms.ir/v1/send/bulk',
			array(
				'headers' => array(
					'X-API-KEY'    => $key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'lineNumber'  => $line_value,
						'messageText' => $message,
						'mobiles'     => array( $phone ),
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = isset( $result['body']['status'] ) ? (int) $result['body']['status'] : 0;
		if ( 1 === $status ) {
			return true;
		}
		$msg = isset( $result['body']['message'] ) ? sanitize_text_field( (string) $result['body']['message'] ) : '';
		return new WP_Error( 'sms_gateway', 'SMS.ir rejected (HTTP ' . $result['code'] . ', status ' . $status . ') ' . $msg );
	}
}

/**
 * Class Lucky_Egg_SMS — gateway resolution, message building and dispatch.
 */
class Lucky_Egg_SMS {

	const CRON_HOOK    = 'lucky_egg_send_sms';
	const SWEEP_HOOK   = 'lucky_egg_sms_sweep';
	const MAX_ATTEMPTS = 3;
	const RETRY_DELAY  = 300;  // Seconds; multiplied by the attempt number.
	const PENDING_AGE  = 600;  // A "pending" row untouched this long lost its cron event.
	const SENDING_AGE  = 900;  // A "sending" row stuck this long lost its worker.
	const SWEEP_BATCH  = 50;

	/** @var Lucky_Egg_Entry */
	private $entries;

	/** @var Lucky_Egg_Campaign */
	private $campaigns;

	/**
	 * Constructor.
	 *
	 * @param Lucky_Egg_Entry    $entries   Entries.
	 * @param Lucky_Egg_Campaign $campaigns Campaigns.
	 */
	public function __construct( Lucky_Egg_Entry $entries, Lucky_Egg_Campaign $campaigns ) {
		$this->entries   = $entries;
		$this->campaigns = $campaigns;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'dispatch_entry' ), 10, 1 );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep' ) );
	}

	/**
	 * Gateway choices for the admin dropdown.
	 *
	 * @return array
	 */
	public function get_gateway_choices() {
		return (array) apply_filters(
			'lucky_egg_sms_gateway_choices',
			array(
				'kavenegar'   => __( 'کاوه‌نگار', 'lucky-egg' ),
				'melipayamak' => __( 'ملی پیامک', 'lucky-egg' ),
				'smsir'       => 'SMS.ir',
			)
		);
	}

	/**
	 * Resolves the active gateway. Custom gateways plug in through "lucky_egg_sms_gateway".
	 *
	 * @return Lucky_Egg_SMS_Gateway_Interface|null
	 */
	public function get_gateway() {
		$settings = Lucky_Egg::get_settings();
		$id       = (string) $settings['sms_gateway'];
		$gateway  = null;

		switch ( $id ) {
			case 'kavenegar':
				$gateway = new Lucky_Egg_SMS_Kavenegar( $settings );
				break;
			case 'melipayamak':
				$gateway = new Lucky_Egg_SMS_Melipayamak( $settings );
				break;
			case 'smsir':
				$gateway = new Lucky_Egg_SMS_Smsir( $settings );
				break;
		}

		$gateway = apply_filters( 'lucky_egg_sms_gateway', $gateway, $id, $settings );

		return ( $gateway instanceof Lucky_Egg_SMS_Gateway_Interface ) ? $gateway : null;
	}

	/**
	 * Whether SMS is enabled for a campaign (global switch AND campaign switch).
	 *
	 * @param array $campaign Campaign.
	 * @return bool
	 */
	public function is_enabled_for( array $campaign ) {
		$settings = Lucky_Egg::get_settings();
		return ! empty( $settings['sms_enabled'] ) && ! empty( $campaign['settings']['sms_enabled'] );
	}

	/**
	 * Builds the message. Supports {code}, {name}, {campaign}.
	 *
	 * @param array  $campaign Campaign.
	 * @param object $entry    Entry row.
	 * @return string
	 */
	public function build_message( array $campaign, $entry ) {
		$settings = Lucky_Egg::get_settings();
		$template = '' !== trim( (string) $campaign['settings']['sms_template'] ) ? $campaign['settings']['sms_template'] : $settings['sms_template'];
		if ( false === strpos( $template, '{code}' ) ) {
			$template .= "\n{code}";
		}
		return strtr(
			$template,
			array(
				'{code}'     => (string) $entry->code,
				'{name}'     => self::sms_safe_name( (string) $entry->name ),
				'{campaign}' => (string) $campaign['title'],
			)
		);
	}

	/**
	 * The name is visitor-supplied and would be sent from the site's own sender line to an
	 * arbitrary number. Strip anything link-like so it cannot be abused for SMS phishing.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function sms_safe_name( $name ) {
		$name = preg_replace( '~\S*(?:https?:|www\.|[./@:])\S*~iu', '', (string) $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) );
		return (string) apply_filters( 'lucky_egg_sms_name', $name );
	}

	/**
	 * Queues the SMS through WP-Cron.
	 *
	 * WP-Cron is NOT real-time: it only runs on a later page load (or a system cron when
	 * DISABLE_WP_CRON is set), so delivery may be delayed. The entry stays "pending" until a
	 * worker claims it, and the hourly sweeper re-queues anything whose event was lost.
	 * The code shown to the visitor never depends on this.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $delay    Seconds from now.
	 */
	public function schedule( $entry_id, $delay = 0 ) {
		$args = array( (int) $entry_id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::CRON_HOOK, $args );
		}
		if ( 0 === (int) $delay && function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/**
	 * Sends the SMS of an entry. Idempotent: only a "pending" entry can be claimed and sent.
	 * Failure never touches the entry or its code; only the SMS state changes:
	 *   pending -> sending -> sent
	 *                      -> pending (transient failure, retried with back-off)
	 *                      -> failed  (configuration error or attempts exhausted)
	 *
	 * @param int $entry_id Entry ID.
	 */
	public function dispatch_entry( $entry_id ) {
		$entry = $this->entries->get( $entry_id );
		if ( ! $entry || Lucky_Egg_Entry::SMS_PENDING !== $entry->sms_status ) {
			return;
		}

		$campaign = $this->campaigns->get( (int) $entry->campaign_id );
		if ( ! $campaign || ! $this->is_enabled_for( $campaign ) ) {
			$this->entries->update_sms_status( $entry->id, Lucky_Egg_Entry::SMS_DISABLED );
			return;
		}

		if ( ! $this->entries->claim_sms( $entry->id ) ) {
			return;
		}
		$attempt = (int) $entry->sms_attempts + 1;

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			$this->entries->update_sms_status( $entry->id, Lucky_Egg_Entry::SMS_FAILED );
			Lucky_Egg_Logger::error( 'SMS failed: no valid gateway configured.', array( 'entry_id' => (int) $entry->id ) );
			return;
		}

		try {
			$result = $gateway->send( (string) $entry->phone, $this->build_message( $campaign, $entry ) );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'sms_exception', get_class( $e ) . ': ' . $e->getMessage() );
		}

		if ( true === $result ) {
			$this->entries->update_sms_status( $entry->id, Lucky_Egg_Entry::SMS_SENT );
			return;
		}

		$error_code = is_wp_error( $result ) ? $result->get_error_code() : 'unknown';
		$retry      = 'sms_config' !== $error_code && $attempt < self::MAX_ATTEMPTS;

		if ( $retry ) {
			$this->entries->update_sms_status( $entry->id, Lucky_Egg_Entry::SMS_PENDING );
			$this->schedule( (int) $entry->id, self::RETRY_DELAY * $attempt );
		} else {
			$this->entries->update_sms_status( $entry->id, Lucky_Egg_Entry::SMS_FAILED );
		}

		Lucky_Egg_Logger::error(
			$retry ? 'SMS send failed; retry scheduled.' : 'SMS send failed permanently.',
			array(
				'entry_id' => (int) $entry->id,
				'gateway'  => $gateway->get_id(),
				'attempt'  => $attempt,
				'error'    => is_wp_error( $result ) ? $result->get_error_message() : 'unknown',
			)
		);
	}

	/**
	 * Hourly sweeper: recovers entries whose cron event was lost or whose worker died.
	 */
	public function sweep() {
		$rows = $this->entries->get_sms_stale( self::PENDING_AGE, self::SENDING_AGE, self::SWEEP_BATCH );
		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			if ( Lucky_Egg_Entry::SMS_SENDING === $row->sms_status ) {
				if ( (int) $row->sms_attempts >= self::MAX_ATTEMPTS ) {
					$this->entries->update_sms_status( $id, Lucky_Egg_Entry::SMS_FAILED );
					Lucky_Egg_Logger::warning( 'SMS marked failed after a stuck final attempt.', array( 'entry_id' => $id ) );
					continue;
				}
				if ( ! $this->entries->requeue_stuck_sms( $id ) ) {
					continue;
				}
			}
			$this->schedule( $id, 0 === (int) $row->sms_attempts ? 0 : 60 );
		}
	}

	/**
	 * Sends an admin test message.
	 *
	 * @param string $phone Canonical phone.
	 * @return true|WP_Error
	 */
	public function send_test( $phone ) {
		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'sms_config', 'No valid gateway configured.' );
		}
		try {
			$result = $gateway->send( $phone, __( 'پیامک آزمایشی افزونه تخم‌مرغ شانسی', 'lucky-egg' ) );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'sms_exception', get_class( $e ) . ': ' . $e->getMessage() );
		}
		if ( true !== $result ) {
			Lucky_Egg_Logger::error(
				'Test SMS failed.',
				array(
					'gateway' => $gateway->get_id(),
					'error'   => is_wp_error( $result ) ? $result->get_error_message() : 'unknown',
				)
			);
			return is_wp_error( $result ) ? $result : new WP_Error( 'sms_failed', 'unknown' );
		}
		return true;
	}
}
