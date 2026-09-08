<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\PageTrending;

use MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeStore;
use MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeUpdateJob;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeUpdateJob
 */
class PageviewTrendingRelativeUpdateJobTest extends MediaWikiIntegrationTestCase {

	private const array TRENDING_MAP = [ 111111 => [], 222222 => [], 333333 => [] ];

	protected function setUp(): void {
		parent::setUp();

		// $wgMainCacheType is CACHE_NONE in tests
		$this->setMainCache( new HashBagOStuff() );
		// The job logs an error for every event it rejects; those are expected here.
		$this->setNullLogger( 'PageTrending' );
		$this->getServiceContainer()->getWANObjectCache()->useInterimHoldOffCaching( false );
	}

	private function getStore(): PageviewTrendingRelativeStore {
		return $this->getServiceContainer()->get( 'WikimediaCustomizations.PageviewTrendingRelativeStore' );
	}

	/**
	 * @return array The stored map, empty when nothing is stored.
	 */
	private function getStoredSet(): array {
		return $this->getStore()->getTrending();
	}

	/**
	 * Build the job the way EventBus's JobExecutor does, so that the extension.json
	 * registration is covered too.
	 */
	private function newJobFromParams( array $params ): PageviewTrendingRelativeUpdateJob {
		$job = $this->getServiceContainer()->getJobFactory()
			->newJob( PageviewTrendingRelativeUpdateJob::TYPE, $params );

		$this->assertInstanceOf( PageviewTrendingRelativeUpdateJob::class, $job );

		return $job;
	}

	private function newParams( array $overrides = [] ): array {
		return $overrides + [
			'payload' => self::TRENDING_MAP,
			'ttl' => 3600,
		];
	}

	public function testJobIsRegisteredAndNeedsNoTitle(): void {
		$job = $this->newJobFromParams( $this->newParams() );

		$this->assertSame( PageviewTrendingRelativeUpdateJob::TYPE, $job->getType() );
	}

	public function testSnapshotIsStoredAsAMapKeyedByPageId(): void {
		$job = $this->newJobFromParams( $this->newParams() );

		$this->assertTrue( $job->run() );
		$this->assertSame( self::TRENDING_MAP, $this->getStoredSet() );
	}

