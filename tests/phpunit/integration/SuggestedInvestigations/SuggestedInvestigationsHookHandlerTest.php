<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\SuggestedInvestigations;

use MediaWiki\Extension\CheckUser\SuggestedInvestigations\Model\SuggestedInvestigationsCaseMetadata;
use MediaWiki\Extension\CheckUser\SuggestedInvestigations\Model\SuggestedInvestigationsSharedPagesSummary;
use MediaWiki\Extension\WikimediaCustomizations\SuggestedInvestigations\SuggestedInvestigationsHookHandler;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use MockMessageLocalizer;
use Wikimedia\Message\MessageValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\SuggestedInvestigations\SuggestedInvestigationsHookHandler
 */
class SuggestedInvestigationsHookHandlerTest extends MediaWikiIntegrationTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
	}

	/**
	 * @param string[] $names Editor names, in order as they would appear.
	 */
	private function newSummary(
		array $names,
		?string $firstEditTimestamp = '20240101000000',
		?string $lastEditTimestamp = '20240102000000'
	): SuggestedInvestigationsSharedPagesSummary {
		$editors = [];
		foreach ( $names as $i => $name ) {
			$editors[] = UserIdentityValue::newRegistered( $i + 1, $name );
		}
		$summary = $this->getMockBuilder( SuggestedInvestigationsSharedPagesSummary::class )
			->onlyMethods( [ 'getCommonEditors', 'getFirstEditTimestamp', 'getLastEditTimestamp', 'getMessage' ] )
			->disableOriginalConstructor()
			->getMock();
		$summary->method( 'getCommonEditors' )
			->willReturn( $editors );
		$summary->method( 'getFirstEditTimestamp' )
			->willReturn( $firstEditTimestamp );
		$summary->method( 'getLastEditTimestamp' )
			->willReturn( $lastEditTimestamp );
		$summary->method( 'getMessage' )
			->willReturn( new MessageValue( 'shared-pages-summary' ) );
		return $summary;
	}

	public function testLinkIsAddedForExactlyTwoEditors(): void {
		// Ensure that we properly urlencode the names
		$metadata = [ $this->newSummary( [ 'Alice Alice', 'Bób' ] ) ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$expectedStart = ConvertibleTimestamp::convert( TS::UNIX, '20240101000000' );
		$expectedEnd = ConvertibleTimestamp::convert( TS::UNIX, '20240102000000' );

		$localizer = new MockMessageLocalizer();
		$output = $localizer->msg( $metadata[0]->getMessageOverride() )->parse();
		// Original text is preserved as the link content.
		$this->assertStringContainsString( '>(shared-pages-summary)</a>', $output );
		$this->assertStringContainsString(
			'href="https://interaction-timeline.toolforge.org/', $output
		);
		$this->assertStringContainsString( 'wiki=' . WikiMap::getCurrentWikiId(), $output );
		$this->assertStringContainsString( 'user=Alice+Alice&amp;user=B%C3%B3b', $output );
		$this->assertStringContainsString(
			'startDate=' . $expectedStart . '&amp;endDate=' . $expectedEnd,
			$output
		);
	}

	/** @dataProvider provideNotTwoEditors */
	public function testNoLinkWhenNotExactlyTwoEditors( array $editors ): void {
		$metadata = [ $this->newSummary( $editors ) ];
		$parsedMetadata = [ 'shared pages summary' ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$this->assertSame( 'shared pages summary', $parsedMetadata[0] );
	}

	public static function provideNotTwoEditors(): iterable {
		yield 'One editor' => [
			'editors' => [ 'Alice' ],
		];
		yield 'Three editors' => [
			'editors' => [ 'Alice', 'Bob', 'Carol' ],
		];
	}

	public function testNoLinkWhenOutputIsNull(): void {
		$metadata = [ $this->newSummary( [ 1 => 'Alice', 2 => 'Bob' ] ) ];
		$parsedMetadata = [ null ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$this->assertNull( $parsedMetadata[0] );
	}

	public function testUnrelatedMetadataIsUntouched(): void {
		$other = $this->createMock( SuggestedInvestigationsCaseMetadata::class );
		$metadata = [ $other ];
		$parsedMetadata = [ 'some other metadata' ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$this->assertSame( 'some other metadata', $parsedMetadata[0] );
	}
}
