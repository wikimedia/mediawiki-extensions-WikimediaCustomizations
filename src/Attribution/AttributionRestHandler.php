<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Message\Message;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Page\WikiPage;
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
use Wikimedia\Telemetry\TracerInterface;

/**
 * A handler that returns metadata attribution information about a page
 *
 * @package MediaWiki\Extension\WikimediaCustomizations\Attribution
 * @unstable
 */
class AttributionRestHandler extends SimpleHandler {

	private PageContentHelper $contentHelper;
	private string $dbname;

	public function __construct(
		private readonly Config $mainConfig,
		private readonly UrlUtils $urlUtils,
		private readonly RepoGroup $repoGroup,
		private readonly PageRestHelperFactory $helperFactory,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly TracerInterface $tracer,
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
			'/' . $this->getModule()->getPathPrefix() . $this->getPath(),
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
	 * @throws LocalizedHttpException
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
	 * @throws LocalizedHttpException
	 */
	public function run(): Response {
		// Check access and existence of the page
		$accessResultResponse = $this->checkPageAccess();
		if ( $accessResultResponse !== null ) {
			return $accessResultResponse;
		}
		$title = Title::newFromText( $this->contentHelper->getTitleText() );
		$span = $this->tracer->createSpan( 'Attribution RestEndpoint' )->start();

		$page = $this->contentHelper->getPage();
		Assert::invariant( $page !== null, 'Page should be known after checkPageAccess()' );
		$metadata = $this->contentHelper->constructMetadata();
		// Get params to be expanded
		$params = $this->getValidatedParams();
		$paramsToExpand = isset( $params['expand'] ) ? explode( ',', $params['expand'] ) : [];
		$span->setAttributes( [ 'title' => $title->getPrefixedText(), 'expand' => $paramsToExpand ] );

		// Get the attribution data
		// TODO: Spike in having the AttributionDataBuilder as a param passed to this class's
		// constructor thereby deprecating the  UrlUtils, RepoGroup and PageViewService which
		// are only used in this class to pass the the data builder
		$attributionDataBuilder = new AttributionDataBuilder(
			$this->mainConfig,
			$this->urlUtils,
			$this->repoGroup,
			$this->parserOutputAccess,
			WikiPage::makeParserOptionsFromTitleAndModel( $title, $title->getContentModel(), 'canonical' ),
			$this->tracer,
			$this->pageViewService
		);
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( RequestContext::getMain()->getLanguage() );
		$format = new FormatMetadata();
		$format->setContext( $context );

		$result = $attributionDataBuilder->getAttributionData(
			$title,
			$page,
			$metadata,
			$paramsToExpand,
			$this->getAuthority(),
			$format
		);
		// Add site_name to source_wiki since we don't have service container in the data builder
		$wikiName = $this->getWikiName( $title );
		$result['source_wiki']['site_name'] = $wikiName;
		$result['source_wiki']['project_family'] = $this->getProjectFamily();
		return $this->getResponseFactory()->createJson( $result );
	}

	/**
	 * Get the project family for the current wiki; "wikipedia", "wiktionary", "wikibooks", etc.
	 * Will return an empty string if the project name is not found.
	 *
	 * @return string The project name or an empty string if not found.
	 */
	private function getProjectFamily() {
		global $wgConf;
		[ $site, ] = $wgConf->siteFromDB( $this->dbname );
		return $site ?? '';
	}

	/**
	 * Get the wiki name from the Title
	 *
	 * @param Title $title The title of the wiki
	 * @return string The wiki name
	 */
	private function getWikiName( Title $title ): string {
		$wikiNameMessage = new Message(
			'project-localized-name-' . $this->dbname,
			[],
			$title->getPageLanguage()
		);
		// If we can't resolve the wiki name, just use an empty string
		$wikiName = !$wikiNameMessage->isBlank() ? $wikiNameMessage->plain() : '';
		return $wikiName;
	}

	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/schema/PagesTitleSignalsSchema.json';
	}
}
