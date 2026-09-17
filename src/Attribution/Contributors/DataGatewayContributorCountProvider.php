<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\ExistingPageRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Stats\StatsFactory;

/**
 * Fetches per-page contributor counts from the WMF Data Gateway and caches them
 * via {@see WANObjectCache}.
 *
 * The upstream endpoint returns the daily-refreshed editor breakdown produced by
 * the analytics pipeline (see T426316). Responses are shaped for the Attribution
 * API using the mapping in the T429834 spec
 */
class DataGatewayContributorCountProvider implements ContributorCountProvider {

	public function __construct(
		private readonly WANObjectCache $cache,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly StatsFactory $stats,
		private readonly LoggerInterface $logger,
		private readonly string $wikiId,
		private readonly string $endpointTemplate
	) {
	}

	public function getContributorCounts( ExistingPageRecord $page ): ?array {
		$timer = $this->stats->getTiming( 'contributor_counts_fetch_seconds' )->start();
		$pageId = $page->getId();
		$errored = false;
		$callbackRan = false;

		$counts = $this->cache->buildGetWithSetCallback()
			->key( 'wmcs-attribution-contributor-counts', $this->wikiId, $pageId )
			->keepForADay()
			->callback( function ( $oldValue, &$ttl ) use ( $pageId, &$errored, &$callbackRan ) {
				$callbackRan = true;
				try {
					return $this->fetchCounts( $pageId );
				} catch ( RuntimeException ) {
					// Upstream error: don't poison the cache for 24h, lets wait for a minute
					$errored = true;
					$ttl = ExpirationAwareness::TTL_MINUTE;
					return null;
				}
			} )
			->fetch();
		$operationResult = $callbackRan
			? 'miss'
			: 'hit';

		$timer->setLabel( 'operation_result', $errored ? 'error' : $operationResult )
			->stop();

		return $errored
			? null
			: $counts;
	}

	/**
	 * @return array{
	 *     total_unique: int,
	 *     logged_in_users: int,
	 *     unregistered_users: int,
	 *     known_bots: int
	 * }|null Counts array on success, null when the page has no pipeline
	 * entry (a legitimate "no data" result worth caching)
	 */
	private function fetchCounts( int $pageId ): array|null {
		$url = $this->buildUrl( $pageId );
		$request = $this->httpRequestFactory->create( $url, [ 'timeout' => 5 ], __METHOD__ );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			if ( $request->getStatus() === 404 ) {
				// we have 404, might no data for this wiki, lets not fail but just log
				$this->logger->warning(
					'DataGateway returned not found when trying to fetch data from ' . $url
				);
				return null;
			}

			$this->logger->error( 'Failed to fetch contributor counts', [
				'url' => $url,
				'httpStatus' => $request->getStatus(),
				'error' => (string)$status,
			] );
			throw new RuntimeException( 'Failed to fetch contributor counts' );
		}

		$parsed = FormatJson::parse( $request->getContent() ?? '', FormatJson::FORCE_ASSOC );

		if ( !$parsed->isGood() || !is_array( $parsed->getValue() ) ) {
			$this->logger->error( 'Malformed contributor counts response from DataGateway', [
				'url' => $url,
			] );
			throw new RuntimeException(
				'Malformed contributor counts response from DataGateway - not a JSON array'
			);
		}
		/**
		 * For Linked artifacts response documentation
		 * @see https://wikitech.wikimedia.org/wiki/Data_Platform/Data_Lake/Edits/Editor_counts_per_page
		 * #Example
		 * {
		 *   "rows": [{
		 *     "wiki_id": "enwiki",
		 *     "page_id": 4563,
		 *     "editor_bot_count": 77,
		 *     "editor_logged_out_count": 633,
		 *     "editor_permanent_count": 759,
		 *     "editor_temporary_count": 19,
		 *     "editor_total_count": 1488,
		 *     "loaded_at": "2026-09-27 00:00:00.000Z",
		 *     "page_is_deleted": false
		 *   }]
		 * }
		 */
		$result = $parsed->getValue();
		if ( !is_array( $result['rows'] ?? null ) ) {
			$this->logger->error( 'Malformed contributor counts response from DataGateway - missing rows', [
				'url' => $url,
			] );
			throw new RuntimeException( 'Malformed contributor counts response from DataGateway' );
		}
		if ( count( $result['rows'] ) === 0 ) {
			// data not present
			return null;
		}

		return $this->mapCounts( $result['rows'][0] );
	}

	/**
	 * @return array{
	 *     total_unique: int,
	 *     logged_in_users: int,
	 *     unregistered_users: int,
	 *     known_bots: int
	 * }
	 */
	private function mapCounts( array $raw ): array {
		$bot       = (int)( $raw['editor_bot_count'] ?? 0 );
		$loggedOut = (int)( $raw['editor_logged_out_count'] ?? 0 );
		$permanent = (int)( $raw['editor_permanent_count'] ?? 0 );
		$temporary = (int)( $raw['editor_temporary_count'] ?? 0 );
		$total     = (int)( $raw['editor_total_count'] ?? 0 );

		// Temporary accounts are anonymous-editor sessions, so they count as
		// unregistered contributors — not logged-in ones. See T429834.
		return [
			'total_unique'       => $total,
			'logged_in_users'    => $permanent,
			'unregistered_users' => $temporary + $loggedOut,
			'known_bots'         => $bot,
		];
	}

	private function buildUrl( int $pageId ): string {
		return strtr( $this->endpointTemplate, [
			'{wiki_id}' => rawurlencode( $this->wikiId ),
			'{page_id}' => (string)$pageId,
		] );
	}
}
