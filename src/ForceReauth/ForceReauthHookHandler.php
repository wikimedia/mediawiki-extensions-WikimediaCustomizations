<?php

namespace MediaWiki\Extension\WikimediaCustomizations\ForceReauth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Hook\AlternateEditHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;

class ForceReauthHookHandler implements
	GetUserPermissionsErrorsExpensiveHook,
	AlternateEditHook
{
	public function __construct(
		private readonly AuthManager $authManager,
		private readonly PermissionManager $permManager,
	) {
	}

	/** @inheritDoc */
	public function onGetUserPermissionsErrorsExpensive(
		$title,
		$user,
		$action,
		&$result
	) {
		if (
			$action === 'edit' &&
			$title->isSiteJsConfigPage() &&
			$this->authManager->securitySensitiveOperationStatus( 'editsitejs' ) !== AuthManager::SEC_OK
		) {
			$result = [ 'userlogin-reauth', $user->getName() ];
			return false;
		}

		return true;
	}

	/** @inheritDoc */
	public function onAlternateEdit( $editPage ) {
		$title   = $editPage->getTitle();
		$context = $editPage->getContext();
		$request = $context->getRequest();
		$user    = $context->getUser();

		if (
			$title->isSiteJsConfigPage() &&
			!$request->wasPosted() &&
			$this->permManager->userCan( 'edit', $user, $title, PermissionManager::RIGOR_QUICK ) &&
			$this->authManager->securitySensitiveOperationStatus( 'editsitejs' ) === AuthManager::SEC_REAUTH
		) {
			$queryParams = $request->getQueryValues();

			$context->getOutput()->redirect(
				SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( [
					'returnto'      => $title->getPrefixedDBkey(),
					'returntoquery' => wfArrayToCgi( array_diff_key( $queryParams, [ 'title' => true ] ) ),
					'force'         => 'editsitejs',
				] )
			);

			return false;
		}

		return true;
	}
}
