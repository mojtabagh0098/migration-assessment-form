<?php
/**
 * Handles email notifications for form submissions and scheduled reports.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Email
 */
class MAF_Email {

	const OPTION_RECIPIENTS = 'maf_email_recipients';
	const OPTION_HOUR       = 'maf_email_hour';
	const CRON_HOOK         = 'maf_send_scheduled_report';

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_action( 'maf_entry_submitted', array( $this, 'send_user_confirmation' ), 10, 3 );
		add_action( 'maf_entry_submitted', array( $this, 'send_admin_notification' ), 11, 3 ); // Added admin hook
		add_action( self::CRON_HOOK, array( $this, 'send_scheduled_report' ) );
		
		// Initial schedule check.
		add_action( 'admin_init', array( $this, 'maybe_setup_cron' ) );
	}

	/**
	 * Helper to replace placeholders in templates.
	 */
	private function replace_placeholders( $template, $data ) {
		$keys = array_keys( $data );
		$values = array_values( $data );
		return str_replace( $keys, $values, $template );
	}

	/**
	 * Sends a confirmation email to the user upon submission.
	 */
	public function send_user_confirmation( $entry_id, $clean_data, $form_id ) {
		if ( empty( $clean_data['email'] ) ) {
			return;
		}

		$tmpl = get_option( 'maf_template_user_confirmation', 'Hello {first_name} {last_name}, thank you for your submission (ID: {entry_id}).' );
		$data = array(
			'{first_name}' => $clean_data['first_name'] ?? '',
			'{last_name}'  => $clean_data['last_name'] ?? '',
			'{email}'      => $clean_data['email'] ?? '',
			'{entry_id}'   => $entry_id,
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		wp_mail( $clean_data['email'], __( 'Form Submission Received', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	/**
	 * Sends an email to configured admins upon submission.
	 */
	public function send_admin_notification( $entry_id, $clean_data, $form_id ) {
		$recipients = get_option( self::OPTION_RECIPIENTS, get_option( 'admin_email' ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$tmpl = get_option( 'maf_template_admin_notification', 'New submission received: {first_name} {last_name} (ID: {entry_id}).' );
		$data = array(
			'{first_name}'      => $clean_data['first_name'] ?? '',
			'{last_name}'       => $clean_data['last_name'] ?? '',
			'{email}'           => $clean_data['email'] ?? '',
			'{entry_id}'        => $entry_id,
			'{submission_date}' => date_i18n( 'Y-m-d H:i:s' ),
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		$to = array_map( 'trim', explode( ',', $recipients ) );
		wp_mail( $to, __( 'New Form Submission', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	/**
	 * Reschedules the cron event based on the configured hour.
	 */
	public static function reschedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		$hour = (int) get_option( self::OPTION_HOUR, 9 );
		$scheduled_time = mktime( $hour, 0, 0 );
		if ( $scheduled_time < time() ) {
			$scheduled_time = strtotime( '+1 day', $scheduled_time );
		}

		wp_schedule_event( $scheduled_time, 'daily', self::CRON_HOOK );
	}

	/**
	 * Sends a daily report to configured admins.
	 */
	public function send_scheduled_report() {
		global $wpdb;

		$recipients = get_option( self::OPTION_RECIPIENTS, get_option( 'admin_email' ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$table      = $wpdb->prefix . 'maf_entries';
		$start_date = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
		
		$entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE created_at >= %s",
			$start_date
		), ARRAY_A );

		// Build submissions list
		$submissions_list = '<table border="1" style="border-collapse: collapse; width: 100%;">';
		$submissions_list .= '<tr><th>ID</th><th>Name</th><th>Email</th><th>Date</th></tr>';
		foreach ( $entries as $entry ) {
			$submissions_list .= sprintf(
				'<tr><td>%d</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
				$entry['id'], $entry['first_name'], $entry['last_name'], $entry['email'], $entry['created_at']
			);
		}
		$submissions_list .= '</table>';

		$tmpl = get_option( 'maf_template_daily_summary', 'Daily Report: {count} submissions received on {date}.\n\n{submissions_list}' );
		$data = array(
			'{count}'            => count( $entries ),
			'{date}'             => date_i18n( 'Y-m-d' ),
			'{submissions_list}' => $submissions_list,
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		$to = array_map( 'trim', explode( ',', $recipients ) );
		wp_mail( $to, __( 'Migration Assessment Form: Daily Report', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	public function maybe_setup_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::reschedule_cron();
		}
	}
}
