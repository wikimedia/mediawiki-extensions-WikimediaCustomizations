<?php

namespace MediaWiki\Extension\WikimediaCustomizations\RateLimit;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\GetSessionJwtDataHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserIdentity;

class RateLimitHookHandler implements GetSessionJwtDataHook {

	public function __construct(
		private Config $config,
		private ExtensionRegistry $extensionRegistry,
	) {
	}

	/** @inheritDoc */
	public function onGetSessionJwtData( ?UserIdentity $user, array &$jwtData ): void {
		if ( $user
			&& !( $jwtData['ownerOnly'] ?? false )
			&& $this->extensionRegistry->isLoaded( 'CentralAuth' )
		) {
			$map = $this->config->get( 'WMCGlobalGroupToRateLimitClass' );
			if ( !$map ) {
				return;
			}
			$groups = CentralAuthUser::getInstance( $user )->getGlobalGroups();
			if ( !$groups ) {
				return;
			}
			// If the user belongs to multiple groups, the first definition in the map wins
			foreach ( $map as $group => $rlc ) {
				if ( in_array( $group, $groups ) ) {
					$jwtData['rlc'] = $rlc;
					break;
				}
			}
		}
	}
}
