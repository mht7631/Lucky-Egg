<?php
/**
 * Shortcode + shared egg markup renderer (also used by the Elementor widget).
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Shortcode
 */
class Lucky_Egg_Shortcode {

	const TAG = 'lucky_egg';

	/** @var Lucky_Egg_Campaign */
	private $campaigns;

	/**
	 * Constructor.
	 *
	 * @param Lucky_Egg_Campaign $campaigns Campaigns.
	 */
	public function __construct( Lucky_Egg_Campaign $campaigns ) {
		$this->campaigns = $campaigns;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Enqueues assets early (in <head>) when the current singular post contains the shortcode.
	 * Other contexts (widgets, builders) enqueue lazily at render time.
	 */
	public function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueues registered frontend assets.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( Lucky_Egg::FRONT_HANDLE );
		wp_enqueue_script( Lucky_Egg::FRONT_HANDLE );
	}

	/**
	 * [lucky_egg campaign="gold"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'campaign' => '' ), $atts, self::TAG );
		return $this->render( sanitize_key( (string) $atts['campaign'] ) );
	}

	/**
	 * Renders the egg for a campaign.
	 *
	 * @param string $key       Campaign key.
	 * @param array  $overrides Optional: egg_image, crack_image, text_position.
	 * @param bool   $preview   Builder preview: bypass visibility rules for display only.
	 * @return string Escaped HTML.
	 */
	public function render( $key, array $overrides = array(), $preview = false ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return $this->admin_notice( __( 'تخم‌مرغ شانسی: کلید کمپین مشخص نشده است.', 'lucky-egg' ) );
		}

		$campaign = $this->campaigns->get_by_key( $key );
		if ( ! $campaign ) {
			/* translators: %s: campaign key */
			return $this->admin_notice( sprintf( __( 'تخم‌مرغ شانسی: کمپین «%s» یافت نشد.', 'lucky-egg' ), $key ) );
		}
		if ( ! Lucky_Egg_Campaign::is_active( $campaign ) ) {
			/* translators: %s: campaign title */
			return $this->admin_notice( sprintf( __( 'تخم‌مرغ شانسی: کمپین «%s» غیرفعال است و به بازدیدکنندگان نمایش داده نمی‌شود.', 'lucky-egg' ), $campaign['title'] ) );
		}

		$settings   = $campaign['settings'];
		$logged_in  = is_user_logged_in();
		$visibility = $settings['visibility'];

		/*
		 * Members-only campaigns are still rendered for guests: the server answers the first
		 * click with "login_required" + the standard WordPress login URL. Deciding this at
		 * click time (not render time) also stays correct behind a full-page cache.
		 */
		if ( ! $preview ) {
			if ( 'guests' === $visibility && $logged_in ) {
				return $this->admin_notice( __( 'تخم‌مرغ شانسی: این کمپین فقط برای مهمان‌ها نمایش داده می‌شود.', 'lucky-egg' ) );
			}
		}

		if ( ! empty( $overrides['egg_image'] ) ) {
			$settings['egg_image'] = Lucky_Egg_Campaign::sanitize_image_url( $overrides['egg_image'] );
		}
		if ( ! empty( $overrides['crack_image'] ) ) {
			$settings['crack_image'] = Lucky_Egg_Campaign::sanitize_image_url( $overrides['crack_image'] );
		}
		if ( ! empty( $overrides['text_position'] ) && array_key_exists( $overrides['text_position'], Lucky_Egg_Campaign::text_position_choices() ) ) {
			$settings['text_position'] = $overrides['text_position'];
		}

		self::enqueue_assets();

		$egg_image   = '' !== $settings['egg_image'] ? $settings['egg_image'] : LUCKY_EGG_URL . 'assets/img/egg.svg';
		$crack_image = '' !== $settings['crack_image'] ? $settings['crack_image'] : LUCKY_EGG_URL . 'assets/img/crack.svg';
		$uid         = 'lucky-egg-' . strtolower( wp_generate_password( 8, false, false ) );

		$vars = array(
			'--le-egg-size:' . (int) $settings['egg_size'] . 'px',
			'--le-text-color:' . $settings['text_color'],
			'--le-accent:' . $settings['accent_color'],
			'--le-code-color:' . $settings['code_color'],
			'--le-code-bg:' . $settings['code_bg_color'],
			'--le-font-size:' . (int) $settings['font_size'] . 'px',
			'--le-radius:' . (int) $settings['border_radius'] . 'px',
		);
		if ( '' !== $settings['bg_color'] ) {
			$vars[] = '--le-bg:' . $settings['bg_color'];
		}

