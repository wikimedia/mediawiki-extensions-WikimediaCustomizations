<?php

namespace MediaWiki\Extension\WikimediaCustomizations\OfficeBan;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;

class OfficeBanHookHandler implements BeforePageDisplayHook {

	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if (
			!$title->isSpecial( 'Blankpage' ) ||
			!str_contains( $title->getText(), '/OfficeBan' )
		) {
			return;
		}

		if ( !$this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
			return;
		}

		$user = $out->getUser();
		if ( !$user->isNamed() ) {
			return;
		}

		$globalGroups = CentralAuthUser::getInstance( $user )->getGlobalGroups();
		if ( !in_array( 'staff', $globalGroups, true ) ) {
			return;
		}

		$out->addModules( 'ext.wikimediaCustomizations.officeBan' );
	}

}
