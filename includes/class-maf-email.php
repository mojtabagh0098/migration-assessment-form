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
	const OPTION_SCHEDULE   = 'maf_email_schedule';
	const CRON_HOOK         = 'maf_send_scheduled_report';

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_action( 'maf_entry_submitted', array( $this, 'send_user_confirmation' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $this, 'send_scheduled_report' ) );
		
		// Ensure cron is scheduled.
		add_action( 'init', array( $this, 'setup_cron' ) );
	}

	/**
	 * Sends a confirmation email to the user upon submission.
	 *
	 * @param int   $entry_id   Entry ID.
	 * @param array $clean_data Submitted data.
	 * @param int   $form_id    Form ID.
	 */
	public function send_user_confirmation( $entry_id, $clean_data, $form_id ) {
		if ( empty( $clean_data['email'] ) ) {
			return;
		}

		$to      = $clean_data['email'];
		$subject = __( 'Form Submission Received', 'migration-assessment-form' );
		$message = __( 'Thank you for your submission. We have received your assessment form.', 'migration-assessment-form' );
		
		wp_mail( $to, $subject, $message );
	}

	/**
	 * Sets up cron schedule based on admin settings.
	 */
	public function setup_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$schedule = get_option( self::OPTION_SCHEDULE, 'daily' );
			wp_schedule_event( time(), $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Sends a report to configured admins.
	 */
	public function send_scheduled_report() {
		global $wpdb;

		$recipients = get_option( self::OPTION_RECIPIENTS, get_option( 'admin_email' ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$table        = $wpdb->prefix . 'maf_entries';
		$interval     = '-' . ( get_option( self::OPTION_SCHEDULE, 'daily' ) === 'daily' ? '1 day' : '1 week' );
		$start_date   = date( 'Y-m-d H:i:s', strtotime( $interval ) );
		
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
			$start_date
		) );

		$to      = explode( ',', $recipients );
		$subject = __( 'Migration Assessment Form: Submission Report', 'migration-assessment-form' );
		$message = sprintf( __( 'A total of %d forms have been submitted in the last period.', 'migration-assessment-form' ), $count );

		wp_mail( $to, $subject, $message );
	}
}
