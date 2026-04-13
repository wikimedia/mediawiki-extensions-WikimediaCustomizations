<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Language\Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Handler\Helper\PageContentHelper;
use MediaWiki\Rest\Handler\Helper\PageRedirectHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\ResponseHeaders;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
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
	private const MAX_AGE_200 = 3600;

	public function __construct(
		private readonly PageRestHelperFactory $helperFactory,
		private readonly AttributionDataBuilder $attributionDataBuilder,
		private readonly Language $contentLanguage,
		private readonly TracerInterface $tracer,
		private ?LoggerInterface $logger = null,
	) {
		$this->contentHelper = $helperFactory->newPageContentHelper();
		$this->logger = $logger ?? LoggerFactory::getInstance( 'Attribution' );
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
	 * @throws LocalizedHttpException
	 */
	private function checkPageAccess(): ?Response {
		try {
			$this->contentHelper->checkAccess();
		} catch ( LocalizedHttpException $e ) {
			$this->logger->warning( 'Attribution request failed', [
				'message' => $e->getMessage(),
				'code' => $e->getCode(),
				'exception' => $e
			] );
			throw $e;
		}
		$pageIdentity = $this->contentHelper->getPageIdentity();

		$followWikiRedirects = $this->contentHelper->getRedirectsAllowed();
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
		$span->setAttributes( [
			'title' => $title->getPrefixedText(),
			'expand' => implode( ',', $paramsToExpand )
		] );

		// Get the attribution data
		// TODO: Spike in having the AttributionDataBuilder as a param passed to this class's
		// constructor thereby deprecating the  UrlUtils, RepoGroup and PageViewService which
		// are only used in this class to pass the the data builder
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( $this->contentLanguage );
		$format = new FormatMetadata();
		$format->setContext( $context );

		$result = $this->attributionDataBuilder->getAttributionData(
			$title,
			$page,
			$metadata,
			$paramsToExpand,
			$this->getAuthority(),
			$format
		);
		$response = $this->getResponseFactory()->createJson( $result );
		if ( !$this->getSession()->isPersistent() ) {
			$response->setHeader(
				ResponseHeaders::CACHE_CONTROL,
				'public, max-age=' . self::MAX_AGE_200 . ', s-maxage=' . self::MAX_AGE_200
			);
		}
		return $response;
	}

	protected function getResponseBodySchemaFileName( string $method ): ?string {
		return __DIR__ . '/schema/PagesTitleSignalsSchema.json';
	}
}
