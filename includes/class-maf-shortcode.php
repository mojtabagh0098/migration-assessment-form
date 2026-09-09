<?php
/**
 * Registers `[assessment_form]` and renders the front-end HTML based on the
 * form's resolved schema. All interactivity (conditional logic, repeater,
 * submission) is handled by assets/js/maf-frontend.js using the Fetch API —
 * no build step, no framework.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Shortcode
 */
class MAF_Shortcode {

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_shortcode( 'assessment_form', array( $this, 'render' ) );
	}

	/**
	 * Enqueues front-end assets. Called lazily only when the shortcode runs,
	 * to avoid loading JS/CSS on unrelated pages.
	 */
	private function enqueue_assets() {
		// intl-tel-input: lightweight vanilla-JS phone input with country flag dropdown.
		wp_enqueue_style( 'maf-intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/css/intlTelInput.css', array(), '23.0.0' );
		wp_enqueue_script( 'maf-intl-tel-input', 'https://cdn.jsdelivr.net/npm/intl-tel-input@23/build/js/intlTelInputWithUtils.min.js', array(), '23.0.0', true );

		wp_enqueue_style( 'maf-frontend', MAF_PLUGIN_URL . 'assets/css/maf-frontend.css', array(), MAF_VERSION );
		wp_enqueue_script( 'maf-frontend', MAF_PLUGIN_URL . 'assets/js/maf-frontend.js', array( 'maf-intl-tel-input' ), MAF_VERSION, true );

		wp_localize_script(
			'maf-frontend',
			'MAF_CONFIG',
			array(
				'restUrl' => esc_url_raw( rest_url( 'maf/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'lang'    => MAF_WPML::current_language(),
				'i18n'    => array(
					'required'     => __( 'This field is required.', 'migration-assessment-form' ),
					'invalidEmail' => __( 'Please enter a valid email address.', 'migration-assessment-form' ),
					'invalidPhone' => __( 'Please enter a valid phone number.', 'migration-assessment-form' ),
					'submitting'   => __( 'Submitting…', 'migration-assessment-form' ),
					'success'      => __( 'Your application has been received. Thank you!', 'migration-assessment-form' ),
					'error'        => __( 'Something went wrong. Please try again.', 'migration-assessment-form' ),
					'addRow'       => __( 'Add', 'migration-assessment-form' ),
					'removeRow'    => __( 'Remove', 'migration-assessment-form' ),
					'fileTooLarge' => __( 'File is too large (max %s MB).', 'migration-assessment-form' ),
					'fileType'     => __( 'File type not allowed.', 'migration-assessment-form' ),
					'tooManyFiles' => __( 'Too many files (max %s).', 'migration-assessment-form' ),
					'invalidUrl'   => __( 'Please enter a valid URL.', 'migration-assessment-form' ),
					'maxRows'      => __( 'Maximum number of rows reached.', 'migration-assessment-form' ),
				),
			)
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'assessment_form' );
		$form_id = (int) $atts['id'];

		if ( ! $form_id || get_post_type( $form_id ) !== MAF_CPT::POST_TYPE ) {
			return '<p class="maf-error">' . esc_html__( 'Invalid assessment form ID.', 'migration-assessment-form' ) . '</p>';
		}

		$this->enqueue_assets();

		$schema = MAF_Fields::get_schema( $form_id );

		ob_start();
		?>
		<form class="maf-form" id="maf-form-<?php echo esc_attr( $form_id ); ?>" data-form-id="<?php echo esc_attr( $form_id ); ?>" enctype="multipart/form-data" novalidate>
			<?php foreach ( $schema as $section ) : ?>
				<section class="maf-card" data-section="<?php echo esc_attr( $section['id'] ); ?>">
					<h3 class="maf-card__title"><?php echo esc_html( $section['title'] ); ?></h3>
					<?php if ( ! empty( $section['description'] ) ) : ?>
						<p class="maf-card__desc"><?php echo esc_html( $section['description'] ); ?></p>
					<?php endif; ?>
					<div class="maf-card__body">
						<?php if ( ! empty( $section['repeater'] ) ) : ?>
							<?php $this->render_repeater( $section ); ?>
						<?php else : ?>
							<div class="maf-grid">
								<?php foreach ( $section['fields'] as $field ) : ?>
									<?php $this->render_field( $field ); ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</section>
			<?php endforeach; ?>

			<div class="maf-submit-row">
				<button type="submit" class="maf-submit-btn"><?php esc_html_e( 'Send Application', 'migration-assessment-form' ); ?></button>
				<span class="maf-submit-status" role="status" aria-live="polite"></span>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the markup for a single field (used both standalone and inside repeater rows).
	 *
	 * @param array  $field Field definition.
	 * @param string $prefix Optional name prefix for repeater rows, e.g. "education[0]".
	 */
	private function render_field( $field, $prefix = '' ) {
		$type        = $field['type'] ?? 'text';
		$name        = $prefix ? $prefix . '[' . $field['key'] . ']' : $field['key'];
		$field_id    = ! empty( $field['custom_id'] ) ? $field['custom_id'] : 'maf-field-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		$required    = ! empty( $field['required'] );
		$condition   = ! empty( $field['condition'] ) ? wp_json_encode( $field['condition'] ) : '';
		
		// Width logic - convert to percentage for inline style.
		$width_map = array( 'full' => 100, 'half' => 50, 'third' => 33.3333 );
		if ( ! empty( $field['width_pc'] ) ) {
			$pct = max( 1, min( 100, (int) $field['width_pc'] ) );
		} else {
			$w   = isset( $field['width'] ) ? $field['width'] : 'full';
			$pct = isset( $width_map[ $w ] ) ? $width_map[ $w ] : 100;
		}
		$style       = ' style="--maf-field-w:' . $pct . '%"';
		$width_class = 'maf-field--w-' . ( $pct == 100 ? 'full' : ( $pct == 50 ? 'half' : ( $pct <= 33.3334 ? 'third' : 'custom' ) ) );

		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$wrap_attrs  = $condition ? ' data-condition=\'' . esc_attr( $condition ) . '\' hidden' : '';
		$wrap_attrs .= ' id="' . esc_attr( $field_id . '-wrap' ) . '"';
		
		$classes = 'maf-field maf-field--' . esc_attr( $type ) . ' ' . $width_class;
		if ( $condition ) {
			$classes .= ' maf-field--conditional';
		}
		if ( ! empty( $field['custom_class'] ) ) {
			$classes .= ' ' . esc_attr( $field['custom_class'] );
		}

		$req_attr    = $required ? ' required' : '';
		$ph_attr     = $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
		$maxlen_attr = ! empty( $field['maxlength'] ) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '';
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>"<?php echo $wrap_attrs . $style; ?>>
			<?php if ( 'html' === $type ) : ?>
				<div class="maf-html"><?php echo wp_kses_post( $field['content'] ?? '' ); ?></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( 'checkbox' !== $type ) : ?>
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( $required ) : ?><span class="maf-required" aria-hidden="true">*</span><?php endif; ?>
			</label>
			<?php endif; ?>

			<?php switch ( $type ) :
				case 'textarea' : ?>
					<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo (int) ( $field['rows'] ?? 3 ); ?>"<?php echo $req_attr . $ph_attr . $maxlen_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></textarea>
					<?php break;

				case 'select' : ?>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php echo $placeholder ? esc_html( $placeholder ) : esc_html__( '— Select —', 'migration-assessment-form' ); ?></option>
						<?php foreach ( (array) ( $field['options'] ?? array() ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php break;

				case 'radio' :
				case 'checkbox_group' :
					$input_type = 'radio' === $type ? 'radio' : 'checkbox';
					$group_name = 'radio' === $type ? $name : $name . '[]';
					?>
					<div class="maf-choices<?php echo ! empty( $field['inline'] ) ? ' maf-choices--inline' : ''; ?>" role="group" aria-labelledby="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>" data-group-required="<?php echo $required ? '1' : '0'; ?>">
						<?php foreach ( (array) ( $field['options'] ?? array() ) as $value => $label ) : ?>
							<label class="maf-choice">
								<input type="<?php echo esc_attr( $input_type ); ?>" name="<?php echo esc_attr( $group_name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<?php break;

				case 'checkbox' : ?>
					<label class="maf-choice maf-choice--single" for="<?php echo esc_attr( $field_id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<span><?php echo esc_html( $field['checkbox_label'] ?? $field['label'] ); ?><?php if ( $required ) : ?> <span class="maf-required" aria-hidden="true">*</span><?php endif; ?></span>
					</label>
					<?php break;

				case 'country' : ?>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="maf-country-select"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( '— Select country —', 'migration-assessment-form' ); ?></option>
						<?php foreach ( MAF_Fields_Countries::list() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php break;

				case 'tel_intl' : ?>
					<input type="tel" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="maf-tel-input"<?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php break;

				case 'number' :
				case 'date' : ?>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"
						<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : ''; ?>
						<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : ''; ?>
						<?php echo isset( $field['step'] ) ? 'step="' . esc_attr( $field['step'] ) . '"' : ''; ?>
						<?php echo $req_attr . $ph_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php break;

				case 'file' :
					$accept   = (array) ( $field['accept'] ?? MAF_Fields::default_file_settings()['accept'] );
					$multiple = ! empty( $field['multiple'] );
					?>
					<div class="maf-upload" data-max-size="<?php echo (int) ( $field['max_size'] ?? 5 ); ?>" data-max-files="<?php echo (int) ( $field['max_files'] ?? 1 ); ?>" data-accept="<?php echo esc_attr( implode( ',', $accept ) ); ?>">
						<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							accept="<?php echo esc_attr( '.' . implode( ',.', $accept ) ); ?>"<?php echo $multiple ? ' multiple' : ''; ?><?php echo $req_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<div class="maf-upload__zone">
							<span class="maf-upload__icon" aria-hidden="true">⬆</span>
							<span class="maf-upload__text"><?php esc_html_e( 'Drag & drop or click to choose a file', 'migration-assessment-form' ); ?></span>
							<small class="maf-upload__hint"><?php echo esc_html( strtoupper( implode( ', ', $accept ) ) ); ?> · <?php echo esc_html( sprintf( __( 'max %d MB', 'migration-assessment-form' ), (int) ( $field['max_size'] ?? 5 ) ) ); ?></small>
						</div>
						<ul class="maf-upload__list"></ul>
					</div>
					<?php break;

				case 'email' :
				case 'url' : ?>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr . $ph_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php break;

				default : ?>
					<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr . $ph_attr . $maxlen_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php break;
			endswitch; ?>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<small class="maf-field__help"><?php echo esc_html( $field['help'] ); ?></small>
			<?php endif; ?>
			<span class="maf-field__error" role="alert"></span>
		</div>
		<?php
	}

	/**
	 * Renders a repeater section (e.g. Education & Training) with a single
	 * hidden `<template>` row that maf-frontend.js clones on "Add".
	 *
	 * @param array $section Section definition with `repeater = true`.
	 */
	private function render_repeater( $section ) {
		?>
		<div class="maf-repeater" data-repeater="<?php echo esc_attr( $section['id'] ); ?>" data-min-rows="<?php echo (int) ( $section['min_rows'] ?? 1 ); ?>" data-max-rows="<?php echo (int) ( $section['max_rows'] ?? 0 ); ?>">
			<div class="maf-repeater__rows"></div>

			<template class="maf-repeater__template">
				<div class="maf-repeater__row">
					<div class="maf-grid">
						<?php foreach ( $section['fields'] as $field ) : ?>
							<?php $this->render_field( $field, $section['id'] . '[__INDEX__]' ); ?>
						<?php endforeach; ?>
					</div>
					<button type="button" class="maf-repeater__remove">
						<?php esc_html_e( 'Remove', 'migration-assessment-form' ); ?>
					</button>
				</div>
			</template>

			<button type="button" class="maf-repeater__add">
				+ <?php echo esc_html( $section['row_label'] ?? __( 'Add row', 'migration-assessment-form' ) ); ?>
			</button>
		</div>
		<?php
	}
}
