<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PageTrending;

use MediaWiki\JobQueue\Job;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Records which pages are trending relative to their own baseline pageviews, on behalf of
 * a non-MediaWiki producer.
 *
 * Each event is a full snapshot of one wiki's trending set, sent every few minutes, rather
 * than a per-page change. A page stops trending by being absent from the next snapshot.
 * An empty "payload" clears the wiki's set.
 *
 * @code
 *   {
 *     "$schema": "/mediawiki/job/1.0.0",
 *     "meta": {
 *       "stream": "mediawiki.job.pageviewTrendingRelativeUpdate",
 *       "dt": "2026-09-02T14:10:00Z",
 *       "request_id": "page-trending-dewiki-1788358200000"
 *     },
 *     "database": "dewiki",
 *     "type": "pageviewTrendingRelativeUpdate",
 *     "params": {
 *       "requestId": "page-trending-dewiki-1788358200000",
 *       "payload": { "477790": {}, "701190": {}, "6217474": {} },
 *       "ttl": 86400
 *     }
 *   }
 * @endcode
 *
 * Note also that it is "params.requestId", not "meta.request_id", that becomes the job's request ID:
 * EventBus's JobExecutor builds the job from $event['params'] alone and ignores "meta".
 */
class PageviewTrendingRelativeUpdateJob extends Job {

	/** Job type, and therefore the suffix of the Kafka topic this job is submitted to. */
	public const string TYPE = 'pageviewTrendingRelativeUpdate';
	private const int MAX_TTL = BagOStuff::TTL_DAY;

	private LoggerInterface $logger;

	public function __construct(
		array $params,
		private readonly PageviewTrendingRelativeStore $store
	) {
		parent::__construct( self::TYPE, $params );

		$this->logger = LoggerFactory::getInstance( 'PageTrending' );
	}

	/** @inheritDoc */
	public function run() {
		$payload = $this->params['payload'] ?? null;

		if ( !is_array( $payload ) ) {
			return $this->permanentFailure( 'payload param is not an object', [] );
		}

		if ( !$payload ) {
			// An empty snapshot means nothing is trending on this wiki any more. It needs
			// no ttl, so it is handled before that param is looked at.
			if ( !$this->store->clearTrending() ) {
				$this->setLastError( 'Failed to clear the trending set' );

				return false;
			}

			return true;
		}

		$ttl = $this->params['ttl'] ?? null;

		if ( !is_int( $ttl ) || $ttl <= 0 ) {
			return $this->permanentFailure( 'invalid ttl param {ttl}', [ 'ttl' => $ttl ] );
		}

		[ $trendingMap, $rejected ] = $this->partitionPayload( $payload );

		if ( $rejected ) {
			$this->logger->warning(
				'PageviewTrendingRelativeUpdateJob: dropped {rejected_count} invalid entries: {page_ids}',
				[
					'rejected_count' => count( $rejected ),
					'page_ids' => implode( ', ', array_slice( $rejected, 0, 10 ) ),
				]
			);
		}

		if ( !$trendingMap ) {
			return $this->permanentFailure(
				'no valid entries among the {payload_count} in payload',
				[ 'payload_count' => count( $payload ) ]
			);
		}

		if ( !$this->store->setTrending( $trendingMap, min( $ttl, self::MAX_TTL ) ) ) {
			$this->setLastError( 'Failed to store the trending set' );

			return false;
		}

		return true;
	}

	/**
	 * Split a payload into the entries that can be stored and the page ids that cannot.
	 *
	 * @return array [ map of page ID to detail, list of rejected page ids ]
	 */
	private function partitionPayload( array $payload ): array {
		$trendingMap = [];
		$rejected = [];

		foreach ( $payload as $pageId => $detail ) {
			if ( is_int( $pageId ) && $pageId > 0 && is_array( $detail ) ) {
				$trendingMap[$pageId] = $detail;
			} else {
				$rejected[] = $pageId;
			}
		}

		return [ $trendingMap, $rejected ];
	}

	/**
	 * Log a param that can never be made valid by retrying, and report the job as done.
	 *
	 * @return true
	 */
	private function permanentFailure( string $message, array $context ): bool {
		$this->logger->error( 'PageviewTrendingRelativeUpdateJob: ' . $message, $context );

		return true;
	}
}
