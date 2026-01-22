<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\BadEmailDomain;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\UserDataAuthenticationRequest;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainPreAuthenticationProvider;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainPreAuthenticationProvider
 */
class BadEmailDomainPreAuthenticationProviderTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideTestUser
	 */
	public function testTestForAccountCreation( array $reqs, bool $expectPass ): void {
		$checker = $this->createMock( BadEmailDomainChecker::class );
		$checker->method( "getDomain" )
			->willReturnCallback( static fn ( $email ) => match ( $email ) {
				'foo@good.org' => 'good.org',
				'bar@evil.com' => 'evil.com',
			} );
		$checker->method( "isBad" )
			->willReturnCallback( static fn ( $domain ) => match ( $domain ) {
				'good.org' => false,
				'evil.com' => true,
			} );

		$provider = new BadEmailDomainPreAuthenticationProvider( $checker );

		$request = $this->createNoOpMock( FauxRequest::class, [ 'getSecurityLogContext' ] );
		$request->method( 'getSecurityLogContext' )->willReturn( [] );
		$authManager = $this->createNoOpMock( AuthManager::class, [ 'getRequest' ] );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$hookContainer = $this->createNoOpMock( HookContainer::class );
		$userNameUtils = $this->createNoOpMock( UserNameUtils::class );
		$provider->init( new NullLogger(), $authManager, $hookContainer, new HashConfig( [] ), $userNameUtils );

		$user = $this->createNoOpMock( User::class );
		$status = $provider->testForAccountCreation( $user, $user, $reqs );

		$this->assertSame( $expectPass, $status->isGood() );
		$this->assertSame( !$expectPass, $status->hasMessage( 'wikimediacustomizations-bademaildomain-error' ) );
	}

	public function provideTestUser() {
		$noemailreq = new UserDataAuthenticationRequest();

		$goodreq = new UserDataAuthenticationRequest();
		$goodreq->email = "foo@good.org";

		$badreq = new UserDataAuthenticationRequest();
		$badreq->email = "bar@evil.com";

		return [
			[ [], true ],
			[ [ $noemailreq ], true ],
			[ [ $goodreq ], true ],
			[ [ $badreq ], false ],
		];
	}

}
