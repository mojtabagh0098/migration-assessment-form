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
	 * Registers the top-level "Assessment Forms" menu plus the "Entries" submenu.
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

		wp_localize_script(
			'maf-admin',
			'MAF_ADMIN_CONFIG',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'maf/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'exportCsvUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=maf_export_csv' ), 'maf_export' ),
				'exportPdfUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=maf_export_pdf' ), 'maf_export' ),
				'forms'        => $form_options,
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
		<div class="maf-modal" id="maf-entry-modal" hidden>
			<div class="maf-modal__panel">
				<button type="button" class="maf-modal__close" id="maf-modal-close">&times;</button>
				<div id="maf-modal-body"></div>
			</div>
		</div>
		<?php
	}
}