		ob_start();
		?>
		<div class="lucky-egg-wrap" style="<?php echo esc_attr( implode( ';', $vars ) ); ?>">
			<div
				id="<?php echo esc_attr( $uid ); ?>"
				class="lucky-egg lucky-egg--text-<?php echo esc_attr( $settings['text_position'] ); ?>"
				dir="rtl"
				data-campaign="<?php echo esc_attr( $campaign['campaign_key'] ); ?>"
				data-sound="<?php echo esc_attr( $settings['sound_enabled'] ? '1' : '0' ); ?>"
				data-vibrate="<?php echo esc_attr( $settings['vibrate_enabled'] ? '1' : '0' ); ?>"
				data-ready-text="<?php echo esc_attr( $settings['ready_text'] ); ?>"
				data-state="idle"
			>
				<div class="lucky-egg__stage">
					<span class="lucky-egg__glow" aria-hidden="true"></span>
					<span class="lucky-egg__burst" aria-hidden="true"></span>
					<button type="button" class="lucky-egg__egg" aria-label="<?php esc_attr_e( 'شکستن تخم‌مرغ شانسی', 'lucky-egg' ); ?>">
						<span class="lucky-egg__halves">
							<span class="lucky-egg__half lucky-egg__half--top"><img src="<?php echo esc_url( $egg_image ); ?>" alt="" draggable="false" decoding="async"></span>
							<span class="lucky-egg__half lucky-egg__half--bottom"><img src="<?php echo esc_url( $egg_image ); ?>" alt="" draggable="false" decoding="async"></span>
							<img class="lucky-egg__crack" src="<?php echo esc_url( $crack_image ); ?>" alt="" draggable="false" decoding="async">
						</span>
					</button>
					<span class="lucky-egg__particles" aria-hidden="true"></span>
				</div>

				<p class="lucky-egg__hint" aria-live="polite"><?php echo esc_html( $settings['hint_text'] ); ?></p>

				<div class="lucky-egg__notice" role="alert" hidden></div>

				<div class="lucky-egg__result" hidden aria-live="polite">
					<?php if ( '' !== $settings['result_title'] ) : ?>
						<h3 class="lucky-egg__result-title"><?php echo esc_html( $settings['result_title'] ); ?></h3>
					<?php endif; ?>
					<div class="lucky-egg__result-body">
						<?php if ( '' !== $settings['result_message'] ) : ?>
							<div class="lucky-egg__message"><?php echo nl2br( esc_html( $settings['result_message'] ) ); ?></div>
						<?php endif; ?>
						<div class="lucky-egg__code-box">
							<output class="lucky-egg__code" dir="ltr"></output>
							<button type="button" class="lucky-egg__copy"><?php esc_html_e( 'کپی کد', 'lucky-egg' ); ?></button>
						</div>
					</div>
					<?php if ( '' !== $settings['result_footer'] ) : ?>
						<div class="lucky-egg__footer"><?php echo nl2br( esc_html( $settings['result_footer'] ) ); ?></div>
					<?php endif; ?>
					<p class="lucky-egg__sms-note" hidden></p>
				</div>

				<div class="lucky-egg-modal" hidden role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $uid ); ?>-title" dir="rtl">
					<div class="lucky-egg-modal__backdrop" data-le-close></div>
					<div class="lucky-egg-modal__dialog">
						<button type="button" class="lucky-egg-modal__close" data-le-close aria-label="<?php esc_attr_e( 'بستن', 'lucky-egg' ); ?>">&times;</button>
						<h3 class="lucky-egg-modal__title" id="<?php echo esc_attr( $uid ); ?>-title"><?php esc_html_e( 'اطلاعات خود را وارد کنید', 'lucky-egg' ); ?></h3>
						<p class="lucky-egg-modal__desc"><?php esc_html_e( 'برای دریافت کد قرعه‌کشی، نام و شماره موبایل خود را وارد کنید.', 'lucky-egg' ); ?></p>
						<form class="lucky-egg-modal__form" novalidate>
							<label class="lucky-egg-modal__field">
								<span><?php esc_html_e( 'نام و نام خانوادگی', 'lucky-egg' ); ?></span>
								<input type="text" name="name" autocomplete="name" maxlength="60" required>
							</label>
							<label class="lucky-egg-modal__field">
								<span><?php esc_html_e( 'شماره موبایل', 'lucky-egg' ); ?></span>
								<input type="tel" name="phone" inputmode="numeric" autocomplete="tel" maxlength="20" placeholder="09xxxxxxxxx" dir="ltr" required>
							</label>
							<div class="lucky-egg-modal__error" role="alert" hidden></div>
							<button type="submit" class="lucky-egg-modal__submit"><?php esc_html_e( 'ثبت و ادامه', 'lucky-egg' ); ?></button>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shows configuration notices only to administrators.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function admin_notice( $message ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<div class="lucky-egg-admin-notice" dir="rtl" style="padding:12px;border:1px dashed #d63638;color:#d63638;border-radius:8px;">' . esc_html( $message ) . '</div>';
	}
}
