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
		return array(
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
		);
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
