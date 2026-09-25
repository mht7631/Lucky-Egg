<?php
/**
 * Bootstrap and orchestration.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg
 */
final class Lucky_Egg {

	const SETTINGS_OPTION = 'lucky_egg_settings';
	const FRONT_HANDLE    = 'lucky-egg-front';

	/**
	 * Singleton instance.
	 *
	 * @var Lucky_Egg|null
	 */
	private static $instance = null;

	/** @var Lucky_Egg_Campaign */
	public $campaigns;

	/** @var Lucky_Egg_Entry */
	public $entries;

	/** @var Lucky_Egg_Rate_Limiter */
	public $rate_limiter;

	/** @var Lucky_Egg_User */
	public $users;

	/** @var Lucky_Egg_SMS */
	public $sms;

	/** @var Lucky_Egg_Shortcode */
	public $shortcode;

	/** @var Lucky_Egg_Ajax */
	public $ajax;

	/** @var Lucky_Egg_Elementor */
	public $elementor;

	/** @var Lucky_Egg_Admin|null */
	public $admin = null;

	/**
	 * Returns the singleton.
	 *
	 * @return Lucky_Egg
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();

		Lucky_Egg_Activator::maybe_upgrade();

		$this->campaigns    = new Lucky_Egg_Campaign();
		$this->entries      = new Lucky_Egg_Entry();
		$this->rate_limiter = new Lucky_Egg_Rate_Limiter();
		$this->users        = new Lucky_Egg_User();
		$this->sms          = new Lucky_Egg_SMS( $this->entries, $this->campaigns );
		$this->shortcode    = new Lucky_Egg_Shortcode( $this->campaigns );
		$this->ajax         = new Lucky_Egg_Ajax( $this->campaigns, $this->entries, $this->rate_limiter, $this->users, $this->sms );
		$this->elementor    = new Lucky_Egg_Elementor();

		if ( is_admin() ) {
			$this->admin = new Lucky_Egg_Admin( $this->campaigns, $this->entries, $this->rate_limiter, $this->sms );
		}

		$this->hooks();
	}

	/**
	 * Loads class files.
	 */
	private function includes() {
		$files = array(
			'class-lucky-egg-logger.php',
			'class-lucky-egg-campaign.php',
			'class-lucky-egg-entry.php',
			'class-lucky-egg-rate-limiter.php',
			'class-lucky-egg-user.php',
			'class-lucky-egg-sms.php',
			'class-lucky-egg-shortcode.php',
			'class-lucky-egg-ajax.php',
			'class-lucky-egg-elementor.php',
			'class-lucky-egg-admin.php',
		);
		foreach ( $files as $file ) {
			require_once LUCKY_EGG_PATH . 'includes/' . $file;
		}
	}

