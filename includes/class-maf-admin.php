<?php
/**
 * Admin dashboard: registers the top-level menu (Forms list is the native
 * CPT list table), the "Entries" screen (dynamic-filter table + status
 * changes + audit trail, all Vanilla-JS/Fetch driven), and export buttons.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Admin
 */
class MAF_Admin {

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level "Assessment Forms" menu plus the "Entries" and "Settings" submenu.
	 * The CPT itself (`register_post_type`) is attached to this same menu slug.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Assessment Forms', 'migration-assessment-form' ),
			__( 'Assessment Forms', 'migration-assessment-form' ),
			'manage_options',
			'maf_main_menu',
			array( $this, 'render_entries_page' ),
			'dashicons-clipboard',
			25
		);

		add_submenu_page(
			'maf_main_menu',
			__( 'Entries', 'migration-assessment-form' ),
			__( 'Entries', 'migration-assessment-form' ),
			'manage_options',
			'maf_main_menu',
			array( $this, 'render_entries_page' )
		);

		add_submenu_page(
			'maf_main_menu',
			__( 'All Forms', 'migration-assessment-form' ),
			__( 'All Forms', 'migration-assessment-form' ),
			'manage_options',
			'edit.php?post_type=' . MAF_CPT::POST_TYPE
		);

		add_submenu_page(
			'maf_main_menu',
			__( 'Add New Form', 'migration-assessment-form' ),
			__( 'Add New Form', 'migration-assessment-form' ),
			'manage_options',
			'post-new.php?post_type=' . MAF_CPT::POST_TYPE
		);
		
		add_submenu_page(
			'maf_main_menu',
			__( 'Settings', 'migration-assessment-form' ),
			__( 'Settings', 'migration-assessment-form' ),
			'manage_options',
			'maf_settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Renders the Settings admin page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['maf_save_settings'] ) && check_admin_referer( 'maf_settings_action' ) ) {
			update_option( 'maf_instant_email_recipients', sanitize_text_field( $_POST['maf_instant_email_recipients'] ) );
			update_option( 'maf_daily_email_recipients', sanitize_text_field( $_POST['maf_daily_email_recipients'] ) );
			update_option( 'maf_email_recipients', sanitize_text_field( $_POST['maf_instant_email_recipients'] ) );
			
			$hour   = max( 0, min( 23, intval( $_POST['maf_email_hour'] ) ) );
			$minute = max( 0, min( 59, intval( $_POST['maf_email_minute'] ) ) );
			update_option( 'maf_email_hour', $hour );
			update_option( 'maf_email_minute', $minute );
			
			// Save Templates
			update_option( 'maf_template_user_confirmation', wp_kses_post( $_POST['maf_template_user_confirmation'] ) );
			update_option( 'maf_template_admin_notification', wp_kses_post( $_POST['maf_template_admin_notification'] ) );
			update_option( 'maf_template_daily_summary', wp_kses_post( $_POST['maf_template_daily_summary'] ) );
			
			if ( class_exists( 'MAF_Email' ) ) {
				MAF_Email::reschedule_cron();
			}

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'migration-assessment-form' ) . '</p></div>';
		}
			// Save Options for Dropdowns
			update_option( 'maf_option_importance', sanitize_textarea_field( $_POST['maf_option_importance'] ) );
			update_option( 'maf_option_steps', sanitize_textarea_field( $_POST['maf_option_steps'] ) );
			update_option( 'maf_option_program_types', sanitize_textarea_field( $_POST['maf_option_program_types'] ) );
			update_option( 'maf_option_statuses', sanitize_textarea_field( $_POST['maf_option_statuses'] ) );
		// Get Saved Options
		$opt_importance = get_option( 'maf_option_importance', "Normal\nImportant\nUrgent" );
		$opt_steps      = get_option( 'maf_option_steps', "Assessment\nassessment follow up\nCancelled\nContract follow up\ncontract signed\ncompleted\n1st payment in process\nprocessing fee payment\n2nd payment in process\nConsulting or initial contract fee\ncosulting or initial cont. fee payment" );
		$opt_programs   = get_option( 'maf_option_program_types', "Quebec Investor\nPEQ\nFederal Self Employed\nExpress Entry\nQuebec Skilled Worker\nSponsorship\nStudent\nSuper Visa\nVisitor Visa\nSaskatchewan Business\nNova Scotia Business\nBritish Columbia Business\nPEI Entrepreneur\nManitoba Entrepreneur\nQuebec Entrepreneur\nMorden\nCanadian experience class\nstart up visa" );
		$opt_statuses   = get_option( 'maf_option_statuses', "Submitted\nConditional\nQualifed\nNot Qualified\nMissing info" );



		$instant_recipients = get_option( 'maf_instant_email_recipients', get_option( 'maf_email_recipients', get_option( 'admin_email' ) ) );
		$daily_recipients   = get_option( 'maf_daily_email_recipients', get_option( 'maf_email_recipients', get_option( 'admin_email' ) ) );
		$hour       = get_option( 'maf_email_hour', 9 );
		$minute     = get_option( 'maf_email_minute', 0 );
		
		$tmpl_user_default = '<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Submission Confirmation</title>
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
                                <td align="right" style="font-size:12px; color:#777777;">ICP Immigration</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:45px 30px 20px;">
                        <div style="width:58px; height:58px; line-height:58px; background:#fcebec; border-radius:50%; color:#c7232d; font-size:28px; margin:auto;">✓</div>
                        <h1 style="margin:22px 0 10px; font-size:24px; color:#2e2f34; font-weight:700;">Thank You!</h1>
                        <p style="margin:0; font-size:15px; line-height:1.8; color:#666666;">Your form has been successfully submitted.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px 10px;">
                        <p style="margin:0; font-size:14px; line-height:2; color:#555555;">Dear <strong>{{user_name}}</strong>,</p>
                        <p style="margin:10px 0 0; font-size:14px; line-height:2; color:#555555;">Thank you for contacting ICP Immigration. We have received your request and one of our consultants will review it and contact you as soon as possible.</p>
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
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Date</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{{submission_date}}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 18px; font-size:13px; color:#777;">Reference</td>
                                <td align="right" style="padding:12px 18px; font-size:13px; color:#333;">{{submission_id}}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 40px 40px;">
                        <a href="{{website_url}}" style="display:inline-block; background:#c7232d; color:#ffffff; text-decoration:none; padding:13px 28px; border-radius:6px; font-size:14px; font-weight:bold;">Visit Our Website</a>
                    </td>
                </tr>
                <tr>
                    <td style="background:#2e2f34; padding:28px 30px; text-align:center;">
                        <p style="margin:0 0 10px; color:#ffffff; font-size:13px;">ICP Immigration</p>
                        <p style="margin:0; color:#aaaaaa; font-size:11px; line-height:1.8;">This is an automated email. Please do not reply directly to this message.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';

		$tmpl_user  = get_option( 'maf_template_user_confirmation', $tmpl_user_default );
		$tmpl_admin = get_option( 'maf_template_admin_notification', class_exists( 'MAF_Email' ) ? MAF_Email::get_default_admin_template() : '' );
		$tmpl_daily = get_option( 'maf_template_daily_summary', class_exists( 'MAF_Email' ) ? MAF_Email::get_default_daily_template() : '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Assessment Form Settings', 'migration-assessment-form' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'maf_settings_action' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="maf_instant_email_recipients"><?php esc_html_e( 'Instant Submission Recipients', 'migration-assessment-form' ); ?></label></th>
						<td>
							<input name="maf_instant_email_recipients" type="text" id="maf_instant_email_recipients" value="<?php echo esc_attr( $instant_recipients ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated email addresses to notify immediately when a form is submitted (supports multiple recipients).', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_daily_email_recipients"><?php esc_html_e( 'Daily Report Recipients', 'migration-assessment-form' ); ?></label></th>
						<td>
							<input name="maf_daily_email_recipients" type="text" id="maf_daily_email_recipients" value="<?php echo esc_attr( $daily_recipients ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated email addresses to receive the daily summary report of submitted forms (supports multiple recipients).', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Daily Report Time', 'migration-assessment-form' ); ?></label></th>
						<td>
							<input name="maf_email_hour" type="number" id="maf_email_hour" value="<?php echo esc_attr( $hour ); ?>" min="0" max="23" style="width: 60px;" /> :
					<tr>
						<th scope="row"><label for="maf_option_importance"><?php esc_html_e( 'Importance Options', 'migration-assessment-form' ); ?></label></th>
						<td>
							<textarea name="maf_option_importance" id="maf_option_importance" rows="3" class="large-text"><?php echo esc_textarea( $opt_importance ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line.', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_option_steps"><?php esc_html_e( 'Steps Options', 'migration-assessment-form' ); ?></label></th>
						<td>
							<textarea name="maf_option_steps" id="maf_option_steps" rows="6" class="large-text"><?php echo esc_textarea( $opt_steps ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line.', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_option_program_types"><?php esc_html_e( 'Program Type Options', 'migration-assessment-form' ); ?></label></th>
						<td>
							<textarea name="maf_option_program_types" id="maf_option_program_types" rows="6" class="large-text"><?php echo esc_textarea( $opt_programs ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line.', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_option_statuses"><?php esc_html_e( 'Status Options', 'migration-assessment-form' ); ?></label></th>
						<td>
							<textarea name="maf_option_statuses" id="maf_option_statuses" rows="4" class="large-text"><?php echo esc_textarea( $opt_statuses ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line.', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>

							<input name="maf_email_minute" type="number" id="maf_email_minute" value="<?php echo esc_attr( $minute ); ?>" min="0" max="59" style="width: 60px;" />
							<p class="description"><?php esc_html_e( 'Time of the day (24-hour format) when the daily report will be sent (HH:MM).', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_template_user_confirmation"><?php esc_html_e( 'User Confirmation Email', 'migration-assessment-form' ); ?></label></th>
						<td>
							<?php wp_editor( $tmpl_user, 'maf_template_user_confirmation', array( 'textarea_rows' => 10 ) ); ?>
							<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {last_name}, {email}, {entry_id}, {site_name}, {site_url}', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_template_admin_notification"><?php esc_html_e( 'Admin Submission Email', 'migration-assessment-form' ); ?></label></th>
						<td>
							<?php wp_editor( $tmpl_admin, 'maf_template_admin_notification', array( 'textarea_rows' => 10 ) ); ?>
							<p class="description"><?php esc_html_e( 'Placeholders: {first_name}, {last_name}, {email}, {entry_id}, {submission_date}, {site_name}, {site_url}', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maf_template_daily_summary"><?php esc_html_e( 'Daily Admin Report Email', 'migration-assessment-form' ); ?></label></th>
						<td>
							<?php wp_editor( $tmpl_daily, 'maf_template_daily_summary', array( 'textarea_rows' => 10 ) ); ?>
							<p class="description"><?php esc_html_e( 'Placeholders: {count}, {date}, {submissions_list}, {site_name}, {site_url}', 'migration-assessment-form' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'migration-assessment-form' ), 'primary', 'maf_save_settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueues admin JS/CSS only on the plugin's own screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'maf_main_menu' ) === false ) {
			return;
		}

		wp_enqueue_style( 'maf-admin', MAF_PLUGIN_URL . 'assets/css/maf-admin.css', array(), MAF_VERSION );
		wp_enqueue_script( 'maf-admin', MAF_PLUGIN_URL . 'assets/js/maf-admin.js', array(), MAF_VERSION, true );

		$forms = get_posts(
			array(
				'post_type'      => MAF_CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$form_options = array();
		foreach ( $forms as $form ) {
			$form_options[ $form->ID ] = $form->post_title;
		}

		$user_options = array( 0 => __( 'Unassigned', 'migration-assessment-form' ) );
		$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
		foreach ( $users as $user ) {
			$user_options[ $user->ID ] = $user->display_name;
		}

		$opt_importance = explode( "\n", str_replace( "\r", "", get_option( 'maf_option_importance', "Normal\nImportant\nUrgent" ) ) );
		$opt_steps      = explode( "\n", str_replace( "\r", "", get_option( 'maf_option_steps', "Assessment\nassessment follow up\nCancelled\nContract follow up\ncontract signed\ncompleted\n1st payment in process\nprocessing fee payment\n2nd payment in process\nConsulting or initial contract fee\ncosulting or initial cont. fee payment" ) ) );
		$opt_programs   = explode( "\n", str_replace( "\r", "", get_option( 'maf_option_program_types', "Quebec Investor\nPEQ\nFederal Self Employed\nExpress Entry\nQuebec Skilled Worker\nSponsorship\nStudent\nSuper Visa\nVisitor Visa\nSaskatchewan Business\nNova Scotia Business\nBritish Columbia Business\nPEI Entrepreneur\nManitoba Entrepreneur\nQuebec Entrepreneur\nMorden\nCanadian experience class\nstart up visa" ) ) );

		wp_localize_script(
			'maf-admin',
			'MAF_ADMIN_CONFIG',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'maf/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'exportCsvUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=maf_export_csv' ), 'maf_export' ),
				'exportPdfUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=maf_export_pdf' ), 'maf_export' ),
				'forms'        => $form_options,
				'users'        => $user_options,
				'importance_options' => array_values( array_filter( array_map( 'trim', $opt_importance ) ) ),
				'steps_options'      => array_values( array_filter( array_map( 'trim', $opt_steps ) ) ),
				'programs_options'   => array_values( array_filter( array_map( 'trim', $opt_programs ) ) ),
				'schemas'      => array_reduce( $forms, function ( $acc, $form ) {
					$acc[ $form->ID ] = MAF_Fields::flatten( MAF_Fields::get_schema( $form->ID ) );
					return $acc;
				}, array() ),
				'activeLang'   => MAF_WPML::admin_active_language(),
				'languages'    => MAF_WPML::active_languages(),
				'i18n'         => array(
					'confirmStatus' => __( 'Add an optional note for this status change:', 'migration-assessment-form' ),
					'saved'         => __( 'Saved.', 'migration-assessment-form' ),
					'error'         => __( 'Something went wrong.', 'migration-assessment-form' ),
					'noResults'     => __( 'No entries match the current filters.', 'migration-assessment-form' ),
					'loading'       => __( 'Loading…', 'migration-assessment-form' ),
				),
			)
		);
	}


	/**
	 * Renders the Entries admin page shell. All data-fetching/rendering
	 * happens client-side via assets/js/maf-admin.js + the REST API.
	 */
	public function render_entries_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap maf-admin-wrap">
			<h1><?php esc_html_e( 'Assessment Entries', 'migration-assessment-form' ); ?></h1>

			<div class="maf-toolbar">
				<select id="maf-filter-form">
					<option value=""><?php esc_html_e( 'All forms', 'migration-assessment-form' ); ?></option>
				</select>

				<select id="maf-filter-language">
					<option value=""><?php esc_html_e( 'All languages', 'migration-assessment-form' ); ?></option>
				</select>

				<select id="maf-filter-status">
					<option value=""><?php esc_html_e( 'All statuses', 'migration-assessment-form' ); ?></option>
					<option value="submitted"><?php esc_html_e( 'Submitted', 'migration-assessment-form' ); ?></option>
					<option value="submitted"><?php esc_html_e( 'Submitted', 'migration-assessment-form' ); ?></option>
					<option value="conditional"><?php esc_html_e( 'Conditional', 'migration-assessment-form' ); ?></option>
					<option value="approved"><?php esc_html_e( 'Approved', 'migration-assessment-form' ); ?></option>
					<option value="rejected"><?php esc_html_e( 'Rejected', 'migration-assessment-form' ); ?></option>
				</select>
                
				<select id="maf-filter-importance">
					<option value=""><?php esc_html_e( 'All importance', 'migration-assessment-form' ); ?></option>
				</select>
				<select id="maf-filter-step">
					<option value=""><?php esc_html_e( 'All steps', 'migration-assessment-form' ); ?></option>
				</select>
                
				<select type="text" id="maf-filter-program" placeholder="<?php esc_attr_e( 'Program type', 'migration-assessment-form' ); ?>">

					<option value="conditional"><?php esc_html_e( 'Conditional', 'migration-assessment-form' ); ?></option>
					<option value="approved"><?php esc_html_e( 'Approved', 'migration-assessment-form' ); ?></option>
					<option value="rejected"><?php esc_html_e( 'Rejected', 'migration-assessment-form' ); ?></option>
				</select>

				<input type="text" id="maf-filter-country" placeholder="<?php esc_attr_e( 'Country of residence', 'migration-assessment-form' ); ?>" />
				<input type="text" id="maf-filter-search" placeholder="<?php esc_attr_e( 'Search name or email…', 'migration-assessment-form' ); ?>" />

				<button type="button" class="button button-primary" id="maf-apply-filters"><?php esc_html_e( 'Filter', 'migration-assessment-form' ); ?></button>
				<a class="button" id="maf-export-csv" target="_blank"><?php esc_html_e( 'Export CSV', 'migration-assessment-form' ); ?></a>
				<a class="button" id="maf-export-pdf" target="_blank"><?php esc_html_e( 'Export PDF', 'migration-assessment-form' ); ?></a>
			</div>
			<div class="maf-advanced-filters-panel" id="maf-advanced-filters-panel" style="margin-bottom: 15px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
					<strong style="font-size: 14px;"><?php esc_html_e( 'Advanced Dynamic Filters', 'migration-assessment-form' ); ?></strong>
					<button type="button" class="button button-secondary" id="maf-add-filter-row">+ <?php esc_html_e( 'Add Filter', 'migration-assessment-form' ); ?></button>
				</div>
				<div id="maf-filter-rows-container"></div>
				<div style="margin-top: 10px; display: flex; gap: 10px;">
					<button type="button" class="button button-primary" id="maf-apply-advanced-filters"><?php esc_html_e( 'Apply Advanced Filters', 'migration-assessment-form' ); ?></button>
					<button type="button" class="button" id="maf-reset-filters"><?php esc_html_e( 'Reset All', 'migration-assessment-form' ); ?></button>
				</div>
			</div>


			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Name', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Email', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Country', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Language', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Status', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'migration-assessment-form' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'migration-assessment-form' ); ?></th>
					</tr>
				</thead>
				<tbody id="maf-entries-tbody">
					<tr><td colspan="8"><?php esc_html_e( 'Loading…', 'migration-assessment-form' ); ?></td></tr>
				</tbody>
			</table>

			<div class="maf-pagination" id="maf-pagination"></div>
		</div>

		<!-- Entry detail / audit-log modal (hidden by default; toggled by maf-admin.js) -->
		<div class="maf-modal" id="maf-entry-modal">
			<div class="maf-modal__panel">
				<button type="button" class="maf-modal__close" id="maf-modal-close">&times;</button>
				<div id="maf-modal-body"></div>
			</div>
		</div>
		<?php
	}
}
