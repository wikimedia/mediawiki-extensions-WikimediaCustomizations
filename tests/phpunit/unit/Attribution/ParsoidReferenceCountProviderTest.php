<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider
 */
class ParsoidReferenceCountProviderTest extends MediaWikiUnitTestCase {

	private function newProvider(
		ParserOutputAccess $parserOutputAccess,
		?ParserOptions $parserOptions = null
	): ParsoidReferenceCountProvider {
		$mockOptions = $parserOptions ?? $this->createMock( ParserOptions::class );
		return new class( $parserOutputAccess, $mockOptions ) extends ParsoidReferenceCountProvider {
			private ParserOptions $mockOptions;

			public function __construct( ParserOutputAccess $poa, ParserOptions $opts ) {
				parent::__construct( $poa );
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
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( Status::newGood( $po ) );

		$provider = $this->newProvider( $parserOutputAccess );
		$this->assertSame( $expected, $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) ) );
	}

	public function testReturnsNullOnParserError() {
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( Status::newFatal( 'some-error' ) );

		$provider = $this->newProvider( $parserOutputAccess );
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
		$this->assertNull( $result );
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
