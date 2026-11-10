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
		?string $message = 'shared-pages-summary',
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
			->willReturn( is_string( $message ) ? new MessageValue( 'shared-pages-summary' ) : null );
		return $summary;
	}

	/** @dataProvider provideEditorsForLink */
	public function testLinkIsAdded( array $editors, string $expected ): void {
		$metadata = [ $this->newSummary( $editors ) ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$expectedStart = ConvertibleTimestamp::convert( TS::MW, '20240101000000' );
		$expectedEnd = ConvertibleTimestamp::convert( TS::MW, '20240102000000' );
		$expectedStart = substr( $expectedStart, 0, 8 );
		$expectedEnd = substr( $expectedEnd, 0, 8 );

		$localizer = new MockMessageLocalizer();
		$output = $localizer->msg( $metadata[0]->getMessageOverride() )->parse();
		// Original text is preserved as the link content.
		$this->assertStringContainsString( '>(shared-pages-summary)</a>', $output );
		$this->assertStringContainsString(
			'href="https://sigma.toolforge.org/editorinteract.py', $output
		);
		$this->assertStringContainsString( 'server=' . WikiMap::getCurrentWikiId(), $output );
		$this->assertStringContainsString( $expected, $output );
		$this->assertStringContainsString(
			'startdate=' . $expectedStart . '&amp;enddate=' . $expectedEnd,
			$output
		);
	}

	public static function provideEditorsForLink(): iterable {
		yield 'Two editors' => [
			'editors' => [ 'Alice', 'Bob' ],
			'expected' => 'users=Alice&amp;users=Bob'
		];
		yield 'Three editors' => [
			'editors' => [ 'Alice', 'Bob', 'Carol' ],
			'expected' => 'users=Alice&amp;users=Bob&amp;users=Carol'
		];
		yield 'Encoded usernames' => [
			'editors' => [ 'Alice Alice', 'Bób' ],
			'expected' => 'users=Alice+Alice&amp;users=B%C3%B3b'
		];
		yield 'Unsorted users' => [
			// explicitly define array indexes so they are used as user IDs in newSummary
			'editors' => [ 3 => 'Alice', 1 => 'Bob', 2 => 'Carol' ],
			'expected' => 'users=Bob&amp;users=Carol&amp;users=Alice'
		];
	}

	public function testNoLinkWhenLessThanTwoEditors(): void {
		$metadata = [ $this->newSummary( [ 'Alice' ] ) ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$output = $metadata[0]->getMessageOverride();

		$this->assertNull( $output );
	}

	public function testNoLinkWhenNoMessage(): void {
		$metadata = [ $this->newSummary( [ 1 => 'Alice', 2 => 'Bob' ], null ) ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$output = $metadata[0]->getMessageOverride();

		$this->assertNull( $output );
	}

	public function testUnrelatedMetadataIsUntouched(): void {
		$other = $this->createMock( SuggestedInvestigationsCaseMetadata::class );
		$metadata = [ $other ];

		$handler = new SuggestedInvestigationsHookHandler();
		$handler->onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay( 42, $metadata );

		$output = $metadata[0]->getMessageOverride();

		$this->assertNull( $output );
	}
}
