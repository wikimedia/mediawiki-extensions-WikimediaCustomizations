<?php

namespace MediaWiki\Extension\WikimediaCustomizations\ForceReauth;

use CentralAuthTokenSessionProvider;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\AlternateEditHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsExpensiveHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class ForceReauthHookHandler implements
	GetUserPermissionsErrorsExpensiveHook,
	AlternateEditHook
{
	public function __construct(
		private readonly AuthManager $authManager,
		private readonly PermissionManager $permManager
	) {
	}

	/**
	 * check if a title is a site/user JS/CSS conf page or raw html msg
	 *
	 * @param Title $title
	 * @param User $user
	 * @return bool|string Returns false or a permissions string based upon check
	 */
	private function isSiteOrUserConfigPage( $title, $user ) {
		// - for site js, also check raw html messages, as those are not covered
		// by isSiteJsConfigPage()
		// - for user js and css, only check that another users' js or css page
		// is being edited
		$permission = false;
		if ( $title->isSiteJsConfigPage() || $title->isRawHtmlMessage() || $title->isSiteCssConfigPage() ) {
			$permission = 'editsitejscss';
		} elseif (
			( $title->isUserJsConfigPage() || $title->isUserCssConfigPage() ) &&
			$title->getRootText() !== $user->getName()
		) {
			$permission = 'edituserjscss';
		}
		return $permission;
	}

	/** @inheritDoc */
	public function onGetUserPermissionsErrorsExpensive(
		$title,
		$user,
		$action,
		&$result
	) {
		$reauthPermission = $this->isSiteOrUserConfigPage( $title, $user );
		$isCentralAuthToken = RequestContext::getMain()->getRequest()->getSession()->getProvider()
			instanceof CentralAuthTokenSessionProvider;

		if (
			$action === 'edit' &&
			(bool)$reauthPermission &&
			(
				$isCentralAuthToken ||
				$this->authManager->securitySensitiveOperationStatus( $reauthPermission ) !== AuthManager::SEC_OK
			)
		) {
			$loginUrl = SpecialPage::getSafeTitleFor( 'Userlogin' )
				?->getFullURL( [ 'force' => $reauthPermission ], proto: PROTO_CURRENT );
			$result = ApiMessage::create( [ 'wikimediacustomizations-forcereauth-error', $loginUrl ], 'reauthenticate',
				[ 'operation' => $reauthPermission ] );
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

		$reauthPermission = $this->isSiteOrUserConfigPage( $title, $user );
		$userCanEdit = $this->permManager->userCan( 'edit', $user, $title, PermissionManager::RIGOR_QUICK );

		if (
			!$request->wasPosted() &&
			$userCanEdit &&
			(bool)$reauthPermission &&
			$this->authManager->securitySensitiveOperationStatus( $reauthPermission ) !== AuthManager::SEC_OK
		) {
			$queryParams = $request->getQueryValues();

			$context->getOutput()->redirect(
				SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( [
					'returnto'      => $title->getPrefixedDBkey(),
					'returntoquery' => wfArrayToCgi( array_diff_key( $queryParams, [ 'title' => true ] ) ),
					'force'         => $reauthPermission,
				] )
			);

			return false;
		}

		return true;
	}
}
