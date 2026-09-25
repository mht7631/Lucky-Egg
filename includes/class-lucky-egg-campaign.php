<?php
/**
 * Campaign CRUD and campaign settings schema.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Campaign
 */
class Lucky_Egg_Campaign {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	/**
	 * Per-request cache, keyed by "id:<id>" and "key:<key>".
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'lucky_egg_campaigns';
	}

	/* ---------------------------------------------------------------------
	 * Settings schema
	 * ------------------------------------------------------------------- */

	/**
	 * Default campaign settings (the complete, fixed settings structure).
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		$global = Lucky_Egg::get_settings();

		return array(
			'egg_image'         => '',
			'crack_image'       => '',
			'hint_text'         => __( 'روی تخم‌مرغ بزن تا ترک بخورد!', 'lucky-egg' ),
			'ready_text'        => __( 'ترک خورد! یک ضربه دیگر بزن تا بشکند.', 'lucky-egg' ),
			'result_title'      => __( 'تبریک! تخم‌مرغ شانسی شکست', 'lucky-egg' ),
			'result_message'    => __( 'کد قرعه‌کشی شما:', 'lucky-egg' ),
			'result_footer'     => __( 'این کد را نزد خود نگه دارید.', 'lucky-egg' ),
			'text_position'     => 'above',
			'code_prefix'       => '',
			'code_length'       => 8,
			'rate_limit_count'  => (int) $global['default_rate_limit_count'],
			'rate_limit_period' => (int) $global['default_rate_limit_period'],
			'rate_limit_unit'   => (string) $global['default_rate_limit_unit'],
			'visibility'        => (string) $global['default_visibility'],
			'sound_enabled'     => 1,
			'vibrate_enabled'   => 1,
			'sms_enabled'       => 1,
			'sms_template'      => '',
			'egg_size'          => 260,
			'bg_color'          => '',
			'text_color'        => '#1f2937',
			'accent_color'      => '#f59e0b',
			'code_color'        => '#92400e',
			'code_bg_color'     => '#fff7e6',
			'font_size'         => 16,
			'border_radius'     => 16,
		);
	}

	/**
	 * Visibility choices.
	 *
	 * @return array
	 */
	public static function visibility_choices() {
		return array(
			'all'     => __( 'همه (مهمان و عضو)', 'lucky-egg' ),
			'guests'  => __( 'فقط مهمان‌ها', 'lucky-egg' ),
			'members' => __( 'فقط اعضا', 'lucky-egg' ),
		);
	}

	/**
	 * Text position choices.
	 *
	 * @return array
	 */
	public static function text_position_choices() {
		return array(
			'above' => __( 'متن بالای کد', 'lucky-egg' ),
			'below' => __( 'متن پایین کد', 'lucky-egg' ),
			'side'  => __( 'متن کنار کد', 'lucky-egg' ),
		);
	}

	/**
	 * Rate limit unit choices.
	 *
	 * @return array
	 */
	public static function rate_unit_choices() {
		return array(
			'hours' => __( 'ساعت', 'lucky-egg' ),
			'days'  => __( 'روز', 'lucky-egg' ),
		);
	}

