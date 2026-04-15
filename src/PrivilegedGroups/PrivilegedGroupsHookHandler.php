<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PrivilegedGroups;

use MediaWiki\Config\Config;
use MediaWiki\Hook\GetSecurityLogContextHook;
use MediaWiki\Password\Hook\PasswordPoliciesForUserHook;
use MediaWiki\Password\UserPasswordPolicy;
use MediaWiki\User\UserIdentity;

/**
 * Password policies and additional logging for members of privileged groups.
 */
class PrivilegedGroupsHookHandler implements
	GetSecurityLogContextHook,
	PasswordPoliciesForUserHook
{

	public function __construct(
		private readonly Config $config,
		private readonly PrivilegedGroups $privilegedGroups,
	) {
	}

	/** @inheritDoc */
	public function onPasswordPoliciesForUser( $user, &$effectivePolicy ) {
		// Enforce password policy when users login on other wikis; also for sensitive global groups
		$privilegedPolicy = $this->config->get( 'WMCPrivilegedPasswordPolicy' );
		$privilegedGroups = $this->privilegedGroups->getPrivilegedGroups( $user );
		if ( $privilegedGroups ) {
			$effectivePolicy = UserPasswordPolicy::maxOfPolicies( $effectivePolicy, $privilegedPolicy );
		}
	}

	/** @inheritDoc */
	public function onGetSecurityLogContext( array $info, array &$context ): void {
		/** @var ?UserIdentity $user */
		$user = $info['user'] ?? null;
		if ( $user ) {
			$privilegedGroups = $this->privilegedGroups->getPrivilegedGroups( $user );
			$context += [
				'user_is_privileged' => (bool)$privilegedGroups,
				'user_privileged_groups' => implode( ', ', $privilegedGroups ),
			];
		}
	}
}
