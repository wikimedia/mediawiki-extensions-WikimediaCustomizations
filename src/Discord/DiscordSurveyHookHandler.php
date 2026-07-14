<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Discord;

use MediaWiki\Config\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\ResourceLoader\Context;

/**
 * Registers a QuickSurvey for readers arriving via Discord preview links
 * (T431352), and queues the client module that decides whether to show it.
 *
 * The survey is registered in a dormant state: QuickSurveys never displays
 * it on its own. Eligibility (wprov match) and sampling happen in the
 * ext.wikimediaCustomizations.discordSurvey module, which then loads
 * QuickSurveys and forces display. This keeps QuickSurveys code off the
 * page for readers who cannot see the survey.
 */
class DiscordSurveyHookHandler implements BeforePageDisplayHook {

	public const SURVEY_NAME = 'discord-arrival-survey';

	public function __construct(
		private readonly Config $config,
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->extensionRegistry->isLoaded( 'QuickSurveys' ) || !$this->isSurveyEnabled() ) {
			return;
		}
		$title = $out->getTitle();
		// Discord preview links point at existing mainspace pages
		// (see DiscordHookHandler), so the survey cannot trigger elsewhere.
		// QuickSurveys never shows surveys on the main page or non-view
		// actions (SurveyContextFilter); match that here.
		if (
			$out->getActionName() !== 'view' ||
			!$title ||
			!$title->exists() ||
			$title->getNamespace() !== NS_MAIN ||
			$title->isMainPage()
		) {
			return;
		}
		$out->addModules( 'ext.wikimediaCustomizations.discordSurvey' );
	}

	/**
	 * QuickSurveysEnabled hook (no interface is defined for it upstream).
	 * Never runs unless the QuickSurveys extension is loaded.
	 *
	 * @param array[] &$surveys
	 */
	public function onQuickSurveysEnabled( array &$surveys ): void {
		if ( !$this->isSurveyEnabled() ) {
			return;
		}
		$surveys[] = [
			'name' => self::SURVEY_NAME,
			'type' => 'internal',
			'enabled' => true,
			// Dormant registration: the empty namespaces audience stops
			// QuickSurveys from queueing its init module for this survey
			// (SurveyContextFilter), and coverage 0 keeps it out of
			// client-side selection when another enabled survey has loaded
			// QuickSurveys anyway. Display is triggered exclusively by
			// ext.wikimediaCustomizations.discordSurvey, which does its own
			// sampling at $wgWMCDiscord['surveyCoverage'].
			'coverage' => 0,
			'audience' => [
				'namespaces' => [],
			],
			'platforms' => [ 'desktop', 'mobile' ],
			'privacyPolicy' => 'wikimediacustomizations-discordsurvey-privacy-policy',
			'questions' => [
				[
					'name' => 'question-1',
					'layout' => 'multiple-answer',
					'question' => 'wikimediacustomizations-discordsurvey-question',
					'answers' => [
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-trust' ],
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-learn-more' ],
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-verify' ],
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-contribute' ],
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-donate' ],
						[ 'label' => 'wikimediacustomizations-discordsurvey-answer-share' ],
						[
							'label' => 'wikimediacustomizations-discordsurvey-answer-other',
							'freeformTextLabel' => 'wikimediacustomizations-discordsurvey-answer-other-placeholder'
						]
					],
				],
			],
		];
	}

	/**
	 * ResourceLoader callback providing the survey gate module's config.
	 */
	public static function getSurveyClientConfig( Context $context, Config $config ): array {
		$discordConfig = $config->get( 'WMCDiscord' );
		$discordConfig = is_array( $discordConfig ) ? $discordConfig : [];
		return [
			'surveyName' => self::SURVEY_NAME,
			'wprov' => $discordConfig['wprov'] ?? null,
			'coverage' => (float)( $discordConfig['surveyCoverage'] ?? 0 ),
		];
	}

	private function isSurveyEnabled(): bool {
		$discordConfig = $this->config->get( 'WMCDiscord' );
		return is_array( $discordConfig ) &&
			!empty( $discordConfig['wprov'] ) &&
			(float)( $discordConfig['surveyCoverage'] ?? 0 ) > 0;
	}
}
