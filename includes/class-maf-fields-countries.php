<?php
/**
 * Static ISO-3166 country list for the `country` field type.
 *
 * Kept as plain English names on purpose: labels are cached/short-lived and
 * meant to be overridden per-language through WPML String Translation if a
 * fully localized country list is required (Settings → WPML → Translate
 * strings from the theme and plugins, module "migration-assessment-form").
 *
 * @package Migration_Assessment_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MAF_Fields_Countries
 */
class MAF_Fields_Countries {

	/**
	 * Returns an associative array of ISO alpha-2 code => country name.
	 *
	 * @return array
	 */
	public static function list() {
		static $countries = null;

		if ( null !== $countries ) {
			return $countries;
		}

		$countries = apply_filters(
			'maf_country_list',
			array(
				'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AR' => 'Argentina',
				'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
				'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BR' => 'Brazil',
				'BG' => 'Bulgaria', 'CA' => 'Canada', 'CN' => 'China', 'CO' => 'Colombia',
				'HR' => 'Croatia', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark',
				'EG' => 'Egypt', 'EE' => 'Estonia', 'FI' => 'Finland', 'FR' => 'France',
				'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece',
				'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India',
				'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland',
				'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan',
				'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KW' => 'Kuwait',
				'KG' => 'Kyrgyzstan', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LY' => 'Libya',
				'LT' => 'Lithuania', 'MY' => 'Malaysia', 'MX' => 'Mexico', 'MA' => 'Morocco',
				'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NG' => 'Nigeria',
				'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PS' => 'Palestine',
				'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar',
				'RO' => 'Romania', 'RU' => 'Russia', 'SA' => 'Saudi Arabia', 'RS' => 'Serbia',
				'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ZA' => 'South Africa',
				'KR' => 'South Korea', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan',
				'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan',
				'TJ' => 'Tajikistan', 'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey',
				'TM' => 'Turkmenistan', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
				'GB' => 'United Kingdom', 'US' => 'United States', 'UZ' => 'Uzbekistan',
				'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen',
			)
		);

		asort( $countries );

		return $countries;
	}
}
