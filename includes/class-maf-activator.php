<?php
/**
 * Handles plugin activation: custom table creation and schema upgrades.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Activator
 */
class MAF_Activator {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'maf_db_version', MAF_DB_VERSION );

		// Register the CPT so rewrite rules include it before flushing.
		require_once MAF_PLUGIN_DIR . 'includes/class-maf-cpt.php';
		$cpt = new MAF_CPT();
		$cpt->register_post_type();

		flush_rewrite_rules();
	}

	/**
	 * Compares stored DB version with current plugin version and upgrades if needed.
	 * Safe to call on every load; dbDelta() only alters what changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'maf_db_version' ) !== MAF_DB_VERSION ) {
			self::create_tables();
			update_option( 'maf_db_version', MAF_DB_VERSION );
		}
	}

	/**
	 * Creates the entries and audit log tables using dbDelta.
	 *
	 * Entries table stores a hybrid schema:
	 *  - Indexed "hot" columns for the fields admins filter on most (status,
	 *    language, country, age, marital status, email) for fast, scalable
	 *    dynamic filtering without scanning JSON on every row.
	 *  - A `data` LONGTEXT JSON column holding the complete submission,
	 *    so new form fields never require a schema migration.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$entries_table = $wpdb->prefix . 'maf_entries';
		$audit_table   = $wpdb->prefix . 'maf_audit_log';

		$sql_entries = "CREATE TABLE {$entries_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			language VARCHAR(10) NOT NULL DEFAULT '',
			status VARCHAR(30) NOT NULL DEFAULT 'submitted',
			first_name VARCHAR(191) DEFAULT '',
			last_name VARCHAR(191) DEFAULT '',
			email VARCHAR(191) DEFAULT '',
			phone VARCHAR(60) DEFAULT '',
			age SMALLINT UNSIGNED DEFAULT NULL,
			country_residence VARCHAR(120) DEFAULT '',
			country_citizenship VARCHAR(120) DEFAULT '',
			marital_status VARCHAR(60) DEFAULT '',
			importance VARCHAR(30) NOT NULL DEFAULT 'Normal',
			step VARCHAR(50) NOT NULL DEFAULT 'Assessment',
			program_type VARCHAR(100) NOT NULL DEFAULT 'none',
			assigned_by BIGINT UNSIGNED DEFAULT NULL,
			assigned_to BIGINT UNSIGNED DEFAULT NULL,
			net_worth_cad DECIMAL(14,2) DEFAULT NULL,
			data LONGTEXT NOT NULL,
			ip_address VARCHAR(64) DEFAULT '',
			user_agent VARCHAR(255) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY language (language),
			KEY status (status),
			KEY country_residence (country_residence),
			KEY age (age),
			KEY marital_status (marital_status),
			KEY email (email),
			KEY importance (importance),
			KEY step (step),
			KEY program_type (program_type),
			KEY assigned_by (assigned_by),
			KEY assigned_to (assigned_to),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_audit = "CREATE TABLE {$audit_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			admin_id BIGINT UNSIGNED NOT NULL,
			admin_name VARCHAR(191) NOT NULL DEFAULT '',
			field_changed VARCHAR(191) NOT NULL DEFAULT 'status',
			old_value TEXT,
			new_value TEXT,
			note TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY admin_id (admin_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_entries );
		dbDelta( $sql_audit );
	}
}
