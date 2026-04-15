<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PrivilegedGroups;

use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Password\UserPasswordPolicy;

/**
 * Password policies and additional logging for members of privileged groups.
 */
class PrivilegedGroupsNoServicesHookHandler implements
	MediaWikiServicesHook
{

	/** @inheritDoc */
	public function onMediaWikiServices( $services ) {
		// This is just to display the policies accurately on Special:PasswordPolicies.
		// We enforce them ourselves below, since we want to handle local groups from other wikis.
		global $wgWMCPrivilegedPasswordPolicy, $wgWMCPrivilegedGroups, $wgPasswordPolicy;
		foreach ( $wgWMCPrivilegedGroups as $group ) {
			if ( $group === 'user' ) {
				// For e.g. private and fishbowl wikis; covers 'user' in password policies
				$group = 'default';
			}
			$wgPasswordPolicy['policies'][$group] = UserPasswordPolicy::maxOfPolicies(
				$wgPasswordPolicy['policies'][$group] ?? [],
				$wgWMCPrivilegedPasswordPolicy
			);
		}
		// We could do the same for $wgWMCPrivilegedGlobalGroups and $wgCentralAuthGlobalPasswordPolicies,
		// but CentralAuth doesn't have a special page that displays these policies, and we enforce them
		// ourselves below, so that would currently do nothing.
	}
}
