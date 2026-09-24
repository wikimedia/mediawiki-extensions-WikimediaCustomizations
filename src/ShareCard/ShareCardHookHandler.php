<?php

namespace MediaWiki\Extension\WikimediaCustomizations\ShareCard;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Skin\Skin;

/**
 * On Minerva, replaces the core share link ($wgPageShareSkinsEnabled) with a
 * dialog that offers the article as a generated image.
 */
class ShareCardHookHandler implements BeforePageDisplayHook {

	public function __construct(
		private readonly Config $config,
		private readonly Config $mainConfig,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $this->isEnabled( $skin ) ) {
			$out->addModules( 'ext.wikimediaCustomizations.shareCard.init' );
		}
	}

	/**
	 * ResourceLoader callback for the client module's config.json.
	 */
	public static function getShareCardClientConfig( Context $context, Config $config ): array {
		$logos = SkinModule::getAvailableLogos( $config, $context->getLanguage() );
		$wordmark = $logos['wordmark'] ?? null;
		return [
			'licenseIcons' => $config->get( 'WMCShareCardLicenseIcons' ),
			'wordmark' => $wordmark ? [
				'src' => $wordmark['src'],
				'width' => $wordmark['width'],
				'height' => $wordmark['height'],
			] : null,
		];
	}

	/**
	 * Whether the page has the core share link for the card to take over.
	 */
	private function isEnabled( Skin $skin ): bool {
		$title = $skin->getTitle();
		return $this->config->get( 'WMCShareCardEnabled' ) &&
			$skin->getSkinName() === 'minerva' &&
			in_array(
				'minerva',
				$this->mainConfig->get( MainConfigNames::PageShareSkinsEnabled ),
				true
			) &&
			$title && $title->isContentPage() && $title->exists() &&
			$skin->getActionName() === 'view';
	}
}
