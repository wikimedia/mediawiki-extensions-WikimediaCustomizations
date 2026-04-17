<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ReferenceCountProvider;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Message\Message;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Permissions\Authority;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Stats\UnitTestingHelper;
use Wikimedia\Telemetry\NoopTracer;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder
 */
class AttributionDataBuilderTest extends MediaWikiIntegrationTestCase {

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
		?ReferenceCountProvider $referenceCountProvider = null,
		?RepoGroup $repoGroup = null,
		?StatsFactory $statsFactory = null
	): AttributionDataBuilder {
		$config = $this->mockConfig();
		$urlUtils = $this->createMock( UrlUtils::class );
		if ( !$referenceCountProvider ) {
			$referenceCountProvider = $this->createMock( ReferenceCountProvider::class );
		}
		if ( !$repoGroup ) {
			$repoGroup = $this->createMock( RepoGroup::class );
		}
		$noopTracer = new NoopTracer();
		$siteConfig = $this->createMock( SiteConfiguration::class );
		$siteConfig->method( 'siteFromDB' )
			->willReturn( [ 'wiki', 'unittest' ] );

		return new AttributionDataBuilder(
			$config, $urlUtils, $repoGroup, $noopTracer, $siteConfig,
			new NullLogger(), $statsFactory ?? StatsFactory::newNull(), $referenceCountProvider, $pageViewService
		);
	}

	private function newStatsHelper(): UnitTestingHelper {
		$helper = StatsFactory::newUnitTestingHelper();
		$helper->withComponent( 'Attribution' );
		return $helper;
	}

	private function mockTitle(): Title {
		$title = $this->createMock( Title::class );
		$language = $this->getMockBuilder( Language::class )->disableOriginalConstructor()->getMock();
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
		$message = $this->createMock( Message::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$result = $builder->getAttributionData( $title, $page, $metadata, [], $authority, $format, $message );
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'title', $result['essential'] );
		$this->assertArrayHasKey( 'license', $result['essential'] );
		$this->assertSame( 'CC-BY-SA', $result['essential']['license'] );
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
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayNotHasKey( 'calls_to_action', $result );
		$this->assertSame( null, $result['trust_and_relevance']['page_views'] );
		$this->assertSame( '20250101000000', $result['trust_and_relevance']['last_updated'] );
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
		$message = $this->createMock( Message::class );
		$format = $this->createMock( FormatMetadata::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayNotHasKey( 'calls_to_action', $result );
		$this->assertSame( 6, $result['trust_and_relevance']['page_views'] );
		$this->assertSame( '20250101000000', $result['trust_and_relevance']['last_updated'] );
	}

	public function testTrustAndRelevanceReferenceCountOfZero() {
		$referenceCountProvider = $this->createMock( ReferenceCountProvider::class );
		$referenceCountProvider->method( 'getReferenceCount' )->willReturn( 0 );

		$builder = $this->newDataBuilder( null, $referenceCountProvider );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertSame( 0, $result['trust_and_relevance']['reference_count'] );
		$this->assertNull( $result['trust_and_relevance']['contributor_counts'] );
	}

	public function testTrustAndRelevanceReferenceCountOfMultiple() {
		$referenceCountProvider = $this->createMock( ReferenceCountProvider::class );
		$referenceCountProvider->method( 'getReferenceCount' )->willReturn( 3 );

		$builder = $this->newDataBuilder( null, $referenceCountProvider );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertSame( 3, $result['trust_and_relevance']['reference_count'] );
		$this->assertNull( $result['trust_and_relevance']['contributor_counts'] );
	}

	public function testTrustAndRelevanceTrendingPlaceholders() {
		$builder = $this->newDataBuilder();
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'trending', $result['trust_and_relevance'] );
		$this->assertArrayHasKey( 'top', $result['trust_and_relevance']['trending'] );
		$this->assertArrayHasKey( 'relative', $result['trust_and_relevance']['trending'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['top']['read'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['top']['edited'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['top']['read_and_edited'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['relative']['read'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['relative']['edited'] );
		$this->assertFalse( $result['trust_and_relevance']['trending']['relative']['read_and_edited'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getCallsToAction()
	 */
	public function testCallsToActionIsExpanded() {
		$builder = $this->newDataBuilder();
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'calls_to_action' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'calls_to_action', $result );
		$this->assertArrayNotHasKey( 'trust_and_relevance', $result );
		$this->assertArrayHasKey( 'donation_ctas', $result['calls_to_action'] );
		$this->assertArrayHasKey( 'default', $result['calls_to_action']['donation_ctas'] );
		$this->assertArrayHasKey( 'url', $result['calls_to_action']['donation_ctas']['default'] );
		$this->assertArrayHasKey( 'link_text', $result['calls_to_action']['donation_ctas']['default'] );
		$this->assertArrayHasKey( 'description', $result['calls_to_action']['donation_ctas']['default'] );
		$this->assertArrayHasKey( 'participation_ctas', $result['calls_to_action'] );
		$this->assertArrayHasKey( 'download_app', $result['calls_to_action']['participation_ctas'] );
		$this->assertArrayHasKey( 'create_account', $result['calls_to_action']['participation_ctas'] );
		$this->assertArrayHasKey( 'learn_more', $result['calls_to_action']['participation_ctas'] );
		$this->assertArrayNotHasKey( 'talk_page', $result['calls_to_action']['participation_ctas'] );
	}

	public function testInjectedMetadata() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$talkTitle = $this->createMock( Title::class );
		$talkPageUrl = 'https://example.org/wiki/Talk:Foo';
		$talkTitle->method( 'getCanonicalURL' )->willReturn( $talkPageUrl );
		$title = $this->mockTitle();
		$title->method( 'getTalkPageIfDefined' )->willReturn( $talkTitle );
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'artist'
				],
				'LicenseShortName' => [
					'value' => 'shortname'
				],
				'LicenseUrl' => [
					'value' => 'url'
				]
			]
		);
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'credit', $result['essential'] );
		$this->assertArrayHasKey( 'license', $result['essential'] );
		$this->assertArrayHasKey( 'title', $result['essential']['license'] );
		$this->assertArrayHasKey( 'url', $result['essential']['license'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 * @see T420780
	 */
	public function testArtistDisplayNoneSpanIsStripped() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		// Simulates {{Unknown|author}}: visible text + hidden machine-readable span
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'Unknown author<span style="display: none;">Unknown author</span>'
				],
				'LicenseShortName' => [ 'value' => 'CC0' ],
				'LicenseUrl' => [ 'value' => 'https://creativecommons.org/publicdomain/zero/1.0/' ],
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [], $authority, $format, $message
		);
		$this->assertSame( 'Unknown author', $result['essential']['credit'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 * @see T420780
	 */
	public function testArtistDisplayNoneDivIsStripped() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		// Simulates Module:TagQS / {{Artwork}}: visible text + hidden machine-readable div
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'Unknown author<div style="display: none;">Unknown author</div>'
				],
				'LicenseShortName' => [ 'value' => 'CC0' ],
				'LicenseUrl' => [ 'value' => 'https://creativecommons.org/publicdomain/zero/1.0/' ],
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [], $authority, $format, $message
		);
		$this->assertSame( 'Unknown author', $result['essential']['credit'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 * @see T420780
	 */
	public function testArtistDisplayNoneParagraphIsStripped() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'Unknown author<p style="display: none;">Unknown author</p>'
				],
				'LicenseShortName' => [ 'value' => 'CC0' ],
				'LicenseUrl' => [ 'value' => 'https://creativecommons.org/publicdomain/zero/1.0/' ],
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [], $authority, $format, $message
		);
		$this->assertSame( 'Unknown author', $result['essential']['credit'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 * @see T420780
	 */
	public function testArtistWithoutDisplayNoneIsUnchanged() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => '<span>Jane Doe</span>'
				],
				'LicenseShortName' => [ 'value' => 'CC-BY-SA' ],
				'LicenseUrl' => [ 'value' => 'https://creativecommons.org/licenses/by-sa/4.0/' ],
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [], $authority, $format, $message
		);
		$this->assertSame( 'Jane Doe', $result['essential']['credit'] );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::getEssential()
	 */
	public function testGetAttributionDataReturnsNullForMissingValues() {
		$builder = $this->newDataBuilder();
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ],
			$authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'title', $result['essential'] );
		$this->assertArrayHasKey( 'license', $result['essential'] );
		// trending has a temporary placeholder so not nullable for now
		$this->assertNull( $result['trust_and_relevance']['contributor_counts'] );
		$this->assertNull( $result['trust_and_relevance']['page_views'] );
	}

	public function testMediaSpecificTrustAndRelevance() {
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup );
		$talkTitle = $this->createMock( Title::class );
		$talkPageUrl = 'https://example.org/wiki/Talk:Foo';
		$talkTitle->method( 'getCanonicalURL' )->willReturn( $talkPageUrl );
		$title = $this->mockTitle();
		$title->method( 'getTalkPageIfDefined' )->willReturn( $talkTitle );
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'artist'
				],
				'LicenseShortName' => [
					'value' => 'shortname'
				],
				'LicenseUrl' => [
					'value' => 'url'
				]
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayHasKey( 'last_updated', $result[ 'trust_and_relevance' ] );
		$this->assertArrayHasKey( 'credit', $result['essential'] );
		$this->assertArrayNotHasKey( 'reference_count', $result['trust_and_relevance'] );
		$this->assertArrayNotHasKey( 'contributor_counts', $result['trust_and_relevance'] );
		$this->assertArrayNotHasKey( 'page_views', $result['trust_and_relevance'] );
	}

	public function testArticleSpecificTrustAndRelevance() {
		$builder = $this->newDataBuilder();
		$talkTitle = $this->createMock( Title::class );
		$talkPageUrl = 'https://example.org/wiki/Talk:Foo';
		$talkTitle->method( 'getCanonicalURL' )->willReturn( $talkPageUrl );
		$title = $this->mockTitle();
		$title->method( 'getTalkPageIfDefined' )->willReturn( $talkTitle );
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$format->method( 'fetchExtendedMetadata' )->willReturn(
			[
				'Artist' => [
					'value' => 'artist'
				],
				'LicenseShortName' => [
					'value' => 'shortname'
				],
				'LicenseUrl' => [
					'value' => 'url'
				]
			]
		);
		$message = $this->createMock( Message::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'trust_and_relevance', $result );
		$this->assertArrayHasKey( 'last_updated', $result[ 'trust_and_relevance' ] );
		$this->assertArrayHasKey( 'reference_count', $result['trust_and_relevance'] );
		$this->assertArrayHasKey( 'contributor_counts', $result['trust_and_relevance'] );
		$this->assertArrayHasKey( 'page_views', $result['trust_and_relevance'] );
		$this->assertArrayNotHasKey( 'credit', $result['essential'] );
	}

	public function testRequestTotalCounterIsEmittedOnEveryCall(): void {
		$statsHelper = $this->newStatsHelper();
		$builder = $this->newDataBuilder( null, null, null, $statsHelper->getStatsFactory() );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$builder->getAttributionData( $title, $page, $metadata, [], $authority, $format );

		$this->assertSame( 1, $statsHelper->count( 'attribution_request_total' ) );
	}

	public function testMissingArticleFieldsEmitMissingDataCounters(): void {
		$statsHelper = $this->newStatsHelper();
		// No PageViewService → page_views=null; default mock ReferenceCountProvider → reference_count=null
		$builder = $this->newDataBuilder( null, null, null, $statsHelper->getStatsFactory() );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA', 'latest' => [ 'timestamp' => '20250101000000' ] ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		$builder->getAttributionData( $title, $page, $metadata, [ 'trust_and_relevance' ], $authority, $format );

		$this->assertSame(
			1,
			$statsHelper->count( 'attribution_missing_data_total{field="attribution_page_views"}' )
		);
		$this->assertSame(
			1,
			$statsHelper->count( 'attribution_missing_data_total{field="attribution_reference_count"}' )
		);
	}

	public function testMissingFileCreditEmitsMissingDataCounter(): void {
		$statsHelper = $this->newStatsHelper();
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$builder = $this->newDataBuilder( null, null, $repoGroup, $statsHelper->getStatsFactory() );
		$title = $this->mockTitle();
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$format = $this->createMock( FormatMetadata::class );
		// No Artist/LicenseShortName/LicenseUrl in extmeta → credit and license fields will be null
		$format->method( 'fetchExtendedMetadata' )->willReturn( [] );
		$builder->getAttributionData( $title, $page, $metadata, [], $authority, $format );

		$this->assertSame(
			1,
			$statsHelper->count( 'attribution_missing_data_total{field="attribution_credit"}' )
		);
		$this->assertSame(
			1,
			$statsHelper->count( 'attribution_missing_data_total{field="attribution_license_title"}' )
		);
		$this->assertSame(
			1,
			$statsHelper->count( 'attribution_missing_data_total{field="attribution_license_url"}' )
		);
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder::buildSourceWiki()
	 */
	public function testBuildSourceWiki() {
		$builder = $this->newDataBuilder();
		$talkTitle = $this->createMock( Title::class );
		$talkPageUrl = 'https://example.org/wiki/Talk:Foo';
		$talkTitle->method( 'getCanonicalURL' )->willReturn( $talkPageUrl );
		$title = $this->mockTitle();
		$title->method( 'getTalkPageIfDefined' )->willReturn( $talkTitle );
		$metadata = [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ];
		$page = $this->createMock( ExistingPageRecord::class );
		$authority = $this->createMock( Authority::class );
		$message = $this->createMock( Message::class );
		$format = $this->createMock( FormatMetadata::class );
		$result = $builder->getAttributionData(
			$title, $page, $metadata, [ 'calls_to_action' ], $authority, $format, $message
		);
		$this->assertArrayHasKey( 'essential', $result );
		$this->assertArrayHasKey( 'source_wiki', $result );
		$this->assertArrayHasKey( 'site_name', $result['source_wiki'] );
		$this->assertArrayHasKey( 'project_family', $result['source_wiki'] );
	}
}
