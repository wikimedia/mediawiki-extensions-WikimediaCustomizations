<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\DonorIdentification;

use MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorPreferenceFilter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorPreferenceFilter
 */
class DonorPreferenceFilterTest extends MediaWikiUnitTestCase {

	public function testFilterForForm(): void {
		$filter = new DonorPreferenceFilter( '{"value":2}' );

		// A stored donor status means the checkbox is ticked.
		$this->assertTrue( $filter->filterForForm( '{"value":2}' ) );
		// No stored status means it is unticked.
		$this->assertFalse( $filter->filterForForm( '' ) );
	}

	public function testFilterFromFormTickedPreservesValue(): void {
		$filter = new DonorPreferenceFilter( '{"value":2}' );

		// Leaving the box ticked keeps the original value untouched.
		$this->assertSame( '{"value":2}', $filter->filterFromForm( true ) );
	}

	public function testFilterFromFormUntickedClearsValue(): void {
		$filter = new DonorPreferenceFilter( '{"value":2}' );

		// Unticking the box clears the value.
		$this->assertSame( '', $filter->filterFromForm( false ) );
	}
}
