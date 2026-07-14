<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Attribution;

use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionRestHandler;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\FileRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Rest\Handler\Helper\PageContentHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Module\Module;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\InvariantException;
use Wikimedia\Message\MessageValue;
use Wikimedia\Stats\StatsFactory;
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
		$repoGroup = $this->getServiceContainer()->getRepoGroup();
		$nsInfo = $this->getServiceContainer()->getNamespaceInfo();

		return new AttributionRestHandler(
			$helperFactory,
			$dataBuilder,
			$repoGroup,
			$language,
			$nsInfo,
			StatsFactory::newNull(),
			$noopTracer,
			$logger
		);
	}

	public static function provideExecute() {
		yield "page identity should be known" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => null,
			'expectedResponse' => new InvariantException()
		];
		yield "page should be known after checkPageAccess" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => 'identity',
			'expectedResponse' => new InvariantException(),
		];
		yield "missing permissions returns 403" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => null,
			'expectedResponse' => 403,
		];
		yield "missing page returns 404" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => null,
			'expectedResponse' => 404
		];
		yield "data builder gets invoked" => [
			'requestData' => [
				'method' => 'GET',
				'queryParams' => []
			],
			'pageContentHelper' => 'identity+page',
			'expectedResponse' => null
		];
	}

	/**
	 * @dataProvider provideExecute
	 */
	public function testExecute(
		$requestData,
		$pageContentHelper,
		$expectedResponse
	) {
		$request = new RequestData( $requestData );
		$dataBuilder = $this->createMock( AttributionDataBuilder::class );
		$helper = $this->createMock( PageContentHelper::class );
		$helper->method( 'constructMetadata' )
			->willReturn( [ 'title' => 'Foo', 'license' => 'CC-BY-SA' ] );
		$logger = null;

		if ( $expectedResponse instanceof InvariantException ) {
			$this->expectException( InvariantException::class );
		} elseif ( is_int( $expectedResponse ) ) {
			$this->expectException( LocalizedHttpException::class );
			$this->expectExceptionCode( $expectedResponse );
			$logger = $this->createMock( LoggerInterface::class );
			$logger->expects( $this->once() )->method( 'warning' );
			$helper->method( 'checkAccess' )
				->willThrowException( new LocalizedHttpException(
					MessageValue::new( 'rest-permission-denied-title' )->plaintextParams( '' ),
					$expectedResponse
				) );
		}
		if ( $pageContentHelper !== null ) {
			$helper->method( 'getPageIdentity' )
				->willReturn( $this->createMock( PageIdentity::class ) );
			if ( $pageContentHelper === 'identity+page' ) {
				$helper->method( 'constructMetadata' )
					->willReturn( [
						'title' => 'Foo',
						'license' => 'CC-BY-SA',
						'latest' => [ 'timestamp' => '20250101000000' ]
					] );
				$existingPageRecord = $this->createMock( ExistingPageRecord::class );
				$helper->method( 'getPage' )
					->willReturn( $existingPageRecord );
				$helper->method( 'getTitleText' )->willReturn( "titleText" );
			}
		}
		if ( !$expectedResponse && !$logger ) {
			$dataBuilder->expects( $this->once() )->method( 'getAttributionData' );
		}

		$handler = $this->newHandler( $helper, $dataBuilder, $logger );
		$this->executeHandler( $handler, $request, [], [], [], [], null, null );
	}

	public function testRedirectOn404ForForeignFile() {
		$titleText = 'File:Foreign.png';
		$request = new RequestData( [
			'method' => 'GET',
			'queryParams' => [ 'expand' => 'latest' ]
		] );

		$helper = $this->createMock( PageContentHelper::class );
		$helper->method( 'getTitleText' )->willReturn( $titleText );
		$helper->method( 'checkAccess' )
			->willThrowException( new LocalizedHttpException( new MessageValue( 'test' ), 404 ) );

		$dataBuilder = $this->createMock( AttributionDataBuilder::class );

		// Mock File and Repo
		$file = $this->createMock( File::class );
		$file->method( 'isLocal' )->willReturn( false );
		$repo = $this->createMock( FileRepo::class );
		$repo->method( 'makeUrl' )->willReturn( 'https://commons.wikimedia.org/w/rest.php' );
		$file->method( 'getRepo' )->willReturn( $repo );

		// Mock RepoGroup
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$this->setService( 'RepoGroup', $repoGroup );

		$handler = $this->newHandler( $helper, $dataBuilder );

		// We need to set the module and path on the handler for the redirect logic
		$module = $this->createMock( Module::class );
		$config = [
			'path' => 'attribution/v0-beta/pages/{title}/signals'
		];

		$response = $this->executeHandler( $handler, $request, $config, [], [], [], null, null, $module );
		$expectedUrl = 'https://commons.wikimedia.org/w/rest.php';
		$expectedUrl .= '/attribution/v0-beta/pages/File%3AForeign.png/signals?expand=latest';
		$this->assertSame( 301, $response->getStatusCode() );
		$this->assertSame( $expectedUrl, $response->getHeaderLine( 'Location' ) );
	}
}
