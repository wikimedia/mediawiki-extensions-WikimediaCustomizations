<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\FlaggedRevs\Backend\FlaggedRevsParserCacheFactory;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\FlaggedRevsReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ReferenceCountResult;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Parser\ParserCache;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\FlaggedRevsReferenceCountProvider
 */
class FlaggedRevsReferenceCountProviderTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'FlaggedRevs' );
	}

	/**
	 * Create a provider with fixed pageUsesFlaggedRevsStable() and makeParserOptions() return
	 * values, bypassing the real FlaggableWikiPage/ExtensionRegistry/Title static calls.
	 */
	private function newProvider(
		bool $usesFlaggedRevsStable,
		FlaggedRevsParserCacheFactory $factory,
		?ReferenceCountProvider $fallback = null,
		?ParserOptions $parserOptions = null
	): FlaggedRevsReferenceCountProvider {
		$fallback ??= $this->createMock( ReferenceCountProvider::class );
		$mockOptions = $parserOptions ?? $this->createMock( ParserOptions::class );
		return new class(
			$factory,
			$fallback,
			$usesFlaggedRevsStable,
			$mockOptions
		) extends FlaggedRevsReferenceCountProvider {
			private bool $usesStable;
			private ParserOptions $mockOptions;

			public function __construct(
				FlaggedRevsParserCacheFactory $factory,
				ReferenceCountProvider $fallback,
				bool $usesStable,
				ParserOptions $mockOptions
			) {
				parent::__construct( $factory, $fallback );
				$this->usesStable = $usesStable;
				$this->mockOptions = $mockOptions;
			}

			protected function pageUsesFlaggedRevsStable( ExistingPageRecord $page ): bool {
				return $this->usesStable;
			}

			protected function makeParserOptions( ExistingPageRecord $page ): ParserOptions {
				return $this->mockOptions;
			}
		};
	}

	private function newFactoryReturning( mixed $parserOutput ): FlaggedRevsParserCacheFactory {
		$parserCache = $this->createMock( ParserCache::class );
		$parserCache->method( 'get' )->willReturn( $parserOutput );
		$factory = $this->createMock( FlaggedRevsParserCacheFactory::class );
		$factory->method( 'getParserCache' )->willReturn( $parserCache );
		return $factory;
	}

	public function testCacheHitCountsEntityEncodedIds() {
		// The legacy parser encodes '_' as '&#95;' in id attributes.
		// FlaggedRevs uses the legacy parser cache, so the HTML uses entity-encoded IDs.
		$po = new ParserOutput();
		$po->setContentHolderText( 'id="cite&#95;note-foo id="cite&#95;note-bar' );

		$provider = $this->newProvider( true, $this->newFactoryReturning( $po ) );
		$refCount = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );

		$this->assertSame( 2, $refCount->getReferenceCount() );
		$this->assertSame( FlaggedRevsReferenceCountProvider::PROVIDER_SHORT_NAME, $refCount->getSource() );
	}

	public function testCacheMissReturnsNull() {
		// FlaggedRevs parser cache returns false (cache miss); no automatic parse is triggered.
		$provider = $this->newProvider(
			true,
			$this->newFactoryReturning( false ),
			null
		);
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );

		$this->assertNull( $result->getReferenceCount() );
		$this->assertSame( ReferenceCountResult::CACHE_MISS, $result->getOperationResult() );
	}

	public function testDelegatesToFallbackWhenNotStable() {
		$factory = $this->createMock( FlaggedRevsParserCacheFactory::class );
		$factory->expects( $this->never() )->method( 'getParserCache' );
		$fallback = $this->createMock( ReferenceCountProvider::class );
		$fallback->expects( $this->once() )->method( 'getReferenceCount' )->willReturn(
			new ReferenceCountResult( 5, 'fallback', ReferenceCountResult::CACHE_MISS )
		);

		$provider = $this->newProvider( false, $factory, $fallback );
		$result = $provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
		$this->assertSame( 5, $result->getReferenceCount() );
		$this->assertSame( 'fallback', $result->getSource() );
	}

	public function testDisablesParsoidForFlaggedRevsCache() {
		// Parsoid must NOT be enabled when querying the FlaggedRevs cache (T421011).
		$po = new ParserOutput();
		$po->setContentHolderText( '' );
		$parserOptions = $this->createMock( ParserOptions::class );
		$parserOptions->expects( $this->once() )->method( 'setUseParsoid' )->with( false );
		$parserOptions->expects( $this->once() )->method( 'setRenderReason' )->with( 'attribution' );

		$provider = $this->newProvider(
			true,
			$this->newFactoryReturning( $po ),
			null,
			$parserOptions
		);
		$provider->getReferenceCount( $this->createMock( ExistingPageRecord::class ) );
	}
}
