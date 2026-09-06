<?php
/**
 * Reads/writes rows in the `{prefix}maf_audit_log` table.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Audit
 */
class MAF_Audit {

	/**
	 * Writes one audit-log row for a single changed field.
	 *
	 * @param int    $entry_id      Entry ID.
	 * @param string $field_changed Field/column name that changed.
	 * @param mixed  $old_value     Previous value.
	 * @param mixed  $new_value     New value.
	 * @param string $note          Optional admin note.
	 */
	public static function log( $entry_id, $field_changed, $old_value, $new_value, $note = '' ) {
		global $wpdb;

		$user = wp_get_current_user();

		$wpdb->insert(
			$wpdb->prefix . 'maf_audit_log',
			array(
				'entry_id'      => (int) $entry_id,
				'admin_id'      => $user ? (int) $user->ID : 0,
				'admin_name'    => $user ? $user->display_name : '',
				'field_changed' => sanitize_key( $field_changed ),
				'old_value'     => is_scalar( $old_value ) ? (string) $old_value : wp_json_encode( $old_value ),
				'new_value'     => is_scalar( $new_value ) ? (string) $new_value : wp_json_encode( $new_value ),
				'note'          => sanitize_textarea_field( $note ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns the full audit trail for one entry, most recent first.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	public static function get_log( $entry_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'maf_audit_log';

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d ORDER BY created_at DESC", $entry_id ),
			ARRAY_A
		);
	}

	/**
	 * Returns audit rows across all entries for a set of entry IDs (used by
	 * the export module to include history alongside the entries CSV/PDF).
	 *
	 * @param array $entry_ids Entry IDs.
	 * @return array
	 */
	public static function get_log_for_entries( array $entry_ids ) {
		global $wpdb;

		if ( empty( $entry_ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'maf_audit_log';
		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id IN ({$placeholders}) ORDER BY entry_id, created_at DESC", $entry_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}
}