	/**
	 * Registers hooks.
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'Lucky_Egg_Activator', 'ensure_events' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_front_assets' ), 5 );
		add_action( Lucky_Egg_Activator::CRON_CLEANUP, array( $this, 'run_cleanup' ) );
		add_action( Lucky_Egg_Activator::CRON_SESSIONS, array( 'Lucky_Egg_Activator', 'revoke_legacy_sessions' ) );
		add_filter( 'plugin_action_links_' . LUCKY_EGG_BASENAME, array( $this, 'action_links' ) );

		$this->shortcode->register();
		$this->ajax->register();
		$this->sms->register();
		$this->elementor->register();

		if ( $this->admin ) {
			$this->admin->register();
		}
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'lucky-egg', false, dirname( LUCKY_EGG_BASENAME ) . '/languages' );
	}

	/**
	 * Registers (not enqueues) frontend assets. They are enqueued only where an egg is rendered.
	 */
	public function register_front_assets() {
		wp_register_style(
			self::FRONT_HANDLE,
			LUCKY_EGG_URL . 'assets/css/front.css',
			array(),
			self::asset_version( 'assets/css/front.css' )
		);

		wp_register_script(
			self::FRONT_HANDLE,
			LUCKY_EGG_URL . 'assets/js/front.js',
			array(),
			self::asset_version( 'assets/js/front.js' ),
			true
		);

		wp_localize_script(
			self::FRONT_HANDLE,
			'LuckyEggConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Lucky_Egg_Ajax::NONCE_ACTION ),
				'actions' => array(
					'prepare'  => Lucky_Egg_Ajax::ACTION_PREPARE,
					'register' => Lucky_Egg_Ajax::ACTION_REGISTER,
					'crack'    => Lucky_Egg_Ajax::ACTION_CRACK,
					'nonce'    => Lucky_Egg_Ajax::ACTION_NONCE,
				),
				'i18n'    => array(
					'genericError' => __( 'خطایی رخ داد. لطفاً دوباره تلاش کنید.', 'lucky-egg' ),
					'networkError' => __( 'ارتباط با سرور برقرار نشد. اتصال اینترنت خود را بررسی کنید.', 'lucky-egg' ),
					'invalidName'  => __( 'لطفاً نام خود را به‌درستی وارد کنید (۲ تا ۶۰ حرف).', 'lucky-egg' ),
					'invalidPhone' => __( 'شماره موبایل معتبر نیست. نمونه صحیح: ۰۹۱۲۳۴۵۶۷۸۹', 'lucky-egg' ),
					'checking'     => __( 'در حال بررسی...', 'lucky-egg' ),
					'submitting'   => __( 'در حال ثبت...', 'lucky-egg' ),
					'submit'       => __( 'ثبت و ادامه', 'lucky-egg' ),
					'cracking'     => __( 'در حال شکستن...', 'lucky-egg' ),
					'copy'         => __( 'کپی کد', 'lucky-egg' ),
					'copied'       => __( 'کپی شد!', 'lucky-egg' ),
					'smsQueued'    => __( 'کد برای شماره موبایل شما پیامک می‌شود.', 'lucky-egg' ),
					'login'        => __( 'ورود به حساب کاربری', 'lucky-egg' ),
				),
			)
		);
	}

	/**
	 * Daily housekeeping.
	 */
	public function run_cleanup() {
		$this->rate_limiter->cleanup();
	}

	/**
	 * Plugin list action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = add_query_arg( array( 'page' => 'lucky-egg' ), admin_url( 'admin.php' ) );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'کمپین‌ها', 'lucky-egg' ) . '</a>' );
		return $links;
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$file = LUCKY_EGG_PATH . ltrim( $relative, '/' );
		return file_exists( $file ) ? LUCKY_EGG_VERSION . '.' . filemtime( $file ) : LUCKY_EGG_VERSION;
	}

	/**
	 * Global settings defaults.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'sms_enabled'               => 1,
			'sms_gateway'               => 'kavenegar',
			'sms_template'              => "کد قرعه‌کشی شما در «{campaign}»: {code}",
			'kavenegar_api_key'         => '',
			'kavenegar_sender'          => '',
			'melipayamak_username'      => '',
			'melipayamak_password'      => '',
			'melipayamak_from'          => '',
			'smsir_api_key'             => '',
			'smsir_line_number'         => '',
			'default_rate_limit_count'  => 1,
			'default_rate_limit_period' => 24,
			'default_rate_limit_unit'   => 'hours',
			'default_visibility'        => 'all',
			'trust_proxy'               => 0,
			'registration_limit'        => 10,
		);
	}

	/**
	 * Global settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), array_intersect_key( $stored, self::default_settings() ) );
	}

	/**
	 * Setting keys that are secrets and must never be exposed or logged.
	 *
	 * @return string[]
	 */
	public static function secret_setting_keys() {
		return array( 'kavenegar_api_key', 'melipayamak_password', 'smsir_api_key' );
	}

	/**
	 * Formats a UTC MySQL datetime in the site timezone/locale.
	 *
	 * @param string $utc UTC datetime.
	 * @return string
	 */
	public static function format_datetime( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}
		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' - ' . get_option( 'time_format' ), $timestamp );
	}
}
