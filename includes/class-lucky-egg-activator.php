<?php
/**
 * Installation, schema and migrations.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Activator
 */
class Lucky_Egg_Activator {

	const DB_OPTION     = 'lucky_egg_db_version';
	const CRON_CLEANUP  = 'lucky_egg_cleanup';
	const CRON_SWEEP    = 'lucky_egg_sms_sweep';
	const CRON_SMS      = 'lucky_egg_send_sms';
	const CRON_SESSIONS = 'lucky_egg_revoke_legacy_sessions';

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Network activation flag.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Deactivation callback. Data is kept; only scheduled events are cleared.
	 *
	 * @param bool $network_wide Network deactivation flag.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clear_events();
				restore_current_blog();
			}
			return;
		}
		self::clear_events();
	}

	/**
	 * Runs install/upgrade when the stored schema version differs.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== LUCKY_EGG_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Installs or upgrades the current site.
	 */
	public static function install() {
		$installed = get_option( self::DB_OPTION );

		self::create_tables();

		if ( $installed && version_compare( (string) $installed, LUCKY_EGG_DB_VERSION, '<' ) ) {
			self::migrate( (string) $installed );
		}

		update_option( self::DB_OPTION, LUCKY_EGG_DB_VERSION );

		if ( false === get_option( Lucky_Egg::SETTINGS_OPTION ) ) {
			add_option( Lucky_Egg::SETTINGS_OPTION, Lucky_Egg::default_settings() );
		}

		self::ensure_events();
	}

	/**
	 * Makes sure recurring events exist (they are cleared on deactivation, and a site added
	 * to a network later only gets them through this check).
	 */
	public static function ensure_events() {
		if ( ! wp_next_scheduled( self::CRON_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP );
		}
		if ( ! wp_next_scheduled( self::CRON_SWEEP ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON_SWEEP );
		}
		// Resume an unfinished legacy-session revocation (e.g. interrupted by deactivation).
		if ( false !== get_option( self::CRON_SESSIONS ) && ! wp_next_scheduled( self::CRON_SESSIONS ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_SESSIONS );
		}
	}

	/**
	 * Creates/updates tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$campaigns = $wpdb->prefix . 'lucky_egg_campaigns';
		$entries   = $wpdb->prefix . 'lucky_egg_entries';
		$limits    = $wpdb->prefix . 'lucky_egg_rate_limits';

		$sql_campaigns = "CREATE TABLE {$campaigns} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL DEFAULT '',
  campaign_key varchar(64) NOT NULL,
  settings longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_key (campaign_key),
  KEY status (status)
) {$charset};";

		$sql_entries = "CREATE TABLE {$entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  code varchar(64) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  sms_status varchar(20) NOT NULL DEFAULT 'pending',
  sms_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  sms_updated_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY campaign_id (campaign_id),
  KEY campaign_created (campaign_id,created_at),
  KEY user_id (user_id),
  KEY phone (phone),
  KEY ip (ip),
  KEY sms_status (sms_status),
  KEY created_at (created_at)
) {$charset};";

		$sql_limits = "CREATE TABLE {$limits} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  cookie_hash char(64) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  entry_id bigint(20) unsigned DEFAULT NULL,
  last_crack datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY campaign_user (campaign_id,user_id,last_crack),
  KEY campaign_ip (campaign_id,ip,last_crack),
  KEY campaign_cookie (campaign_id,cookie_hash,last_crack),
  KEY campaign_phone (campaign_id,phone,last_crack),
  KEY last_crack (last_crack)
) {$charset};";

		dbDelta( $sql_campaigns );
		dbDelta( $sql_entries );
		dbDelta( $sql_limits );
	}

	/**
	 * Version-to-version migrations. dbDelta already handles additive schema changes;
	 * data migrations for future versions go here.
	 *
	 * @param string $from Installed schema version.
	 */
	private static function migrate( $from ) {
		if ( version_compare( $from, '1.1.0', '<' ) ) {
			self::migrate_to_110();
		}
		do_action( 'lucky_egg_migrate', $from, LUCKY_EGG_DB_VERSION );
	}

	/**
	 * 1.1.0: phone becomes a rate-limit identity (backfilled from entries), and every session
	 * of accounts auto-created by 1.0.0 is revoked, because 1.0.0 issued auth cookies for
	 * them based on a phone number alone (phone-only login is removed in 1.1.0).
	 */
	private static function migrate_to_110() {
		global $wpdb;
		$limits  = $wpdb->prefix . 'lucky_egg_rate_limits';
		$entries = $wpdb->prefix . 'lucky_egg_entries';

		// Table names are built from $wpdb->prefix and fixed suffixes only.
		$wpdb->query( "UPDATE {$limits} rl INNER JOIN {$entries} e ON e.id = rl.entry_id SET rl.phone = e.phone WHERE rl.phone = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::CRON_SESSIONS, 0, false );
		if ( ! wp_next_scheduled( self::CRON_SESSIONS ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_SESSIONS );
		}
	}

	/**
	 * Batched revocation of sessions of legacy auto-created accounts (keyset over user IDs).
	 * Accounts themselves and their entries are kept.
	 */
	public static function revoke_legacy_sessions() {
		global $wpdb;
		$after = (int) get_option( self::CRON_SESSIONS, 0 );
		$ids   = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id > %d ORDER BY user_id ASC LIMIT 200",
					'lucky_egg_created',
					'1',
					$after
				)
			)
		);

		if ( empty( $ids ) ) {
			delete_option( self::CRON_SESSIONS );
			return;
		}

		foreach ( $ids as $id ) {
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $id )->destroy_all();
			}
			$after = max( $after, $id );
		}
		update_option( self::CRON_SESSIONS, $after, false );
		wp_schedule_single_event( time() + 60, self::CRON_SESSIONS );
	}

	/**
	 * Clears scheduled events.
	 */
	private static function clear_events() {
		wp_clear_scheduled_hook( self::CRON_CLEANUP );
		wp_clear_scheduled_hook( self::CRON_SWEEP );
		wp_clear_scheduled_hook( self::CRON_SESSIONS );
		wp_unschedule_hook( self::CRON_SMS );
	}
}
