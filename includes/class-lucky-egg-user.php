<?php
/**
 * Identity helpers: phone normalization, profile lookup and guest-submission throttling.
 *
 * Authentication is delegated entirely to WordPress. This class never creates accounts,
 * never logs anyone in and never treats a phone number as a credential.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_User
 */
class Lucky_Egg_User {

	const META_PHONE = 'lucky_egg_phone';
	const META_NAME  = 'lucky_egg_name';

	/**
	 * Legacy (<= 1.0.0) marker of accounts auto-created by the plugin. Read only by the migration.
	 */
	const META_CREATED = 'lucky_egg_created';

	/**
	 * Converts Persian and Arabic-Indic digits to ASCII digits.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function normalize_digits( $value ) {
		$english = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		return str_replace( $arabic, $english, str_replace( $persian, $english, (string) $value ) );
	}

	/**
	 * Normalizes an Iranian mobile number to canonical 09xxxxxxxxx (may still be invalid).
	 *
	 * @param mixed $raw Raw input.
	 * @return string
	 */
	public static function normalize_phone( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$phone = self::normalize_digits( (string) $raw );
		$phone = preg_replace( '/[\s\-\.\(\)\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $phone );
		if ( null === $phone ) {
			return '';
		}

		if ( 0 === strpos( $phone, '+98' ) ) {
			$phone = '0' . substr( $phone, 3 );
		} elseif ( 0 === strpos( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( preg_match( '/^98\d{10}$/', $phone ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( preg_match( '/^9\d{9}$/', $phone ) ) {
			$phone = '0' . $phone;
		}

		return $phone;
	}

	/**
	 * Validates canonical phone.
	 *
	 * @param string $phone Phone.
	 * @return bool
	 */
	public static function is_valid_phone( $phone ) {
		return is_string( $phone ) && 1 === preg_match( '/^09\d{9}$/', $phone );
	}

	/**
	 * Sanitizes a display name.
	 *
	 * @param mixed $raw Raw.
	 * @return string
	 */
	public static function sanitize_name( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$name = sanitize_text_field( (string) $raw );
		$name = preg_replace( '/\s+/u', ' ', $name );
		return trim( (string) $name );
	}

	/**
	 * Validates name length (2..60 characters).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		$name   = (string) $name;
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		return $length >= 2 && $length <= 60 && ! preg_match( '/^\d+$/', $name );
	}

	/**
	 * Returns the name/phone of a logged-in WordPress user.
	 * Sources, in order: Lucky Egg meta, WooCommerce billing fields, WordPress profile.
	 *
	 * @param int $user_id User ID.
	 * @return array name, phone
	 */
	public function get_profile( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array(
				'name'  => '',
				'phone' => '',
			);
		}

		$phone = self::normalize_phone( (string) get_user_meta( $user_id, self::META_PHONE, true ) );
		if ( ! self::is_valid_phone( $phone ) ) {
			// WooCommerce stores the customer's phone in billing_phone.
			$billing = self::normalize_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );
			$phone   = self::is_valid_phone( $billing ) ? $billing : '';
		}

		$name = (string) get_user_meta( $user_id, self::META_NAME, true );
		if ( '' === trim( $name ) ) {
			$name = trim( $user->first_name . ' ' . $user->last_name );
		}
		if ( '' === $name ) {
			$name = trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'billing_last_name', true ) );
		}
		if ( '' === $name ) {
			$name = (string) $user->display_name;
		}

		$profile = array(
			'name'  => self::sanitize_name( $name ),
			'phone' => $phone,
		);

		return (array) apply_filters( 'lucky_egg_user_profile', $profile, $user );
	}

	/**
	 * Stores a phone (and name) on the CURRENT logged-in user's own profile.
	 * The phone is contact data only; it is never used to identify or authenticate anyone.
	 *
	 * @param int    $user_id User ID (must be the current user; enforced by the caller).
	 * @param string $phone   Canonical phone.
	 * @param string $name    Sanitized name.
	 * @return bool
	 */
	public function save_contact( $user_id, $phone, $name ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
			return false;
		}
		update_user_meta( $user_id, self::META_PHONE, $phone );
		update_user_meta( $user_id, self::META_NAME, $name );
		return true;
	}

	/**
	 * Whether guest submissions from this network are throttled (anti-abuse).
	 *
	 * @param string $ip Rate-limit IP key.
	 * @return bool
	 */
	public function is_guest_submission_throttled( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$settings = Lucky_Egg::get_settings();
		$limit    = max( 1, (int) $settings['registration_limit'] );
		return (int) get_transient( 'lucky_egg_reg_' . md5( $ip ) ) >= $limit;
	}

	/**
	 * Increments the guest-submission counter of the network (1 hour window).
	 * Soft limit: a lost increment under concurrency is acceptable; the authoritative
	 * limit is the locked check in the crack step.
	 *
	 * @param string $ip Rate-limit IP key.
	 */
	public function bump_guest_submission( $ip ) {
		if ( '' === $ip ) {
			return;
		}
		$key   = 'lucky_egg_reg_' . md5( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}
