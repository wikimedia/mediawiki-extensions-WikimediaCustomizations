<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

/**
 * A quick helper class to transform/normalize license information
 *
 * @unstable
 */
class LicenseHelper {
	/**
	 * List created based on mediawiki-config/wmf-config/InitializeSettings wgRightsText config
	 */
	private static array $licenseMap = [
		'Creative Commons Attribution-Share Alike 4.0' => 'CC BY-SA 4.0',
		'Creative Commons Attribution 2.5' => 'CC BY 2.5',
		'Creative Commons Attribution 3.0' => 'CC BY 3.0',
		'Creative Commons Attribution 4.0' => 'CC BY 4.0',
	];

	/**
	 * Map the long license name like "Creative Commons Attribution 3.0" to
	 * a short name like "CC BY 3.0"
	 */
	public static function mapLongNameToShortName( string $longName ): string {
		return array_key_exists( $longName, self::$licenseMap ) ? self::$licenseMap[$longName] :
			$longName;
	}

	/**
	 * Normalize the short license name to match the CC format
	 */
	public static function normalizeShortLicenseName( string $shortName ): string {
		$allUpperCase = strtoupper( $shortName );

		// quick and simple check first, CC) return as is
		if ( $allUpperCase === 'CC0' || $allUpperCase === 'CC 0' ) {
			return 'CC0';
		}

		// Handle the most common license type - Creative Commons
		if ( str_starts_with( $allUpperCase, 'CC' ) ) {
			// `CC [BY|BY-SA|BY-ND|BY-NC|BY-NC-SA|BY-NC-ND] [version] [variant|Generic]` format
			$pattern = '/(CC)\s*[- ]*(BY)?\s*[- ]*(NC)?\s*[- ]*(SA|ND)?\s*[- ]*(\d+\.\d+)?\s*[- ]*([A-Z]+|GENERIC)?/';
			return preg_replace_callback( $pattern, static function ( $matched ) use ( $allUpperCase ) {
				if ( count( $matched ) == 2 ) {
					// Only CC matched, return as is but upper case
					return $allUpperCase;
				}
				$by = $matched[2] ?? '';
				$nc = $matched[3] ?? '';
				$sand = $matched[4] ?? '';
				$version = $matched[5] ?? '';
				$variant = $matched[6] ?? '';
				if ( $variant === 'GENERIC' ) {
					// skip if variant is Generic
					$variant = '';
				}
				$licenseComponents = array_filter( [ $by, $nc, $sand ] );
				$licensePart = implode( '-', $licenseComponents );
				$parts = [ 'CC' ];
				if ( $licensePart !== '' ) {
					$parts[] = $licensePart;
				}
				if ( $version !== '' ) {
					$parts[] = $version;
				}
				if ( $variant !== '' ) {
					$parts[] = $variant;
				}

				return implode( ' ', $parts );
			}, $allUpperCase, 5 );
		}

		// The second most popular is Public Domain
		if ( $allUpperCase === 'PUBLIC DOMAIN'
			|| $allUpperCase === 'PD'
			|| $allUpperCase === 'PDM'
			|| str_starts_with( $allUpperCase, 'PD-' )
			|| str_starts_with( $allUpperCase, 'PDM-' )
		) {
			// PD, PDM, Public Domain and PD-{variant} / PDM-{variant} are all Public Domain
			return 'PDM';
		}

		$map = [
			'FAIR USE' => 'Fair Use',
			'ATTRIBUTION' => 'Attribution',
			'NO RESTRICTIONS' => 'No Restrictions'
		];

		// For known type return normalized, otherwise return all caps
		return array_key_exists( $allUpperCase, $map ) ? $map[$allUpperCase] : $allUpperCase;
	}

}
