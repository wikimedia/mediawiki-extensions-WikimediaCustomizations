<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Discord;

use Config;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Skin;

/**
 * Hook that adds a special meta tag for use by Discord
 */
class DiscordHookHandler implements BeforePageDisplayHook {

	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * Conditionally adds meta tags to the page header for discord preview generation.
	 * The actual preview generation is handled by a separate service, which uses the meta tags to generate the preview
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		$dbname = $this->config->get( MainConfigNames::DBname );
		$lang = $this->config->get( MainConfigNames::LanguageCode );

		if ( !$title || !$title->exists() || $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		$discordConfig = $this->config->get( 'WMCDiscord' );
		if ( !$discordConfig || !is_array( $discordConfig ) ) {
			return;
		}

		$baseUrl = $discordConfig['baseUrl'] ?? null;
		if ( !$baseUrl ) {
			return;
		}

		$discordMetaTagName = $discordConfig['metaTagName'] ?? null;
		if ( !$discordMetaTagName ) {
			return;
		}

		$urlUtils = MediaWikiServices::getInstance()->getURLUtils();
		$parsedUrl = $urlUtils->parse( $baseUrl );

		$serviceArguments = [
			'title' => $title->getPrefixedText(),
			'wprov' => $discordConfig['wprov'] ?? null,
			'db' => $dbname,
			'lang' => $lang
		];
		$parsedUrl['query'] = http_build_query( $serviceArguments );
		$previewUrl = $urlUtils->assemble( $parsedUrl );

		$out->addMeta(
			$discordMetaTagName,
			$previewUrl
		);
	}
}
