<?php
/**
 * Admin: menu, campaign CRUD, participants, statistics, settings and CSV export.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Admin
 */
class Lucky_Egg_Admin {

	const CAP          = 'manage_options';
	const PER_PAGE     = 20;
	const EXPORT_BATCH = 1000;

	/** @var Lucky_Egg_Campaign */
	private $campaigns;

	/** @var Lucky_Egg_Entry */
	private $entries;

	/** @var Lucky_Egg_Rate_Limiter */
	private $limiter;

	/** @var Lucky_Egg_SMS */
	private $sms;

	/**
	 * Constructor.
	 *
	 * @param Lucky_Egg_Campaign     $campaigns Campaigns.
	 * @param Lucky_Egg_Entry        $entries   Entries.
	 * @param Lucky_Egg_Rate_Limiter $limiter   Rate limiter.
	 * @param Lucky_Egg_SMS          $sms       SMS.
	 */
	public function __construct( Lucky_Egg_Campaign $campaigns, Lucky_Egg_Entry $entries, Lucky_Egg_Rate_Limiter $limiter, Lucky_Egg_SMS $sms ) {
		$this->campaigns = $campaigns;
		$this->entries   = $entries;
		$this->limiter   = $limiter;
		$this->sms       = $sms;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$handlers = array(
			'lucky_egg_save_campaign'   => 'handle_save_campaign',
			'lucky_egg_toggle_campaign' => 'handle_toggle_campaign',
			'lucky_egg_delete_campaign' => 'handle_delete_campaign',
			'lucky_egg_export_csv'      => 'handle_export_csv',
			'lucky_egg_save_settings'   => 'handle_save_settings',
			'lucky_egg_test_sms'        => 'handle_test_sms',
			'lucky_egg_clear_log'       => 'handle_clear_log',
		);
		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Infrastructure
	 * ------------------------------------------------------------------- */

	/**
	 * Admin menu.
	 */
	public function register_menu() {
		$icon_file = LUCKY_EGG_PATH . 'assets/img/icon.svg';
		$icon      = 'dashicons-awards';
		if ( is_readable( $icon_file ) ) {
			$svg = file_get_contents( $icon_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $svg ) {
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		add_menu_page( __( 'تخم‌مرغ شانسی', 'lucky-egg' ), __( 'تخم‌مرغ شانسی', 'lucky-egg' ), self::CAP, 'lucky-egg', array( $this, 'render_campaigns_page' ), $icon, 56 );
		add_submenu_page( 'lucky-egg', __( 'کمپین‌ها', 'lucky-egg' ), __( 'کمپین‌ها', 'lucky-egg' ), self::CAP, 'lucky-egg', array( $this, 'render_campaigns_page' ) );
		add_submenu_page( 'lucky-egg', __( 'افزودن / ویرایش کمپین', 'lucky-egg' ), __( 'افزودن کمپین', 'lucky-egg' ), self::CAP, 'lucky-egg-edit', array( $this, 'render_edit_page' ) );
		add_submenu_page( 'lucky-egg', __( 'شرکت‌کنندگان', 'lucky-egg' ), __( 'شرکت‌کنندگان', 'lucky-egg' ), self::CAP, 'lucky-egg-participants', array( $this, 'render_participants_page' ) );
		add_submenu_page( 'lucky-egg', __( 'آمار', 'lucky-egg' ), __( 'آمار', 'lucky-egg' ), self::CAP, 'lucky-egg-stats', array( $this, 'render_stats_page' ) );
		add_submenu_page( 'lucky-egg', __( 'تنظیمات', 'lucky-egg' ), __( 'تنظیمات', 'lucky-egg' ), self::CAP, 'lucky-egg-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Admin assets only on plugin pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'lucky-egg' ) ) {
			return;
		}
		wp_enqueue_style( 'lucky-egg-admin', LUCKY_EGG_URL . 'assets/css/admin.css', array(), Lucky_Egg::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'lucky-egg-admin', LUCKY_EGG_URL . 'assets/js/admin.js', array(), Lucky_Egg::asset_version( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'lucky-egg-admin',
			'LuckyEggAdmin',
			array(
				'i18n' => array(
					'selectImage' => __( 'انتخاب تصویر', 'lucky-egg' ),
					'useImage'    => __( 'استفاده از این تصویر', 'lucky-egg' ),
					'copied'      => __( 'کپی شد', 'lucky-egg' ),
				),
			)
		);
		if ( false !== strpos( (string) $hook, 'lucky-egg-edit' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Dies unless the user has the required capability.
	 */
	private function require_cap() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'lucky-egg' ), esc_html__( 'دسترسی غیرمجاز', 'lucky-egg' ), array( 'response' => 403 ) );
		}
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	private function page_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirects to a plugin page and exits.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 */
	private function redirect( $page, array $args = array() ) {
		wp_safe_redirect( $this->page_url( $page, $args ) );
		exit;
	}

	/**
	 * Renders a result notice from the "le_msg" query arg.
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET['le_msg'] ) ? sanitize_key( wp_unslash( $_GET['le_msg'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$messages = array(
			'saved'          => array( 'success', __( 'کمپین ذخیره شد.', 'lucky-egg' ) ),
			'deleted'        => array( 'success', __( 'کمپین و تمام داده‌های آن حذف شد.', 'lucky-egg' ) ),
			'toggled'        => array( 'success', __( 'وضعیت کمپین تغییر کرد.', 'lucky-egg' ) ),
			'settings_saved' => array( 'success', __( 'تنظیمات ذخیره شد.', 'lucky-egg' ) ),
			'log_cleared'    => array( 'success', __( 'گزارش خطاها پاک شد.', 'lucky-egg' ) ),
			'test_ok'        => array( 'success', __( 'پیامک آزمایشی با موفقیت ارسال شد.', 'lucky-egg' ) ),
			'test_failed'    => array( 'error', __( 'ارسال پیامک آزمایشی ناموفق بود. جزئیات در گزارش خطاها ثبت شد.', 'lucky-egg' ) ),
			'invalid_phone'  => array( 'error', __( 'شماره موبایل معتبر نیست.', 'lucky-egg' ) ),
			'invalid_title'  => array( 'error', __( 'عنوان کمپین الزامی است.', 'lucky-egg' ) ),
			'invalid_key'    => array( 'error', __( 'کلید کمپین باید ۲ تا ۶۴ کاراکتر و فقط شامل حروف کوچک انگلیسی، عدد، خط تیره یا زیرخط باشد.', 'lucky-egg' ) ),
			'key_exists'     => array( 'error', __( 'این کلید کمپین قبلاً استفاده شده است.', 'lucky-egg' ) ),
			'not_found'      => array( 'error', __( 'کمپین یافت نشد.', 'lucky-egg' ) ),
			'db_error'       => array( 'error', __( 'خطای پایگاه داده. جزئیات در گزارش خطاها ثبت شد.', 'lucky-egg' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
	}

	/* ---------------------------------------------------------------------
	 * Campaign list
	 * ------------------------------------------------------------------- */

	/**
	 * Campaign list page.
	 */
	public function render_campaigns_page() {
		$this->require_cap();
		$campaigns = $this->campaigns->get_all();
		$stats     = $this->entries->get_stats_by_campaign();
		?>
		<div class="wrap lucky-egg-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'کمپین‌های تخم‌مرغ شانسی', 'lucky-egg' ); ?></h1>
			<a href="<?php echo esc_url( $this->page_url( 'lucky-egg-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'افزودن کمپین', 'lucky-egg' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_notice(); ?>

			<?php if ( empty( $campaigns ) ) : ?>
				<div class="lucky-egg-empty">
					<p><?php esc_html_e( 'هنوز کمپینی ساخته نشده است.', 'lucky-egg' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $this->page_url( 'lucky-egg-edit' ) ); ?>"><?php esc_html_e( 'ساخت اولین کمپین', 'lucky-egg' ); ?></a>
				</div>
			<?php else : ?>
				<table class="widefat striped lucky-egg-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'عنوان', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'شورت‌کد', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'شکستن‌ها', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'تاریخ ایجاد', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'آخرین بروزرسانی', 'lucky-egg' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'lucky-egg' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $campaigns as $campaign ) : ?>
						<?php
						$id        = (int) $campaign['id'];
						$is_active = Lucky_Egg_Campaign::is_active( $campaign );
						$cracks    = isset( $stats[ $id ] ) ? $stats[ $id ]['cracks'] : 0;
						$shortcode = '[lucky_egg campaign="' . $campaign['campaign_key'] . '"]';
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $this->page_url( 'lucky-egg-edit', array( 'id' => $id ) ) ); ?>"><?php echo esc_html( $campaign['title'] ); ?></a></strong>
								<div class="lucky-egg-muted"><?php echo esc_html( $campaign['campaign_key'] ); ?></div>
							</td>
							<td><code class="lucky-egg-copy" data-le-copy="<?php echo esc_attr( $shortcode ); ?>" dir="ltr" title="<?php esc_attr_e( 'برای کپی کلیک کنید', 'lucky-egg' ); ?>"><?php echo esc_html( $shortcode ); ?></code></td>
							<td><span class="lucky-egg-badge <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>"><?php echo $is_active ? esc_html__( 'فعال', 'lucky-egg' ) : esc_html__( 'غیرفعال', 'lucky-egg' ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $cracks ) ); ?></td>
							<td><?php echo esc_html( Lucky_Egg::format_datetime( $campaign['created_at'] ) ); ?></td>
							<td><?php echo esc_html( Lucky_Egg::format_datetime( $campaign['updated_at'] ) ); ?></td>
							<td class="lucky-egg-actions">
								<a class="button button-small" href="<?php echo esc_url( $this->page_url( 'lucky-egg-edit', array( 'id' => $id ) ) ); ?>"><?php esc_html_e( 'ویرایش', 'lucky-egg' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( $this->page_url( 'lucky-egg-participants', array( 'campaign_id' => $id ) ) ); ?>"><?php esc_html_e( 'شرکت‌کنندگان', 'lucky-egg' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( $this->export_url( $id ) ); ?>"><?php esc_html_e( 'خروجی CSV', 'lucky-egg' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lucky-egg-inline-form">
									<input type="hidden" name="action" value="lucky_egg_toggle_campaign">
									<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
									<?php wp_nonce_field( 'lucky_egg_toggle_campaign_' . $id ); ?>
									<button type="submit" class="button button-small"><?php echo $is_active ? esc_html__( 'غیرفعال‌سازی', 'lucky-egg' ) : esc_html__( 'فعال‌سازی', 'lucky-egg' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lucky-egg-inline-form">
									<input type="hidden" name="action" value="lucky_egg_delete_campaign">
									<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
									<?php wp_nonce_field( 'lucky_egg_delete_campaign_' . $id ); ?>
									<button type="submit" class="button button-small button-link-delete" data-le-confirm="<?php esc_attr_e( 'این کمپین و تمام شرکت‌کنندگان و کدهای آن برای همیشه حذف می‌شوند. ادامه می‌دهید؟', 'lucky-egg' ); ?>"><?php esc_html_e( 'حذف', 'lucky-egg' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Nonce-protected export URL.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return string
	 */
	private function export_url( $campaign_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'lucky_egg_export_csv',
					'campaign_id' => (int) $campaign_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lucky_egg_export_' . (int) $campaign_id
		);
	}

	/* ---------------------------------------------------------------------
	 * Campaign edit
	 * ------------------------------------------------------------------- */

	/**
	 * Add/edit campaign page.
	 */
	public function render_edit_page() {
		$this->require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$campaign = $id ? $this->campaigns->get( $id ) : null;

		if ( $id && ! $campaign ) {
			echo '<div class="wrap lucky-egg-admin"><div class="notice notice-error"><p>' . esc_html__( 'کمپین یافت نشد.', 'lucky-egg' ) . '</p></div></div>';
			return;
		}

		$values = array(
			'title'        => $campaign ? $campaign['title'] : '',
			'campaign_key' => $campaign ? $campaign['campaign_key'] : '',
			'status'       => $campaign ? $campaign['status'] : Lucky_Egg_Campaign::STATUS_ACTIVE,
			'settings'     => $campaign ? $campaign['settings'] : Lucky_Egg_Campaign::get_default_settings(),
		);

		$transient_key = 'lucky_egg_form_' . get_current_user_id();
		$draft         = get_transient( $transient_key );
		if ( is_array( $draft ) && isset( $draft['id'] ) && (int) $draft['id'] === $id ) {
			$values = array_merge( $values, array_intersect_key( $draft, $values ) );
			delete_transient( $transient_key );
		}

		$s = $values['settings'];
		?>
		<div class="wrap lucky-egg-admin">
			<h1><?php echo $campaign ? esc_html__( 'ویرایش کمپین', 'lucky-egg' ) : esc_html__( 'افزودن کمپین جدید', 'lucky-egg' ); ?></h1>
			<?php $this->render_notice(); ?>

			<?php if ( $campaign ) : ?>
				<p class="lucky-egg-muted">
					<?php esc_html_e( 'شورت‌کد:', 'lucky-egg' ); ?>
					<code dir="ltr" class="lucky-egg-copy" data-le-copy="<?php echo esc_attr( '[lucky_egg campaign="' . $campaign['campaign_key'] . '"]' ); ?>"><?php echo esc_html( '[lucky_egg campaign="' . $campaign['campaign_key'] . '"]' ); ?></code>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lucky-egg-form">
				<input type="hidden" name="action" value="lucky_egg_save_campaign">
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'lucky_egg_save_campaign' ); ?>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'اطلاعات کلی', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="le-title"><?php esc_html_e( 'عنوان', 'lucky-egg' ); ?></label></th>
							<td><input type="text" id="le-title" name="title" class="regular-text" value="<?php echo esc_attr( $values['title'] ); ?>" required maxlength="191"></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-key"><?php esc_html_e( 'کلید کمپین (Campaign Key)', 'lucky-egg' ); ?></label></th>
							<td>
								<input type="text" id="le-key" name="campaign_key" class="regular-text" dir="ltr" value="<?php echo esc_attr( $values['campaign_key'] ); ?>" required pattern="[a-z0-9_\-]{2,64}" maxlength="64">
								<p class="description"><?php esc_html_e( 'فقط حروف کوچک انگلیسی، عدد، - و _ . مثال: gold', 'lucky-egg' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'وضعیت', 'lucky-egg' ); ?></th>
							<td>
								<select name="status">
									<option value="active" <?php selected( $values['status'], 'active' ); ?>><?php esc_html_e( 'فعال', 'lucky-egg' ); ?></option>
									<option value="inactive" <?php selected( $values['status'], 'inactive' ); ?>><?php esc_html_e( 'غیرفعال', 'lucky-egg' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'نمایش برای', 'lucky-egg' ); ?></th>
							<td><?php $this->select( 'settings[visibility]', Lucky_Egg_Campaign::visibility_choices(), $s['visibility'] ); ?></td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'تصاویر', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->image_row( 'egg_image', __( 'تصویر تخم‌مرغ', 'lucky-egg' ), $s['egg_image'], LUCKY_EGG_URL . 'assets/img/egg.svg' );
						$this->image_row( 'crack_image', __( 'تصویر ترک', 'lucky-egg' ), $s['crack_image'], LUCKY_EGG_URL . 'assets/img/crack.svg' );
						?>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'متن‌ها', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="le-hint"><?php esc_html_e( 'متن راهنما (قبل از کلیک)', 'lucky-egg' ); ?></label></th>
							<td><input type="text" id="le-hint" name="settings[hint_text]" class="large-text" value="<?php echo esc_attr( $s['hint_text'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-ready"><?php esc_html_e( 'متن بعد از ترک خوردن', 'lucky-egg' ); ?></label></th>
							<td><input type="text" id="le-ready" name="settings[ready_text]" class="large-text" value="<?php echo esc_attr( $s['ready_text'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-rtitle"><?php esc_html_e( 'عنوان نتیجه', 'lucky-egg' ); ?></label></th>
							<td><input type="text" id="le-rtitle" name="settings[result_title]" class="large-text" value="<?php echo esc_attr( $s['result_title'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-rmsg"><?php esc_html_e( 'متن کنار کد', 'lucky-egg' ); ?></label></th>
							<td><textarea id="le-rmsg" name="settings[result_message]" class="large-text" rows="3"><?php echo esc_textarea( $s['result_message'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'موقعیت متن نسبت به کد', 'lucky-egg' ); ?></th>
							<td><?php $this->select( 'settings[text_position]', Lucky_Egg_Campaign::text_position_choices(), $s['text_position'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-rfoot"><?php esc_html_e( 'متن پایانی', 'lucky-egg' ); ?></label></th>
							<td><textarea id="le-rfoot" name="settings[result_footer]" class="large-text" rows="2"><?php echo esc_textarea( $s['result_footer'] ); ?></textarea></td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'کد قرعه‌کشی', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="le-prefix"><?php esc_html_e( 'پیشوند کد', 'lucky-egg' ); ?></label></th>
							<td>
								<input type="text" id="le-prefix" name="settings[code_prefix]" dir="ltr" maxlength="16" value="<?php echo esc_attr( $s['code_prefix'] ); ?>">
								<p class="description"><?php esc_html_e( 'مثال: GOLD- (فقط حروف انگلیسی، عدد، - و _؛ حداکثر ۱۶ کاراکتر)', 'lucky-egg' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="le-length"><?php esc_html_e( 'طول بخش تصادفی', 'lucky-egg' ); ?></label></th>
							<td><input type="number" id="le-length" name="settings[code_length]" min="4" max="32" value="<?php echo esc_attr( $s['code_length'] ); ?>"></td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'محدودیت شرکت (Rate Limit)', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'تعداد مجاز', 'lucky-egg' ); ?></th>
							<td class="lucky-egg-inline-fields">
								<input type="number" name="settings[rate_limit_count]" min="1" max="1000" value="<?php echo esc_attr( $s['rate_limit_count'] ); ?>">
								<span><?php esc_html_e( 'بار در هر', 'lucky-egg' ); ?></span>
								<input type="number" name="settings[rate_limit_period]" min="1" max="8760" value="<?php echo esc_attr( $s['rate_limit_period'] ); ?>">
								<?php $this->select( 'settings[rate_limit_unit]', Lucky_Egg_Campaign::rate_unit_choices(), $s['rate_limit_unit'] ); ?>
								<p class="description"><?php esc_html_e( 'بر اساس شناسه کاربر، IP و کوکی به‌صورت همزمان در سمت سرور کنترل می‌شود.', 'lucky-egg' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'صدا، لرزش و پیامک', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'صدا', 'lucky-egg' ); ?></th>
							<td><label><input type="checkbox" name="settings[sound_enabled]" value="1" <?php checked( $s['sound_enabled'], 1 ); ?>> <?php esc_html_e( 'پخش صدای شکستن', 'lucky-egg' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'لرزش موبایل', 'lucky-egg' ); ?></th>
							<td><label><input type="checkbox" name="settings[vibrate_enabled]" value="1" <?php checked( $s['vibrate_enabled'], 1 ); ?>> <?php esc_html_e( 'لرزش هنگام شکستن (در صورت پشتیبانی مرورگر)', 'lucky-egg' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'پیامک', 'lucky-egg' ); ?></th>
							<td><label><input type="checkbox" name="settings[sms_enabled]" value="1" <?php checked( $s['sms_enabled'], 1 ); ?>> <?php esc_html_e( 'ارسال کد با پیامک برای این کمپین', 'lucky-egg' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-smstpl"><?php esc_html_e( 'متن پیامک اختصاصی', 'lucky-egg' ); ?></label></th>
							<td>
								<textarea id="le-smstpl" name="settings[sms_template]" class="large-text" rows="3"><?php echo esc_textarea( $s['sms_template'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'خالی = متن پیش‌فرض تنظیمات. متغیرها: {code} ، {name} ، {campaign}', 'lucky-egg' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'استایل', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'اندازه تخم‌مرغ (px)', 'lucky-egg' ); ?></th>
							<td><input type="number" name="settings[egg_size]" min="80" max="800" value="<?php echo esc_attr( $s['egg_size'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'اندازه فونت (px)', 'lucky-egg' ); ?></th>
							<td><input type="number" name="settings[font_size]" min="10" max="48" value="<?php echo esc_attr( $s['font_size'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'گردی گوشه‌ها (px)', 'lucky-egg' ); ?></th>
							<td><input type="number" name="settings[border_radius]" min="0" max="200" value="<?php echo esc_attr( $s['border_radius'] ); ?>"></td>
						</tr>
						<?php
						$this->color_row( 'bg_color', __( 'رنگ پس‌زمینه', 'lucky-egg' ), $s['bg_color'], true );
						$this->color_row( 'text_color', __( 'رنگ متن', 'lucky-egg' ), $s['text_color'], false );
						$this->color_row( 'accent_color', __( 'رنگ تأکیدی', 'lucky-egg' ), $s['accent_color'], false );
						$this->color_row( 'code_color', __( 'رنگ کد', 'lucky-egg' ), $s['code_color'], false );
						$this->color_row( 'code_bg_color', __( 'پس‌زمینه کد', 'lucky-egg' ), $s['code_bg_color'], false );
						?>
					</table>
				</div>

				<?php submit_button( $campaign ? __( 'بروزرسانی کمپین', 'lucky-egg' ) : __( 'ساخت کمپین', 'lucky-egg' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Select helper.
	 *
	 * @param string $name     Name.
	 * @param array  $choices  Choices.
	 * @param string $selected Selected.
	 */
	private function select( $name, array $choices, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Media-library image row.
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Label.
	 * @param string $value    URL.
	 * @param string $fallback Default image.
	 */
	private function image_row( $key, $label, $value, $fallback ) {
		$input_id   = 'le-' . $key;
		$preview_id = 'le-' . $key . '-preview';
		$preview    = '' !== $value ? $value : $fallback;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="lucky-egg-media">
					<img id="<?php echo esc_attr( $preview_id ); ?>" src="<?php echo esc_url( $preview ); ?>" data-fallback="<?php echo esc_url( $fallback ); ?>" alt="">
					<div>
						<input type="url" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" class="regular-text" dir="ltr" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'پیش‌فرض افزونه', 'lucky-egg' ); ?>">
						<p>
							<button type="button" class="button" data-le-media="<?php echo esc_attr( $input_id ); ?>" data-le-preview="<?php echo esc_attr( $preview_id ); ?>"><?php esc_html_e( 'انتخاب از کتابخانه رسانه', 'lucky-egg' ); ?></button>
							<button type="button" class="button-link" data-le-clear="<?php echo esc_attr( $input_id ); ?>" data-le-preview="<?php echo esc_attr( $preview_id ); ?>"><?php esc_html_e( 'بازگشت به پیش‌فرض', 'lucky-egg' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'فرمت‌های مجاز: JPG، PNG، GIF، WebP، AVIF', 'lucky-egg' ); ?></p>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Color row.
	 *
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param bool   $allow_empty Whether empty is allowed.
	 */
	private function color_row( $key, $label, $value, $allow_empty ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td class="lucky-egg-inline-fields">
				<input type="text" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" dir="ltr" class="lucky-egg-color-text" maxlength="7" placeholder="#000000" pattern="#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?">
				<input type="color" class="lucky-egg-color-picker" value="<?php echo esc_attr( '' !== $value && 7 === strlen( $value ) ? $value : '#ffffff' ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php if ( $allow_empty ) : ?>
					<span class="description"><?php esc_html_e( 'خالی = شفاف', 'lucky-egg' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves a campaign.
	 */
	public function handle_save_campaign() {
		$this->require_cap();
		check_admin_referer( 'lucky_egg_save_campaign' );

		$id           = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$raw_settings = ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted in Lucky_Egg_Campaign::sanitize_settings().

		$data = array(
			'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'campaign_key' => isset( $_POST['campaign_key'] ) ? sanitize_key( wp_unslash( $_POST['campaign_key'] ) ) : '',
			'status'       => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
			'settings'     => $raw_settings,
		);

		$result = $this->campaigns->save( $data, $id );

		if ( is_wp_error( $result ) ) {
			set_transient(
				'lucky_egg_form_' . get_current_user_id(),
				array(
					'id'           => $id,
					'title'        => $data['title'],
					'campaign_key' => $data['campaign_key'],
					'status'       => 'inactive' === $data['status'] ? 'inactive' : 'active',
					'settings'     => Lucky_Egg_Campaign::sanitize_settings( $raw_settings ),
				),
				5 * MINUTE_IN_SECONDS
			);
			$args = array( 'le_msg' => $result->get_error_code() );
			if ( $id ) {
				$args['id'] = $id;
			}
			$this->redirect( 'lucky-egg-edit', $args );
		}

		$this->redirect(
			'lucky-egg-edit',
			array(
				'id'     => (int) $result,
				'le_msg' => 'saved',
			)
		);
	}

	/**
	 * Toggles status.
	 */
	public function handle_toggle_campaign() {
		$this->require_cap();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'lucky_egg_toggle_campaign_' . $id );

		$campaign = $this->campaigns->get( $id );
		if ( ! $campaign ) {
			$this->redirect( 'lucky-egg', array( 'le_msg' => 'not_found' ) );
		}
		$new = Lucky_Egg_Campaign::is_active( $campaign ) ? Lucky_Egg_Campaign::STATUS_INACTIVE : Lucky_Egg_Campaign::STATUS_ACTIVE;
		$this->campaigns->set_status( $id, $new );
		$this->redirect( 'lucky-egg', array( 'le_msg' => 'toggled' ) );
	}

	/**
	 * Deletes a campaign together with its entries and rate-limit rows.
	 */
	public function handle_delete_campaign() {
		$this->require_cap();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'lucky_egg_delete_campaign_' . $id );

		if ( ! $this->campaigns->get( $id ) ) {
			$this->redirect( 'lucky-egg', array( 'le_msg' => 'not_found' ) );
		}

		$ok = $this->entries->delete_by_campaign( $id )
			&& $this->limiter->delete_by_campaign( $id )
			&& $this->campaigns->delete( $id );

		if ( ! $ok ) {
			Lucky_Egg_Logger::error( 'Campaign deletion failed.', array( 'campaign_id' => $id ) );
			$this->redirect( 'lucky-egg', array( 'le_msg' => 'db_error' ) );
		}

		do_action( 'lucky_egg_campaign_deleted', $id );
		$this->redirect( 'lucky-egg', array( 'le_msg' => 'deleted' ) );
	}

	/* ---------------------------------------------------------------------
	 * Participants
	 * ------------------------------------------------------------------- */

	/**
	 * Participants page with filter, search and pagination.
	 */
	public function render_participants_page() {
		$this->require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$result  = $this->entries->query(
			array(
				'campaign_id' => $campaign_id,
				'search'      => $search,
				'page'        => $paged,
				'per_page'    => self::PER_PAGE,
			)
		);
		$total   = $result['total'];
		$pages   = (int) ceil( $total / self::PER_PAGE );
		$choices = $this->campaigns->get_choices( 'id' );
		$labels  = Lucky_Egg_Entry::sms_status_labels();
		?>
		<div class="wrap lucky-egg-admin">
			<h1><?php esc_html_e( 'شرکت‌کنندگان', 'lucky-egg' ); ?></h1>
			<?php $this->render_notice(); ?>

			<form method="get" class="lucky-egg-filters">
				<input type="hidden" name="page" value="lucky-egg-participants">
				<select name="campaign_id">
					<option value="0"><?php esc_html_e( 'همه کمپین‌ها', 'lucky-egg' ); ?></option>
					<?php foreach ( $choices as $cid => $label ) : ?>
						<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $campaign_id, (int) $cid ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجو در نام، موبایل یا کد', 'lucky-egg' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'فیلتر', 'lucky-egg' ); ?></button>
				<?php if ( $campaign_id ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $this->export_url( $campaign_id ) ); ?>"><?php esc_html_e( 'خروجی CSV این کمپین', 'lucky-egg' ); ?></a>
				<?php endif; ?>
			</form>

			<p class="lucky-egg-muted">
				<?php
				/* translators: %s: number of records */
				echo esc_html( sprintf( __( '%s رکورد', 'lucky-egg' ), number_format_i18n( $total ) ) );
				?>
			</p>

			<table class="widefat striped lucky-egg-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'نام', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'موبایل', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'کد', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'کمپین', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'تاریخ و زمان', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'پیامک', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'نوع کاربر', 'lucky-egg' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'رکوردی یافت نشد.', 'lucky-egg' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['items'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->name ); ?></td>
							<td dir="ltr" class="lucky-egg-ltr"><?php echo esc_html( $row->phone ); ?></td>
							<td dir="ltr" class="lucky-egg-ltr"><code><?php echo esc_html( $row->code ); ?></code></td>
							<td><?php echo esc_html( $row->campaign_title ? $row->campaign_title : '—' ); ?></td>
							<td><?php echo esc_html( Lucky_Egg::format_datetime( $row->created_at ) ); ?></td>
							<td><span class="lucky-egg-badge sms-<?php echo esc_attr( $row->sms_status ); ?>"><?php echo esc_html( isset( $labels[ $row->sms_status ] ) ? $labels[ $row->sms_status ] : $row->sms_status ); ?></span></td>
							<td>
								<?php if ( $row->user_id ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( (int) $row->user_id ) ); ?>"><?php esc_html_e( 'عضو', 'lucky-egg' ); ?> #<?php echo esc_html( (int) $row->user_id ); ?></a>
								<?php else : ?>
									<?php esc_html_e( 'مهمان', 'lucky-egg' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			if ( $pages > 1 ) {
				$base = add_query_arg(
					'paged',
					'%#%',
					$this->page_url(
						'lucky-egg-participants',
						array(
							'campaign_id' => $campaign_id,
							's'           => rawurlencode( $search ),
						)
					)
				);
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => $base,
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&rsaquo;',
							'next_text' => '&lsaquo;',
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Statistics
	 * ------------------------------------------------------------------- */

	/**
	 * Statistics page.
	 */
	public function render_stats_page() {
		$this->require_cap();
		$totals    = $this->entries->get_totals();
		$per       = $this->entries->get_stats_by_campaign();
		$campaigns = $this->campaigns->get_all();

		$cards = array(
			__( 'کل کمپین‌ها', 'lucky-egg' )        => $this->campaigns->count(),
			__( 'کمپین‌های فعال', 'lucky-egg' )     => $this->campaigns->count( Lucky_Egg_Campaign::STATUS_ACTIVE ),
			__( 'تعداد شرکت‌کنندگان', 'lucky-egg' ) => $totals['participants'],
			__( 'تعداد شکستن‌ها', 'lucky-egg' )     => $totals['cracks'],
			__( 'شکستن‌های امروز', 'lucky-egg' )    => $totals['today'],
			__( 'پیامک موفق', 'lucky-egg' )         => $totals['sms_sent'],
			__( 'پیامک ناموفق', 'lucky-egg' )       => $totals['sms_failed'],
		);
		?>
		<div class="wrap lucky-egg-admin">
			<h1><?php esc_html_e( 'آمار', 'lucky-egg' ); ?></h1>
			<div class="lucky-egg-cards">
				<?php foreach ( $cards as $label => $value ) : ?>
					<div class="lucky-egg-stat">
						<span class="lucky-egg-stat__value"><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></span>
						<span class="lucky-egg-stat__label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'آمار هر کمپین', 'lucky-egg' ); ?></h2>
			<table class="widefat striped lucky-egg-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'کمپین', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'شرکت‌کنندگان', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'شکستن‌ها', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'پیامک موفق', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'پیامک ناموفق', 'lucky-egg' ); ?></th>
						<th><?php esc_html_e( 'آخرین شکستن', 'lucky-egg' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $campaigns ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'کمپینی وجود ندارد.', 'lucky-egg' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $campaigns as $campaign ) : ?>
					<?php
					$row = isset( $per[ $campaign['id'] ] ) ? $per[ $campaign['id'] ] : array(
						'participants' => 0,
						'cracks'       => 0,
						'sms_sent'     => 0,
						'sms_failed'   => 0,
						'last_crack'   => '',
					);
					?>
					<tr>
						<td><?php echo esc_html( $campaign['title'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['participants'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['cracks'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['sms_sent'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['sms_failed'] ) ); ?></td>
						<td><?php echo esc_html( $row['last_crack'] ? Lucky_Egg::format_datetime( $row['last_crack'] ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * Masks a secret for display.
	 *
	 * @param string $value Secret.
	 * @return string
	 */
	private function mask( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 6 ) {
			return str_repeat( '•', 6 );
		}
		return str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	/**
	 * Secret input row (never prints the stored value).
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Label.
	 * @param array  $settings Settings.
	 */
	private function secret_row( $key, $label, array $settings ) {
		$masked = $this->mask( $settings[ $key ] );
		?>
		<tr>
			<th scope="row"><label for="le-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="password" id="le-<?php echo esc_attr( $key ); ?>" name="lucky_egg[<?php echo esc_attr( $key ); ?>]" class="regular-text" dir="ltr" autocomplete="new-password" value="">
				<?php if ( '' !== $masked ) : ?>
					<p class="description">
						<?php esc_html_e( 'مقدار فعلی:', 'lucky-egg' ); ?> <code dir="ltr"><?php echo esc_html( $masked ); ?></code>
						— <?php esc_html_e( 'برای حفظ مقدار فعلی خالی بگذارید.', 'lucky-egg' ); ?>
						<label><input type="checkbox" name="lucky_egg[clear_<?php echo esc_attr( $key ); ?>]" value="1"> <?php esc_html_e( 'حذف', 'lucky-egg' ); ?></label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Plain text setting row.
	 *
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param array  $settings Settings.
	 * @param bool   $ltr      Whether LTR.
	 */
	private function text_row( $key, $label, array $settings, $ltr = true ) {
		?>
		<tr>
			<th scope="row"><label for="le-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="text" id="le-<?php echo esc_attr( $key ); ?>" name="lucky_egg[<?php echo esc_attr( $key ); ?>]" class="regular-text" <?php echo $ltr ? 'dir="ltr"' : ''; ?> value="<?php echo esc_attr( $settings[ $key ] ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * Settings page.
	 */
	public function render_settings_page() {
		$this->require_cap();
		$s    = Lucky_Egg::get_settings();
		$logs = Lucky_Egg_Logger::get_entries();
		?>
		<div class="wrap lucky-egg-admin">
			<h1><?php esc_html_e( 'تنظیمات تخم‌مرغ شانسی', 'lucky-egg' ); ?></h1>
			<?php $this->render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lucky_egg_save_settings">
				<?php wp_nonce_field( 'lucky_egg_save_settings' ); ?>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'پیامک (SMS)', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'ارسال پیامک', 'lucky-egg' ); ?></th>
							<td><label><input type="checkbox" name="lucky_egg[sms_enabled]" value="1" <?php checked( $s['sms_enabled'], 1 ); ?>> <?php esc_html_e( 'فعال (کلید سراسری)', 'lucky-egg' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'درگاه پیامک', 'lucky-egg' ); ?></th>
							<td><?php $this->select( 'lucky_egg[sms_gateway]', $this->sms->get_gateway_choices(), $s['sms_gateway'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="le-sms-template"><?php esc_html_e( 'متن پیش‌فرض پیامک', 'lucky-egg' ); ?></label></th>
							<td>
								<textarea id="le-sms-template" name="lucky_egg[sms_template]" class="large-text" rows="3"><?php echo esc_textarea( $s['sms_template'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'متغیرها: {code} (الزامی)، {name}، {campaign}', 'lucky-egg' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'کاوه‌نگار', 'lucky-egg' ); ?></h3>
					<table class="form-table" role="presentation">
						<?php
						$this->secret_row( 'kavenegar_api_key', __( 'API Key', 'lucky-egg' ), $s );
						$this->text_row( 'kavenegar_sender', __( 'شماره خط ارسال‌کننده', 'lucky-egg' ), $s );
						?>
					</table>

					<h3><?php esc_html_e( 'ملی پیامک', 'lucky-egg' ); ?></h3>
					<table class="form-table" role="presentation">
						<?php
						$this->text_row( 'melipayamak_username', __( 'نام کاربری', 'lucky-egg' ), $s );
						$this->secret_row( 'melipayamak_password', __( 'رمز عبور / کلید API', 'lucky-egg' ), $s );
						$this->text_row( 'melipayamak_from', __( 'شماره خط ارسال‌کننده', 'lucky-egg' ), $s );
						?>
					</table>

					<h3>SMS.ir</h3>
					<table class="form-table" role="presentation">
						<?php
						$this->secret_row( 'smsir_api_key', __( 'API Key', 'lucky-egg' ), $s );
						$this->text_row( 'smsir_line_number', __( 'شماره خط', 'lucky-egg' ), $s );
						?>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'پیش‌فرض کمپین‌های جدید', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'محدودیت شرکت', 'lucky-egg' ); ?></th>
							<td class="lucky-egg-inline-fields">
								<input type="number" name="lucky_egg[default_rate_limit_count]" min="1" max="1000" value="<?php echo esc_attr( $s['default_rate_limit_count'] ); ?>">
								<span><?php esc_html_e( 'بار در هر', 'lucky-egg' ); ?></span>
								<input type="number" name="lucky_egg[default_rate_limit_period]" min="1" max="8760" value="<?php echo esc_attr( $s['default_rate_limit_period'] ); ?>">
								<?php $this->select( 'lucky_egg[default_rate_limit_unit]', Lucky_Egg_Campaign::rate_unit_choices(), $s['default_rate_limit_unit'] ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'نمایش برای', 'lucky-egg' ); ?></th>
							<td><?php $this->select( 'lucky_egg[default_visibility]', Lucky_Egg_Campaign::visibility_choices(), $s['default_visibility'] ); ?></td>
						</tr>
					</table>
				</div>

				<div class="lucky-egg-card">
					<h2><?php esc_html_e( 'تنظیمات عمومی و امنیتی', 'lucky-egg' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'پراکسی / CDN', 'lucky-egg' ); ?></th>
							<td>
								<label><input type="checkbox" name="lucky_egg[trust_proxy]" value="1" <?php checked( $s['trust_proxy'], 1 ); ?>> <?php esc_html_e( 'اعتماد به هدرهای X-Forwarded-For / CF-Connecting-IP', 'lucky-egg' ); ?></label>
								<p class="description"><?php esc_html_e( 'فقط اگر سایت پشت Cloudflare یا Reverse Proxy است فعال کنید؛ در غیر این صورت امکان جعل IP وجود دارد.', 'lucky-egg' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'حداکثر ثبت اطلاعات مهمان از یک IP در ساعت', 'lucky-egg' ); ?></th>
							<td>
								<input type="number" name="lucky_egg[registration_limit]" min="1" max="1000" value="<?php echo esc_attr( $s['registration_limit'] ); ?>">
								<p class="description"><?php esc_html_e( 'مهمان‌ها بدون ساخت حساب کاربری شرکت می‌کنند؛ احراز هویت کاربران فقط از طریق ورود استاندارد وردپرس انجام می‌شود.', 'lucky-egg' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'ذخیره تنظیمات', 'lucky-egg' ) ); ?>
			</form>

			<div class="lucky-egg-card">
				<h2><?php esc_html_e( 'ارسال پیامک آزمایشی', 'lucky-egg' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lucky-egg-inline-fields">
					<input type="hidden" name="action" value="lucky_egg_test_sms">
					<?php wp_nonce_field( 'lucky_egg_test_sms' ); ?>
					<input type="tel" name="phone" dir="ltr" placeholder="09xxxxxxxxx" required>
					<button type="submit" class="button"><?php esc_html_e( 'ارسال آزمایشی', 'lucky-egg' ); ?></button>
				</form>
			</div>

			<div class="lucky-egg-card">
				<h2><?php esc_html_e( 'گزارش خطاها', 'lucky-egg' ); ?></h2>
				<?php if ( empty( $logs ) ) : ?>
					<p class="lucky-egg-muted"><?php esc_html_e( 'خطایی ثبت نشده است.', 'lucky-egg' ); ?></p>
				<?php else : ?>
					<table class="widefat striped lucky-egg-table lucky-egg-log">
						<thead>
							<tr>
								<th><?php esc_html_e( 'زمان', 'lucky-egg' ); ?></th>
								<th><?php esc_html_e( 'سطح', 'lucky-egg' ); ?></th>
								<th><?php esc_html_e( 'پیام', 'lucky-egg' ); ?></th>
								<th><?php esc_html_e( 'جزئیات', 'lucky-egg' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( array_slice( $logs, 0, 50 ) as $log ) : ?>
							<tr>
								<td><?php echo esc_html( Lucky_Egg::format_datetime( isset( $log['time'] ) ? $log['time'] : '' ) ); ?></td>
								<td><?php echo esc_html( isset( $log['level'] ) ? $log['level'] : '' ); ?></td>
								<td dir="ltr" class="lucky-egg-ltr"><?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?></td>
								<td dir="ltr" class="lucky-egg-ltr"><code><?php echo esc_html( ! empty( $log['context'] ) ? wp_json_encode( $log['context'], JSON_UNESCAPED_UNICODE ) : '' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="lucky_egg_clear_log">
						<?php wp_nonce_field( 'lucky_egg_clear_log' ); ?>
						<p><button type="submit" class="button"><?php esc_html_e( 'پاک کردن گزارش', 'lucky-egg' ); ?></button></p>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Whitelists and sanitizes global settings.
	 *
	 * @param array $raw     Raw (unslashed).
	 * @param array $current Current settings.
	 * @return array
	 */
	private function sanitize_global_settings( array $raw, array $current ) {
		$d   = Lucky_Egg::default_settings();
		$out = $current;
		$raw = array_filter( $raw, 'is_scalar' );

		$out['sms_enabled'] = ! empty( $raw['sms_enabled'] ) ? 1 : 0;

		$gateway            = isset( $raw['sms_gateway'] ) ? sanitize_key( $raw['sms_gateway'] ) : '';
		$out['sms_gateway'] = array_key_exists( $gateway, $this->sms->get_gateway_choices() ) ? $gateway : $current['sms_gateway'];

		$template            = isset( $raw['sms_template'] ) ? sanitize_textarea_field( $raw['sms_template'] ) : '';
		$template            = function_exists( 'mb_substr' ) ? mb_substr( $template, 0, 500 ) : substr( $template, 0, 500 );
		$out['sms_template'] = '' !== trim( $template ) ? $template : $d['sms_template'];

		foreach ( array( 'kavenegar_sender', 'melipayamak_username', 'melipayamak_from', 'smsir_line_number' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? substr( sanitize_text_field( $raw[ $key ] ), 0, 100 ) : '';
		}

		foreach ( Lucky_Egg::secret_setting_keys() as $key ) {
			if ( ! empty( $raw[ 'clear_' . $key ] ) ) {
				$out[ $key ] = '';
			} elseif ( isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				$out[ $key ] = self::sanitize_secret( $raw[ $key ] );
			} else {
				$out[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : '';
			}
		}

		$unit                           = isset( $raw['default_rate_limit_unit'] ) && 'days' === $raw['default_rate_limit_unit'] ? 'days' : 'hours';
		$out['default_rate_limit_unit'] = $unit;
		$out['default_rate_limit_count'] = max( 1, min( 1000, isset( $raw['default_rate_limit_count'] ) ? absint( $raw['default_rate_limit_count'] ) : 1 ) );
		$out['default_rate_limit_period'] = max( 1, min( 'days' === $unit ? 365 : 8760, isset( $raw['default_rate_limit_period'] ) ? absint( $raw['default_rate_limit_period'] ) : 24 ) );

		$visibility                = isset( $raw['default_visibility'] ) ? sanitize_key( $raw['default_visibility'] ) : 'all';
		$out['default_visibility'] = array_key_exists( $visibility, Lucky_Egg_Campaign::visibility_choices() ) ? $visibility : 'all';

		$out['trust_proxy']        = ! empty( $raw['trust_proxy'] ) ? 1 : 0;
		$out['registration_limit'] = max( 1, min( 1000, isset( $raw['registration_limit'] ) ? absint( $raw['registration_limit'] ) : 10 ) );

		return array_intersect_key( $out, $d );
	}

	/**
	 * Secrets (API keys/passwords) may legitimately contain "%", "<" or ">" which
	 * sanitize_text_field() would silently strip. Only control characters and invalid UTF-8
	 * are removed; the value is never echoed back (see secret_row()).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_secret( $value ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return substr( trim( $value ), 0, 255 );
	}

	/**
	 * Saves global settings.
	 */
	public function handle_save_settings() {
		$this->require_cap();
		check_admin_referer( 'lucky_egg_save_settings' );

		$raw = ( isset( $_POST['lucky_egg'] ) && is_array( $_POST['lucky_egg'] ) ) ? wp_unslash( $_POST['lucky_egg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted in sanitize_global_settings().
		update_option( Lucky_Egg::SETTINGS_OPTION, $this->sanitize_global_settings( $raw, Lucky_Egg::get_settings() ) );

		$this->redirect( 'lucky-egg-settings', array( 'le_msg' => 'settings_saved' ) );
	}

	/**
	 * Sends a test SMS.
	 */
	public function handle_test_sms() {
		$this->require_cap();
		check_admin_referer( 'lucky_egg_test_sms' );

		$phone = Lucky_Egg_User::normalize_phone( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '' );
		if ( ! Lucky_Egg_User::is_valid_phone( $phone ) ) {
			$this->redirect( 'lucky-egg-settings', array( 'le_msg' => 'invalid_phone' ) );
		}

		$result = $this->sms->send_test( $phone );
		$this->redirect( 'lucky-egg-settings', array( 'le_msg' => true === $result ? 'test_ok' : 'test_failed' ) );
	}

	/**
	 * Clears the log.
	 */
	public function handle_clear_log() {
		$this->require_cap();
		check_admin_referer( 'lucky_egg_clear_log' );
		Lucky_Egg_Logger::clear();
		$this->redirect( 'lucky-egg-settings', array( 'le_msg' => 'log_cleared' ) );
	}

	/* ---------------------------------------------------------------------
	 * CSV export
	 * ------------------------------------------------------------------- */

	/**
	 * Neutralizes spreadsheet formula injection.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function csv_safe( $value ) {
		$value   = (string) $value;
		$trimmed = ltrim( $value, " \t\r\n\0\x0B" );
		if ( '' !== $value && ( $trimmed !== $value || ( '' !== $trimmed && in_array( $trimmed[0], array( '=', '+', '-', '@', '|', '%' ), true ) ) ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Streams a UTF-8 (BOM) CSV for one campaign, in keyset-paginated batches.
	 */
	public function handle_export_csv() {
		$this->require_cap();
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		check_admin_referer( 'lucky_egg_export_' . $campaign_id );

		$campaign = $this->campaigns->get( $campaign_id );
		if ( ! $campaign ) {
			wp_die( esc_html__( 'کمپین یافت نشد.', 'lucky-egg' ), '', array( 'response' => 404 ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = 'lucky-egg-' . $campaign['campaign_key'] . '-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv(
			$out,
			array(
				__( 'ردیف', 'lucky-egg' ),
				__( 'نام', 'lucky-egg' ),
				__( 'شماره موبایل', 'lucky-egg' ),
				__( 'کد', 'lucky-egg' ),
				__( 'کمپین', 'lucky-egg' ),
				__( 'تاریخ و زمان', 'lucky-egg' ),
				__( 'وضعیت پیامک', 'lucky-egg' ),
				__( 'IP', 'lucky-egg' ),
				__( 'نوع کاربر', 'lucky-egg' ),
				__( 'شناسه کاربر', 'lucky-egg' ),
			),
			',',
			'"',
			''
		);

		$labels   = Lucky_Egg_Entry::sms_status_labels();
		$after_id = 0;
		$index    = 0;

		do {
			$rows = $this->entries->get_export_batch( $campaign_id, $after_id, self::EXPORT_BATCH );
			foreach ( $rows as $row ) {
				++$index;
				$after_id = (int) $row->id;
				fputcsv(
					$out,
					array(
						$index,
						$this->csv_safe( $row->name ),
						$this->csv_safe( $row->phone ),
						$this->csv_safe( $row->code ),
						$this->csv_safe( $campaign['title'] ),
						$this->csv_safe( Lucky_Egg::format_datetime( $row->created_at ) ),
						$this->csv_safe( isset( $labels[ $row->sms_status ] ) ? $labels[ $row->sms_status ] : $row->sms_status ),
						$this->csv_safe( $row->ip ),
						$row->user_id ? __( 'عضو', 'lucky-egg' ) : __( 'مهمان', 'lucky-egg' ),
						$row->user_id ? (int) $row->user_id : '',
					),
					',',
					'"',
					''
				);
			}
			flush();
		} while ( count( $rows ) === self::EXPORT_BATCH );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
