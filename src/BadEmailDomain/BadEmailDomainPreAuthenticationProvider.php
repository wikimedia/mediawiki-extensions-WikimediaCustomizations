<?php

namespace MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\UserDataAuthenticationRequest;
use MediaWiki\Logger\LoggerFactory;
use StatusValue;

class BadEmailDomainPreAuthenticationProvider extends AbstractPreAuthenticationProvider {

	public function __construct(
		private readonly BadEmailDomainChecker $checker
	) {
	}

	/** @inheritDoc */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		$req = AuthenticationRequest::getRequestByClass(
			$reqs,
			UserDataAuthenticationRequest::class
		);
		if ( !$req ) {
			return StatusValue::newGood();
		}

		$email = $req->email;
		if ( !$email ) {
			return StatusValue::newGood();
		}

		$domain = $this->checker->getDomain( $email );
		if ( $domain && $this->checker->isBad( $domain ) ) {
			LoggerFactory::getInstance( 'security' )->info( 'Account creation prevented due to bad email domain',
				[ 'email' => $email ]
				// Do not pass a user as the account doesn't exist yet
				+ $this->manager->getRequest()->getSecurityLogContext()
			);
			return StatusValue::newFatal( 'wikimediacustomizations-bademaildomain-error', $domain );
		}

		return StatusValue::newGood();
	}

}
