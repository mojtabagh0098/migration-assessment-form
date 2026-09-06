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
	 * Meta box: form schema builder (label overrides + advanced JSON) and shortcode display.
	 *
	 * @param string $post_type Current post type.
	 */
	public function register_meta_boxes( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		add_meta_box(
			'maf_form_shortcode',
			__( 'Shortcode', 'migration-assessment-form' ),
			array( $this, 'render_shortcode_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'maf_form_schema',
			__( 'Form Fields (Schema)', 'migration-assessment-form' ),
			array( $this, 'render_schema_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the read-only shortcode box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_shortcode_box( $post ) {
		printf(
			'<p><input type="text" readonly onclick="this.select();" style="width:100%%" value="%s" /></p><p class="description">%s</p>',
			esc_attr( '[assessment_form id="' . $post->ID . '"]' ),
			esc_html__( 'Copy this shortcode into any page or template. Each language version of this form has its own post ID and its own shortcode.', 'migration-assessment-form' )
		);
	}

	/**
	 * Renders the schema editor: a JSON textarea seeded with the canonical
	 * assessment-form field structure. Kept intentionally as structured JSON
	 * (rather than a heavy visual builder) so section/field labels can be
	 * translated per-language post via WPML while remaining fully editable.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_schema_box( $post ) {
		wp_nonce_field( 'maf_save_schema', 'maf_schema_nonce' );

		$schema = get_post_meta( $post->ID, self::META_KEY, true );
		if ( empty( $schema ) ) {
			$schema = wp_json_encode( MAF_Fields::default_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Edit labels, options and conditional-logic rules for this language version. Structure must remain valid JSON.', 'migration-assessment-form' ); ?>
		</p>
		<textarea name="maf_form_schema" id="maf_form_schema" rows="28" style="width:100%; direction:ltr; font-family:monospace;"><?php echo esc_textarea( $schema ); ?></textarea>
		<p><button type="button" class="button" id="maf-validate-schema"><?php esc_html_e( 'Validate JSON', 'migration-assessment-form' ); ?></button>
		<span id="maf-schema-status" style="margin-inline-start:8px;"></span></p>
		<script>
		(function () {
			var btn = document.getElementById('maf-validate-schema');
			var out = document.getElementById('maf-schema-status');
			if (!btn) return;
			btn.addEventListener('click', function () {
				try {
					JSON.parse(document.getElementById('maf_form_schema').value);
					out.textContent = '<?php echo esc_js( __( 'Valid JSON ✓', 'migration-assessment-form' ) ); ?>';
					out.style.color = 'green';
				} catch (e) {
					out.textContent = '<?php echo esc_js( __( 'Invalid JSON: ', 'migration-assessment-form' ) ); ?>' + e.message;
					out.style.color = 'red';
				}
			});
		})();
		</script>
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

		// Re-encode after decode to strip any stray executable content and normalize.
		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) );
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
}
