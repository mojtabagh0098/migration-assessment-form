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
	 * against the form's schema (including uploads), then stores it in the
	 * custom entries table.
	 *
	 * Accepts either JSON (legacy) or multipart/form-data with:
	 *   payload  JSON string of all scalar values
	 *   files    JSON array of { id, path[], multiple } describing each file part
	 *   file_N   the binary uploads
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( $request ) {
		global $wpdb;

		$form_id = (int) $request->get_param( 'form_id' );
		$schema  = MAF_Fields::get_schema( $form_id );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$body    = $request->get_body_params();
			$payload = array();
			if ( isset( $body['payload'] ) ) {
				$payload = json_decode( wp_unslash( $body['payload'] ), true );
				if ( ! is_array( $payload ) ) {
					$payload = array();
				}
			} elseif ( is_array( $body ) ) {
				$payload = $body;
			}
		}

		// Process uploads first so file values participate in validation like any other value.
		$file_errors = array();
		$payload     = $this->process_uploads( $request, $schema, $payload, $file_errors );

		$clean_data = array();
		$errors     = $file_errors;

		foreach ( $schema as $section ) {
			if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			if ( ! empty( $section['repeater'] ) ) {
				$rows       = isset( $payload[ $section['id'] ] ) && is_array( $payload[ $section['id'] ] ) ? array_values( $payload[ $section['id'] ] ) : array();
				$clean_rows = array();
				$row_no     = 0;
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean_row = array();
					foreach ( $section['fields'] as $field ) {
						if ( in_array( $field['type'], MAF_Fields::static_types(), true ) ) {
							continue;
						}
						$raw           = $row[ $field['key'] ] ?? '';
						$is_applicable = empty( $field['condition'] ) || MAF_Fields::condition_matches( $field['condition'], $row );
						if ( $is_applicable && ! empty( $field['required'] ) && $this->is_blank( $raw ) ) {
							$errors[ $section['id'] . '[' . $row_no . '][' . $field['key'] . ']' ] = __( 'This field is required.', 'migration-assessment-form' );
							continue;
						}
						$clean_row[ $field['key'] ] = $is_applicable ? $this->sanitize_field( $field, $raw ) : '';
					}
					$clean_rows[] = $clean_row;
					$row_no++;
				}
				$min = (int) ( $section['min_rows'] ?? 0 );
				$max = (int) ( $section['max_rows'] ?? 0 );
				if ( $max > 0 && count( $clean_rows ) > $max ) {
					$clean_rows = array_slice( $clean_rows, 0, $max );
				}
				if ( $min > 0 && count( $clean_rows ) < $min ) {
					$errors[ $section['id'] ] = sprintf( __( 'Please add at least %d entries.', 'migration-assessment-form' ), $min );
				}
				$clean_data[ $section['id'] ] = $clean_rows;
				continue;
			}

			foreach ( $section['fields'] as $field ) {
				if ( in_array( $field['type'], MAF_Fields::static_types(), true ) ) {
					continue;
				}
				$raw_value = $payload[ $field['key'] ] ?? '';

				// Respect conditional-required logic (mirrors front-end behavior server-side).
				$is_applicable = empty( $field['condition'] ) || MAF_Fields::condition_matches( $field['condition'], $payload );

				if ( $is_applicable && ! empty( $field['required'] ) && $this->is_blank( $raw_value ) ) {
					$errors[ $field['key'] ] = __( 'This field is required.', 'migration-assessment-form' );
					continue;
				}

				$clean_data[ $field['key'] ] = $is_applicable ? $this->sanitize_field( $field, $raw_value ) : '';
			}
		}

		if ( ! empty( $errors ) ) {
			$this->discard_uploads( $payload );
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
	 * True when a submitted value should count as "empty" for required checks.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_blank( $value ) {
		if ( is_array( $value ) ) {
			return 0 === count( array_filter( $value, function ( $v ) { return ! $this->is_blank( $v ); } ) );
		}
		return '' === trim( (string) $value );
	}

	/**
	 * Moves uploaded files into `uploads/maf-uploads/{Y}/{m}/` (with an
	 * index.php + .htaccess guard) after validating extension/size against
	 * the matching `file` field, and injects file descriptors into $payload
	 * at the path described by the `files` map.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $schema  Form schema.
	 * @param array           $payload Scalar payload.
	 * @param array           $errors  Collected validation errors (by ref).
	 * @return array Payload with file descriptors merged in.
	 */
	private function process_uploads( $request, $schema, $payload, &$errors ) {
		$files = $request->get_file_params();
		if ( empty( $files ) ) {
			return $payload;
		}

		$map = json_decode( wp_unslash( $request->get_param( 'files' ) ?: '[]' ), true );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		// Build a lookup of file field definitions: "section_id/key" for repeaters, "key" otherwise.
		$file_fields = array();
		foreach ( $schema as $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( 'file' === ( $field['type'] ?? '' ) ) {
					$lookup = ! empty( $section['repeater'] ) ? $section['id'] . '/' . $field['key'] : $field['key'];
					$file_fields[ $lookup ] = $field;
				}
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload_dir = wp_upload_dir();
		$subdir     = '/maf-uploads/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$target_dir = $upload_dir['basedir'] . $subdir;
		wp_mkdir_p( $target_dir );
		$this->protect_upload_dir( $upload_dir['basedir'] . '/maf-uploads' );

		$per_field_count = array();

		foreach ( $map as $entry ) {
			if ( empty( $entry['id'] ) || empty( $entry['path'] ) || ! is_array( $entry['path'] ) || ! isset( $files[ $entry['id'] ] ) ) {
				continue;
			}
			$path = array_map( 'sanitize_text_field', $entry['path'] );
			$key  = end( $path );
			$lookup = count( $path ) === 3 ? $path[0] . '/' . $key : $key;
			$field  = $file_fields[ $lookup ] ?? null;
			$err_key = count( $path ) === 3 ? $path[0] . '[' . $path[1] . '][' . $path[2] . ']' : $key;

			if ( ! $field ) {
				continue; // Unknown file field: ignore silently.
			}

			$file = $files[ $entry['id'] ];
			if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
				$errors[ $err_key ] = __( 'Upload failed. Please try again.', 'migration-assessment-form' );
				continue;
			}

			$allowed  = array_map( 'strtolower', (array) $field['accept'] );
			$max_size = (int) ( $field['max_size'] ?? 5 ) * 1048576;
			$ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, $allowed, true ) ) {
				$errors[ $err_key ] = __( 'File type not allowed.', 'migration-assessment-form' );
				continue;
			}
			if ( (int) $file['size'] > $max_size ) {
				$errors[ $err_key ] = sprintf( __( 'File is too large (max %s MB).', 'migration-assessment-form' ), (int) ( $field['max_size'] ?? 5 ) );
				continue;
			}

			$per_field_count[ $err_key ] = ( $per_field_count[ $err_key ] ?? 0 ) + 1;
			$limit = ! empty( $field['multiple'] ) ? (int) ( $field['max_files'] ?? 1 ) : 1;
			if ( $per_field_count[ $err_key ] > $limit ) {
				$errors[ $err_key ] = sprintf( __( 'Too many files (max %s).', 'migration-assessment-form' ), $limit );
				continue;
			}

			// Real MIME sniffing via WP (rejects disguised executables).
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
				$errors[ $err_key ] = __( 'File type not allowed.', 'migration-assessment-form' );
				continue;
			}

			$filter = function ( $dirs ) use ( $subdir ) {
				$dirs['subdir'] = $subdir;
				$dirs['path']   = $dirs['basedir'] . $subdir;
				$dirs['url']    = $dirs['baseurl'] . $subdir;
				return $dirs;
			};
			add_filter( 'upload_dir', $filter );
			$moved = wp_handle_upload(
				$file,
				array(
					'test_form'                => false,
					'unique_filename_callback' => function ( $dir, $name, $ext ) {
						return 'maf-' . wp_generate_password( 12, false ) . '-' . sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) ) . $ext;
					},
				)
			);
			remove_filter( 'upload_dir', $filter );

			if ( isset( $moved['error'] ) ) {
				$errors[ $err_key ] = $moved['error'];
				continue;
			}

			$descriptor = array(
				'name' => sanitize_file_name( $file['name'] ),
				'size' => (int) $file['size'],
				'type' => $check['type'],
				'url'  => esc_url_raw( $moved['url'] ),
				'path' => $subdir . '/' . basename( $moved['file'] ),
			);

			// Inject into payload at path.
			$cursor = &$payload;
			foreach ( $path as $i => $segment ) {
				$last = $i === count( $path ) - 1;
				if ( $last ) {
					if ( ! empty( $entry['multiple'] ) ) {
						if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
							$cursor[ $segment ] = array();
						}
						$cursor[ $segment ][] = $descriptor;
					} else {
						$cursor[ $segment ] = $descriptor;
					}
				} else {
					if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
						$cursor[ $segment ] = array();
					}
					$cursor = &$cursor[ $segment ];
				}
			}
			unset( $cursor );
		}

		return $payload;
	}

	/**
	 * Deletes files that were moved for a submission that ultimately failed validation.
	 *
	 * @param array $payload Payload containing file descriptors.
	 */
	private function discard_uploads( $payload ) {
		$basedir = wp_upload_dir()['basedir'];
		array_walk_recursive( $payload, function ( $value, $key ) use ( $basedir ) {
			// Descriptors are flattened by walk_recursive; catch the `path` leaf.
			if ( 'path' === $key && is_string( $value ) && 0 === strpos( $value, '/maf-uploads/' ) && file_exists( $basedir . $value ) ) {
				wp_delete_file( $basedir . $value );
			}
		} );
	}

	/**
	 * Drops index.php + .htaccess into the upload folder so directory listing
	 * and script execution are blocked.
	 *
	 * @param string $dir Absolute path.
	 */
	private function protect_upload_dir( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', '<?php' . PHP_EOL . '// Silence is golden.' . PHP_EOL ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', 'Options -Indexes' . PHP_EOL . '<FilesMatch "\.(php|phtml|php\d|pl|py|cgi|sh)$">' . PHP_EOL . 'Deny from all' . PHP_EOL . '</FilesMatch>' . PHP_EOL ); // phpcs:ignore
		}
	}

	/**
	 * Sanitizes a single value according to its declared field type.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public function sanitize_field( $field, $value ) {
		switch ( $field['type'] ) {
			case 'email':
				return sanitize_email( $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return '';
				}
				$num = $value + 0;
				if ( isset( $field['min'] ) && is_numeric( $field['min'] ) && $num < $field['min'] ) {
					$num = $field['min'] + 0;
				}
				if ( isset( $field['max'] ) && is_numeric( $field['max'] ) && $num > $field['max'] ) {
					$num = $field['max'] + 0;
				}
				return $num;
			case 'date':
				$value = sanitize_text_field( (string) $value );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			case 'tel_intl':
				return preg_replace( '/[^0-9+\-\s()]/', '', (string) $value );
			case 'country':
				$valid = MAF_Fields_Countries::list();
				return isset( $valid[ $value ] ) ? $value : '';
			case 'select':
			case 'radio':
				$valid = isset( $field['options'] ) ? array_map( 'strval', array_keys( $field['options'] ) ) : array();
				return in_array( (string) $value, $valid, true ) ? (string) $value : '';
			case 'checkbox_group':
				$valid = isset( $field['options'] ) ? array_map( 'strval', array_keys( $field['options'] ) ) : array();
				$vals  = is_array( $value ) ? $value : ( '' === $value ? array() : array( $value ) );
				return array_values( array_intersect( array_map( 'strval', $vals ), $valid ) );
			case 'checkbox':
				return ! empty( $value ) && '0' !== (string) $value ? 1 : 0;
			case 'file':
				return $this->sanitize_file_value( $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			default:
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				$clean = sanitize_text_field( (string) $value );
				if ( ! empty( $field['maxlength'] ) ) {
					$clean = mb_substr( $clean, 0, (int) $field['maxlength'] );
				}
				return $clean;
		}
	}

	/**
	 * Only descriptors created by process_uploads() survive; anything
	 * client-supplied that isn't a real stored file is discarded.
	 *
	 * @param mixed $value Descriptor or list of descriptors.
	 * @return array
	 */
	private function sanitize_file_value( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$is_list = isset( $value[0] ) || array() === $value;
		$items   = $is_list ? $value : array( $value );
		$basedir = wp_upload_dir()['basedir'];
		$clean   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['path'] ) || 0 !== strpos( $item['path'], '/maf-uploads/' ) || ! file_exists( $basedir . $item['path'] ) ) {
				continue;
			}
			$clean[] = array(
				'name' => sanitize_file_name( $item['name'] ?? basename( $item['path'] ) ),
				'size' => (int) ( $item['size'] ?? 0 ),
				'type' => sanitize_text_field( $item['type'] ?? '' ),
				'url'  => esc_url_raw( $item['url'] ?? '' ),
				'path' => sanitize_text_field( $item['path'] ),
			);
		}
		return $clean;
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
