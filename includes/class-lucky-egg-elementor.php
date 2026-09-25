<?php
/**
 * Elementor integration. Does nothing (and never fatals) when Elementor is absent.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lucky_Egg_Elementor
 */
class Lucky_Egg_Elementor {

	/**
	 * Hooks. These actions are only fired by Elementor, so nothing runs without it.
	 */
	public function register() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		/*
		 * The legacy hook is deprecated since Elementor 3.5 and merely hooking it triggers a
		 * deprecation notice there, so it is only attached on older versions.
		 */
		if ( did_action( 'elementor/loaded' ) ) {
			$this->maybe_hook_legacy();
		} else {
			add_action( 'elementor/loaded', array( $this, 'maybe_hook_legacy' ) );
		}
	}

	/**
	 * Attaches the legacy registration hook on Elementor < 3.5 only.
	 */
	public function maybe_hook_legacy() {
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '3.5.0', '<' ) ) {
			add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_widget_legacy' ) );
		}
	}

	/**
	 * Whether Elementor's widget API is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Widget_Base' );
	}

	/**
	 * Elementor >= 3.5.
	 *
	 * @param object $widgets_manager Widgets manager.
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! self::is_available() || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}
		require_once LUCKY_EGG_PATH . 'includes/elementor-widget.php';
		if ( class_exists( 'Lucky_Egg_Elementor_Widget' ) ) {
			$widgets_manager->register( new Lucky_Egg_Elementor_Widget() );
		}
	}

	/**
	 * Elementor < 3.5 (legacy API). Skipped on newer versions to avoid double registration.
	 */
	public function register_widget_legacy() {
		if ( ! self::is_available() || ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, '3.5.0', '>=' ) ) {
			return;
		}
		require_once LUCKY_EGG_PATH . 'includes/elementor-widget.php';
		if ( class_exists( 'Lucky_Egg_Elementor_Widget' ) ) {
			\Elementor\Plugin::instance()->widgets_manager->register_widget_type( new Lucky_Egg_Elementor_Widget() );
		}
	}
}
