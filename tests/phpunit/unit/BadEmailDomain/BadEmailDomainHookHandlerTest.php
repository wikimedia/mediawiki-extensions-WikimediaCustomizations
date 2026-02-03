<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\BadEmailDomain;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainHookHandler;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainHookHandler
 */
class BadEmailDomainHookHandlerTest extends MediaWikiUnitTestCase {

	public function testOnUserCanChangeEmail(): void {
		$checker = $this->createMock( BadEmailDomainChecker::class );
		$checker->method( "getDomain" )
			->willReturnMap( [
				[ 'foo@good.org', 'good.org' ],
				[ 'bar@evil.com', 'evil.com' ],
			] );
		$checker->method( "isBad" )
			->willReturnMap( [
				[ 'good.org', false ],
				[ 'evil.com', true ],
			] );

		$handler = new BadEmailDomainHookHandler( $checker );

		$request = $this->createNoOpMock( FauxRequest::class, [ 'getSecurityLogContext' ] );
		$request->method( 'getSecurityLogContext' )->willReturn( [] );
		RequestContext::getMain()->setRequest( $request );

		$user = $this->createNoOpMock( User::class );
		$status = Status::newGood();

		$result = $handler->onUserCanChangeEmail(
			$user,
			'old@old.com',
			'foo@good.org',
			$status,
		);
		$this->assertTrue( $result );
		$this->assertStatusGood( $status );

		$result = $handler->onUserCanChangeEmail(
			$user,
			'old@old.com',
			'bar@evil.com',
			$status,
		);
		$this->assertFalse( $result );
		$this->assertStatusError( 'wikimediacustomizations-bademaildomain-error', $status );
	}

}
