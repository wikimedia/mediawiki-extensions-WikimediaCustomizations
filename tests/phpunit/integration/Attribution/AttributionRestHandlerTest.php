<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionRestHandler;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Rest\Handler\Helper\PageContentHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\InvariantException;
use Wikimedia\Message\MessageValue;
use Wikimedia\Telemetry\NoopTracer;

/**
 * @group Database
 * @covers MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionRestHandler
 */
class AttributionRestHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private function newHandler( $pageContentHelper, $dataBuilder, $logger = null ): AttributionRestHandler {
		$helperFactory = $this->createMock( PageRestHelperFactory::class );
		$helperFactory->method( 'newPageContentHelper' )->willReturn( $pageContentHelper );
		$noopTracer = new NoopTracer();
		$language = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		return new AttributionRestHandler(
			$helperFactory, $dataBuilder, $language, $noopTracer, $logger
		);
	}

	public function provideExecute() {
		$basePageContentHelper = $this->createMock( PageContentHelper::class );
		$basePageContentHelper->method( 'constructMetadata' )
			->willReturn( [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ] );

		$notFoundException = new LocalizedHttpException(
			MessageValue::new( 'rest-permission-denied-title' )->plaintextParams( '' ),
			404
		);
		$permissionDeniedException = new LocalizedHttpException(
			MessageValue::new( 'rest-permission-denied-title' )->plaintextParams( '' ),
			403
		);

		$basePageContentHelper403 = $this->createMock( PageContentHelper::class );
		$basePageContentHelper403->method( 'constructMetadata' )
			->willReturn( [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ] );
		$basePageContentHelper403->method( 'checkAccess' )
			->willThrowException( $permissionDeniedException );

		$basePageContentHelper404 = $this->createMock( PageContentHelper::class );
		$basePageContentHelper404->method( 'constructMetadata' )
			->willReturn( [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ] );
		$basePageContentHelper404->method( 'checkAccess' )
			->willThrowException( $notFoundException );

		$pageIdentity = $this->createMock( PageIdentity::class );
		$pageContentHelperWithIdentity = $this->createMock( PageContentHelper::class );
		$pageContentHelperWithIdentity->method( 'constructMetadata' )
			->willReturn( [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ] );
		$pageContentHelperWithIdentity->method( 'getPageIdentity' )
			->willReturn( $pageIdentity );

		$pageContentHelperWithIdentityAndPage = $this->createMock( PageContentHelper::class );
		$pageContentHelperWithIdentityAndPage->method( 'constructMetadata' )
			->willReturn( [
				'title' => 'Foo',
				'license' => 'CC-BY-SA',
				'latest' => [ 'timestamp' => '20250101000000' ]
			] );
		$pageContentHelperWithIdentityAndPage->method( 'getPageIdentity' )
			->willReturn( $pageIdentity );
		$existingPageRecord = $this->createMock( ExistingPageRecord::class );
		$pageContentHelperWithIdentityAndPage->method( 'getPage' )
			->willReturn( $existingPageRecord );
		$pageContentHelperWithIdentityAndPage->method( 'getTitleText' )->willReturn( "titleText" );

		yield "page identity should be known" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => $basePageContentHelper,
			'logger' => null,
			'expectedResponse' => new InvariantException()
		];
		yield "page should be known after checkPageAccess" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => $pageContentHelperWithIdentity,
			'logger' => null,
			'expectedResponse' => new InvariantException(),
		];
		yield "missing permissions returns 403" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => $basePageContentHelper403,
			'logger' => [ $this->createMock( LoggerInterface::class ), 403 ],
			'expectedResponse' => $permissionDeniedException,
		];
		yield "missing page returns 404" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => $basePageContentHelper404,
			'logger' => [ $this->createMock( LoggerInterface::class ), 404 ],
			'expectedResponse' => $notFoundException
		];
		yield "data builder gets invoked" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => $pageContentHelperWithIdentityAndPage,
			'logger' => null,
			'expectedResponse' => null
		];
	}

	/**
	 * @dataProvider provideExecute
	 */
	public function testExecute(
		$requestData,
		$pageContentHelper,
		$logger,
		$expectedResponse
	) {
		$request = new RequestData( $requestData );
		$dataBuilder = $this->createMock( AttributionDataBuilder::class );

		if ( $expectedResponse instanceof InvariantException ) {
			$this->expectException( InvariantException::class );
		}
		if ( $logger ) {
			$this->expectException( LocalizedHttpException::class );
			$this->expectExceptionCode( $logger[ 1 ] );
			$logger = $logger[ 0 ];
			$logger->expects( $this->once() )->method( 'warning' );
		}
		if ( !$expectedResponse && !$logger ) {
			$dataBuilder->expects( $this->once() )->method( 'getAttributionData' );
		}

		$handler = $this->newHandler( $pageContentHelper, $dataBuilder, $logger );
		$this->executeHandler( $handler, $request, [], [], [], [], null, null );
	}
}
