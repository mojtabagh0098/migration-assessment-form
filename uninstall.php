<?php
/**
 * Fires only when the plugin is deleted from Plugins → Installed Plugins
 * (never on simple deactivation). By default entry/audit data and form
 * posts are PRESERVED, since immigration-assessment data is sensitive and
 * should not disappear silently. Site owners who explicitly want a clean
 * uninstall can set the `maf_delete_data_on_uninstall` option to `1`
 * (e.g. via wp-cli: `wp option update maf_delete_data_on_uninstall 1`)
 * before deleting the plugin.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'maf_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}maf_audit_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}maf_entries" );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$forms = get_posts(
	array(
		'post_type'      => 'maf_form',
		'posts_per_page' => -1,
		'post_status'    => 'any',
	)
);

foreach ( $forms as $form ) {
	wp_delete_post( $form->ID, true );
}

delete_option( 'maf_db_version' );
delete_option( 'maf_delete_data_on_uninstall' );
