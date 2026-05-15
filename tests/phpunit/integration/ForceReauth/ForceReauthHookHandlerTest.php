<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\ForceReauth;

use CentralAuthTokenSessionProvider;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\WikimediaCustomizations\ForceReauth\ForceReauthHookHandler;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\ForceReauth\ForceReauthHookHandler
 */
class ForceReauthHookHandlerTest extends MediaWikiIntegrationTestCase {

	/** @var AuthManager|MockObject */
	private $mockAuthManager;

	/** @var PermissionManager|MockObject */
	private $mockPermManager;

	protected function setUp(): void {
		parent::setUp();
		$this->mockAuthManager = $this->createMock( AuthManager::class );
		$this->mockPermManager = $this->createMock( PermissionManager::class );
	}

	/**
	 * Helper to create a highly configured Title mock.
	 */
	private function createMockTitle(
		string $pageType,
		string $rootText ): Title {
		$title = $this->createMock( Title::class );
		if ( $pageType == 'sitejs' ) {
			$title->method( 'isSiteJsConfigPage' )->willReturn( true );
		}
		if ( $pageType == 'sitecss' ) {
			$title->method( 'isSiteCssConfigPage' )->willReturn( true );
		}
		if ( $pageType == 'userjs' ) {
			$title->method( 'isUserJsConfigPage' )->willReturn( true );
		}
		if ( $pageType == 'usercss' ) {
			$title->method( 'isUserCssConfigPage' )->willReturn( true );
		}
		$title->method( 'getRootText' )->willReturn( $rootText );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'Test_Page' );
		return $title;
	}

	/**
	 * Data provider for matching page configurations.
	 */
	public static function providePageScenarios(): array {
		return [
			'Site JS configuration' => [
				'type' => 'sitejs', 'rootText' => 'MediaWiki', 'userName' => 'SomeBody123', 'hookReturn' => false
			],
			'Site CSS configuration' => [
				'type' => 'sitecss', 'rootText' => 'MediaWiki', 'userName' => 'SomeBody123', 'hookReturn' => false
			],
			'User JS config page' => [
				'type' => 'userjs', 'rootText' => 'SomeBody789', 'userName' => 'SomeBody123', 'hookReturn' => false
			],
			'User CSS config page' => [
				'type' => 'usercss', 'rootText' => 'SomeBody789', 'userName' => 'SomeBody123', 'hookReturn' => false
			],
			'Current user editing their own user JS page' => [
				'type' => 'userjs', 'rootText' => 'SomeBody123', 'userName' => 'SomeBody123', 'hookReturn' => true
			],
			'Current user editing their own user CSS page' => [
				'type' => 'usercss', 'rootText' => 'SomeBody123', 'userName' => 'SomeBody123', 'hookReturn' => true
			],
			'Standard wiki page' => [
				'type' => 'std', 'rootText' => 'A_Test_Root_Page', 'userName' => 'SomeBody123', 'hookReturn' => true
			],
		];
	}

	/**
	 * @dataProvider providePageScenarios
	 */
	public function testOnGetUserPermissionsErrorsExpensive(
		string $pageType,
		string $rootText,
		string $userName,
		bool $hookReturn
	): void {
		$title = $this->createMockTitle( $pageType, $rootText );
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( $userName );

		// If the configuration triggers the rule, we enforce reauth
		$secStatus = !$hookReturn ? AuthManager::SEC_REAUTH : AuthManager::SEC_OK;
		$permChecked = '';
		if ( $pageType == 'sitejs' || $pageType === 'sitecss' ) {
			$permChecked = 'editsitejscss';
		}
		if ( $pageType == 'userjs' || $pageType === 'usercss' ) {
			$permChecked = 'edituserjscss';
		}
		$this->mockAuthManager->method( 'securitySensitiveOperationStatus' )
			->with( $permChecked )
			->willReturn( $secStatus );

		// Mock RequestContext structure for the global state
		$mockSession = $this->createMock( Session::class );
		// Normal provider to avoid CentralAuth logic branch here
		$mockProvider = $this->createMock( SessionProvider::class );
		$mockSession->method( 'getProvider' )->willReturn( $mockProvider );

		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'getSession' )->willReturn( $mockSession );

		$context = RequestContext::getMain();
		$context->setRequest( $mockRequest );

		$handler = new ForceReauthHookHandler( $this->mockAuthManager, $this->mockPermManager );

		$result = null;
		$returnValue = $handler->onGetUserPermissionsErrorsExpensive( $title, $user, 'edit', $result );

		if ( !$hookReturn ) {
			$this->assertFalse( $returnValue );
			$this->assertInstanceOf( ApiMessage::class, $result );
		} else {
			$this->assertTrue( $returnValue );
			$this->assertNull( $result );
		}
	}

	public function testOnGetUserPermissionsErrorsExpensiveWithCentralAuth(): void {
		// Force an instance where CentralAuth session forces a block even if SEC_OK is true
		$title = $this->createMockTitle( true, 'MediaWiki' );
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'Admin' );

		$this->mockAuthManager->method( 'securitySensitiveOperationStatus' )
			->willReturn( AuthManager::SEC_OK );

		// Mock CentralAuthTokenSessionProvider
		$mockSession = $this->createMock( Session::class );
		$mockCentralAuthProvider = $this->createMock( CentralAuthTokenSessionProvider::class );
		$mockSession->method( 'getProvider' )->willReturn( $mockCentralAuthProvider );
		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'getSession' )->willReturn( $mockSession );

		RequestContext::getMain()->setRequest( $mockRequest );

		$handler = new ForceReauthHookHandler( $this->mockAuthManager, $this->mockPermManager );

		$result = null;
		$returnValue = $handler->onGetUserPermissionsErrorsExpensive( $title, $user, 'edit', $result );

		// Should return true due to CentralAuth injection branch
		$this->assertTrue( $returnValue );
	}

	/**
	 * @dataProvider providePageScenarios
	 */
	public function testOnAlternateEdit(
		string $pageType,
		string $rootText,
		string $userName,
		bool $hookReturn
	): void {
		$title = $this->createMockTitle( $pageType, $rootText );
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( $userName );

		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'wasPosted' )->willReturn( false );
		$mockRequest->method( 'getQueryValues' )->willReturn( [ 'action' => 'edit', 'title' => 'Test' ] );

		$mockOutput = $this->createMock( OutputPage::class );
		if ( !$hookReturn ) {
			// It should expect a redirect invocation
			$mockOutput->expects( $this->once() )->method( 'redirect' );
		} else {
			$mockOutput->expects( $this->never() )->method( 'redirect' );
		}

		$mockContext = $this->createMock( IContextSource::class );
		$mockContext->method( 'getRequest' )->willReturn( $mockRequest );
		$mockContext->method( 'getUser' )->willReturn( $user );
		$mockContext->method( 'getOutput' )->willReturn( $mockOutput );

		$editPage = $this->createMock( EditPage::class );
		$editPage->method( 'getTitle' )->willReturn( $title );
		$editPage->method( 'getContext' )->willReturn( $mockContext );

		$this->mockPermManager->method( 'userCan' )->willReturn( true );
		$this->mockAuthManager->method( 'securitySensitiveOperationStatus' )
			->willReturn( !$hookReturn ? AuthManager::SEC_REAUTH : AuthManager::SEC_OK );

		$handler = new ForceReauthHookHandler( $this->mockAuthManager, $this->mockPermManager );
		$returnValue = $handler->onAlternateEdit( $editPage );

		if ( !$hookReturn ) {
			$this->assertFalse( $returnValue );
		} else {
			$this->assertTrue( $returnValue );
		}
	}
}
