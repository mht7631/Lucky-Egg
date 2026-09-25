<?php
/**
 * Minimal logger that never stores secrets.
 *
 * Added file: keeps logging out of business classes (single responsibility).
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Logger
 */
class Lucky_Egg_Logger {

	const OPTION      = 'lucky_egg_log';
	const MAX_ENTRIES = 200;

	/**
	 * While a DB transaction is open, option writes would be rolled back together with the
	 * failed work, so log records are buffered and written after COMMIT/ROLLBACK.
	 *
	 * @var array|null
	 */
	private static $buffer = null;

	/**
	 * Starts buffering (call right before START TRANSACTION).
	 */
	public static function defer() {
		if ( null === self::$buffer ) {
			self::$buffer = array();
		}
	}

	/**
	 * Stops buffering and persists buffered records (call right after COMMIT/ROLLBACK).
	 */
	public static function flush() {
		$records      = (array) self::$buffer;
		self::$buffer = null;
		if ( $records ) {
			self::persist( $records );
		}
	}

	/**
	 * Logs an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Context (secrets are redacted).
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Writes a log entry to a bounded, non-autoloaded option and to debug.log when enabled.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$level   = in_array( $level, array( 'error', 'warning', 'info' ), true ) ? $level : 'info';
		$message = self::truncate( self::scrub( sanitize_text_field( (string) $message ) ), 500 );
		$context = self::redact( $context, 0 );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Lucky Egg][' . $level . '] ' . $message . ( $context ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : '' ) );
		}

		$record = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		if ( null !== self::$buffer ) {
			self::$buffer[] = $record;
			return;
		}
		self::persist( array( $record ) );
	}

	/**
	 * Prepends records (oldest first) to the bounded, non-autoloaded log option.
	 *
	 * @param array $records Records.
	 */
	private static function persist( array $records ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		foreach ( $records as $record ) {
			array_unshift( $log, $record );
		}
		update_option( self::OPTION, array_slice( $log, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Returns stored entries.
	 *
	 * @return array
	 */
	public static function get_entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clears the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Redacts sensitive keys and normalizes values.
	 *
	 * @param array $context Context.
	 * @param int   $depth   Recursion depth.
	 * @return array
	 */
	private static function redact( array $context, $depth ) {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : (int) $key;

			if ( is_string( $key ) && preg_match( '/pass|secret|token|api_?key|apikey|credential|auth|cookie/i', $key ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $depth < 3 ? self::redact( $value, $depth + 1 ) : '[array]';
			} elseif ( is_bool( $value ) || is_int( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = self::truncate( self::scrub( sanitize_text_field( (string) $value ) ), 300 );
			} elseif ( null === $value ) {
				$clean[ $key ] = null;
			} else {
				$clean[ $key ] = '[' . gettype( $value ) . ']';
			}
		}
		return $clean;
	}

	/**
	 * Removes any configured secret value that might appear inside a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function scrub( $text ) {
		$settings = get_option( Lucky_Egg::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return $text;
		}
		foreach ( Lucky_Egg::secret_setting_keys() as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) && strlen( $settings[ $key ] ) >= 4 ) {
				$text = str_replace( $settings[ $key ], '[redacted]', $text );
			}
		}
		return $text;
	}

	/**
	 * Multibyte-safe truncate.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private static function truncate( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
