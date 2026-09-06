<?php
/**
 * Handles CSV (Excel-friendly, UTF-8 BOM) and PDF export of entries,
 * respecting the same filters used on the Entries admin screen.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Export
 */
class MAF_Export {

	/**
	 * Constructor: wires the admin-post handlers for both export formats.
	 */
	public function __construct() {
		add_action( 'admin_post_maf_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_maf_export_pdf', array( $this, 'export_pdf' ) );
	}

	/**
	 * Fetches entries using the same filter vocabulary as the REST /entries
	 * endpoint, but without pagination (export = full filtered set).
	 *
	 * @return array
	 */
	private function get_filtered_entries() {
		global $wpdb;
		$table = $wpdb->prefix . 'maf_entries';

		$where  = array( '1=1' );
		$values = array();

		$filters = array(
			'form_id'           => '%d',
			'status'            => '%s',
			'language'          => '%s',
			'country_residence' => '%s',
			'marital_status'    => '%s',
		);

		foreach ( $filters as $param => $fmt ) {
			if ( ! empty( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below via check_admin_referer.
				$where[]  = "{$param} = {$fmt}";
				$values[] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';

		return $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Streams a UTF-8 (BOM-prefixed) CSV of the filtered entries. The BOM is
	 * required for Excel on Windows to render Persian/Arabic and other
	 * non-Latin characters correctly instead of mojibake.
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'migration-assessment-form' ) );
		}
		check_admin_referer( 'maf_export' );

		$entries = $this->get_filtered_entries();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=assessment-entries-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM.
		fwrite( $output, "\xEF\xBB\xBF" );

		$columns = array(
			'id', 'form_id', 'language', 'status', 'first_name', 'last_name', 'email',
			'phone', 'age', 'country_residence', 'country_citizenship', 'marital_status',
			'net_worth_cad', 'created_at',
		);

		fputcsv( $output, $columns );

		foreach ( $entries as $entry ) {
			$row = array();
			foreach ( $columns as $col ) {
				$row[] = $entry[ $col ] ?? '';
			}
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Streams a simple tabular PDF of the filtered entries using a minimal
	 * hand-rolled PDF writer (no external Composer dependency required).
	 * For richer multi-page/RTL PDF layouts, swap this for a bundled
	 * library such as Dompdf/mPDF if the host environment allows Composer.
	 */
	public function export_pdf() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'migration-assessment-form' ) );
		}
		check_admin_referer( 'maf_export' );

		$entries = $this->get_filtered_entries();

		if ( ! class_exists( 'MAF_Simple_PDF' ) ) {
			require_once MAF_PLUGIN_DIR . 'includes/class-maf-simple-pdf.php';
		}

		$pdf = new MAF_Simple_PDF();
		$pdf->add_title( __( 'Assessment Entries', 'migration-assessment-form' ) );

		$header = array( 'ID', 'Name', 'Email', 'Phone', 'Country', 'Status', 'Date' );
		$rows   = array();

		foreach ( $entries as $entry ) {
			$rows[] = array(
				$entry['id'],
				trim( $entry['first_name'] . ' ' . $entry['last_name'] ),
				$entry['email'],
				$entry['phone'],
				$entry['country_residence'],
				$entry['status'],
				$entry['created_at'],
			);
		}

		$pdf->add_table( $header, $rows );
		$pdf->stream( 'assessment-entries-' . gmdate( 'Y-m-d' ) . '.pdf' );
		exit;
	}
}
