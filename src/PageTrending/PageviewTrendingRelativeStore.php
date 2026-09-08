<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PageTrending;

use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Stores the set of pages currently trending relative to their own baseline traffic.
 *
 * "Relative" trending means a page is trending relative to its own baseline traffic, as
 * opposed to global trending, which ranks a page against other pages and overall site
 * traffic.
 *
 * The stored value is a map of page ID to a per-page detail object.
 *
 *
 * An absent key means "nothing is trending".
 */
class PageviewTrendingRelativeStore {

	private const string KEY_GROUP = 'pageview-trending-relative';

	private const int CACHE_TTL = 5 * BagOStuff::TTL_MINUTE;
	private const int MISS_CACHE_TTL = BagOStuff::TTL_MINUTE;

	private readonly string $stashKey;
	private readonly string $cacheKey;

	public function __construct(
		private readonly BagOStuff $stash,
		private readonly WANObjectCache $cache
	) {
		$this->stashKey = $stash->makeKey( self::KEY_GROUP );
		$this->cacheKey = $cache->makeKey( self::KEY_GROUP );
	}

	/**
	 * The pages currently trending on this wiki.
	 *
	 * @return array Map of page ID, empty when nothing is trending.
	 */
	public function getTrending(): array {
		$map = $this->cache->getWithSetCallback(
			$this->cacheKey,
			self::CACHE_TTL,
			function ( $oldValue, &$ttl ) {
				$map = $this->stash->get( $this->stashKey );

				// If missing, return empty array and reduce cache TTL.
				if ( !is_array( $map ) ) {
					$ttl = self::MISS_CACHE_TTL;
					return [];
				}

				return $map;
			}
		);

		return is_array( $map ) ? $map : [];
	}

	/**
	 * Whether one page is currently trending on this wiki.
	 */
	public function isTrending( int $pageId ): bool {
		return array_key_exists( $pageId, $this->getTrending() );
	}

	/**
	 * Replace this wiki's relative trending set.
	 *
	 * @param array $trendingMap Map of page ID.
	 * @param int $ttl Expiry, in seconds.
	 * @return bool Success.
	 */
	public function setTrending( array $trendingMap, int $ttl ): bool {
		return $this->stash->set( $this->stashKey, $trendingMap, $ttl );
	}

	/**
	 * Drop this wiki's relative trending set, before it would otherwise expire.
	 *
	 * @return bool Success, including when there was nothing stored.
	 */
	public function clearTrending(): bool {
		$deleted = $this->stash->delete( $this->stashKey );
		$this->cache->delete( $this->cacheKey );

		return $deleted;
	}
}
