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

}
