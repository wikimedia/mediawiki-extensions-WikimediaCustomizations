<?php

namespace MediaWiki\Extension\CentralAuth\User;

use MediaWiki\User\UserIdentity;

/**
 * Stub of CentralAuth extension's CentralAuthUser class
 */
class CentralAuthUser {

	public static function getInstance( UserIdentity $user ): self {
	}

	/**
	 * @return string[]
	 */
	public function getGlobalGroups() {
	}

}
