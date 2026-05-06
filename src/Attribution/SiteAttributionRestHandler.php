<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\ResponseHeaders;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Telemetry\TracerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * A handler that returns metadata attribution information about a site
 *
 * @package MediaWiki\Extension\WikimediaCustomizations\Attribution
 * @unstable
 */
class SiteAttributionRestHandler extends SimpleHandler {

	private StatsFactory $statsFactory;
	private const MAX_AGE_200 = 3600;

	public function __construct(
		private readonly AttributionDataBuilder $attributionDataBuilder,
		StatsFactory $statsFactory,
		private readonly TracerInterface $tracer,
		private ?LoggerInterface $logger = null,
	) {
		$this->statsFactory = $statsFactory->withComponent( 'Attribution' );
		$this->logger = $logger ?? LoggerFactory::getInstance( 'Attribution' );
	}

	public function needsWriteAccess(): bool {
		return false;
	}

	public function run(): Response {
		// startedAt is used to calculate the total time to check whether we need to log request
		$startedAt = ConvertibleTimestamp::hrtime();

		$span = $this->tracer->createSpan( 'Site Attribution RestEndpoint' )->start();

		try {
			return $this->fetchAttribution();
		} finally {
			$total = ConvertibleTimestamp::hrtime() - $startedAt;
			if ( $total > 500_000_000 ) {
				// 500 ms is a hard limit for the duration of the endpoint, log cases when this happens
				// @see T421905 for more details
				$this->logger->info( 'Metric: Site Attribution endpoint took too long to respond', [
					'site_name' => Title::newMainPage()->getFullURL(),
					'total' => $total,
				] );
				$this->statsFactory
					->getCounter( 'site_article_attribution_too_long_total' )
					->increment();
			}
		}
	}

	/**
	 * @throws LocalizedHttpException
	 */
	private function fetchAttribution(): Response {
		$result = $this->attributionDataBuilder->getSiteAttributionData();
		$response = $this->getResponseFactory()->createJson( $result );
		if ( !$this->getSession()->isPersistent() ) {
			$response->setHeader(
				ResponseHeaders::CACHE_CONTROL,
				'public, max-age=' . self::MAX_AGE_200 . ', s-maxage=' . self::MAX_AGE_200
			);
		}
		return $response;
	}

	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/schema/SiteSignalsSchema.json';
	}
}