	/**
	 * Whitelist + type validation + sanitization of campaign settings.
	 * Unknown keys are dropped; missing keys fall back to defaults.
	 *
	 * @param mixed $raw Raw settings (already unslashed).
	 * @return array
	 */
	public static function sanitize_settings( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$d   = self::get_default_settings();
		$s   = array();

		$s['egg_image']   = isset( $raw['egg_image'] ) ? self::sanitize_image_url( $raw['egg_image'] ) : $d['egg_image'];
		$s['crack_image'] = isset( $raw['crack_image'] ) ? self::sanitize_image_url( $raw['crack_image'] ) : $d['crack_image'];

		$s['hint_text']      = self::text( $raw, 'hint_text', $d['hint_text'], 200 );
		$s['ready_text']     = self::text( $raw, 'ready_text', $d['ready_text'], 200 );
		$s['result_title']   = self::text( $raw, 'result_title', $d['result_title'], 200 );
		$s['result_message'] = self::textarea( $raw, 'result_message', $d['result_message'], 1000 );
		$s['result_footer']  = self::textarea( $raw, 'result_footer', $d['result_footer'], 1000 );
		$s['text_position']  = self::choice( $raw, 'text_position', array_keys( self::text_position_choices() ), $d['text_position'] );

		$prefix = $d['code_prefix'];
		if ( isset( $raw['code_prefix'] ) && is_scalar( $raw['code_prefix'] ) ) {
			$prefix = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $raw['code_prefix'] ) );
		}
		$s['code_prefix'] = substr( $prefix, 0, 16 );
		$s['code_length'] = self::int_range( $raw, 'code_length', $d['code_length'], 4, 32 );

		$s['rate_limit_unit']   = self::choice( $raw, 'rate_limit_unit', array_keys( self::rate_unit_choices() ), $d['rate_limit_unit'] );
		$s['rate_limit_count']  = self::int_range( $raw, 'rate_limit_count', $d['rate_limit_count'], 1, 1000 );
		$s['rate_limit_period'] = self::int_range( $raw, 'rate_limit_period', $d['rate_limit_period'], 1, 'days' === $s['rate_limit_unit'] ? 365 : 8760 );
		$s['visibility']        = self::choice( $raw, 'visibility', array_keys( self::visibility_choices() ), $d['visibility'] );

		$s['sound_enabled']   = self::flag( $raw, 'sound_enabled' );
		$s['vibrate_enabled'] = self::flag( $raw, 'vibrate_enabled' );
		$s['sms_enabled']     = self::flag( $raw, 'sms_enabled' );
		$s['sms_template']    = self::textarea( $raw, 'sms_template', $d['sms_template'], 500 );

		$s['egg_size']      = self::int_range( $raw, 'egg_size', $d['egg_size'], 80, 800 );
		$s['bg_color']      = self::color( $raw, 'bg_color', $d['bg_color'], true );
		$s['text_color']    = self::color( $raw, 'text_color', $d['text_color'], false );
		$s['accent_color']  = self::color( $raw, 'accent_color', $d['accent_color'], false );
		$s['code_color']    = self::color( $raw, 'code_color', $d['code_color'], false );
		$s['code_bg_color'] = self::color( $raw, 'code_bg_color', $d['code_bg_color'], false );
		$s['font_size']     = self::int_range( $raw, 'font_size', $d['font_size'], 10, 48 );
		$s['border_radius'] = self::int_range( $raw, 'border_radius', $d['border_radius'], 0, 200 );

		return $s;
	}

	/**
	 * Validates an image URL. Only http(s) raster images are accepted; SVG is accepted
	 * only when it is one of the plugin's own bundled assets.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function sanitize_image_url( $url ) {
		if ( ! is_scalar( $url ) ) {
			return '';
		}
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'svg' === $ext ) {
			return 0 === strpos( $url, LUCKY_EGG_URL . 'assets/img/' ) ? $url : '';
		}

		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ? $url : '';
	}

	/**
	 * Single-line text helper.
	 *
	 * @param array  $raw     Raw.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @param int    $max     Max length.
	 * @return string
	 */
	private static function text( array $raw, $key, $default, $max ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
			return $default;
		}
		return self::cut( sanitize_text_field( (string) $raw[ $key ] ), $max );
	}

	/**
	 * Multi-line text helper.
	 *
	 * @param array  $raw     Raw.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @param int    $max     Max length.
	 * @return string
	 */
	private static function textarea( array $raw, $key, $default, $max ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
			return $default;
		}
		return self::cut( sanitize_textarea_field( (string) $raw[ $key ] ), $max );
	}

	/**
	 * Enum helper.
	 *
	 * @param array  $raw     Raw.
	 * @param string $key     Key.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default.
	 * @return string
	 */
	private static function choice( array $raw, $key, array $allowed, $default ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && in_array( (string) $raw[ $key ], $allowed, true ) ) {
			return (string) $raw[ $key ];
		}
		return in_array( $default, $allowed, true ) ? $default : (string) reset( $allowed );
	}

	/**
	 * Integer range helper.
	 *
	 * @param array  $raw     Raw.
	 * @param string $key     Key.
	 * @param int    $default Default.
	 * @param int    $min     Min.
	 * @param int    $max     Max.
	 * @return int
	 */
	private static function int_range( array $raw, $key, $default, $min, $max ) {
		$value = ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) ? (int) $raw[ $key ] : (int) $default;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Boolean helper (absent = off).
	 *
	 * @param array  $raw Raw.
	 * @param string $key Key.
	 * @return int
	 */
	private static function flag( array $raw, $key ) {
		return ! empty( $raw[ $key ] ) ? 1 : 0;
	}

	/**
	 * Hex color helper.
	 *
	 * @param array  $raw         Raw.
	 * @param string $key         Key.
	 * @param string $default     Default.
	 * @param bool   $allow_empty Whether empty is valid.
	 * @return string
	 */
	private static function color( array $raw, $key, $default, $allow_empty ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
			return $default;
		}
		$value = trim( (string) $raw[ $key ] );
		if ( '' === $value ) {
			return $allow_empty ? '' : $default;
		}
		$hex = sanitize_hex_color( $value );
		if ( $hex ) {
			return $hex;
		}
		return $allow_empty ? '' : $default;
	}

	/**
	 * Multibyte-safe cut.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/* ---------------------------------------------------------------------
	 * Persistence
	 * ------------------------------------------------------------------- */

	/**
	 * Converts a DB row into a normalized campaign array.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		$decoded = json_decode( (string) $row->settings, true );
		if ( ! is_array( $decoded ) ) {
			Lucky_Egg_Logger::error(
				'Campaign settings could not be decoded; defaults applied.',
				array(
					'campaign_id' => (int) $row->id,
					'json_error'  => json_last_error_msg(),
				)
			);
			$decoded = array();
		}

		return array(
			'id'           => (int) $row->id,
			'title'        => (string) $row->title,
			'campaign_key' => (string) $row->campaign_key,
			'status'       => self::STATUS_INACTIVE === $row->status ? self::STATUS_INACTIVE : self::STATUS_ACTIVE,
			'settings'     => self::sanitize_settings( array_merge( self::get_default_settings(), $decoded ) ),
			'created_at'   => (string) $row->created_at,
			'updated_at'   => (string) $row->updated_at,
		);
	}

	/**
	 * Gets a campaign by ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		if ( array_key_exists( 'id:' . $id, $this->cache ) ) {
			return $this->cache[ 'id:' . $id ];
		}
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$campaign = $this->hydrate( $row );

		$this->cache[ 'id:' . $id ] = $campaign;
		if ( $campaign ) {
			$this->cache[ 'key:' . $campaign['campaign_key'] ] = $campaign;
		}
		return $campaign;
	}

	/**
	 * Gets a campaign by key.
	 *
	 * @param string $key Campaign key.
	 * @return array|null
	 */
	public function get_by_key( $key ) {
		global $wpdb;
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return null;
		}
		if ( array_key_exists( 'key:' . $key, $this->cache ) ) {
			return $this->cache[ 'key:' . $key ];
		}
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE campaign_key = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$campaign = $this->hydrate( $row );

		$this->cache[ 'key:' . $key ] = $campaign;
		if ( $campaign ) {
			$this->cache[ 'id:' . $campaign['id'] ] = $campaign;
		}
		return $campaign;
	}

	/**
	 * Lists campaigns.
	 *
	 * @param string $status Optional status filter.
	 * @return array[]
	 */
	public function get_all( $status = '' ) {
		global $wpdb;
		if ( in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ), true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY id DESC", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$list = array();
		foreach ( (array) $rows as $row ) {
			$campaign = $this->hydrate( $row );
			if ( $campaign ) {
				$list[] = $campaign;
			}
		}
		return $list;
	}

	/**
	 * Lightweight choices list (no settings decoding).
	 *
	 * @param string $index 'id' or 'key'.
	 * @return array
	 */
	public function get_choices( $index = 'id' ) {
		global $wpdb;
		$rows    = $wpdb->get_results( "SELECT id, title, campaign_key, status FROM {$this->table()} ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$choices = array();
		foreach ( (array) $rows as $row ) {
			$label = $row->title . ( self::STATUS_INACTIVE === $row->status ? ' (' . __( 'غیرفعال', 'lucky-egg' ) . ')' : '' );
			if ( 'key' === $index ) {
				$choices[ $row->campaign_key ] = $label;
			} else {
				$choices[ (int) $row->id ] = $label;
			}
		}
		return $choices;
	}

	/**
	 * Checks key uniqueness.
	 *
	 * @param string $key        Key.
	 * @param int    $exclude_id ID to exclude.
	 * @return bool
	 */
	public function key_exists( $key, $exclude_id = 0 ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE campaign_key = %s AND id <> %d LIMIT 1", $key, absint( $exclude_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return ! empty( $found );
	}

	/**
	 * Creates or updates a campaign. All input is validated and sanitized here.
	 *
	 * @param array $data Unslashed data: title, campaign_key, status, settings.
	 * @param int   $id   Campaign ID for updates.
	 * @return int|WP_Error Campaign ID.
	 */
	public function save( array $data, $id = 0 ) {
		global $wpdb;

		$id    = absint( $id );
		$title = isset( $data['title'] ) && is_scalar( $data['title'] ) ? self::cut( sanitize_text_field( (string) $data['title'] ), 191 ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'عنوان کمپین الزامی است.', 'lucky-egg' ) );
		}

		$key = isset( $data['campaign_key'] ) && is_scalar( $data['campaign_key'] ) ? sanitize_key( (string) $data['campaign_key'] ) : '';
		if ( ! preg_match( '/^[a-z0-9_-]{2,64}$/', $key ) ) {
			return new WP_Error( 'invalid_key', __( 'کلید کمپین باید ۲ تا ۶۴ کاراکتر و فقط شامل حروف کوچک انگلیسی، عدد، خط تیره یا زیرخط باشد.', 'lucky-egg' ) );
		}
		if ( $this->key_exists( $key, $id ) ) {
			return new WP_Error( 'key_exists', __( 'این کلید کمپین قبلاً استفاده شده است.', 'lucky-egg' ) );
		}

		$status   = ( isset( $data['status'] ) && self::STATUS_INACTIVE === $data['status'] ) ? self::STATUS_INACTIVE : self::STATUS_ACTIVE;
		$settings = self::sanitize_settings( isset( $data['settings'] ) ? $data['settings'] : array() );
		$json     = wp_json_encode( $settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			Lucky_Egg_Logger::error( 'Campaign settings JSON encoding failed.', array( 'campaign_id' => $id ) );
			return new WP_Error( 'db_error', __( 'ذخیره تنظیمات ممکن نشد.', 'lucky-egg' ) );
		}

		$now      = current_time( 'mysql', true );
		$suppress = $wpdb->suppress_errors( true );

		if ( $id ) {
			if ( ! $this->get( $id ) ) {
				$wpdb->suppress_errors( $suppress );
				return new WP_Error( 'not_found', __( 'کمپین یافت نشد.', 'lucky-egg' ) );
			}
			$result = $wpdb->update(
				$this->table(),
				array(
					'title'        => $title,
					'campaign_key' => $key,
					'settings'     => $json,
					'status'       => $status,
					'updated_at'   => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$result = $wpdb->insert(
				$this->table(),
				array(
					'title'        => $title,
					'campaign_key' => $key,
					'settings'     => $json,
					'status'       => $status,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		}

		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			if ( false !== stripos( $error, 'Duplicate' ) ) {
				return new WP_Error( 'key_exists', __( 'این کلید کمپین قبلاً استفاده شده است.', 'lucky-egg' ) );
			}
			Lucky_Egg_Logger::error( 'Campaign save failed.', array( 'db_error' => $error ) );
			return new WP_Error( 'db_error', __( 'ذخیره کمپین با خطا مواجه شد.', 'lucky-egg' ) );
		}

		$this->cache = array();
		return $id;
	}

	/**
	 * Sets campaign status.
	 *
	 * @param int    $id     ID.
	 * @param string $status Status.
	 * @return bool
	 */
	public function set_status( $id, $status ) {
		global $wpdb;
		$status = self::STATUS_INACTIVE === $status ? self::STATUS_INACTIVE : self::STATUS_ACTIVE;
		$result = $wpdb->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->cache = array();
		return false !== $result;
	}

	/**
	 * Deletes a campaign row. Related entries/rate limits are removed by the caller
	 * (Lucky_Egg_Admin orchestrates), keeping each class owner of its own table.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$result      = $wpdb->delete( $this->table(), array( 'id' => absint( $id ) ), array( '%d' ) );
		$this->cache = array();
		return false !== $result;
	}

	/**
	 * Counts campaigns.
	 *
	 * @param string $status Optional status.
	 * @return int
	 */
	public function count( $status = '' ) {
		global $wpdb;
		if ( in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_INACTIVE ), true ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a campaign is active.
	 *
	 * @param array|null $campaign Campaign.
	 * @return bool
	 */
	public static function is_active( $campaign ) {
		return is_array( $campaign ) && self::STATUS_ACTIVE === $campaign['status'];
	}
}
