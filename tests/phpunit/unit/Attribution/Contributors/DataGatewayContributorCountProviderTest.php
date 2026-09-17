<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution\Contributors;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\DataGatewayContributorCountProvider;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Page\ExistingPageRecord;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Stats\UnitTestingHelper;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\DataGatewayContributorCountProvider
 */
class DataGatewayContributorCountProviderTest extends MediaWikiUnitTestCase {

	private const ENDPOINT = 'https://data-gateway.test/counts/editors/per-page/{wiki_id}/{page_id}';

	private function newPage( int $id = 42 ): ExistingPageRecord {
		$page = $this->createMock( ExistingPageRecord::class );
		$page->method( 'getId' )->willReturn( $id );
		return $page;
	}

	private function newWanCache(): WANObjectCache {
		return new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
	}

	private function newStats(): UnitTestingHelper {
		$helper = StatsFactory::newUnitTestingHelper();
		$helper->withComponent( 'Attribution' );
		return $helper;
	}

	private function mockHttpFactory( int $httpStatus, ?string $body ): HttpRequestFactory {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn(
			$httpStatus >= 200 && $httpStatus < 300
				? StatusValue::newGood()
				: StatusValue::newFatal( 'http-bad-status' )
		);
		$request->method( 'getStatus' )->willReturn( $httpStatus );
		$request->method( 'getContent' )->willReturn( $body );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $request );
		return $factory;
	}

	public function testSuccessfulFetchMapsCountsAndReportsMiss(): void {
		$body = json_encode( [ 'rows' => [ [
			'wiki_id' => 'enwiki',
			'page_id' => 42,
			'page_is_deleted' => false,
			'editor_bot_count' => 4,
			'editor_logged_out_count' => 21,
			'editor_permanent_count' => 93,
			'editor_temporary_count' => 9,
			'editor_total_count' => 127,
			'updated_at' => '2026-05-26 00:00:00.000Z'
		] ] ] );
		$stats = $this->newStats();
		$provider = new DataGatewayContributorCountProvider(
			$this->newWanCache(),
			$this->mockHttpFactory( 200, $body ),
			$stats->getStatsFactory(),
			new NullLogger(),
			'enwiki',
			self::ENDPOINT
		);
		$result = $provider->getContributorCounts( $this->newPage() );

		$this->assertSame( [
			'total_unique' => 127,
			'logged_in_users' => 93,
			'unregistered_users' => 30,
			'known_bots' => 4,
		], $result );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="miss"}' ) );
	}

	public function testSecondCallHitsCache(): void {
		$body = json_encode( [ "rows" => [ [
			'editor_bot_count' => 0,
			'editor_logged_out_count' => 0,
			'editor_permanent_count' => 5,
			'editor_temporary_count' => 0,
			'editor_total_count' => 5,
		] ] ] );
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		$request->method( 'getStatus' )->willReturn( 200 );
		$request->method( 'getContent' )->willReturn( $body );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->once() )->method( 'create' )->willReturn( $request );

		$stats = $this->newStats();
		$cache = $this->newWanCache();
		$provider = new DataGatewayContributorCountProvider(
			$cache, $factory, $stats->getStatsFactory(), new NullLogger(), 'enwiki', self::ENDPOINT
		);
		$first = $provider->getContributorCounts( $this->newPage() );
		$second = $provider->getContributorCounts( $this->newPage() );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="miss"}' ) );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="hit"}' ) );
	}

	public function testHttp404CachesNullAsLegitimateNoData(): void {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( StatusValue::newFatal( 'not-found' ) );
		$request->method( 'getStatus' )->willReturn( 404 );
		$request->method( 'getContent' )->willReturn( '' );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->once() )->method( 'create' )->willReturn( $request );

		$stats = $this->newStats();
		$cache = $this->newWanCache();
		$provider = new DataGatewayContributorCountProvider(
			$cache, $factory, $stats->getStatsFactory(), new NullLogger(), 'enwiki', self::ENDPOINT
		);
		$first = $provider->getContributorCounts( $this->newPage() );
		$second = $provider->getContributorCounts( $this->newPage() );

		$this->assertNull( $first );
		$this->assertNull( $second );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="miss"}' ) );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="hit"}' ) );
	}

	public function testHttp500ReturnsNullAndShortensCacheTtl(): void {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( StatusValue::newFatal( 'server-error' ) );
		$request->method( 'getStatus' )->willReturn( 500 );
		$request->method( 'getContent' )->willReturn( '' );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->once() )->method( 'create' )->willReturn( $request );

		$stats = $this->newStats();
		$cache = $this->newWanCache();
		$provider = new DataGatewayContributorCountProvider(
			$cache, $factory, $stats->getStatsFactory(), new NullLogger(), 'enwiki', self::ENDPOINT
		);
		$first = $provider->getContributorCounts( $this->newPage() );
		// Second call within the short error TTL: the callback doesn't re-run,
		// the cached null surfaces as a cache hit (not an error).
		$second = $provider->getContributorCounts( $this->newPage() );

		$this->assertNull( $first );
		$this->assertNull( $second );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="error"}' ) );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="hit"}' ) );
	}

	public function testMalformedJsonReturnsNull(): void {
		$stats = $this->newStats();
		$provider = new DataGatewayContributorCountProvider(
			$this->newWanCache(),
			$this->mockHttpFactory( 200, 'not-json' ),
			$stats->getStatsFactory(),
			new NullLogger(),
			'enwiki',
			self::ENDPOINT
		);
		$result = $provider->getContributorCounts( $this->newPage() );

		$this->assertNull( $result );
		$this->assertSame( 1, $stats->count( 'contributor_counts_fetch_seconds{operation_result="error"}' ) );
	}

	public function testEmptyRowsIsHandledAsDataMissing(): void {
		$stats = $this->newStats();
		$provider = new DataGatewayContributorCountProvider(
			$this->newWanCache(),
			$this->mockHttpFactory( 200, '{"rows": []}' ),
			$stats->getStatsFactory(),
			new NullLogger(),
			'enwiki',
			self::ENDPOINT
		);
		$result = $provider->getContributorCounts( $this->newPage() );

		$this->assertNull( $result );
	}

	public function testMissingFieldsTreatedAsZero(): void {
		$body = json_encode( [ 'rows' => [ [
			'editor_bot_count' => 3,
			// other fields omitted
		] ] ] );
		$provider = new DataGatewayContributorCountProvider(
			$this->newWanCache(),
			$this->mockHttpFactory( 200, $body ),
			StatsFactory::newNull(),
			new NullLogger(),
			'enwiki',
			self::ENDPOINT
		);
		$result = $provider->getContributorCounts( $this->newPage() );

		$this->assertSame( [
			'total_unique' => 0,
			'logged_in_users' => 0,
			'unregistered_users' => 0,
			'known_bots' => 3,
		], $result );
	}

	public function testUrlSubstitutesPlaceholders(): void {
		$capturedUrl = null;
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( StatusValue::newGood() );
		$request->method( 'getStatus' )->willReturn( 200 );
		$request->method( 'getContent' )->willReturn( '{}' );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static function ( $url ) use ( &$capturedUrl, $request ) {
				$capturedUrl = $url;
				return $request;
			}
		);

		$provider = new DataGatewayContributorCountProvider(
			$this->newWanCache(), $factory, StatsFactory::newNull(), new NullLogger(), 'enwiki', self::ENDPOINT
		);
		$provider->getContributorCounts( $this->newPage( 12345 ) );

		$this->assertSame(
			'https://data-gateway.test/counts/editors/per-page/enwiki/12345',
			$capturedUrl
		);
	}
}
