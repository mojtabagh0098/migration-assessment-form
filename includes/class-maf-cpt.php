<?php
/**
 * Registers the `maf_form` custom post type used to define/store each
 * Assessment Form (one post per WPML language, native WPML translation).
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_CPT
 */
class MAF_CPT {

	const POST_TYPE = 'maf_form';
	const META_KEY  = '_maf_form_schema';

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_builder_assets' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Registers the `maf_form` CPT.
	 *
	 * WPML treats every custom post type as translatable by default once the
	 * user enables it in WPML → Settings → Custom Posts, which is the native,
	 * recommended path (each translation gets its own post ID + permalink,
	 * no client-side language switching).
	 */
	public function register_post_type() {
		$labels = array(
			'name'          => __( 'Assessment Forms', 'migration-assessment-form' ),
			'singular_name' => __( 'Assessment Form', 'migration-assessment-form' ),
			'add_new_item'  => __( 'Add New Form', 'migration-assessment-form' ),
			'edit_item'     => __( 'Edit Form', 'migration-assessment-form' ),
			'all_items'     => __( 'All Forms', 'migration-assessment-form' ),
			'menu_name'     => __( 'Assessment Forms', 'migration-assessment-form' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'maf_main_menu',
				'show_in_rest'    => false, // Managed via dedicated REST controller + nonce, not core REST.
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => 'assessment-form' ),
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Meta box: form schema builder (label overrides + advanced JSON).
	 * The Shortcode and Publish controls are embedded inside the builder header
	 * so that it can occupy the full width of the edit screen.
	 *
	 * @param string $post_type Current post type.
	 */
	public function register_meta_boxes( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		// Remove core Publish meta box — its controls live inside the builder header now.
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );

		add_meta_box(
			'maf_form_schema',
			__( 'Form Builder', 'migration-assessment-form' ),
			array( $this, 'render_schema_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Enqueues the visual form builder on the `maf_form` edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_builder_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'maf-builder', MAF_PLUGIN_URL . 'assets/css/maf-builder.css', array( 'dashicons' ), MAF_VERSION );
		wp_enqueue_script( 'maf-builder', MAF_PLUGIN_URL . 'assets/js/maf-builder.js', array(), MAF_VERSION, true );

		global $post;
		$is_new    = 'post-new.php' === $hook;
		$shortcode = $is_new ? '' : '[assessment_form id="' . $post->ID . '"]';

		wp_localize_script(
			'maf-builder',
			'MAF_BUILDER',
			array(
				'fieldTypes'   => MAF_Fields::field_types(),
				'fileDefaults' => MAF_Fields::default_file_settings(),
				'isRtl'        => is_rtl(),
				'isNewPost'    => $is_new,
				'postStatus'   => $is_new ? 'auto-draft' : get_post_status( $post ),
				'shortcode'    => $shortcode,
				'i18n'         => array(
					'sections'          => __( 'Sections', 'migration-assessment-form' ),
					'fields'            => __( 'Fields', 'migration-assessment-form' ),
					'addSection'        => __( 'Add section', 'migration-assessment-form' ),
					'addRepeater'       => __( 'Add repeater section', 'migration-assessment-form' ),
					'addField'          => __( 'Add field', 'migration-assessment-form' ),
					'newSection'        => __( 'New section', 'migration-assessment-form' ),
					'newRepeater'       => __( 'Repeating group', 'migration-assessment-form' ),
					'newField'          => __( 'New field', 'migration-assessment-form' ),
					'untitled'          => __( '(untitled)', 'migration-assessment-form' ),
					'emptySection'      => __( 'Drop fields here or click a field type on the left.', 'migration-assessment-form' ),
					'emptyForm'         => __( 'Your form is empty. Add a section to get started.', 'migration-assessment-form' ),
					'selectField'       => __( 'Select a section or field to edit its settings.', 'migration-assessment-form' ),
					'sectionSettings'   => __( 'Section settings', 'migration-assessment-form' ),
					'fieldSettings'     => __( 'Field settings', 'migration-assessment-form' ),
					'title'             => __( 'Title', 'migration-assessment-form' ),
					'description'       => __( 'Description', 'migration-assessment-form' ),
					'sectionId'         => __( 'Section ID', 'migration-assessment-form' ),
					'repeater'          => __( 'Repeatable section (users can add multiple rows)', 'migration-assessment-form' ),
					'rowLabel'          => __( 'Add-row button label', 'migration-assessment-form' ),
					'minRows'           => __( 'Minimum rows', 'migration-assessment-form' ),
					'maxRows'           => __( 'Maximum rows (0 = unlimited)', 'migration-assessment-form' ),
					'label'             => __( 'Label', 'migration-assessment-form' ),
					'key'               => __( 'Field key', 'migration-assessment-form' ),
					'keyHelp'           => __( 'Used as the data key in entries and exports. Lowercase letters, numbers, underscore.', 'migration-assessment-form' ),
					'type'              => __( 'Field type', 'migration-assessment-form' ),
					'placeholder'       => __( 'Placeholder', 'migration-assessment-form' ),
					'help'              => __( 'Help text', 'migration-assessment-form' ),
					'required'          => __( 'Required', 'migration-assessment-form' ),
					'width'             => __( 'Width', 'migration-assessment-form' ),
					'widthDesktop'      => __( 'Width on Desktop', 'migration-assessment-form' ),
					'widthLaptop'       => __( 'Width on Laptop', 'migration-assessment-form' ),
					'widthTablet'       => __( 'Width on Tablet', 'migration-assessment-form' ),
					'widthMobile'       => __( 'Width on Mobile', 'migration-assessment-form' ),
					'widthInherit'      => __( 'Inherit', 'migration-assessment-form' ),
					'widthFull'         => __( 'Full', 'migration-assessment-form' ),
					'widthHalf'         => __( 'Half', 'migration-assessment-form' ),
					'widthThird'        => __( 'Third', 'migration-assessment-form' ),
					'preset'            => __( 'Preset', 'migration-assessment-form' ),
					'min'               => __( 'Min', 'migration-assessment-form' ),
					'max'               => __( 'Max', 'migration-assessment-form' ),
					'step'              => __( 'Step', 'migration-assessment-form' ),
					'maxlength'         => __( 'Max length', 'migration-assessment-form' ),
					'rows'              => __( 'Rows', 'migration-assessment-form' ),
					'inline'            => __( 'Show options inline', 'migration-assessment-form' ),
					'checkboxLabel'     => __( 'Checkbox text', 'migration-assessment-form' ),
					'options'           => __( 'Options', 'migration-assessment-form' ),
					'optionValue'       => __( 'value', 'migration-assessment-form' ),
					'optionLabel'       => __( 'Label', 'migration-assessment-form' ),
					'addOption'         => __( 'Add option', 'migration-assessment-form' ),
					'bulkOptions'       => __( 'Bulk edit', 'migration-assessment-form' ),
					'bulkOptionsHelp'   => __( 'One option per line. Use "value | Label" to set a custom value.', 'migration-assessment-form' ),
					'content'           => __( 'Content (HTML allowed)', 'migration-assessment-form' ),
					'fileAccept'        => __( 'Allowed extensions', 'migration-assessment-form' ),
					'fileAcceptHelp'    => __( 'Comma-separated, e.g. pdf, jpg, png', 'migration-assessment-form' ),
					'fileMaxSize'       => __( 'Max file size (MB)', 'migration-assessment-form' ),
					'fileMultiple'      => __( 'Allow multiple files', 'migration-assessment-form' ),
					'fileMaxFiles'      => __( 'Max number of files', 'migration-assessment-form' ),
					'conditional'       => __( 'Conditional logic', 'migration-assessment-form' ),
					'conditionalEnable' => __( 'Only show this field when…', 'migration-assessment-form' ),
					'condField'         => __( 'Field', 'migration-assessment-form' ),
					'condOperator'      => __( 'Operator', 'migration-assessment-form' ),
					'condValue'         => __( 'Value', 'migration-assessment-form' ),
					'opEquals'          => __( 'is equal to', 'migration-assessment-form' ),
					'opNotEquals'       => __( 'is not equal to', 'migration-assessment-form' ),
					'opContains'        => __( 'contains', 'migration-assessment-form' ),
					'opNotEmpty'        => __( 'is not empty', 'migration-assessment-form' ),
					'opEmpty'           => __( 'is empty', 'migration-assessment-form' ),
					'noCondFields'      => __( 'No other fields available in this section.', 'migration-assessment-form' ),
					'duplicate'         => __( 'Duplicate', 'migration-assessment-form' ),
					'delete'            => __( 'Delete', 'migration-assessment-form' ),
					'collapse'          => __( 'Collapse', 'migration-assessment-form' ),
					'moveUp'            => __( 'Move up', 'migration-assessment-form' ),
					'moveDown'          => __( 'Move down', 'migration-assessment-form' ),
					'confirmDeleteSec'  => __( 'Delete this section and all of its fields?', 'migration-assessment-form' ),
					'confirmDeleteFld'  => __( 'Delete this field?', 'migration-assessment-form' ),
					'tabBuilder'        => __( 'Builder', 'migration-assessment-form' ),
					'tabPreview'        => __( 'Preview', 'migration-assessment-form' ),
					'tabJson'           => __( 'JSON', 'migration-assessment-form' ),
					'jsonHelp'          => __( 'Advanced: edit the raw schema. Click "Apply" to load it into the builder.', 'migration-assessment-form' ),
					'applyJson'         => __( 'Apply JSON', 'migration-assessment-form' ),
					'copyJson'          => __( 'Copy', 'migration-assessment-form' ),
					'copied'            => __( 'Copied!', 'migration-assessment-form' ),
					'jsonOk'            => __( 'Schema applied.', 'migration-assessment-form' ),
					'jsonErr'           => __( 'Invalid JSON: ', 'migration-assessment-form' ),
					'search'            => __( 'Search field types…', 'migration-assessment-form' ),
					'undo'              => __( 'Undo', 'migration-assessment-form' ),
					'redo'              => __( 'Redo', 'migration-assessment-form' ),
					'resetDefault'      => __( 'Reset to default form', 'migration-assessment-form' ),
					'confirmReset'      => __( 'Replace the current form with the default assessment form? This cannot be undone after saving.', 'migration-assessment-form' ),
					'dupKey'            => __( 'Duplicate key in this scope', 'migration-assessment-form' ),
					'repeaterBadge'     => __( 'Repeater', 'migration-assessment-form' ),
					'conditionalBadge'  => __( 'Conditional', 'migration-assessment-form' ),
					'requiredBadge'     => __( 'Required', 'migration-assessment-form' ),
					'statsFields'       => __( '%d fields', 'migration-assessment-form' ),
					'statsSections'     => __( '%d sections', 'migration-assessment-form' ),
					'unsaved'           => __( 'Unsaved changes — remember to Update the form.', 'migration-assessment-form' ),
					'previewNote'       => __( 'Live preview — this is approximately how visitors will see the form.', 'migration-assessment-form' ),
					'select'            => __( '— Select —', 'migration-assessment-form' ),
					'chooseFile'        => __( 'Choose file…', 'migration-assessment-form' ),
					'dragHint'          => __( 'Drag to reorder', 'migration-assessment-form' ),
					'publish'           => __( 'Publish', 'migration-assessment-form' ),
					'update'            => __( 'Update', 'migration-assessment-form' ),
					'saveDraft'         => __( 'Save Draft', 'migration-assessment-form' ),
					'shortcode'         => __( 'Shortcode', 'migration-assessment-form' ),
					'copyShortcode'     => __( 'Copy shortcode', 'migration-assessment-form' ),
				),
			)
		);
	}

	/**
	 * Renders the visual form builder shell. All rendering happens in
	 * assets/js/maf-builder.js; the schema is round-tripped through a hidden
	 * textarea (`maf_form_schema`) so the classic post form still submits it.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_schema_box( $post ) {
		wp_nonce_field( 'maf_save_schema', 'maf_schema_nonce' );

		$schema = get_post_meta( $post->ID, self::META_KEY, true );
		if ( empty( $schema ) ) {
			$schema = wp_json_encode( MAF_Fields::default_schema(), JSON_UNESCAPED_UNICODE );
		}
		$default = wp_json_encode( MAF_Fields::default_schema(), JSON_UNESCAPED_UNICODE );
		?>
		<div id="maf-builder" class="maf-builder" data-default-schema="<?php echo esc_attr( $default ); ?>">
			<noscript><p><?php esc_html_e( 'The form builder requires JavaScript.', 'migration-assessment-form' ); ?></p></noscript>
			<div class="maf-builder__loading"><span class="spinner is-active"></span></div>
		</div>
		<textarea name="maf_form_schema" id="maf_form_schema_input" hidden aria-hidden="true"><?php echo esc_textarea( $schema ); ?></textarea>
		<?php
	}

	/**
	 * Saves the schema meta after verifying nonce, capability and JSON validity.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['maf_schema_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['maf_schema_nonce'] ) ), 'maf_save_schema' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['maf_form_schema'] ) ) {
			return;
		}

		$raw     = wp_unslash( $_POST['maf_form_schema'] );
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			add_settings_error( 'maf_form_schema', 'invalid_json', __( 'Form schema was not saved: invalid JSON.', 'migration-assessment-form' ) );
			return;
		}

		// Deep-sanitize: whitelist field types/keys, normalise options, conditions and upload rules.
		$clean = MAF_Fields::sanitize_schema( $decoded );
		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Adds a "Shortcode" and "Translations" column to the forms list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function admin_columns( $columns ) {
		$columns['maf_shortcode']    = __( 'Shortcode', 'migration-assessment-form' );
		$columns['maf_translations'] = __( 'Translations', 'migration-assessment-form' );
		return $columns;
	}

	/**
	 * Renders custom admin column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'maf_shortcode' === $column ) {
			echo '<code>[assessment_form id="' . (int) $post_id . '"]</code>';
		}

		if ( 'maf_translations' === $column ) {
			echo esc_html( MAF_WPML::translation_status_label( $post_id ) );
		}
	}
		if ( 'maf_status' === $column ) {
			echo 'Status';
		}

}
