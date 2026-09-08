<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\PageTrending;

use MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeStore;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeStore
 */
class PageviewTrendingRelativeStoreTest extends MediaWikiUnitTestCase {

	private const array TRENDING_MAP = [ 111111 => [], 222222 => [] ];

	private HashBagOStuff $stash;
	private WANObjectCache $cache;
	private string $stashKey;
	private PageviewTrendingRelativeStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->stash = new HashBagOStuff();
		$this->cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->cache->useInterimHoldOffCaching( false );
		$this->stashKey = $this->stash->makeKey( 'pageview-trending-relative' );
		$this->store = new PageviewTrendingRelativeStore( $this->stash, $this->cache );
	}

	private function newStoreWithStash( BagOStuff $stash ): PageviewTrendingRelativeStore {
		return new PageviewTrendingRelativeStore(
			$stash,
			new WANObjectCache( [ 'cache' => new HashBagOStuff() ] )
		);
	}

	public function testSetTrendingRoundTrips(): void {
		$this->assertTrue( $this->store->setTrending( self::TRENDING_MAP, 3600 ) );

		$this->assertSame( self::TRENDING_MAP, $this->store->getTrending() );
	}

	public function testSetTrendingReplacesTheWholeSet(): void {
		$this->store->setTrending( self::TRENDING_MAP, 3600 );
		$this->store->setTrending( [ 12345 => [] ], 3600 );

		$this->assertSame( [ 12345 => [] ], $this->store->getTrending() );
	}

	public function testSetTrendingKeepsPerPageDetail(): void {
		$this->store->setTrending( [ 111111 => [ 'score' => 4.2 ] ], 3600 );

		$this->assertSame( [ 111111 => [ 'score' => 4.2 ] ], $this->store->getTrending() );
	}

	public function testClearTrendingRemovesTheSet(): void {
		$this->store->setTrending( self::TRENDING_MAP, 3600 );

		$this->assertTrue( $this->store->clearTrending() );

		$this->assertSame( [], $this->store->getTrending() );
		$this->assertFalse( $this->stash->get( $this->stashKey ) );
	}

	public function testClearTrendingSucceedsWhenNothingIsStored(): void {
		$this->assertTrue( $this->store->clearTrending() );
	}

	public function testGetTrendingIsEmptyWhenNothingIsStored(): void {
		$this->assertSame( [], $this->store->getTrending() );
	}

	public function testGetTrendingIgnoresANonArrayValue(): void {
		$this->stash->set( $this->stashKey, 'not a map', 3600 );

		$this->assertSame( [], $this->store->getTrending() );
	}

	public function testIsTrending(): void {
		$this->store->setTrending( self::TRENDING_MAP, 3600 );

		$this->assertTrue( $this->store->isTrending( 111111 ) );
		$this->assertTrue( $this->store->isTrending( 222222 ) );
		$this->assertFalse( $this->store->isTrending( 999999 ) );
	}

	public function testIsTrendingIsFalseWhenNothingIsStored(): void {
		$this->assertFalse( $this->store->isTrending( 111111 ) );
	}

	public function testGetTrendingCachesTheStashValue(): void {
		$this->stash->set( $this->stashKey, self::TRENDING_MAP, 3600 );
		$this->store->getTrending();

		$this->stash->set( $this->stashKey, [ 12345 => [] ], 3600 );

		$this->assertSame( self::TRENDING_MAP, $this->store->getTrending() );
	}

	public function testGetTrendingCachesTheEmptySet(): void {
		$this->assertSame( [], $this->store->getTrending() );

		$this->stash->set( $this->stashKey, self::TRENDING_MAP, 3600 );

		// An empty result (no trending pages) has to be cacheable, or every read would reach MainStash.
		$this->assertSame( [], $this->store->getTrending() );
	}

	public function testSetTrendingPassesTheKeyAndTtlThrough(): void {
		$stash = $this->createMock( BagOStuff::class );
		$stash->method( 'makeKey' )->willReturn( 'key' );
		$stash->expects( $this->once() )
			->method( 'set' )
			->with( 'key', self::TRENDING_MAP, 3600 )
			->willReturn( true );

		$this->assertTrue( $this->newStoreWithStash( $stash )->setTrending( self::TRENDING_MAP, 3600 ) );
	}

	public function testSetTrendingFailsWhenTheStoreFails(): void {
		$stash = $this->createMock( BagOStuff::class );
		$stash->method( 'makeKey' )->willReturn( 'key' );
		$stash->method( 'set' )->willReturn( false );

		$this->assertFalse( $this->newStoreWithStash( $stash )->setTrending( self::TRENDING_MAP, 3600 ) );
	}

	public function testClearTrendingFailsWhenTheStoreFails(): void {
		$stash = $this->createMock( BagOStuff::class );
		$stash->method( 'makeKey' )->willReturn( 'key' );
		$stash->method( 'delete' )->willReturn( false );

		$this->assertFalse( $this->newStoreWithStash( $stash )->clearTrending() );
	}
}
