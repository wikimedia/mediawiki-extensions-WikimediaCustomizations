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
	private array $licenseMap = [
		'Creative Commons Attribution-Share Alike 4.0' => 'CC BY-SA 4.0',
		'Creative Commons Attribution 2.5' => 'CC BY 2.5',
		'Creative Commons Attribution 3.0' => 'CC BY 3.0',
		'Creative Commons Attribution 4.0' => 'CC BY 4.0',
	];

	/**
	 * Map the long license name like "Creative Commons Attribution 3.0" to
	 * a short name like "CC BY 3.0"
	 */
	public function mapLongNameToShortName( string $longName ): string {
		return array_key_exists( $longName, $this->licenseMap ) ? $this->licenseMap[$longName] : $longName;
	}

}
