<?php

namespace MediaWiki\Extension\CentralAuth\User;

use MediaWiki\User\UserIdentity;

/**
 * Stub of the class from CentralAuth.
 */
class CentralAuthUser {

	public static function getInstance( UserIdentity $user ): self {
	}

	/**
	 * @return string[]
	 */
	public function getGlobalGroups() {
	}

	/**
	 * @return string timestamp
	 */
	public function getRegistration() {
	}

	/**
	 * @return bool
	 */
	public function exists() {
	}

}
