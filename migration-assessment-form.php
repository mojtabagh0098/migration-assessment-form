<?php
/**
 * Plugin Name:       Migration Assessment Form
 * Plugin URI:        https://example.com/migration-assessment-form
 * Description:       افزونه اختصاصی فرم ارزیابی مهاجرت با پشتیبانی کامل از WPML، پنل مدیریت ورودی‌ها، لاگ تغییرات و خروجی CSV/PDF. فرانت و پنل ادمین کاملاً با Vanilla JS پیاده‌سازی شده‌اند.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mojtaba Ghazi Nejad
 * Text Domain:       migration-assessment-form
 * Domain Path:       /languages
 *
 * @package Migration_Assessment_Form
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 */
define( 'MAF_VERSION', '1.0.0' );
define( 'MAF_DB_VERSION', '1.0.0' );
define( 'MAF_PLUGIN_FILE', __FILE__ );
define( 'MAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAF_TEXT_DOMAIN', 'migration-assessment-form' );

/**
 * Autoload plugin classes.
 *
 * Simple PSR-ish autoloader: class-ma-{name}.php located in /includes.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'MAF_' ) !== 0 ) {
			return;
		}

		$file_name = 'class-maf-' . strtolower( str_replace( array( 'MAF_', '_' ), array( '', '-' ), $class ) ) . '.php';
		$file_path = MAF_PLUGIN_DIR . 'includes/' . $file_name;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

/**
 * Activation hook: creates custom tables and default form.
 */
function maf_activate_plugin() {
	require_once MAF_PLUGIN_DIR . 'includes/class-maf-activator.php';
	MAF_Activator::activate();
}
register_activation_hook( __FILE__, 'maf_activate_plugin' );

/**
 * Deactivation hook: flush rewrite rules only (data is preserved).
 */
function maf_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'maf_deactivate_plugin' );

/**
 * Boots all plugin modules once WordPress core (and WPML, if present) is loaded.
 */
function maf_bootstrap_plugin() {

	// Maybe upgrade DB schema silently.
	require_once MAF_PLUGIN_DIR . 'includes/class-maf-activator.php';
	MAF_Activator::maybe_upgrade();

	// Load translation files (only relevant for admin-side default strings;
	// front-end form labels are managed by the form builder + WPML strings).
	load_plugin_textdomain( MAF_TEXT_DOMAIN, false, dirname( MAF_PLUGIN_BASENAME ) . '/languages' );

	// Core modules.
	new MAF_CPT();
	new MAF_Shortcode();
	new MAF_REST();
	new MAF_WPML();
	new MAF_Export();

	if ( is_admin() ) {
		new MAF_Admin();
	}
}
add_action( 'plugins_loaded', 'maf_bootstrap_plugin' );
