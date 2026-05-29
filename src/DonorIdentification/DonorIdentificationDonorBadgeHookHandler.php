<?php

namespace MediaWiki\Extension\WikimediaCustomizations\DonorIdentification;

use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;

class DonorIdentificationDonorBadgeHookHandler implements BeforePageDisplayHook {

	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'TestKitchen' ) ) {
			$experimentManager = MediaWikiServices::getInstance()->getService( 'TestKitchen.ExperimentManager' );
			$experiment = $experimentManager->getExperiment( 'donor-delight-badge' );
			if (
				// Limit to experiment treatment groups and ensure showing only to anonymous users.
				$experiment &&
				$experiment->getAssignedGroup() !== null &&
				$out->getSkin()->getSkinName() === 'minerva' &&
				$out->getUser()->isAnon()
			) {
				// Badge and delightful animation styles.
				$out->addHtmlClasses( 'wikimedia-donor-badge-' . $experiment->getAssignedGroup() );
				$out->addModuleStyles( 'ext.wikimediaCustomizations.donorDelightBadge.styles' );
				// Badge and delightful animation script.
				$out->addModules( 'ext.wikimediaCustomizations.donorDelightBadge' );

				$out->addJsConfigVars(
					'wgDonorDelightBadgeBucket',
					$experiment->getAssignedGroup()
				);
			}
		}
	}

}
