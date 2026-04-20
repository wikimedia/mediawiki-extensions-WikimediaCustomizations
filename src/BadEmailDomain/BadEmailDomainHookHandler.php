<?php

namespace MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain;

use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Specials\Hook\UserCanChangeEmailHook;
use MediaWiki\User\User;

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
				+ $this->getSecurityLogContext( $user )
			);

			$status->fatal( 'wikimediacustomizations-bademaildomain-error', $domain );
			return false;
		}

		return true;
	}

	/**
	 * Allow tests to override the security log context and avoid accessing RequestContext
	 *
	 * @param User $user
	 */
	protected function getSecurityLogContext( $user ): array {
		return RequestContext::getMain()->getRequest()->getSecurityLogContext( $user );
	}

}
