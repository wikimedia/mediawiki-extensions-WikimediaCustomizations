<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PrivilegedGroups;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

class PrivilegedGroups {

	public function __construct(
		private readonly Config $config,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserGroupManager $userGroupManager,
	) {
	}

	/**
	 * Get a list of "privileged" groups and global groups the user is part of.
	 * Typically this means groups with powers comparable to admins and above
	 * (block, delete, edit i18n messages etc).
	 * On SUL wikis, this will take into account group memberships on any wiki,
	 * not just the current one.
	 *
	 * @return string[] Any elevated/privileged groups the user is a member of
	 */
	public function getPrivilegedGroups( UserIdentity $user ): array {
		$privilegedGroups = $this->config->get( 'WMCPrivilegedGroups' );
		$privilegedGlobalGroups = $this->config->get( 'WMCPrivilegedGlobalGroups' );

		if (
			$this->extensionRegistry->isLoaded( 'CentralAuth' ) &&
			CentralAuthUser::getInstanceByName( $user->getName() )->exists()
		) {
			$centralUser = CentralAuthUser::getInstanceByName( $user->getName() );
			try {
				$groups = array_intersect(
					array_merge( $privilegedGroups, $privilegedGlobalGroups ),
					array_merge( $centralUser->getGlobalGroups(), $centralUser->getLocalGroups() )
				);
			} catch ( Exception $e ) {
				// Don't block login if we can't query attached (T119736)
				MWExceptionHandler::logException( $e );
				$groups = array_merge(
					$this->userGroupManager->getUserGroups( $user ),
					$centralUser->getGlobalGroups()
				);
			}
		} else {
			// use effective groups, as we set 'user' as privileged for private/fishbowl wikis
			$groups = array_intersect(
				$privilegedGroups,
				$this->userGroupManager->getUserEffectiveGroups( $user )
			);
		}
		return $groups;
	}

}
