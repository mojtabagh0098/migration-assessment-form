<?php
/**
 * WPML integration.
 *
 * Design decisions:
 *  - Forms (`maf_form` CPT) rely on WPML's NATIVE post-translation
 *    mechanism: each language is its own post, own ID, own permalink.
 *    We do not build a custom translation table for forms — WPML already
 *    solves that correctly and this keeps admin UX (Duplicate/Translate
 *    buttons on the WPML metabox) working out of the box, as long as the
 *    site admin enables translation for `maf_form` under
 *    WPML → Settings → Custom Posts Translation.
 *  - Entries are language-tagged with a plain `language` column (the active
 *    front-end language at submission time), which is what the admin
 *    "Entries" screen filters on when the WPML admin-language-switcher is
 *    changed — entries are never silently merged across languages.
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_WPML
 */
class MAF_WPML {

	/**
	 * Constructor: wires hooks (kept intentionally light — most of WPML's
	 * work for post translation is automatic once the CPT is registered and
	 * enabled in WPML settings).
	 */
	public function __construct() {
		add_filter( 'wpml_config_array', array( $this, 'register_wpml_config' ) );
	}

	/**
	 * Suggests the wpml-config.xml equivalent programmatically for sites that
	 * don't ship the XML file — declares custom fields that should stay
	 * language-independent (none here, form schema is per-translation by design)
	 * and confirms the CPT + taxonomy translation mode.
	 *
	 * The plugin also ships /wpml-config.xml (static file) which WPML reads
	 * automatically; this filter is a defensive fallback.
	 *
	 * @param array $config Existing config.
	 * @return array
	 */
	public function register_wpml_config( $config ) {
		return $config;
	}

	/**
	 * Returns the current front-end/admin language code as tracked by WPML,
	 * falling back to the site's default locale prefix when WPML is inactive.
	 *
	 * @return string
	 */
	public static function current_language() {
		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			return ICL_LANGUAGE_CODE;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$lang = apply_filters( 'wpml_current_language', null );
			if ( $lang ) {
				return $lang;
			}
		}

		return substr( get_locale(), 0, 2 );
	}

	/**
	 * Returns the language the admin-bar language switcher is currently set
	 * to, which is what the Entries screen should scope its query by. Falls
	 * back to current_language() when WPML isn't active.
	 *
	 * @return string
	 */
	public static function admin_active_language() {
		if ( function_exists( 'apply_filters' ) ) {
			$lang = apply_filters( 'wpml_current_admin_language', null );
			if ( $lang ) {
				return $lang;
			}
		}

		return self::current_language();
	}

	/**
	 * Returns all active site languages as `code => native_name`.
	 *
	 * @return array
	 */
	public static function active_languages() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}

		$languages = apply_filters( 'wpml_active_languages', array() );
		$out       = array();

		foreach ( (array) $languages as $code => $lang ) {
			$out[ $code ] = $lang['native_name'] ?? $code;
		}

		return $out;
	}

	/**
	 * Builds a short, human-readable translation-status string for the forms
	 * list table, e.g. "EN ✓ · FA ✓ · FR —".
	 *
	 * @param int $post_id Source form post ID.
	 * @return string
	 */
	public static function translation_status_label( $post_id ) {
		$languages = self::active_languages();

		if ( empty( $languages ) ) {
			return __( 'WPML not active', 'migration-assessment-form' );
		}

		$parts = array();
		foreach ( $languages as $code => $native_name ) {
			$translated_id = apply_filters( 'wpml_object_id', $post_id, MAF_CPT::POST_TYPE, false, $code );
			$parts[]       = strtoupper( $code ) . ( $translated_id ? ' ✓' : ' —' );
		}

		return implode( ' · ', $parts );
	}
}
