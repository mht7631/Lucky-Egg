<?php
/**
 * Native Elementor widget. Loaded only from Lucky_Egg_Elementor when Elementor is active.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Class Lucky_Egg_Elementor_Widget
 */
class Lucky_Egg_Elementor_Widget extends \Elementor\Widget_Base {

	/** {@inheritDoc} */
	public function get_name() {
		return 'lucky_egg';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'تخم‌مرغ شانسی', 'lucky-egg' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'eicon-gift';
	}

	/** {@inheritDoc} */
	public function get_categories() {
		return array( 'general' );
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'lucky', 'egg', 'lottery', 'تخم مرغ', 'قرعه کشی' );
	}

	/** {@inheritDoc} */
	public function get_script_depends() {
		return array( Lucky_Egg::FRONT_HANDLE );
	}

	/** {@inheritDoc} */
	public function get_style_depends() {
		return array( Lucky_Egg::FRONT_HANDLE );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {

		/* Content ------------------------------------------------------ */
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'کمپین', 'lucky-egg' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$choices = array( '' => __( '— انتخاب کمپین —', 'lucky-egg' ) );
		if ( function_exists( 'lucky_egg' ) ) {
			$choices = $choices + lucky_egg()->campaigns->get_choices( 'key' );
		}

		$this->add_control(
			'campaign',
			array(
				'label'   => __( 'کمپین', 'lucky-egg' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $choices,
				'default' => '',
			)
		);

		$this->add_control(
			'egg_image',
			array(
				'label'       => __( 'تصویر تخم‌مرغ', 'lucky-egg' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'description' => __( 'خالی = تصویر تعریف‌شده در کمپین.', 'lucky-egg' ),
				'default'     => array( 'url' => '' ),
			)
		);

		$this->add_control(
			'crack_image',
			array(
				'label'   => __( 'تصویر ترک', 'lucky-egg' ),
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'default' => array( 'url' => '' ),
			)
		);

		$this->add_control(
			'text_position',
			array(
				'label'   => __( 'موقعیت متن نسبت به کد', 'lucky-egg' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array_merge( array( '' => __( 'مطابق کمپین', 'lucky-egg' ) ), Lucky_Egg_Campaign::text_position_choices() ),
				'default' => '',
			)
		);

		$this->end_controls_section();

		/* Style: box ---------------------------------------------------- */
		$this->start_controls_section(
			'section_box',
			array(
				'label' => __( 'کادر', 'lucky-egg' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'box_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .lucky-egg',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .lucky-egg',
			)
		);

		$this->add_responsive_control(
			'box_radius',
			array(
				'label'      => __( 'گردی گوشه‌ها', 'lucky-egg' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .lucky-egg' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'فاصله داخلی', 'lucky-egg' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .lucky-egg' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'spacing',
			array(
				'label'      => __( 'فاصله بین اجزا', 'lucky-egg' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .lucky-egg' => '--le-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		/* Style: egg ---------------------------------------------------- */
		$this->start_controls_section(
			'section_egg',
			array(
				'label' => __( 'تخم‌مرغ', 'lucky-egg' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_size',
			array(
				'label'      => __( 'اندازه تصویر', 'lucky-egg' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 80,
						'max' => 800,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .lucky-egg' => '--le-egg-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		/* Style: text --------------------------------------------------- */
		$this->start_controls_section(
			'section_text',
			array(
				'label' => __( 'متن و کد', 'lucky-egg' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'label'    => __( 'فونت', 'lucky-egg' ),
				'selector' => '{{WRAPPER}} .lucky-egg',
			)
		);

		$this->add_responsive_control(
			'font_size',
			array(
				'label'      => __( 'اندازه فونت', 'lucky-egg' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 48,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .lucky-egg' => '--le-font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'font_color',
			array(
				'label'     => __( 'رنگ فونت', 'lucky-egg' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .lucky-egg' => '--le-text-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'code_color',
			array(
				'label'     => __( 'رنگ کد', 'lucky-egg' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .lucky-egg' => '--le-code-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'code_background',
			array(
				'label'     => __( 'پس‌زمینه کد', 'lucky-egg' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .lucky-egg' => '--le-code-bg: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => __( 'رنگ تأکیدی', 'lucky-egg' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .lucky-egg' => '--le-accent: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Frontend + editor render. Output is fully escaped by the shared renderer.
	 */
	protected function render() {
		if ( ! function_exists( 'lucky_egg' ) ) {
			return;
		}

		$settings  = $this->get_settings_for_display();
		$key       = isset( $settings['campaign'] ) ? sanitize_key( (string) $settings['campaign'] ) : '';
		$overrides = array();

		if ( ! empty( $settings['egg_image']['url'] ) ) {
			$overrides['egg_image'] = (string) $settings['egg_image']['url'];
		}
		if ( ! empty( $settings['crack_image']['url'] ) ) {
			$overrides['crack_image'] = (string) $settings['crack_image']['url'];
		}
		if ( ! empty( $settings['text_position'] ) ) {
			$overrides['text_position'] = (string) $settings['text_position'];
		}

		$is_editor = false;
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$plugin    = \Elementor\Plugin::$instance;
			$is_editor = ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() )
				|| ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
		}

		if ( '' === $key && $is_editor ) {
			echo '<div class="lucky-egg-admin-notice" dir="rtl" style="padding:12px;border:1px dashed #999;border-radius:8px;">' . esc_html__( 'یک کمپین برای تخم‌مرغ شانسی انتخاب کنید.', 'lucky-egg' ) . '</div>';
			return;
		}

		echo lucky_egg()->shortcode->render( $key, $overrides, $is_editor ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderer.
	}
}
