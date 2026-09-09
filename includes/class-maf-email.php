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

	const OPTION_RECIPIENTS         = 'maf_email_recipients';
	const OPTION_INSTANT_RECIPIENTS = 'maf_instant_email_recipients';
	const OPTION_DAILY_RECIPIENTS   = 'maf_daily_email_recipients';
	const OPTION_HOUR               = 'maf_email_hour';
	const CRON_HOOK                 = 'maf_send_scheduled_report';

	public function __construct() {
		add_action( 'maf_entry_submitted', array( $this, 'send_user_confirmation' ), 10, 3 );
		add_action( 'maf_entry_submitted', array( $this, 'send_admin_notification' ), 11, 3 );
		add_action( self::CRON_HOOK, array( $this, 'send_scheduled_report' ) );
		
		add_action( 'admin_init', array( $this, 'maybe_setup_cron' ) );
	}

	/**
	 * Wraps email content in a beautiful HTML template.
	 */
	private function wrap_email( $content ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = get_site_url();
		
		$template = '
		<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
			<header style="padding-bottom: 20px; border-bottom: 2px solid #0073aa; text-align: center;">
				<h1 style="color: #0073aa;">' . esc_html( $site_name ) . '</h1>
			</header>
			<div style="padding: 20px 0; color: #333; line-height: 1.6;">
				' . $content . '
			</div>
			<footer style="padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #777; text-align: center;">
				<p>&copy; ' . date( 'Y' ) . ' <a href="' . esc_url( $site_url ) . '" style="color: #0073aa;">' . esc_html( $site_name ) . '</a></p>
			</footer>
		</div>';
		return $template;
	}

	/**
	 * Helper to replace placeholders in templates.
	 */
	private function replace_placeholders( $template, $data ) {
		// Global placeholders
		$data['{site_name}']    = get_bloginfo( 'name' );
		$data['{site_url}']     = get_site_url();
		$data['{{website_url}}'] = get_site_url();
		$data['{{logo_url}}']    = get_header_image() ? get_header_image() : MAF_PLUGIN_URL . 'assets/logo-placeholder.png'; // Fallback logo

		$keys = array_keys( $data );
		$values = array_values( $data );
		
		// Support both {key} and {{key}} formats
		return str_replace( $keys, $values, $template );
	}

	/**
	 * Sends a confirmation email to the user upon submission.
	 */
	public function send_user_confirmation( $entry_id, $clean_data, $form_id ) {
		if ( empty( $clean_data['email'] ) ) {
			return;
		}

		$form_post = get_post( $form_id );
		$form_name = $form_post ? $form_post->post_title : __( 'Assessment Form', 'migration-assessment-form' );

		$tmpl = get_option( 'maf_template_user_confirmation', '' );
		
		// If template is empty, we will use your provided HTML as a fallback later or assume it's set in DB.
		$data = array(
			'{first_name}'      => $clean_data['first_name'] ?? '',
			'{last_name}'       => $clean_data['last_name'] ?? '',
			'{{user_name}}'     => ($clean_data['first_name'] ?? '') . ' ' . ($clean_data['last_name'] ?? ''),
			'{email}'           => $clean_data['email'] ?? '',
			'{entry_id}'        => $entry_id,
			'{{submission_id}}' => $entry_id,
			'{{form_name}}'     => $form_name,
			'{{submission_date}}' => date_i18n( get_option( 'date_format' ) ),
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		
		// If the template contains <html> tags, don't wrap it in the default wrapper
		if ( strpos( $message, '<html' ) === false ) {
			$message = $this->wrap_email( $message );
		}

		wp_mail( $clean_data['email'], __( 'Form Submission Received', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	public function send_admin_notification( $entry_id, $clean_data, $form_id ) {
		$recipients = get_option( self::OPTION_INSTANT_RECIPIENTS, get_option( self::OPTION_RECIPIENTS, get_option( 'admin_email' ) ) );
		if ( empty( $recipients ) ) return;

		$tmpl = get_option( 'maf_template_admin_notification', 'New submission received: {first_name} {last_name} (ID: {entry_id}).' );
		$data = array(
			'{first_name}'      => $clean_data['first_name'] ?? '',
			'{last_name}'       => $clean_data['last_name'] ?? '',
			'{email}'           => $clean_data['email'] ?? '',
			'{entry_id}'        => $entry_id,
			'{submission_date}' => date_i18n( 'Y-m-d H:i:s' ),
		);

		$message = $this->wrap_email( $this->replace_placeholders( $tmpl, $data ) );
		$to = array_map( 'trim', explode( ',', $recipients ) );
		wp_mail( $to, __( 'New Form Submission', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	public function send_scheduled_report() {
		global $wpdb;

		$recipients = get_option( self::OPTION_DAILY_RECIPIENTS, get_option( self::OPTION_RECIPIENTS, get_option( 'admin_email' ) ) );
		if ( empty( $recipients ) ) return;

		$table      = $wpdb->prefix . 'maf_entries';
		$start_date = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
		
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s", $start_date ), ARRAY_A );

		$submissions_list = '<table border="1" style="border-collapse: collapse; width: 100%; margin-top: 15px;">';
		$submissions_list .= '<tr style="background-color: #f2f2f2;"><th>ID</th><th>Name</th><th>Email</th><th>Date</th></tr>';
		foreach ( $entries as $entry ) {
			$submissions_list .= sprintf(
				'<tr><td>%d</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
				$entry['id'], $entry['first_name'], $entry['last_name'], $entry['email'], $entry['created_at']
			);
		}
		$submissions_list .= '</table>';

		$tmpl = get_option( 'maf_template_daily_summary', 'Daily Report: {count} submissions received on {date}.<br><br>{submissions_list}' );
		$data = array(
			'{count}'            => count( $entries ),
			'{date}'             => date_i18n( 'Y-m-d' ),
			'{submissions_list}' => $submissions_list,
		);

		$message = $this->wrap_email( $this->replace_placeholders( $tmpl, $data ) );
		$to = array_map( 'trim', explode( ',', $recipients ) );
		wp_mail( $to, __( 'Migration Assessment Form: Daily Report', 'migration-assessment-form' ), $message, array('Content-Type: text/html; charset=UTF-8') );
	}

	public static function reschedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) wp_clear_scheduled_hook( self::CRON_HOOK );

		$hour   = (int) get_option( self::OPTION_HOUR, 9 );
		$minute = (int) get_option( 'maf_email_minute', 0 );
		
		$scheduled_time = mktime( $hour, $minute, 0 );
		if ( $scheduled_time < time() ) $scheduled_time = strtotime( '+1 day', $scheduled_time );

		wp_schedule_event( $scheduled_time, 'daily', self::CRON_HOOK );
	}

	public function maybe_setup_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) self::reschedule_cron();
	}
}
