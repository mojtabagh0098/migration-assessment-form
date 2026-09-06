<?php
/**
 * A minimal, dependency-free PDF writer used only for the plain tabular
 * entries export, so the plugin doesn't require Composer/Dompdf/mPDF to be
 * available on the host. Supports Latin-1 text via the built-in Helvetica
 * base-14 font (standard PDF viewers render this without embedding).
 *
 * NOTE: base-14 fonts do not support Persian/Arabic/CJK glyphs. If the
 * export must render non-Latin text, either (a) embed a Unicode TTF font
 * (requires a font-subsetting step beyond this minimal writer) or (b) rely
 * on the CSV export, which fully supports UTF-8 or (c) swap this class for
 * Dompdf/mPDF via Composer if the hosting environment allows it.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Simple_PDF
 */
class MAF_Simple_PDF {

	const PAGE_WIDTH   = 595; // A4 at 72dpi.
	const PAGE_HEIGHT  = 842;
	const MARGIN       = 40;
	const LINE_HEIGHT  = 16;

	/** @var array Lines of ['text' => string, 'size' => int, 'bold' => bool] to render, one page worth at a time. */
	private $pages = array();

	/** @var array Buffer for the page currently being built. */
	private $current_lines = array();

	/**
	 * Adds a document title (first line, larger/bold).
	 *
	 * @param string $title Title text.
	 */
	public function add_title( $title ) {
		$this->current_lines[] = array( 'text' => $title, 'size' => 18, 'bold' => true );
		$this->current_lines[] = array( 'text' => '', 'size' => 10, 'bold' => false );
	}

	/**
	 * Adds a simple header + rows table, wrapping to new pages as needed.
	 *
	 * @param array $header Column headers.
	 * @param array $rows   Array of row arrays (same column count as header).
	 */
	public function add_table( $header, $rows ) {
		$this->current_lines[] = array( 'text' => implode( '   |   ', $header ), 'size' => 10, 'bold' => true );
		$this->current_lines[] = array( 'text' => str_repeat( '-', 100 ), 'size' => 8, 'bold' => false );

		foreach ( $rows as $row ) {
			$line = implode( '   |   ', array_map( array( $this, 'to_latin1_safe' ), $row ) );
			$this->current_lines[] = array( 'text' => $line, 'size' => 9, 'bold' => false );

			if ( $this->lines_fit_on_page() >= $this->max_lines_per_page() ) {
				$this->flush_page();
			}
		}

		$this->flush_page();
	}

	/**
	 * Best-effort transliteration guard: base-14 fonts only support
	 * Latin-1, so non-Latin bytes are replaced to avoid a corrupt PDF.
	 * The CSV export should be used when full Unicode is required.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function to_latin1_safe( $text ) {
		$converted = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text );
		return false === $converted ? preg_replace( '/[^\x20-\x7E]/', '?', (string) $text ) : $converted;
	}

	/**
	 * @return int Number of lines queued for the current page.
	 */
	private function lines_fit_on_page() {
		return count( $this->current_lines );
	}

	/**
	 * @return int Max lines that fit within the printable page height.
	 */
	private function max_lines_per_page() {
		return (int) floor( ( self::PAGE_HEIGHT - 2 * self::MARGIN ) / self::LINE_HEIGHT );
	}

	/**
	 * Moves the current line buffer into `$pages` and resets the buffer.
	 */
	private function flush_page() {
		if ( ! empty( $this->current_lines ) ) {
			$this->pages[]       = $this->current_lines;
			$this->current_lines = array();
		}
	}

	/**
	 * Builds the raw PDF byte stream from the buffered pages.
	 *
	 * @return string
	 */
	private function build() {
		$this->flush_page();
		if ( empty( $this->pages ) ) {
			$this->pages[] = array( array( 'text' => ' ', 'size' => 10, 'bold' => false ) );
		}

		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$page_ids = array();
		$content_objects = array();

		foreach ( $this->pages as $i => $lines ) {
			$page_obj_id    = 3 + $i * 2;
			$content_obj_id = $page_obj_id + 1;
			$page_ids[]     = $page_obj_id;

			$stream  = "BT\n/F1 10 Tf\n";
			$y       = self::PAGE_HEIGHT - self::MARGIN;

			foreach ( $lines as $line ) {
				$size = $line['size'];
				$font = $line['bold'] ? '/F2' : '/F1';
				$escaped = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line['text'] );
				$stream .= "{$font} {$size} Tf\n1 0 0 1 " . self::MARGIN . " {$y} Tm\n({$escaped}) Tj\n";
				$y -= self::LINE_HEIGHT;
			}
			$stream .= 'ET';

			$content_objects[ $content_obj_id ] = "<< /Length " . strlen( $stream ) . " >>\nstream\n{$stream}\nendstream";
		}

		$kids = implode( ' ', array_map( fn( $id ) => "{$id} 0 R", $page_ids ) );
		$objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count( $page_ids ) . ' >>';

		foreach ( $this->pages as $i => $lines ) {
			$page_obj_id    = 3 + $i * 2;
			$content_obj_id = $page_obj_id + 1;
			$objects[ $page_obj_id ]    = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . "] /Resources << /Font << /F1 100 0 R /F2 101 0 R >> >> /Contents {$content_obj_id} 0 R >>";
			$objects[ $content_obj_id ] = $content_objects[ $content_obj_id ];
		}

		$objects[100] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		$objects[101] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

		ksort( $objects );

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		$max_id  = max( array_keys( $objects ) );

		for ( $id = 1; $id <= $max_id; $id++ ) {
			if ( ! isset( $objects[ $id ] ) ) {
				continue;
			}
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= "{$id} 0 obj\n{$objects[ $id ]}\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$pdf .= "xref\n0 " . ( $max_id + 1 ) . "\n0000000000 65535 f \n";
		for ( $id = 1; $id <= $max_id; $id++ ) {
			$pdf .= isset( $offsets[ $id ] ) ? sprintf( "%010d 00000 n \n", $offsets[ $id ] ) : "0000000000 00000 f \n";
		}

		$pdf .= "trailer\n<< /Size " . ( $max_id + 1 ) . " /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF";

		return $pdf;
	}

	/**
	 * Streams the generated PDF to the browser as a file download.
	 *
	 * @param string $filename Suggested filename.
	 */
	public function stream( $filename ) {
		$pdf = $this->build();

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		header( 'Content-Length: ' . strlen( $pdf ) );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary PDF stream.
	}
}
