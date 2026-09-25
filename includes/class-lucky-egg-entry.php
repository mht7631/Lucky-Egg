<?php
/**
 * Entry persistence and secure code generation.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Entry
 */
class Lucky_Egg_Entry {

	/**
	 * 32 unambiguous characters (no I, O, 0, 1). 256 is divisible by 32,
	 * so "byte % 32" has no modulo bias.
	 */
	const ALPHABET          = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const MAX_CODE_ATTEMPTS = 10;

	const SMS_PENDING  = 'pending';
	const SMS_SENDING  = 'sending';
	const SMS_SENT     = 'sent';
	const SMS_FAILED   = 'failed';
	const SMS_DISABLED = 'disabled';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'lucky_egg_entries';
	}

	/**
	 * SMS status labels.
	 *
	 * @return array
	 */
	public static function sms_status_labels() {
		return array(
			self::SMS_PENDING  => __( 'در صف', 'lucky-egg' ),
			self::SMS_SENDING  => __( 'در حال ارسال', 'lucky-egg' ),
			self::SMS_SENT     => __( 'ارسال شد', 'lucky-egg' ),
			self::SMS_FAILED   => __( 'ناموفق', 'lucky-egg' ),
			self::SMS_DISABLED => __( 'غیرفعال', 'lucky-egg' ),
		);
	}

	/**
	 * Generates a cryptographically secure random code.
	 *
	 * @param string $prefix Prefix.
	 * @param int    $length Random part length.
	 * @return string
	 * @throws Exception When no secure random source is available.
	 */
	public function generate_code( $prefix, $length ) {
		$length   = max( 4, min( 32, (int) $length ) );
		$bytes    = random_bytes( $length );
		$alphabet = self::ALPHABET;
		$size     = strlen( $alphabet );
		$code     = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= $alphabet[ ord( $bytes[ $i ] ) % $size ];
		}
		return (string) $prefix . $code;
	}

	/**
	 * Whether a code already exists (layer 1 of collision control).
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public function code_exists( $code ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$this->table()} WHERE code = %s LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Creates an entry with a guaranteed-unique code.
	 * Layer 1: pre-insert lookup. Layer 2: UNIQUE(code) constraint; on duplicate we regenerate.
	 *
	 * @param array  $campaign   Campaign (server-resolved).
	 * @param int    $user_id    User ID.
	 * @param string $name       Name (sanitized).
	 * @param string $phone      Canonical phone.
	 * @param string $ip         Client IP.
	 * @param string $sms_status Initial SMS status.
	 * @return array|WP_Error array( 'id' => int, 'code' => string )
	 */
	public function create_entry( array $campaign, $user_id, $name, $phone, $ip, $sms_status ) {
		global $wpdb;

		$prefix = (string) $campaign['settings']['code_prefix'];
		$length = (int) $campaign['settings']['code_length'];

		for ( $attempt = 1; $attempt <= self::MAX_CODE_ATTEMPTS; $attempt++ ) {
			try {
				$code = $this->generate_code( $prefix, $length );
			} catch ( Exception $e ) {
				Lucky_Egg_Logger::error( 'Secure random source unavailable.', array( 'error' => $e->getMessage() ) );
				return new WP_Error( 'random_failed', 'random_failed' );
			}

			if ( $this->code_exists( $code ) ) {
				continue;
			}

			$suppress = $wpdb->suppress_errors( true );
			$inserted = $wpdb->insert(
				$this->table(),
				array(
					'campaign_id' => (int) $campaign['id'],
					'user_id'     => $user_id > 0 ? (int) $user_id : null,
					'name'        => (string) $name,
					'phone'       => (string) $phone,
					'code'        => $code,
					'ip'          => (string) $ip,
					'sms_status'  => (string) $sms_status,
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$error    = $wpdb->last_error;
			$wpdb->suppress_errors( $suppress );

			if ( $inserted ) {
				return array(
					'id'   => (int) $wpdb->insert_id,
					'code' => $code,
				);
			}

			if ( false !== stripos( $error, 'Duplicate' ) ) {
				continue;
			}

			Lucky_Egg_Logger::error(
				'Entry insert failed.',
				array(
					'campaign_id' => (int) $campaign['id'],
					'db_error'    => $error,
				)
			);
			return new WP_Error( 'db_error', 'db_error' );
		}

		Lucky_Egg_Logger::error( 'Unique code generation exhausted all attempts.', array( 'campaign_id' => (int) $campaign['id'] ) );
		return new WP_Error( 'code_collision', 'code_collision' );
	}

	/**
	 * Gets an entry.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Atomically claims an entry for SMS sending (pending -> sending) and counts the attempt.
	 * Only one concurrent worker can win the claim, which prevents double sends.
	 *
	 * @param int $id Entry ID.
	 * @return bool
	 */
	public function claim_sms( $id ) {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET sms_status = %s, sms_attempts = sms_attempts + 1, sms_updated_at = %s WHERE id = %d AND sms_status = %s", self::SMS_SENDING, current_time( 'mysql', true ), absint( $id ), self::SMS_PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $affected;
	}

	/**
	 * Updates SMS status.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status Status.
	 * @return bool
	 */
	public function update_sms_status( $id, $status ) {
		global $wpdb;
		if ( ! array_key_exists( $status, self::sms_status_labels() ) ) {
			return false;
		}
		return false !== $wpdb->update(
			$this->table(),
			array(
				'sms_status'     => $status,
				'sms_updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Entries whose SMS is due for (re)dispatch by the sweeper:
	 * "pending" rows not touched for $pending_age seconds (lost/late WP-Cron event), and
	 * "sending" rows stuck for $sending_age seconds (worker died mid-request).
	 *
	 * @param int $pending_age Seconds.
	 * @param int $sending_age Seconds.
	 * @param int $limit       Max rows.
	 * @return object[] id, sms_status, sms_attempts
	 */
	public function get_sms_stale( $pending_age, $sending_age, $limit ) {
		global $wpdb;
		$now = time();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sms_status, sms_attempts FROM {$this->table()} WHERE ( sms_status = %s AND COALESCE( sms_updated_at, created_at ) < %s ) OR ( sms_status = %s AND COALESCE( sms_updated_at, created_at ) < %s ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::SMS_PENDING,
				gmdate( 'Y-m-d H:i:s', $now - (int) $pending_age ),
				self::SMS_SENDING,
				gmdate( 'Y-m-d H:i:s', $now - (int) $sending_age ),
				max( 1, absint( $limit ) )
			)
		);
	}

	/**
	 * Moves a stuck "sending" entry back to "pending" (conditional, race-safe).
	 *
	 * @param int $id Entry ID.
	 * @return bool
	 */
	public function requeue_stuck_sms( $id ) {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET sms_status = %s, sms_updated_at = %s WHERE id = %d AND sms_status = %s", self::SMS_PENDING, current_time( 'mysql', true ), absint( $id ), self::SMS_SENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $affected;
	}

	/**
	 * Paginated admin query.
	 *
	 * @param array $args campaign_id, search, page, per_page.
	 * @return array array( 'items' => object[], 'total' => int )
	 */
	public function query( array $args ) {
		global $wpdb;

		$campaign_id = isset( $args['campaign_id'] ) ? absint( $args['campaign_id'] ) : 0;
		$search      = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$per_page    = isset( $args['per_page'] ) ? max( 1, min( 200, absint( $args['per_page'] ) ) ) : 20;
		$page        = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$offset      = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( $campaign_id ) {
			$where[]  = 'e.campaign_id = %d';
			$params[] = $campaign_id;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( Lucky_Egg_User::normalize_digits( $search ) ) . '%';
			$where[]  = '(e.name LIKE %s OR e.phone LIKE %s OR e.code LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$entries   = $this->table();
		$campaigns = $wpdb->prefix . 'lucky_egg_campaigns';

		$count_sql = "SELECT COUNT(*) FROM {$entries} e WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql = "SELECT e.*, c.title AS campaign_title FROM {$entries} e LEFT JOIN {$campaigns} c ON c.id = e.campaign_id WHERE {$where_sql} ORDER BY e.id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Keyset-paginated export batch.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $after_id    Last exported ID.
	 * @param int $limit       Batch size.
	 * @return object[]
	 */
	public function get_export_batch( $campaign_id, $after_id, $limit ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, name, phone, code, ip, sms_status, created_at FROM {$this->table()} WHERE campaign_id = %d AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $campaign_id ),
				absint( $after_id ),
				max( 1, absint( $limit ) )
			)
		);
	}

	/**
	 * Per-campaign statistics keyed by campaign ID.
	 * Participants are counted by distinct phone: guest entries have no user_id.
	 *
	 * @return array
	 */
	public function get_stats_by_campaign() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT campaign_id, COUNT(*) AS cracks, COUNT(DISTINCT phone) AS participants, SUM(CASE WHEN sms_status = %s THEN 1 ELSE 0 END) AS sms_sent, SUM(CASE WHEN sms_status = %s THEN 1 ELSE 0 END) AS sms_failed, MAX(created_at) AS last_crack FROM {$this->table()} GROUP BY campaign_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::SMS_SENT,
				self::SMS_FAILED
			)
		);
		$stats = array();
		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row->campaign_id ] = array(
				'cracks'       => (int) $row->cracks,
				'participants' => (int) $row->participants,
				'sms_sent'     => (int) $row->sms_sent,
				'sms_failed'   => (int) $row->sms_failed,
				'last_crack'   => (string) $row->last_crack,
			);
		}
		return $stats;
	}

	/**
	 * Global totals.
	 *
	 * @return array
	 */
	public function get_totals() {
		global $wpdb;
		$today_start_utc = get_gmt_from_date( wp_date( 'Y-m-d' ) . ' 00:00:00' );
		$row             = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cracks, COUNT(DISTINCT phone) AS participants, SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS today, SUM(CASE WHEN sms_status = %s THEN 1 ELSE 0 END) AS sms_sent, SUM(CASE WHEN sms_status = %s THEN 1 ELSE 0 END) AS sms_failed FROM {$this->table()}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today_start_utc,
				self::SMS_SENT,
				self::SMS_FAILED
			)
		);
		return array(
			'cracks'       => $row ? (int) $row->cracks : 0,
			'participants' => $row ? (int) $row->participants : 0,
			'today'        => $row ? (int) $row->today : 0,
			'sms_sent'     => $row ? (int) $row->sms_sent : 0,
			'sms_failed'   => $row ? (int) $row->sms_failed : 0,
		);
	}

	/**
	 * Deletes all entries of a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool
	 */
	public function delete_by_campaign( $campaign_id ) {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'campaign_id' => absint( $campaign_id ) ), array( '%d' ) );
	}
}
