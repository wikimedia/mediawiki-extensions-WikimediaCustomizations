<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Handler\Helper\PageContentHelper;
use MediaWiki\Rest\Handler\Helper\PageRedirectHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\Assert\Assert;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * A handler that returns metadata attribution information about a page
 *
 * @package MediaWiki\Extension\WikimediaCustomizations\Attribution
 * @unstable
 */
class AttributionRestHandler extends SimpleHandler {

	private const ALLOWED_EXPAND_KEYS = [
		'trust_and_relevance',
		'calls_to_action',
	];
	private PageContentHelper $contentHelper;
	private string $dbname;

	public function __construct(
		private readonly Config $mainConfig,
		private readonly UrlUtils $urlUtils,
		private readonly RepoGroup $repoGroup,
		private readonly PageRestHelperFactory $helperFactory,
		private readonly ?PageViewService $pageViewService = null
	) {
		$this->contentHelper = $helperFactory->newPageContentHelper();
		$this->dbname = $this->mainConfig->get( MainConfigNames::DBname );
	}

	public function getParamSettings(): array {
		return array_merge( $this->contentHelper->getParamSettings(), [
			'expand' => [
				Handler::PARAM_SOURCE => 'query',
				Handler::PARAM_DESCRIPTION => new MessageValue(
					'wikimediacustomizations-attribution-get-pages-signals-param-expand'
				),
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		] );
	}

	public function needsWriteAccess(): bool {
		return false;
	}

	private function getRedirectHelper(): PageRedirectHelper {
		return $this->helperFactory->newPageRedirectHelper(
			$this->getResponseFactory(),
			$this->getRouter(),
			$this->getPath(),
			$this->getRequest()
		);
	}

	protected function postValidationSetup() {
		$authority = $this->getAuthority();
		$this->contentHelper->init( $authority, $this->getValidatedParams() );
	}

	/**
	 * Perform the necessary checks to ensure the page is accessible and redirectable.
	 * If the page is not accessible or redirectable, return a response with the appropriate status code.
	 * If the page is accessible and redirectable, return null.
	 *
	 * @return Response|null
	 */
	private function checkPageAccess() {
		$this->contentHelper->checkAccessPermission();
		$pageIdentity = $this->contentHelper->getPageIdentity();

		$followWikiRedirects = $this->contentHelper->getRedirectsAllowed();

		// The page should be set if checkAccessPermission() didn't throw
		Assert::invariant( $pageIdentity !== null, 'Page should be known' );

		$redirectHelper = $this->getRedirectHelper();
		$redirectHelper->setFollowWikiRedirects( $followWikiRedirects );
		// Respect wiki redirects and variant redirects unless ?redirect=no was provided.
		// With ?redirect=no, non-existing pages with an existing variant will get a 404.
		$redirectResponse = $redirectHelper->createRedirectResponseIfNeeded(
			$pageIdentity,
			$this->contentHelper->getTitleText()
		);

		if ( $redirectResponse !== null ) {
			return $redirectResponse;
		}

		// We could have a missing page at this point, check and return 404 if that's the case
		$this->contentHelper->checkHasContent();

		return null;
	}

	/**
	 * @return Response
	 * @throws LocalizedHttpException
	 */
	public function run(): Response {
		// Check access and existence of the page
		$accessResultResponse = $this->checkPageAccess();
		if ( $accessResultResponse !== null ) {
			return $accessResultResponse;
		}

		// Set up an object response for page information and attribution data.
		$title = Title::newFromText( $this->contentHelper->getTitleText() );
		$page = $this->contentHelper->getPage();
		$metadata = $this->contentHelper->constructMetadata();

		// Get the wiki name
		$wikiNameMessage = new Message(
			'project-localized-name-' . $this->dbname,
			[],
			$title->getPageLanguage()
		);
		// If we can't resolve the wiki name, just use an empty string
		$wikiName = !$wikiNameMessage->isBlank() ? $wikiNameMessage->plain() : '';

		$result = [ 'essential' => [
			'title' => $metadata['title'],
			'license' => $metadata['license'],
			'link' => $title->getCanonicalURL(),
			'default_brand_marks' => $this->getSiteBrandMarksObject( $title->getPageLanguage()->getCode() ),
			'source_wiki' => [
				'site_name' => $wikiName,
				'project_family' => $this->getProjectFamily(),
				'site_id' => $this->dbname,
				'site_language' => $this->mainConfig->get( MainConfigNames::LanguageCode ),
				'page_language' => $title->getPageLanguage()->getHtmlCode(),
			],
		] ];

		// If this is a file page, we'll add the author to the response.
		$file =
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->repoGroup->findFile( $page, [ 'private' => $this->getAuthority() ] ) ?: null;
		if ( $file ) {
			$result['essential']['author'] = $file->getUploader( File::FOR_PUBLIC )->getName();
		}

		if ( $this->shouldExpand( 'trust_and_relevance' ) ) {
			$result['trust_and_relevance'] = [
				'last_modified' => $metadata['latest']['timestamp'],
				'page_views' => $this->getPageViews( $title ),
				// Placeholder for the contributor counts, will be implemented in a future version.
				'contributor_counts' => 0,
			];
		}

		if ( $this->shouldExpand( 'calls_to_action' ) ) {
			$talkPage = $title->getTalkPageIfDefined();

			$result['calls_to_action'] = [
				'donation_cta' => [
					'default' => 'https://donate.wikimedia.org',
					'foundation' => 'https://donate.wikimedia.org',
					'special' => 'https://donate.wikipedia25.org/',
				],
				'participation_cta' => [
					'talk_page' => $talkPage ? $talkPage->getCanonicalURL() : '',
					// HACK: We will link to the enwiki task center for now for the demo/experimentation purposes
					'task_center' => 'https://en.wikipedia.org/wiki/Wikipedia:Task_Center',
				],
			];
		}

		return $this->getResponseFactory()->createJson( $result );
	}

	/**
	 * Check if the given key sould be included in the expanded response based
	 * on the `expand` parameter.
	 *
	 * @param string $key The key to check if it should be expanded.
	 * @return bool True if the key should be expanded, false otherwise.
	 */
	private function shouldExpand( string $key ): bool {
		$params = $this->getValidatedParams();
		$expand = isset( $params['expand'] ) ? explode( ',', $params['expand'] ) : [];

		return in_array( $key, $expand );
	}

	/**
	 * Get the project family for the current wiki; "wikipedia", "wiktionary", "wikibooks", etc.
	 * Will return an empty string if the project name is not found.
	 *
	 * @return string The project name, or an empty string if not found.
	 */
	private function getProjectFamily() {
		global $wgConf;
		[ $site, ] = $wgConf->siteFromDB( $this->dbname );
		return $site ?? '';
	}

	/**
	 * Get the site brand marks for a given title and create the response object.
	 *
	 * @param string $langCode The language code of the page
	 * @return array
	 */
	private function getSiteBrandMarksObject( string $langCode ): array {
		// Get the site brand mark logo from the config
		// For the moment, we'll get the icon logo, and fall back on 1x if the icon logo is not set.
		$logos = SkinModule::getAvailableLogos( $this->mainConfig, $langCode );

		// Collect site brand marks into an array
		$brandMarks = [
			[
				'name' => 'Default logo',
				// 1x version always exists, and falls back on config if not set
				'url' => $this->urlUtils->expand( $logos['1x'], PROTO_CANONICAL ),
				'type' => 'logo',
			]
		];
		if ( isset( $logos['icon'] ) ) {
			$brandMarks[] = [
				"name" => "Site icon",
				"url" => $this->urlUtils->expand( $logos['icon'], PROTO_CANONICAL ),
				'type' => 'icon',
			];
		}
		$brandMarks[] = [
			'name' => 'Sound logo',
			'url' => 'https://upload.wikimedia.org/wikipedia/commons/9/91/Wikimedia_Sonic_Logo_-_4-seconds.wav',
			'type' => 'audio',
		];

		return $brandMarks;
	}

	/**
	 * Get the page views for a given title, summed over the last 30 days.
	 * Note: This is copied from PageViewInfo\Hooks::onInfoAction, as it is doing exactly what we need.
	 *
	 * @param Title $title
	 * @return int -1 if the page views are not supported or unavailable,
	 *  or the total number of views over the last 30 days.
	 */
	private function getPageViews( Title $title ): int {
		if (
			!$this->pageViewService ||
			!$this->pageViewService->supports(
				PageViewService::METRIC_VIEW,
				PageViewService::SCOPE_ARTICLE
			)
		) {
			return -1;
		}

		$status = $this->pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		if ( !$status->isOK() ) {
			return -1;
		}
		$data = $status->getValue();
		$views = $data[$title->getPrefixedDBkey()];

		return array_sum( $views );
	}

	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/schema/PagesTitleSignalsSchema.json';
	}
}
