<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Permissions\Authority;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use Wikimedia\Telemetry\NoopTracer;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder
 */
class AttributionDataBuilderTest extends MediaWikiUnitTestCase {

	private function mockConfig(): Config {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap(
			[
				[ MainConfigNames::DBname, 'enwiki' ],
				[ MainConfigNames::LanguageCode, 'en' ],
				[ MainConfigNames::Logos, false ]
			]
		);
		return $config;
	}

	private function newDataBuilder(
		?PageViewService $pageViewService = null,
		?ParserOutputAccess $parserOutputAccess = null
	): AttributionDataBuilder {
		$config = $this->mockConfig();
		$urlUtils = $this->createMock( UrlUtils::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$parserOptions = $this->createMock( ParserOptions::class );
		if ( !$parserOutputAccess ) {
			$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		}

		$noopTracer = new NoopTracer();
		return new AttributionDataBuilder(
			$config, $urlUtils, $repoGroup, $parserOutputAccess, $parserOptions,
			$noopTracer, $pageViewService );
	}

	private function mockTitle(): Title {
		$title = $this->createMock( Title::class );
		$language = $this->getMockBuilder( \Language::class )->disableOriginalConstructor()->getMock();
		$language->method( 'getCode' )->willReturn( 'en' );
		$language->method( 'getHtmlCode' )->willReturn( 'en' );
		$title->method( 'getPageLanguage' )->willReturn( $language );
		$title->method( 'getCanonicalURL' )->willReturn( 'https://example.org/wiki/Foo' );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'Foo' );
		return $title;
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 */
	public function testGetAttributionDataReturnsDefaultEssentials() {
		$builder = $this->newDataBuilder();
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [], $authority );
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'title', $result['essential'] );
		$this->assertArrayHasKey( 'license', $result['essential'] );
		$this->assertArrayHasKey( 'link', $result['essential'] );
		$this->assertArrayHasKey( 'default_brand_marks', $result['essential'] );
		$this->assertArrayHasKey( 'source_wiki', $result['essential'] );
		$this->assertArrayNotHasKey( 'trust_and_relevance', $result );
		$this->assertArrayNotHasKey( 'calls_to_action', $result );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getTrustAndRelevance()
	 */
	public function testTrustAndRelevanceDefaultPageviews() {
		$builder = $this->newDataBuilder();
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [ 'trust_and_relevance' ], $authority );
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayNotHasKey( 'calls_to_action', $result );
		$this->assertSame( -1, $result['trust_and_relevance']['page_views'] );
		$this->assertSame( '20250101000000', $result['trust_and_relevance']['last_modified'] );
	}

	public function testTrustAndRelevanceIsExpanded() {
		if ( !interface_exists( PageViewService::class ) ) {
			$this->markTestSkipped( 'PageViewService not installed' );
		}
		$pageViewService = $this->createMock( PageViewService::class );
		$pageViewService->method( 'supports' )->willReturn( true );
		$status = Status::newGood( [ 'Foo' => [ 1, 2, 3 ] ] );
		$pageViewService->method( 'getPageData' )->willReturn( $status );
		$builder = $this->newDataBuilder( $pageViewService );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayNotHasKey( 'calls_to_action', $result );
		$this->assertSame( 6, $result['trust_and_relevance']['page_views'] );
		$this->assertSame( '20250101000000', $result['trust_and_relevance']['last_modified'] );
	}

	public function testTrustAndRelevanceReferenceCountOfZero() {
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$po = new ParserOutput();
		$po->setRawText( 'Foo bar' );
		$status = Status::newGood( $po );
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( $status );

		$builder = $this->newDataBuilder( null, $parserOutputAccess );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [ 'trust_and_relevance' ], $authority );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertSame( 0, $result['trust_and_relevance']['reference_count'] );
	}

	public function testTrustAndRelevanceReferenceCountOfMultiple() {
		$parserOutputAccess = $this->createMock( ParserOutputAccess::class );
		$po = new ParserOutput();
		$po->setRawText( 'id="cite_note-2 id="cite_note-7 id="cite_note-3' );
		$status = Status::newGood( $po );
		$parserOutputAccess->method( 'getParserOutput' )->willReturn( $status );

		$builder = $this->newDataBuilder( null, $parserOutputAccess );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [ 'trust_and_relevance' ], $authority );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertSame( 3, $result['trust_and_relevance']['reference_count'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getCallsToAction()
	 */
	public function testCallsToActionIsExpanded() {
		$builder = $this->newDataBuilder();
		$talkTitle = $this->createMock( Title::class );
		$talkPageUrl = 'https://example.org/wiki/Talk:Foo';
		$talkTitle->method( 'getCanonicalURL' )->willReturn( $talkPageUrl );
		$title = $this->mockTitle();
		$title->method( 'getTalkPageIfDefined' )->willReturn( $talkTitle );
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [ 'calls_to_action' ], $authority );
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'calls_to_action', $result );
		$this->assertArrayNotHasKey( 'trust_and_relevance', $result );
		$this->assertSame( $talkPageUrl, $result['calls_to_action'][ 'participation_cta' ][ 'talk_page' ] );
	}
}
