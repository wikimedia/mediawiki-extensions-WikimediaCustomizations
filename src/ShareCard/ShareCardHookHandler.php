<?php

namespace MediaWiki\Extension\WikimediaCustomizations\ShareCard;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Skin\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Skin\Skin;

/**
 * On Minerva, replaces the core share link ($wgPageShareSkinsEnabled) with a
 * dialog that offers the article as a generated image.
 */
class ShareCardHookHandler implements
	BeforePageDisplayHook,
	SkinTemplateNavigation__UniversalHook
{

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
	 * Adds the share link where the ?sharecard=1 URL parameter enabled the
	 * card on a wiki that doesn't show core's link.
	 *
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		if ( isset( $links['views']['share'] ) || !$this->isEnabled( $sktemplate ) ) {
			return;
		}
		// Matches the link core adds, which the card takes over.
		$links['views']['share'] = [
			'class' => 'mw-page-share',
			'text' => $sktemplate->msg( 'skin-view-share' )->text(),
			'icon' => 'share',
			'href' => $sktemplate->getTitle()->getLocalURL(),
			'data' => [
				'mw-interface' => '1',
			],
		];
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
	 * Whether the page gets the card: where config enables it and core shows
	 * the share link, or where the ?sharecard=1 URL parameter asks for it, for
	 * testing on any wiki.
	 */
	private function isEnabled( Skin $skin ): bool {
		$title = $skin->getTitle();
		if (
			$skin->getSkinName() !== 'minerva' ||
			!$title || !$title->isContentPage() || !$title->exists() ||
			$skin->getActionName() !== 'view'
		) {
			return false;
		}
		if ( $skin->getRequest()->getFuzzyBool( 'sharecard' ) ) {
			return true;
		}
		return $this->config->get( 'WMCShareCardEnabled' ) &&
			in_array(
				'minerva',
				$this->mainConfig->get( MainConfigNames::PageShareSkinsEnabled ),
				true
			);
	}
}
