<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\LicenseHelper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\LicenseHelper
 */
class LicenceHelperTest extends MediaWikiUnitTestCase {

	public function testMapLongNameToShortNameCorrectlyReturnsKnowLicense(): void {
		$license = 'Creative Commons Attribution-Share Alike 4.0';
		$sut = new LicenseHelper();

		$this->assertSame( 'CC BY-SA 4.0', $sut->mapLongNameToShortName( $license ) );
	}

	public function testMapLongNameToShortNameReturnsLongNameWhenLicenseIsUknown(): void {
		$license = 'Attribution NonCommercial ShareAlike 4.0 International';
		$sut = new LicenseHelper();

		$this->assertSame( $license, $sut->mapLongNameToShortName( $license ) );
	}

	public function provideLicenseShortNameNormalization(): \Generator {
		// format is `shortname`, `expected`, `message`
		// https://commons.wikimedia.org/wiki/Template:Fair_use
		yield [ 'Fair Use', 'Fair Use', 'Fair Use should be kept as is' ];
		// https://commons.wikimedia.org/wiki/File:TemplateWizard_help_template_en_2025.png
		yield [ 'MIT', 'MIT', 'MIT Should be kept as is' ];
		yield [ 'No Restrictions', 'No Restrictions', 'No Restrictions should be kept as is' ];
		// https://commons.wikimedia.org/wiki/Template:Attribution_only_license
		yield [ 'Attribution', 'Attribution', 'Attribution should be kept as is' ];
		// https://commons.wikimedia.org/wiki/File:00_dgk5gru_32296_5640_2_nw.tif
		yield [ 'dl-de/zero-2-0', 'DL-DE/ZERO-2-0', 'Unknown strings should be only normalized to uppercase' ];
		yield [ '', '', 'Empty Licence should be kept untouched' ];
		yield [ 'public domain', 'PDM', 'Public Domain should be normalized to PDM' ];
		yield [ 'Public Domain', 'PDM', 'Public Domain should be normalized to PDM' ];
		yield [ 'PD', 'PDM', 'PD should be normalized to PDM' ];
		// https://commons.wikimedia.org/wiki/Template:PDMark-owner
		yield [ 'PDM-Owner', 'PDM', 'PDMark-Owner should be normalized to PDM' ];
		// https://commons.wikimedia.org/wiki/Template:PD-Art
		yield [ 'PDM', 'PDM', 'PDM should stay as is' ];
		// https://commons.wikimedia.org/wiki/Template:PD-US
		yield [ 'PD-US', 'PDM', 'Public domain variants should be normalized to PDM' ];
		yield [ 'pdm', 'PDM', 'lower case pdm should be normalized to PDM' ];
		yield [ 'CC with extra string', 'CC WITH EXTRA STRING', 'Unknown CC upper case but kept as is' ];
		// https://commons.wikimedia.org/wiki/Template:Cc-zero
		yield [ 'cc0', 'CC0', 'lowercase cc0 should be normalized to CC0' ];
		yield [ 'cc 0', 'CC0', 'cc 0 should be normalized to CC0' ];
		// most common licences, should handled properly
		yield [ 'CC BY-SA 4.0', 'CC BY-SA 4.0', 'Correct form should be preserved as is' ];
		yield [ 'cc by-sa 3.0', 'CC BY-SA 3.0', 'Correct form but lowercase should be uppercased' ];
		yield [ 'CC BY 2.0', 'CC BY 2.0', 'Correct CC BY 2.0 should be kept as is' ];
		yield [ 'CC BY 4.0', 'CC BY 4.0', 'Correct CC BY 4.0 should be kept as is' ];
		yield [ 'CC BY 2.5', 'CC BY 2.5', 'Correct CC BY 2.5 should be kept as is' ];
		// https://commons.wikimedia.org/wiki/Template:GFDL
		yield [ 'GFDL', 'GFDL', 'GFDL should be kept as is' ];

		yield [ 'cc-by-sa', 'CC BY-SA', 'cc-by-sa should be normalized to CC BY-SA' ];
		yield [ 'CC BY-SA Generic', 'CC BY-SA', 'Generic variant should be dropped' ];
		yield [ 'CC BY NC ND 4.0', 'CC BY-NC-ND 4.0', 'BY NC ND should be normalized to BY-NC-ND' ];
		yield [ 'cc by-sa 1.0', 'CC BY-SA 1.0', 'lowercase should be normalized, version kept as is' ];
		yield [ 'CC BY 2.0 de', 'CC BY 2.0 DE', 'DE lang should be preserved' ];
		yield [ 'CC BY-SA 3.0 igo', 'CC BY-SA 3.0 IGO', 'IGO should be preserved' ];
	}

	/**
	 * @dataProvider provideLicenseShortNameNormalization
	 */
	public function testLicenseShortNameNormalization( $shortname, $expected, $messageIfFailed ) {
		$sut = new LicenseHelper();
		$this->assertSame( $expected, $sut->normalizeShortLicenseName( $shortname ), $messageIfFailed );
	}
}
