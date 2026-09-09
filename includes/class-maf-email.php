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

	public static function get_default_admin_template() {
		return '<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Form Submission</title>
</head>
<body style="margin:0; padding:0; background:#f3f5f9; font-family:Arial, Tahoma, sans-serif; color:#2e2f34;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f5f9;">
    <tr>
        <td align="center" style="padding:40px 15px;">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden;">
                <tr>
                    <td style="padding:24px 30px; border-bottom:1px solid #eeeeee;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="left">
                                    <img src="{{logo_url}}" alt="ICP Immigration" width="120" style="display:block; border:0;">
                                </td>
                                <td align="right" style="font-size:12px; color:#777777;">Admin Notification</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:45px 30px 20px;">
                        <div style="width:58px; height:58px; line-height:58px; background:#eef2f7; border-radius:50%; color:#0073aa; font-size:24px; margin:auto;">📋</div>
                        <h1 style="margin:22px 0 10px; font-size:24px; color:#2e2f34; font-weight:700;">New Submission Received</h1>
                        <p style="margin:0; font-size:15px; line-height:1.8; color:#666666;">A new assessment form has been submitted by <strong>{first_name} {last_name}</strong>.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px 10px;">
                        <p style="margin:0; font-size:14px; line-height:2; color:#555555;">Hello Admin,</p>
                        <p style="margin:10px 0 0; font-size:14px; line-height:2; color:#555555;">A new candidate has completed the assessment form on your website. Below are the submission details:</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px 30px;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9; border-radius:8px;">
                            <tr>
                                <td colspan="2" style="padding:16px 18px; border-bottom:1px solid #e8e8e8;"><strong style="font-size:14px;">Submission Details</strong></td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Form</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{{form_name}}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Candidate Name</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{first_name} {last_name}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Email</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{email}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Date</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{submission_date}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Reference ID</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{entry_id}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 40px 40px;">
                        <a href="{{site_url}}/wp-admin/admin.php?page=maf_main_menu" style="display:inline-block; background:#0073aa; color:#ffffff; text-decoration:none; padding:13px 28px; border-radius:6px; font-size:14px; font-weight:bold;">View Entries in Admin</a>
                    </td>
                </tr>
                <tr>
                    <td style="background:#2e2f34; padding:28px 30px; text-align:center;">
                        <p style="margin:0 0 10px; color:#ffffff; font-size:13px;">ICP Immigration</p>
                        <p style="margin:0; color:#aaaaaa; font-size:11px; line-height:1.8;">This is an automated notification from Migration Assessment Form.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';
	}

	public static function get_default_daily_template() {
		return '<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Assessment Report</title>
</head>
<body style="margin:0; padding:0; background:#f3f5f9; font-family:Arial, Tahoma, sans-serif; color:#2e2f34;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f5f9;">
    <tr>
        <td align="center" style="padding:40px 15px;">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:12px; overflow:hidden;">
                <tr>
                    <td style="padding:24px 30px; border-bottom:1px solid #eeeeee;">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="left">
                                    <img src="{{logo_url}}" alt="ICP Immigration" width="120" style="display:block; border:0;">
                                </td>
                                <td align="right" style="font-size:12px; color:#777777;">Daily Report</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:45px 30px 20px;">
                        <div style="width:58px; height:58px; line-height:58px; background:#e8f4fd; border-radius:50%; color:#0073aa; font-size:24px; margin:auto;">📊</div>
                        <h1 style="margin:22px 0 10px; font-size:24px; color:#2e2f34; font-weight:700;">Daily Summary Report</h1>
                        <p style="margin:0; font-size:15px; line-height:1.8; color:#666666;">Total <strong>{count}</strong> submissions received on <strong>{date}</strong>.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px 10px;">
                        <p style="margin:0; font-size:14px; line-height:2; color:#555555;">Hello Admin,</p>
                        <p style="margin:10px 0 0; font-size:14px; line-height:2; color:#555555;">Here is the daily summary of all assessment form submissions over the past 24 hours:</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px 30px;">
                        <div style="font-size:13px; color:#333;">
                            {submissions_list}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 40px 40px;">
                        <a href="{{site_url}}/wp-admin/admin.php?page=maf_main_menu" style="display:inline-block; background:#0073aa; color:#ffffff; text-decoration:none; padding:13px 28px; border-radius:6px; font-size:14px; font-weight:bold;">View All Entries</a>
                    </td>
                </tr>
                <tr>
                    <td style="background:#2e2f34; padding:28px 30px; text-align:center;">
                        <p style="margin:0 0 10px; color:#ffffff; font-size:13px;">ICP Immigration</p>
                        <p style="margin:0; color:#aaaaaa; font-size:11px; line-height:1.8;">This is an automated daily report from Migration Assessment Form.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';
	}

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

		$form_post = get_post( $form_id );
		$form_name = $form_post ? $form_post->post_title : __( 'Assessment Form', 'migration-assessment-form' );

		$tmpl = get_option( 'maf_template_admin_notification', self::get_default_admin_template() );
		$data = array(
			'{first_name}'        => $clean_data['first_name'] ?? '',
			'{last_name}'         => $clean_data['last_name'] ?? '',
			'{{user_name}}'       => ($clean_data['first_name'] ?? '') . ' ' . ($clean_data['last_name'] ?? ''),
			'{email}'             => $clean_data['email'] ?? '',
			'{entry_id}'          => $entry_id,
			'{{submission_id}}'   => $entry_id,
			'{{form_name}}'       => $form_name,
			'{submission_date}'   => date_i18n( 'Y-m-d H:i:s' ),
			'{{submission_date}}' => date_i18n( 'Y-m-d H:i:s' ),
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		if ( strpos( $message, '<html' ) === false ) {
			$message = $this->wrap_email( $message );
		}

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

		$submissions_list = '<table border="1" style="border-collapse: collapse; width: 100%; margin-top: 15px; border-color: #ddd;" cellpadding="8">';
		$submissions_list .= '<tr style="background-color: #f2f2f2; color: #333;"><th>ID</th><th>Name</th><th>Email</th><th>Date</th></tr>';
		foreach ( $entries as $entry ) {
			$submissions_list .= sprintf(
				'<tr><td>%d</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
				$entry['id'], $entry['first_name'], $entry['last_name'], $entry['email'], $entry['created_at']
			);
		}
		$submissions_list .= '</table>';

		$tmpl = get_option( 'maf_template_daily_summary', self::get_default_daily_template() );
		$data = array(
			'{count}'            => count( $entries ),
			'{date}'             => date_i18n( 'Y-m-d' ),
			'{submissions_list}' => $submissions_list,
		);

		$message = $this->replace_placeholders( $tmpl, $data );
		if ( strpos( $message, '<html' ) === false ) {
			$message = $this->wrap_email( $message );
		}

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
