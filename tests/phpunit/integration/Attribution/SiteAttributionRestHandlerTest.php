<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\SiteAttributionRestHandler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Telemetry\NoopTracer;

/**
 * @covers MediaWiki\Extension\WikimediaCustomizations\Attribution\SiteAttributionRestHandler
 */
class SiteAttributionRestHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private function newHandler( $dataBuilder, $logger = null ): SiteAttributionRestHandler {
		$noopTracer = new NoopTracer();
		return new SiteAttributionRestHandler(
			$dataBuilder, StatsFactory::newNull(), $noopTracer, $logger
		);
	}

	public static function provideExecute() {
		yield "data builder gets invoked" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'expectedResponse' => null
		];
	}

	/**
	 * @dataProvider provideExecute
	 */
	public function testExecute(
		$requestData,
		$expectedResponse
	) {
		$request = new RequestData( $requestData );
		$dataBuilder = $this->createMock( AttributionDataBuilder::class );
		if ( !$expectedResponse ) {
			$dataBuilder->expects( $this->once() )->method( 'getSiteAttributionData' );
		}
		$handler = $this->newHandler( $dataBuilder );
		$this->executeHandler( $handler, $request, [], [], [], [], null, null );
	}
}
