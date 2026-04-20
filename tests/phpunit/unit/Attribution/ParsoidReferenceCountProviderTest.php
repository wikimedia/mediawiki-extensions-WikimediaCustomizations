<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ReferenceCountResult;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider
 */
class ParsoidReferenceCountProviderTest extends MediaWikiUnitTestCase {

	private function newProvider(
		ParserOutputAccess $parserOutputAccess,
		?ParserOptions $parserOptions = null,
		?StatsFactory $statsFactory = null
	): ParsoidReferenceCountProvider {
		$mockOptions = $parserOptions ?? $this->createMock( ParserOptions::class );
		$statsFactory ??= StatsFactory::newNull();
		return new class( $parserOutputAccess, $mockOptions, $statsFactory ) extends
			ParsoidReferenceCountProvider {
			private ParserOptions $mockOptions;

			public function __construct( ParserOutputAccess $poa, ParserOptions $opts, StatsFactory $statsFactory ) {
				parent::__construct( $poa, $statsFactory );
				$this->mockOptions = $opts;
			}

			protected function makeParserOptions( ExistingPageRecord $page ): ParserOptions {
				return $this->mockOptions;
			}
		};
	}

	public static function provideReferenceCountCases(): array {
		return [
			'no references' => [ 'Foo bar', 0 ],
			'multiple references' => [ 'id="cite_note-2 id="cite_note-7 id="cite_note-3', 3 ],
		];
	}

	/** @dataProvider provideReferenceCountCases */
	public function testCountsReferences( string $html, int $expected ): void {
		$po = new ParserOutput();
		$po->setContentHolderText( $html );
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$parserOutputAccess->method( 'getCachedParserOutput' )->willReturn( $po );

		$statsFactory = StatsFactory::newNull();

		$provider = $this->newProvider(
			$parserOutputAccess,
			null,
			$statsFactory
		);
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
		$this->assertSame( $expected, $result->getReferenceCount() );
		$this->assertSame( ReferenceCountResult::CACHE_HIT, $result->getOperationResult() );
		$this->assertSame( ParsoidReferenceCountProvider::PROVIDER_SHORT_NAME, $result->getSource() );
	}

	public function testReturnsNullOnParserError() {
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( Status::newFatal( 'some-error' ) );
		$statsHelper = StatsFactory::newUnitTestingHelper()->withComponent( 'Attribution' );

		$provider = $this->newProvider(
			$parserOutputAccess,
			null,
			$statsHelper->getStatsFactory()
		);
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );

		$this->assertNull( $result->getReferenceCount() );
		$this->assertSame( ReferenceCountResult::ERROR, $result->getOperationResult() );
		$this->assertSame( ParsoidReferenceCountProvider::PROVIDER_SHORT_NAME, $result->getSource() );
	}

	public function testTracksCacheMissAndRendersThePage() {
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$parserOutputAccess->method( 'getCachedParserOutput' )->willReturn( null );
		$statsHelper = StatsFactory::newUnitTestingHelper()->withComponent( 'Attribution' );
		$parserOutputAccess->expects( $this->once() )
			->method( 'getParserOutput' )
			->willReturn( Status::newGood( new ParserOutput( 'test' ) ) );
		$provider = $this->newProvider(
			$parserOutputAccess,
			null,
			$statsHelper->getStatsFactory()
		);
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
		$this->assertSame( 0, $result->getReferenceCount() );
		$this->assertSame( ReferenceCountResult::CACHE_MISS, $result->getOperationResult() );
		$this->assertSame( ParsoidReferenceCountProvider::PROVIDER_SHORT_NAME, $result->getSource() );
	}

	public function testEnablesParsoid() {
		$po = new ParserOutput();
		$po->setContentHolderText( '' );
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( Status::newGood( $po ) );
		$parserOptions = $this->createMock( ParserOptions::class );
		$parserOptions->expects( $this->once() )->method( 'setUseParsoid' )->with( true );
		$parserOptions->expects( $this->once() )->method( 'setRenderReason' )->with( 'attribution' );

		$provider = $this->newProvider( $parserOutputAccess, $parserOptions );
		$provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
	}
}
