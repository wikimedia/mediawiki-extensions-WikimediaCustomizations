<?php

namespace MediaWiki\Extension\WikimediaCustomizations\RateLimit;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\GetSessionJwtDataHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserIdentity;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Assign a rate limit class (rlc claim) for use by the REST Gateway,
 * see <https://wikitech.wikimedia.org/wiki/REST_Gateway/Rate_limiting>.
 */
class RateLimitHookHandler implements GetSessionJwtDataHook {

	/** @var int minimum edit count for confirmed users */
	private const CONFIRMED_USER_EDIT_COUNT = 1000;

	/** @var int minimum account age count for confirmed users */
	private const CONFIRMED_USER_AGE = 7 * 24 * 60 * 60;

	public function __construct(
		private readonly Config $config,
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/**
	 * Utility method, protected so it can be overridden in tests.
	 */
	protected function getCentralAuthUser( UserIdentity $user ): CentralAuthUser {
		return CentralAuthUser::getInstance( $user );
	}

	protected function getEditCounter(): CentralAuthEditCounter {
		return CentralAuthServices::getEditCounter();
	}

	/** @inheritDoc */
	public function onGetSessionJwtData( ?UserIdentity $user, array &$jwtData ): void {
		if ( $user
			&& !( $jwtData['ownerOnly'] ?? false )
			&& $this->extensionRegistry->isLoaded( 'CentralAuth' )
		) {
			// NOTE: As of March 2026, the REST Gateway will ignore the rlc claim for requests
			// that are coming from known clients (x-trusted-request: B) or known networks (x-trusted-request: A).
			// The reasons is that the limit for WMCS is extremely high, and users would not want to authenticate
			// if that gave them worse limits.
			// If needed, we could introduce a "magical" DEFAULT class, which the gateway would be free to ignore,
			// while it would be expected to honour all other classes set in the rlc claim, regardless of
			// the origin of the request. This would be useful if the default limits on WMCS would be mediocre,
			// and certain users could get better limits by authenticating.

			// Determine the rate limit class based on global groups.
			$caUser = $this->getCentralAuthUser( $user );
			if ( !$caUser->exists() ) {
				return;
			}

			$rlc = $this->getRateLimitClassFromGlobalGroups( $caUser );
			if ( $rlc !== null ) {
				$jwtData['rlc'] = $rlc;
				return;
			}

			// Determine the rate limit class based on edit count and account age (see T419796).
			$registration = $caUser->getRegistration();

			$age = MWTimestamp::time() - (int)MWTimestamp::convert( TimestampFormat::UNIX, $registration );

			// NOTE: Edit counts *should* already be initialized for all users.
			$edits = $this->getEditCounter()->getCountIfInitialized( $caUser ) ?? -1;

			// NOTE: this has hard-coded limits for now, if we need this to be more complex and configurable
			// we can use UserRequirementsConditionChecker and make CentralAuth provide implementations
			// for global conditions via the UserRequirementsCondition hook.
			if ( $edits >= self::CONFIRMED_USER_EDIT_COUNT && $age >= self::CONFIRMED_USER_AGE ) {
				$jwtData['rlc'] = 'established-user';
				return;
			}

			$jwtData['rlc'] = 'authed-user';
		}
	}

	private function getRateLimitClassFromGlobalGroups( CentralAuthUser $user ): ?string {
		$map = $this->config->get( 'WMCGlobalGroupToRateLimitClass' );
		if ( !$map ) {
			return null;
		}

		$groups = $user->getGlobalGroups();
		if ( !$groups ) {
			return null;
		}

		// If the user belongs to multiple groups, the first definition in the map wins
		foreach ( $map as $group => $rlc ) {
			if ( in_array( $group, $groups ) ) {
				return $rlc;
			}
		}

		return null;
	}
}
