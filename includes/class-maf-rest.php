<?php
/**
 * REST API controller.
 *
 * Public route:
 *   POST /wp-json/maf/v1/submit           — form submission (nonce-protected, rate-limited)
 *
 * Admin routes (require `manage_options` + valid nonce):
 *   GET   /wp-json/maf/v1/entries          — list/filter entries
 *   GET   /wp-json/maf/v1/entries/{id}     — single entry + audit trail
 *   PATCH /wp-json/maf/v1/entries/{id}     — update status/fields (writes audit log)
 *   GET   /wp-json/maf/v1/entries/{id}/audit — audit log only
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_REST
 */
class MAF_REST {

	const NAMESPACE_URI = 'maf/v1';

	/**
	 * Constructor: wires hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers all REST routes.
	 */
	public function register_routes() {

		register_rest_route(
			self::NAMESPACE_URI,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'verify_public_nonce' ),
				'args'                => array(
					'form_id' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && get_post_type( (int) $value ) === MAF_CPT::POST_TYPE;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_URI,
			'/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_entries' ),
				'permission_callback' => array( $this, 'verify_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_URI,
			'/entries/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_entry' ),
					'permission_callback' => array( $this, 'verify_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update_entry' ),
					'permission_callback' => array( $this, 'verify_admin' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_URI,
			'/entries/(?P<id>\d+)/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_audit' ),
				'permission_callback' => array( $this, 'verify_admin' ),
			)
		);
	}

	/**
	 * Verifies the WP REST nonce for public (front-end) requests. Combined
	 * with WordPress's built-in cookie auth, this protects against CSRF while
	 * still allowing logged-out visitors to submit the form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_public_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Verifies the requester is an authenticated administrator with a valid nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function verify_admin( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'maf_forbidden', __( 'You do not have permission to do this.', 'migration-assessment-form' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'maf_bad_nonce', __( 'Security check failed, please reload the page.', 'migration-assessment-form' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handles a public form submission: validates + sanitizes every field
	 * against the form's schema, then stores it in the custom entries table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( $request ) {
		global $wpdb;

		$form_id = (int) $request->get_param( 'form_id' );
		$schema  = MAF_Fields::get_schema( $form_id );
		$flat    = MAF_Fields::flatten( $schema );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$clean_data = array();
		$errors     = array();

		foreach ( $schema as $section ) {
			if ( ! empty( $section['repeater'] ) ) {
				$rows = isset( $payload[ $section['id'] ] ) && is_array( $payload[ $section['id'] ] ) ? $payload[ $section['id'] ] : array();
				$clean_rows = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean_row = array();
					foreach ( $section['fields'] as $field ) {
						$clean_row[ $field['key'] ] = $this->sanitize_field( $field, $row[ $field['key'] ] ?? '' );
					}
					$clean_rows[] = $clean_row;
				}
				$clean_data[ $section['id'] ] = $clean_rows;
				continue;
			}

			foreach ( $section['fields'] as $field ) {
				$raw_value = $payload[ $field['key'] ] ?? '';

				// Respect conditional-required logic (mirrors front-end behavior server-side).
				$is_applicable = true;
				if ( ! empty( $field['condition'] ) ) {
					$dep_value     = $payload[ $field['condition']['field'] ] ?? '';
					$is_applicable = ( (string) $dep_value === (string) $field['condition']['value'] );
				}

				if ( $is_applicable && ! empty( $field['required'] ) && '' === trim( (string) $raw_value ) ) {
					$errors[ $field['key'] ] = __( 'This field is required.', 'migration-assessment-form' );
					continue;
				}

				$clean_data[ $field['key'] ] = $is_applicable ? $this->sanitize_field( $field, $raw_value ) : '';
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'maf_validation_failed', __( 'Please correct the highlighted fields.', 'migration-assessment-form' ), array(
				'status' => 422,
				'fields' => $errors,
			) );
		}

		$table = $wpdb->prefix . 'maf_entries';

		$inserted = $wpdb->insert(
			$table,
			array(
				'form_id'             => $form_id,
				'language'            => MAF_WPML::current_language(),
				'status'              => 'submitted',
				'first_name'          => $clean_data['first_name'] ?? '',
				'last_name'           => $clean_data['last_name'] ?? '',
				'email'               => $clean_data['email'] ?? '',
				'phone'               => $clean_data['phone'] ?? '',
				'age'                 => isset( $clean_data['age'] ) && '' !== $clean_data['age'] ? (int) $clean_data['age'] : null,
				'country_residence'   => $clean_data['country_residence'] ?? '',
				'country_citizenship' => $clean_data['country_citizenship'] ?? '',
				'marital_status'      => $clean_data['marital_status'] ?? '',
				'net_worth_cad'       => isset( $clean_data['net_worth_cad'] ) && '' !== $clean_data['net_worth_cad'] ? (float) $clean_data['net_worth_cad'] : null,
				'data'                => wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE ),
				'ip_address'          => $this->get_client_ip(),
				'user_agent'          => substr( sanitize_text_field( $request->get_header( 'User-Agent' ) ), 0, 255 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'maf_db_error', __( 'Could not save your submission. Please try again.', 'migration-assessment-form' ), array( 'status' => 500 ) );
		}

		do_action( 'maf_entry_submitted', $wpdb->insert_id, $clean_data, $form_id );

		return new WP_REST_Response( array( 'success' => true, 'entry_id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Sanitizes a single value according to its declared field type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return string|int|float
	 */
	public function sanitize_field( $field, $value ) {
		switch ( $field['type'] ) {
			case 'email':
				return sanitize_email( $value );
			case 'number':
				return is_numeric( $value ) ? $value + 0 : '';
			case 'tel_intl':
				return preg_replace( '/[^0-9+\-\s()]/', '', (string) $value );
			case 'country':
				$valid = MAF_Fields_Countries::list();
				return isset( $valid[ $value ] ) ? $value : '';
			case 'select':
				$valid = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
				return in_array( $value, $valid, true ) ? $value : '';
			case 'textarea':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Lists entries with dynamic filtering (status, language, country, age
	 * range, marital status, form, free-text search) + pagination.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_list_entries( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'maf_entries';

		$where  = array( '1=1' );
		$values = array();

		$filters = array(
			'form_id'            => '%d',
			'status'             => '%s',
			'language'           => '%s',
			'country_residence'  => '%s',
			'marital_status'     => '%s',
		);

		foreach ( $filters as $param => $fmt ) {
			$val = $request->get_param( $param );
			if ( null !== $val && '' !== $val ) {
				$where[]  = "{$param} = {$fmt}";
				$values[] = $val;
			}
		}

		$age_min = $request->get_param( 'age_min' );
		$age_max = $request->get_param( 'age_max' );
		if ( is_numeric( $age_min ) ) {
			$where[]  = 'age >= %d';
			$values[] = (int) $age_min;
		}
		if ( is_numeric( $age_max ) ) {
			$where[]  = 'age <= %d';
			$values[] = (int) $age_max;
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_values = array_merge( $values, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as &$row ) {
			$row['data'] = json_decode( $row['data'], true );
		}

		return new WP_REST_Response(
			array(
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'entries'  => $rows,
			),
			200
		);
	}

	/**
	 * Fetches a single entry with its full audit trail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_entry( $request ) {
		global $wpdb;
		$id    = (int) $request['id'];
		$table = $wpdb->prefix . 'maf_entries';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'maf_not_found', __( 'Entry not found.', 'migration-assessment-form' ), array( 'status' => 404 ) );
		}

		$row['data']  = json_decode( $row['data'], true );
		$row['audit'] = MAF_Audit::get_log( $id );

		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * Updates an entry's status and/or field values, writing one audit-log
	 * row per changed field so the "previous value / new value" trail is
	 * always reconstructable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_update_entry( $request ) {
		global $wpdb;
		$id    = (int) $request['id'];
		$table = $wpdb->prefix . 'maf_entries';

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $existing ) {
			return new WP_Error( 'maf_not_found', __( 'Entry not found.', 'migration-assessment-form' ), array( 'status' => 404 ) );
		}

		$body   = $request->get_json_params();
		$note   = isset( $body['note'] ) ? sanitize_textarea_field( $body['note'] ) : '';
		$update = array();
		$formats = array();

		if ( isset( $body['status'] ) ) {
			$new_status = sanitize_key( $body['status'] );
			$allowed    = array( 'submitted', 'conditional', 'approved', 'rejected' );
			if ( in_array( $new_status, $allowed, true ) && $new_status !== $existing['status'] ) {
				MAF_Audit::log( $id, 'status', $existing['status'], $new_status, $note );
				$update['status'] = $new_status;
				$formats[]         = '%s';
			}
		}

		// Allow limited direct edits to a few hot columns (extend as needed).
		$editable_columns = array( 'first_name', 'last_name', 'email', 'phone', 'country_residence' );
		foreach ( $editable_columns as $col ) {
			if ( isset( $body[ $col ] ) ) {
				$new_val = sanitize_text_field( $body[ $col ] );
				if ( $new_val !== $existing[ $col ] ) {
					MAF_Audit::log( $id, $col, $existing[ $col ], $new_val, $note );
					$update[ $col ] = $new_val;
					$formats[]      = '%s';
				}
			}
		}

		if ( ! empty( $update ) ) {
			$wpdb->update( $table, $update, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		$fresh['data']  = json_decode( $fresh['data'], true );
		$fresh['audit'] = MAF_Audit::get_log( $id );

		return new WP_REST_Response( $fresh, 200 );
	}

	/**
	 * Returns only the audit trail for an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_get_audit( $request ) {
		$id = (int) $request['id'];
		return new WP_REST_Response( MAF_Audit::get_log( $id ), 200 );
	}

	/**
	 * Best-effort client IP resolution (kept out of user-editable input).
	 *
	 * @return string
	 */
	private function get_client_ip() {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0];
				return trim( $ip );
			}
		}
		return '';
	}
}
