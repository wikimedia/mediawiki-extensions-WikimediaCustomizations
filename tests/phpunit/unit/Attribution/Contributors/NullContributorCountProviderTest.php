<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution\Contributors;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\NullContributorCountProvider;
use MediaWiki\Page\ExistingPageRecord;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\NullContributorCountProvider
 */
class NullContributorCountProviderTest extends MediaWikiUnitTestCase {

	public function testAlwaysReturnsNull(): void {
		$provider = new NullContributorCountProvider();
		$page = $this->createMock( ExistingPageRecord::class );
		$this->assertNull( $provider->getContributorCounts( $page ) );
	}
}
