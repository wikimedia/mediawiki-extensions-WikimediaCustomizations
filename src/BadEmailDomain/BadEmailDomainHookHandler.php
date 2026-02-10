<?php

namespace MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain;

use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Specials\Hook\UserCanChangeEmailHook;

class BadEmailDomainHookHandler implements UserCanChangeEmailHook {

	public function __construct(
		private readonly BadEmailDomainChecker $checker
	) {
	}

	/** @inheritDoc */
	public function onUserCanChangeEmail( $user, $oldaddr, $newaddr, &$status ) {
		$domain = $this->checker->getDomain( $newaddr );
		if ( $domain && $this->checker->isBad( $domain ) ) {
			LoggerFactory::getInstance( 'security' )->info( 'Email change prevented due to bad email domain',
				[ 'email' => $newaddr ]
				+ RequestContext::getMain()->getRequest()->getSecurityLogContext( $user )
			);

			$status->fatal( 'wikimediacustomizations-bademaildomain-error', $domain );
			return false;
		}

		return true;
	}

}
