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
		<form class="maf-form" id="maf-form-<?php echo esc_attr( $form_id ); ?>" data-form-id="<?php echo esc_attr( $form_id ); ?>" novalidate>
			<?php foreach ( $schema as $section ) : ?>
				<section class="maf-card" data-section="<?php echo esc_attr( $section['id'] ); ?>">
					<h3 class="maf-card__title"><?php echo esc_html( $section['title'] ); ?></h3>
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
		$name       = $prefix ? $prefix . '[' . $field['key'] . ']' : $field['key'];
		$field_id   = 'maf-field-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		$required   = ! empty( $field['required'] );
		$condition  = ! empty( $field['condition'] ) ? wp_json_encode( $field['condition'] ) : '';
		$wrap_attrs = $condition ? ' data-condition=\'' . esc_attr( $condition ) . '\' hidden' : '';
		?>
		<div class="maf-field maf-field--<?php echo esc_attr( $field['type'] ); ?>"<?php echo $wrap_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?>>
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( $required ) : ?><span class="maf-required" aria-hidden="true">*</span><?php endif; ?>
			</label>

			<?php switch ( $field['type'] ) :
				case 'textarea' : ?>
					<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" <?php echo $required ? 'required' : ''; ?>></textarea>
					<?php break;

				case 'select' : ?>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( '— Select —', 'migration-assessment-form' ); ?></option>
						<?php foreach ( $field['options'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php break;

				case 'country' : ?>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="maf-country-select" <?php echo $required ? 'required' : ''; ?>>
						<option value=""><?php esc_html_e( '— Select country —', 'migration-assessment-form' ); ?></option>
						<?php foreach ( MAF_Fields_Countries::list() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php break;

				case 'tel_intl' : ?>
					<input type="tel" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="maf-tel-input" <?php echo $required ? 'required' : ''; ?> />
					<?php break;

				case 'number' : ?>
					<input type="number" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>"
						<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : ''; ?>
						<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : ''; ?>
						<?php echo $required ? 'required' : ''; ?> />
					<?php break;

				case 'email' : ?>
					<input type="email" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?> />
					<?php break;

				default : ?>
					<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?> />
					<?php break;
			endswitch; ?>
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
		<div class="maf-repeater" data-repeater="<?php echo esc_attr( $section['id'] ); ?>">
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
