<?php
/**
 * Defines the canonical Assessment Form field structure and resolves the
 * effective schema for a given form post (per-language override + WPML
 * string translation for any label left untouched).
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Fields
 */
class MAF_Fields {

	/**
	 * Returns the default schema used to seed every new form.
	 *
	 * Each section maps to a UI "card". Field `type` drives both the
	 * front-end renderer and the server-side sanitizer/validator, so the two
	 * must be kept in sync (see MAF_REST::sanitize_field()).
	 *
	 * @return array
	 */
	public static function default_schema() {
		$schema = array(
			array(
				'id'     => 'contact',
				'title'  => __( 'Your Contact Info', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'      => 'first_name',
						'type'     => 'text',
						'label'    => __( 'First Name', 'migration-assessment-form' ),
						'required' => true,
					),
					array(
						'key'      => 'last_name',
						'type'     => 'text',
						'label'    => __( 'Last Name', 'migration-assessment-form' ),
						'required' => true,
					),
					array(
						'key'      => 'email',
						'type'     => 'email',
						'label'    => __( 'Email', 'migration-assessment-form' ),
						'required' => true,
					),
					array(
						'key'      => 'phone',
						'type'     => 'tel_intl',
						'label'    => __( 'Phone Number', 'migration-assessment-form' ),
						'required' => true,
					),
				),
			),
			array(
				'id'     => 'personal',
				'title'  => __( 'Your Personal Info', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'      => 'age',
						'type'     => 'number',
						'label'    => __( 'Age', 'migration-assessment-form' ),
						'min'      => 16,
						'max'      => 120,
						'required' => true,
					),
					array(
						'key'      => 'country_residence',
						'type'     => 'country',
						'label'    => __( 'Country of Residence', 'migration-assessment-form' ),
						'required' => true,
					),
					array(
						'key'      => 'country_citizenship',
						'type'     => 'country',
						'label'    => __( 'Country of Citizenship', 'migration-assessment-form' ),
						'required' => true,
					),
					array(
						'key'      => 'marital_status',
						'type'     => 'select',
						'label'    => __( 'Marital Status', 'migration-assessment-form' ),
						'options'  => array(
							'single'   => __( 'Single', 'migration-assessment-form' ),
							'married'  => __( 'Married', 'migration-assessment-form' ),
							'divorced' => __( 'Divorced', 'migration-assessment-form' ),
							'widowed'  => __( 'Widowed', 'migration-assessment-form' ),
						),
						'required' => true,
					),
					// Conditional: only rendered/required when marital_status === married.
					array(
						'key'         => 'spouse_age',
						'type'        => 'number',
						'label'       => __( "Spouse's Age", 'migration-assessment-form' ),
						'condition'   => array(
							'field' => 'marital_status',
							'value' => 'married',
						),
						'required'    => false,
					),
					array(
						'key'       => 'spouse_country',
						'type'      => 'country',
						'label'     => __( "Spouse's Country", 'migration-assessment-form' ),
						'condition' => array(
							'field' => 'marital_status',
							'value' => 'married',
						),
						'required'  => false,
					),
				),
			),
			array(
				'id'     => 'canada_contact',
				'title'  => __( 'Family or Friend in Canada', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'   => 'ca_relationship',
						'type'  => 'text',
						'label' => __( 'Relationship', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'ca_full_name',
						'type'  => 'text',
						'label' => __( 'Full Name', 'migration-assessment-form' ),
					),
					array(
						'key'     => 'ca_status',
						'type'    => 'select',
						'label'   => __( 'Their Residency Status', 'migration-assessment-form' ),
						'options' => array(
							'citizen'         => __( 'Canadian Citizen', 'migration-assessment-form' ),
							'permanent_res'   => __( 'Permanent Resident', 'migration-assessment-form' ),
							'temporary_res'   => __( 'Temporary Resident', 'migration-assessment-form' ),
						),
					),
					array(
						'key'   => 'ca_province',
						'type'  => 'select',
						'label' => __( 'Province', 'migration-assessment-form' ),
						'options' => self::canadian_provinces(),
					),
				),
			),
			array(
				'id'     => 'language_skills',
				'title'  => __( 'Your Language Skills', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'     => 'english_test',
						'type'    => 'select',
						'label'   => __( 'English Test Status', 'migration-assessment-form' ),
						'options' => array(
							'not_taken' => __( 'Not taken yet', 'migration-assessment-form' ),
							'booked'    => __( 'Booked', 'migration-assessment-form' ),
							'completed' => __( 'Completed', 'migration-assessment-form' ),
						),
					),
					array(
						'key'   => 'english_test_notes',
						'type'  => 'textarea',
						'label' => __( 'English Test Details', 'migration-assessment-form' ),
					),
					array(
						'key'     => 'french_test',
						'type'    => 'select',
						'label'   => __( 'French Test Status', 'migration-assessment-form' ),
						'options' => array(
							'not_taken' => __( 'Not taken yet', 'migration-assessment-form' ),
							'booked'    => __( 'Booked', 'migration-assessment-form' ),
							'completed' => __( 'Completed', 'migration-assessment-form' ),
						),
					),
					array(
						'key'   => 'french_test_notes',
						'type'  => 'textarea',
						'label' => __( 'French Test Details', 'migration-assessment-form' ),
					),
				),
			),
			array(
				'id'       => 'education',
				'title'    => __( 'Your Education & Training', 'migration-assessment-form' ),
				'repeater' => true,
				'row_label' => __( 'Education Record', 'migration-assessment-form' ),
				'fields'   => array(
					array(
						'key'   => 'institution',
						'type'  => 'text',
						'label' => __( 'Institution', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'credential',
						'type'  => 'text',
						'label' => __( 'Credential / Degree', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'field_of_study',
						'type'  => 'text',
						'label' => __( 'Field of Study', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'year_completed',
						'type'  => 'number',
						'label' => __( 'Year Completed', 'migration-assessment-form' ),
					),
				),
			),
			array(
				'id'     => 'work_experience',
				'title'  => __( 'Work Experience', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'   => 'job_title',
						'type'  => 'text',
						'label' => __( 'Job Title', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'employer',
						'type'  => 'text',
						'label' => __( 'Employer', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'years_experience',
						'type'  => 'number',
						'label' => __( 'Years of Experience', 'migration-assessment-form' ),
					),
				),
			),
			array(
				'id'     => 'job_offer',
				'title'  => __( 'Canadian Job Offer', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'     => 'has_job_offer',
						'type'    => 'select',
						'label'   => __( 'Do you have a Canadian job offer?', 'migration-assessment-form' ),
						'options' => array(
							'yes' => __( 'Yes', 'migration-assessment-form' ),
							'no'  => __( 'No', 'migration-assessment-form' ),
						),
					),
					array(
						'key'       => 'job_offer_details',
						'type'      => 'textarea',
						'label'     => __( 'Job Offer Details', 'migration-assessment-form' ),
						'condition' => array(
							'field' => 'has_job_offer',
							'value' => 'yes',
						),
					),
				),
			),
			array(
				'id'     => 'express_entry',
				'title'  => __( 'Express Entry Profile', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'     => 'ee_profile_exists',
						'type'    => 'select',
						'label'   => __( 'Do you have an Express Entry profile?', 'migration-assessment-form' ),
						'options' => array(
							'yes' => __( 'Yes', 'migration-assessment-form' ),
							'no'  => __( 'No', 'migration-assessment-form' ),
						),
					),
					array(
						'key'       => 'ee_crs_score',
						'type'      => 'number',
						'label'     => __( 'CRS Score', 'migration-assessment-form' ),
						'condition' => array(
							'field' => 'ee_profile_exists',
							'value' => 'yes',
						),
					),
				),
			),
			array(
				'id'     => 'finance',
				'title'  => __( 'Financial & Additional Info', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'   => 'net_worth_cad',
						'type'  => 'number',
						'label' => __( 'Personal Net Worth (CAD)', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'immigration_reason',
						'type'  => 'textarea',
						'label' => __( 'Reason for Immigration', 'migration-assessment-form' ),
					),
					array(
						'key'   => 'additional_notes',
						'type'  => 'textarea',
						'label' => __( 'Additional Notes', 'migration-assessment-form' ),
					),
				),
			),
			array(
				'id'     => 'documents',
				'title'  => __( 'Supporting Documents', 'migration-assessment-form' ),
				'fields' => array(
					array(
						'key'       => 'resume',
						'type'      => 'file',
						'label'     => __( 'Resume / CV', 'migration-assessment-form' ),
						'accept'    => array( 'pdf', 'doc', 'docx' ),
						'max_size'  => 5,
						'multiple'  => false,
						'max_files' => 1,
					),
					array(
						'key'       => 'language_certificates',
						'type'      => 'file',
						'label'     => __( 'Language Test Certificates', 'migration-assessment-form' ),
						'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png' ),
						'max_size'  => 5,
						'multiple'  => true,
						'max_files' => 3,
					),
				),
			),
		);

		// Two-column layout by default for short inputs; long inputs stay full width.
		foreach ( $schema as &$section ) {
			foreach ( $section['fields'] as &$field ) {
				if ( ! in_array( $field['type'], array( 'textarea', 'file', 'html' ), true ) ) {
					$field['width'] = 'half';
				}
			}
		}
		unset( $section, $field );

		return $schema;
	}

	/**
	 * Short list of Canadian provinces/territories for the select field.
	 *
	 * @return array
	 */
	private static function canadian_provinces() {
		return array(
			'AB' => 'Alberta',
			'BC' => 'British Columbia',
			'MB' => 'Manitoba',
			'NB' => 'New Brunswick',
			'NL' => 'Newfoundland and Labrador',
			'NS' => 'Nova Scotia',
			'NT' => 'Northwest Territories',
			'NU' => 'Nunavut',
			'ON' => 'Ontario',
			'PE' => 'Prince Edward Island',
			'QC' => 'Quebec',
			'SK' => 'Saskatchewan',
			'YT' => 'Yukon',
		);
	}

	/**
	 * Registry of supported field types. Shared with the visual builder
	 * (admin) so the palette, inspector and server-side sanitizer never drift.
	 *
	 * `supports` lists which inspector settings apply to a type.
	 *
	 * @return array
	 */
	public static function field_types() {
		return array(
			'text'           => array(
				'label'    => __( 'Text', 'migration-assessment-form' ),
				'icon'     => 'editor-textcolor',
				'supports' => array( 'placeholder', 'required', 'maxlength' ),
			),
			'textarea'       => array(
				'label'    => __( 'Paragraph', 'migration-assessment-form' ),
				'icon'     => 'editor-paragraph',
				'supports' => array( 'placeholder', 'required', 'maxlength', 'rows' ),
			),
			'email'          => array(
				'label'    => __( 'Email', 'migration-assessment-form' ),
				'icon'     => 'email',
				'supports' => array( 'placeholder', 'required' ),
			),
			'tel_intl'       => array(
				'label'    => __( 'Phone (international)', 'migration-assessment-form' ),
				'icon'     => 'phone',
				'supports' => array( 'required' ),
			),
			'number'         => array(
				'label'    => __( 'Number', 'migration-assessment-form' ),
				'icon'     => 'calculator',
				'supports' => array( 'placeholder', 'required', 'min', 'max', 'step' ),
			),
			'date'           => array(
				'label'    => __( 'Date', 'migration-assessment-form' ),
				'icon'     => 'calendar-alt',
				'supports' => array( 'required', 'min', 'max' ),
			),
			'url'            => array(
				'label'    => __( 'Website / URL', 'migration-assessment-form' ),
				'icon'     => 'admin-links',
				'supports' => array( 'placeholder', 'required' ),
			),
			'select'         => array(
				'label'    => __( 'Dropdown', 'migration-assessment-form' ),
				'icon'     => 'menu-alt',
				'supports' => array( 'required', 'options' ),
			),
			'radio'          => array(
				'label'    => __( 'Radio buttons', 'migration-assessment-form' ),
				'icon'     => 'marker',
				'supports' => array( 'required', 'options', 'inline' ),
			),
			'checkbox_group' => array(
				'label'    => __( 'Checkboxes', 'migration-assessment-form' ),
				'icon'     => 'yes-alt',
				'supports' => array( 'required', 'options', 'inline' ),
			),
			'checkbox'       => array(
				'label'    => __( 'Single checkbox / consent', 'migration-assessment-form' ),
				'icon'     => 'saved',
				'supports' => array( 'required', 'checkbox_label' ),
			),
			'country'        => array(
				'label'    => __( 'Country', 'migration-assessment-form' ),
				'icon'     => 'admin-site-alt3',
				'supports' => array( 'required' ),
			),
			'file'           => array(
				'label'    => __( 'File upload', 'migration-assessment-form' ),
				'icon'     => 'upload',
				'supports' => array( 'required', 'file' ),
			),
			'html'           => array(
				'label'    => __( 'Content block', 'migration-assessment-form' ),
				'icon'     => 'text-page',
				'supports' => array( 'content' ),
				'static'   => true,
			),
		);
	}

	/**
	 * Field types that carry no submitted value (purely presentational).
	 *
	 * @return array
	 */
	public static function static_types() {
		$static = array();
		foreach ( self::field_types() as $type => $def ) {
			if ( ! empty( $def['static'] ) ) {
				$static[] = $type;
			}
		}
		return $static;
	}

	/**
	 * Default upload constraints for `file` fields.
	 *
	 * @return array
	 */
	public static function default_file_settings() {
		return array(
			'accept'   => array( 'pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx' ),
			'max_size' => 5,   // MB.
			'multiple' => false,
			'max_files' => 3,
		);
	}

	/**
	 * Extensions that may never be whitelisted for upload, regardless of
	 * what the builder sends.
	 *
	 * @return array
	 */
	public static function blocked_extensions() {
		return array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'exe', 'bat', 'cmd', 'com', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'js', 'jar', 'msi', 'dll', 'scr', 'vbs', 'ps1', 'htaccess', 'htm', 'html', 'svg', 'swf' );
	}

	/**
	 * Deep-sanitizes a decoded schema coming from the builder (or the raw
	 * JSON tab). Unknown keys are dropped, keys are normalised, duplicate
	 * keys are made unique, and option/condition structures are validated.
	 *
	 * @param array $schema Decoded schema.
	 * @return array
	 */
	public static function sanitize_schema( $schema ) {
		$types      = self::field_types();
		$clean      = array();
		$used_keys  = array();
		$used_ids   = array();
		$section_no = 0;

		foreach ( (array) $schema as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section_no++;

			$id = isset( $section['id'] ) ? sanitize_key( $section['id'] ) : '';
			if ( '' === $id ) {
				$id = 'section_' . $section_no;
			}
			$base = $id;
			$n    = 2;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $base . '_' . $n++;
			}
			$used_ids[ $id ] = true;

			$clean_section = array(
				'id'          => $id,
				'title'       => isset( $section['title'] ) ? sanitize_text_field( $section['title'] ) : '',
				'description' => isset( $section['description'] ) ? sanitize_textarea_field( $section['description'] ) : '',
				'fields'      => array(),
			);

			if ( ! empty( $section['repeater'] ) ) {
				$clean_section['repeater']  = true;
				$clean_section['row_label'] = isset( $section['row_label'] ) ? sanitize_text_field( $section['row_label'] ) : '';
				$clean_section['min_rows']  = isset( $section['min_rows'] ) ? max( 0, (int) $section['min_rows'] ) : 0;
				$clean_section['max_rows']  = isset( $section['max_rows'] ) ? max( 0, (int) $section['max_rows'] ) : 0;
				// Repeater field keys are scoped to the section.
				$scope_keys = array();
			} else {
				$scope_keys = &$used_keys;
			}

			$field_no = 0;
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$field_no++;

				$type = isset( $field['type'] ) && isset( $types[ $field['type'] ] ) ? $field['type'] : 'text';
				$key  = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
				if ( '' === $key ) {
					$key = $type . '_' . $section_no . '_' . $field_no;
				}
				$base = $key;
				$n    = 2;
				while ( isset( $scope_keys[ $key ] ) ) {
					$key = $base . '_' . $n++;
				}
				$scope_keys[ $key ] = true;

				$cf = array(
					'key'   => $key,
					'type'  => $type,
					'label' => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
				);

				if ( ! empty( $field['required'] ) ) {
					$cf['required'] = true;
				}
				foreach ( array( 'placeholder', 'help', 'checkbox_label', 'default' ) as $str_key ) {
					if ( isset( $field[ $str_key ] ) && '' !== $field[ $str_key ] ) {
						$cf[ $str_key ] = sanitize_text_field( $field[ $str_key ] );
					}
				}
				// Width Sanitization
				$legacy_width = isset( $field['width'] ) && in_array( (string) $field['width'], array( 'full', 'half', 'third' ), true ) ? (string) $field['width'] : 'full';
				$cf['width']  = isset( $field['width_desktop'] ) && in_array( (string) $field['width_desktop'], array( 'full', 'half', 'third' ), true ) ? (string) $field['width_desktop'] : $legacy_width;
				$cf['width_desktop'] = $cf['width'];

				foreach ( array( 'width_laptop', 'width_tablet', 'width_mobile' ) as $device_width ) {
					if ( isset( $field[ $device_width ] ) && in_array( (string) $field[ $device_width ], array( 'full', 'half', 'third' ), true ) ) {
						$cf[ $device_width ] = (string) $field[ $device_width ];
					}
				}

				if ( ! empty( $field['width_pc'] ) ) {
					$cf['width_pc'] = max( 1, min( 100, (int) $field['width_pc'] ) );
				}

				foreach ( array( 'min', 'max', 'step' ) as $num_key ) {
					if ( isset( $field[ $num_key ] ) && '' !== $field[ $num_key ] ) {
						$cf[ $num_key ] = is_numeric( $field[ $num_key ] ) ? $field[ $num_key ] + 0 : sanitize_text_field( $field[ $num_key ] );
					}
				}
				foreach ( array( 'maxlength', 'rows' ) as $int_key ) {
					if ( ! empty( $field[ $int_key ] ) ) {
						$cf[ $int_key ] = (int) $field[ $int_key ];
					}
				}
				if ( ! empty( $field['inline'] ) ) {
					$cf['inline'] = true;
				}
				if ( ! empty( $field['custom_id'] ) ) {
					$cf['custom_id'] = sanitize_key( $field['custom_id'] );
				}
				if ( ! empty( $field['custom_class'] ) ) {
					$cf['custom_class'] = sanitize_text_field( $field['custom_class'] );
				}

				// Responsive widths (per-breakpoint object: { desktop: {preset,pct}, laptop: … }).
				if ( ! empty( $field['widths'] ) && is_array( $field['widths'] ) ) {
					$valid_presets  = array( '', 'full', 'half', 'third', 'inherit' );
					$valid_bp_keys = array( 'desktop', 'laptop', 'tablet', 'mobile' );
					$clean_widths  = array();
					foreach ( $field['widths'] as $bp_key => $bp_val ) {
						if ( ! in_array( $bp_key, $valid_bp_keys, true ) || ! is_array( $bp_val ) ) {
							continue;
						}
						$entry = array();
						if ( isset( $bp_val['preset'] ) && in_array( (string) $bp_val['preset'], $valid_presets, true ) ) {
							$entry['preset'] = (string) $bp_val['preset'];
						}
						if ( ! empty( $bp_val['pct'] ) ) {
							$entry['pct'] = max( 1, min( 100, (int) $bp_val['pct'] ) );
						}
						if ( ! empty( $entry ) ) {
							$clean_widths[ $bp_key ] = $entry;
						}
					}
					if ( ! empty( $clean_widths ) ) {
						$cf['widths'] = $clean_widths;
					}
				}

				if ( in_array( $type, array( 'select', 'radio', 'checkbox_group' ), true ) ) {
					$cf['options'] = array();
					foreach ( (array) ( $field['options'] ?? array() ) as $opt_val => $opt_label ) {
						$opt_val = sanitize_text_field( (string) $opt_val );
						if ( '' === $opt_val ) {
							continue;
						}
						$cf['options'][ $opt_val ] = sanitize_text_field( (string) $opt_label );
					}
				}

				if ( 'file' === $type ) {
					$defaults = self::default_file_settings();
					$accept   = isset( $field['accept'] ) ? $field['accept'] : $defaults['accept'];
					if ( is_string( $accept ) ) {
						$accept = preg_split( '/[\s,]+/', $accept );
					}
					$blocked = self::blocked_extensions();
					$accept  = array_values( array_unique( array_filter( array_map( function ( $ext ) {
						return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) );
					}, (array) $accept ), function ( $ext ) use ( $blocked ) {
						return '' !== $ext && ! in_array( $ext, $blocked, true );
					} ) ) );

					$cf['accept']    = ! empty( $accept ) ? $accept : $defaults['accept'];
					$cf['max_size']  = isset( $field['max_size'] ) ? max( 1, (int) $field['max_size'] ) : $defaults['max_size'];
					$cf['multiple']  = ! empty( $field['multiple'] );
					$cf['max_files'] = isset( $field['max_files'] ) ? max( 1, (int) $field['max_files'] ) : $defaults['max_files'];
				}

				if ( 'html' === $type ) {
					$cf['content'] = isset( $field['content'] ) ? wp_kses_post( $field['content'] ) : '';
				}

				if ( ! empty( $field['condition'] ) && is_array( $field['condition'] ) && ! empty( $field['condition']['field'] ) ) {
					$operator = isset( $field['condition']['operator'] ) ? $field['condition']['operator'] : 'equals';
					if ( ! in_array( $operator, array( 'equals', 'not_equals', 'not_empty', 'empty', 'contains' ), true ) ) {
						$operator = 'equals';
					}
					$cf['condition'] = array(
						'field'    => sanitize_key( $field['condition']['field'] ),
						'operator' => $operator,
						'value'    => isset( $field['condition']['value'] ) ? sanitize_text_field( (string) $field['condition']['value'] ) : '',
					);
				}

				$clean_section['fields'][] = $cf;
			}

			unset( $scope_keys );
			$clean[] = $clean_section;
		}

		return $clean;
	}

	/**
	 * Evaluates a field condition against a value map.
	 *
	 * @param array $condition {field, operator, value}.
	 * @param array $values    key => submitted value.
	 * @return bool
	 */
	public static function condition_matches( $condition, $values ) {
		$actual   = $values[ $condition['field'] ] ?? '';
		$expected = (string) ( $condition['value'] ?? '' );
		$operator = $condition['operator'] ?? 'equals';

		if ( is_array( $actual ) ) {
			switch ( $operator ) {
				case 'not_empty':
					return ! empty( $actual );
				case 'empty':
					return empty( $actual );
				case 'not_equals':
					return ! in_array( $expected, array_map( 'strval', $actual ), true );
				default:
					return in_array( $expected, array_map( 'strval', $actual ), true );
			}
		}

		$actual = (string) $actual;
		switch ( $operator ) {
			case 'not_equals':
				return $actual !== $expected;
			case 'not_empty':
				return '' !== trim( $actual );
			case 'empty':
				return '' === trim( $actual );
			case 'contains':
				return '' !== $expected && false !== mb_stripos( $actual, $expected );
			default:
				return $actual === $expected;
		}
	}

	/**
	 * Resolves the effective schema for a form post: saved JSON meta if
	 * present and valid, otherwise the translated default.
	 *
	 * @param int $form_id Form post ID.
	 * @return array
	 */
	public static function get_schema( $form_id ) {
		$raw = get_post_meta( $form_id, MAF_CPT::META_KEY, true );

		if ( ! empty( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return self::default_schema();
	}

	/**
	 * Flattens a schema into `key => field_definition` for quick lookup
	 * during server-side validation/sanitization.
	 *
	 * @param array $schema Schema as returned by get_schema().
	 * @return array
	 */
	public static function flatten( $schema ) {
		$flat = array();
		foreach ( $schema as $section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $field ) {
				$flat[ $field['key'] ] = $field;
			}
		}
		return $flat;
	}
}