	public function testLaterSnapshotReplacesTheEarlierOne(): void {
		$this->newJobFromParams( $this->newParams() )->run();

		$job = $this->newJobFromParams( $this->newParams( [ 'payload' => [ 12345 => [] ] ] ) );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 12345 => [] ], $this->getStoredSet() );
	}

	public function testEmptySnapshotClearsTheSet(): void {
		$this->newJobFromParams( $this->newParams() )->run();

		$job = $this->newJobFromParams( $this->newParams( [ 'payload' => [] ] ) );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $this->getStoredSet() );
	}

	public function testEmptySnapshotSucceedsWhenNothingIsStored(): void {
		$job = $this->newJobFromParams( $this->newParams( [ 'payload' => [] ] ) );

		$this->assertTrue( $job->run() );
	}

	/** A clear stores nothing, so it does not need a ttl to be valid. */
	public function testEmptySnapshotNeedsNoTtl(): void {
		$this->newJobFromParams( $this->newParams() )->run();

		$job = $this->newJobFromParams( [ 'payload' => [] ] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $this->getStoredSet() );
	}

	/**
	 * Test a real event like the one received in Production.
	 */
	public function testEventFromTheProducerIsStored(): void {
		$event = json_decode( <<<'JSON'
			{
			  "$schema": "/mediawiki/job/1.0.0",
			  "meta": {
			    "stream": "mediawiki.job.pageviewTrendingRelativeUpdate",
			    "dt": "2026-09-02T14:10:00Z",
			    "request_id": "page-trending-dewiki-1788358200000"
			  },
			  "database": "dewiki",
			  "type": "pageviewTrendingRelativeUpdate",
			  "params": {
			    "requestId": "page-trending-dewiki-1788358200000",
			    "payload": { "477790": {}, "701190": {}, "6217474": {} },
			    "ttl": 86400
			  }
			}
			JSON, true );

		$job = $this->newJobFromParams( $event['params'] );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 477790 => [], 701190 => [], 6217474 => [] ], $this->getStoredSet() );
		$this->assertTrue( $this->getStore()->isTrending( 477790 ) );
		$this->assertFalse( $this->getStore()->isTrending( 999999 ) );
	}

	public static function provideInvalidParams(): iterable {
		yield 'missing payload' => [ [ 'ttl' => 3600 ] ];
		yield 'payload not an array' => [ [ 'payload' => 111111, 'ttl' => 3600 ] ];
		yield 'zero page id' => [ [ 'payload' => [ 0 => [] ], 'ttl' => 3600 ] ];
		yield 'negative page id' => [ [ 'payload' => [ -1 => [] ], 'ttl' => 3600 ] ];
		yield 'page id as non-numeric string' => [ [ 'payload' => [ 'enwiki' => [] ], 'ttl' => 3600 ] ];
		yield 'detail is not an object' => [ [ 'payload' => [ 111111 => true ], 'ttl' => 3600 ] ];
		yield 'detail is a scalar' => [ [ 'payload' => [ 111111 => 'trending' ], 'ttl' => 3600 ] ];
		yield 'missing ttl' => [ [ 'payload' => self::TRENDING_MAP ] ];
		yield 'zero ttl' => [ [ 'payload' => self::TRENDING_MAP, 'ttl' => 0 ] ];
		yield 'ttl as string' => [ [ 'payload' => self::TRENDING_MAP, 'ttl' => '3600' ] ];
	}

	/**
	 * Params that can never be made valid must report success, so that change-prop stops
	 * redelivering the event, and must not write anything.
	 *
	 * @dataProvider provideInvalidParams
	 */
	public function testInvalidParamsAreNotRetriedAndStoreNothing( array $params ): void {
		$job = $this->newJobFromParams( $params );

		$this->assertTrue( $job->run() );
		$this->assertSame( [], $this->getStoredSet() );
	}

	public static function providePartlyInvalidPayloads(): iterable {
		yield 'bad page id' => [ [ 111111 => [], 'x' => [] ] ];
		yield 'zero page id' => [ [ 111111 => [], 0 => [] ] ];
		yield 'negative page id' => [ [ 111111 => [], -1 => [] ] ];
		yield 'detail is not an object' => [ [ 111111 => [], 222222 => true ] ];
	}

	/**
	 * @dataProvider providePartlyInvalidPayloads
	 */
	public function testInvalidEntriesAreDroppedAndTheRestIsStored( array $payload ): void {
		$job = $this->newJobFromParams( $this->newParams( [ 'payload' => $payload ] ) );

		$this->assertTrue( $job->run() );
		$this->assertSame( [ 111111 => [] ], $this->getStoredSet() );
	}

	public function testInvalidPayloadDoesNotRemoveStorage(): void {
		$this->newJobFromParams( $this->newParams() )->run();

		$job = $this->newJobFromParams( $this->newParams( [ 'payload' => [ 'x' => [], 'y' => [] ] ] ) );

		$this->assertTrue( $job->run() );
		$this->assertSame( self::TRENDING_MAP, $this->getStoredSet() );
	}

	public function testShorterTtlIsPassedThrough(): void {
		$store = $this->createMock( PageviewTrendingRelativeStore::class );
		$store->expects( $this->once() )
			->method( 'setTrending' )
			->with( self::TRENDING_MAP, 3600 )
			->willReturn( true );

		$job = new PageviewTrendingRelativeUpdateJob( $this->newParams(), $store );

		$this->assertTrue( $job->run() );
	}

	public function testFailedWriteIsReportedAsRetryable(): void {
		$store = $this->createMock( PageviewTrendingRelativeStore::class );
		$store->method( 'setTrending' )->willReturn( false );

		$job = new PageviewTrendingRelativeUpdateJob( $this->newParams(), $store );

		$this->assertFalse( $job->run() );
		$this->assertNotNull( $job->getLastError() );
	}

	public function testFailedClearIsReportedAsRetryable(): void {
		$store = $this->createMock( PageviewTrendingRelativeStore::class );
		$store->method( 'clearTrending' )->willReturn( false );

		$job = new PageviewTrendingRelativeUpdateJob( $this->newParams( [ 'payload' => [] ] ), $store );

		$this->assertFalse( $job->run() );
		$this->assertNotNull( $job->getLastError() );
	}
}
