<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Discord;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Content\WikiTextStructure;
use MediaWiki\Extension\ParserMigration\Oracle;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageProps;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Handler\Helper\PageContentHelper;
use MediaWiki\Rest\Handler\Helper\PageRestHelperFactory;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * A handler that returns preview information for Discord about a page
 *
 * @package MediaWiki\Extension\WikimediaCustomizations\Discord
 * @unstable
 */
class DiscordPreviewRestHandler extends SimpleHandler {
	/** Bump to invalidate cached extracts when the extract format changes */
	private const CACHE_VERSION = 1;

	private PageContentHelper $contentHelper;

	public function __construct(
		private readonly PageRestHelperFactory $helperFactory,
		private readonly TitleFactory $titleFactory,
		private readonly UrlUtils $urlUtils,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly PageProps $pageProps,
		private readonly RepoGroup $repoGroup,
		private readonly WANObjectCache $cache,
		private readonly Config $config,
		private readonly ?Oracle $parserMigrationOracle,
	) {
		$this->contentHelper = $helperFactory->newPageContentHelper();
	}

	/**
	 * Escapes special characters in the given text to prevent unintended formatting in Discord's Markdown.
	 * @param string $text The plaintext to escape
	 * @return string The escaped text
	 */
	private function escapeMarkdown( $text ) {
		$charactersToEscape = [ '\\', '*', '_', '~', '`', '|', '#', '>', '[', ']' ];
		$escapedText = str_replace( $charactersToEscape, array_map( static function ( $char ) {
			return '\\' . $char;
		}, $charactersToEscape ), $text );
		return $escapedText;
	}

	/**
	 * Fetches the plain-text extract for the page, memoized in WANObjectCache
	 * so that repeat requests for the same rendering of a page skip the
	 * HTML-to-text pass
	 * @param ExistingPageRecord $page The page to extract from
	 * @return string|null The extract, or null if none could be produced
	 */
	private function fetchExtract( ExistingPageRecord $page ): ?string {
		$extract = $this->cache->getWithSetCallback(
			// page_touched changes whenever the page must be re-rendered
			// (edits, template changes, purges), so each rendering gets its
			// own key and superseded entries just age out via the TTL
			$this->cache->makeKey(
				'WikimediaCustomizations',
				'discord-preview-extract',
				self::CACHE_VERSION,
				$page->getId(),
				$page->getTouched()
			),
			WANObjectCache::TTL_DAY,
			function () use ( $page ): string {
				// "No extract" must be cached too; '' represents it because
				// WANObjectCache reserves false as its cache-miss value
				return $this->buildExtract( $page ) ?? '';
			}
		);
		return $extract === '' ? null : $extract;
	}

	/**
	 * Builds a plain-text extract from the page's rendered HTML
	 * @param ExistingPageRecord $page The page to extract from
	 * @return string|null The extract, or null if none could be produced
	 */
	private function buildExtract( ExistingPageRecord $page ): ?string {
		$parserOptions = ParserOptions::newFromAnon();
		// Follow the parser that ParserMigration selects for anonymous
		// article views, so that on Parsoid-default wikis this reuses the
		// ParserCache entry those views populate instead of triggering a
		// redundant legacy parse
		if ( $this->parserMigrationOracle ) {
			$title = $this->titleFactory->newFromPageIdentity( $page );
			if ( $title->hasContentModel( CONTENT_MODEL_WIKITEXT ) &&
				$this->parserMigrationOracle->isParsoidDefaultFor( $title )
			) {
				$parserOptions->setUseParsoid();
			}
		}
		$status = $this->parserOutputAccess->getParserOutput(
			$page,
			$parserOptions,
			null,
			ParserOutputAccess::OPT_FOR_ARTICLE_VIEW
		);
		if ( !$status->isOK() ) {
			return null;
		}
		try {
			$structure = new WikiTextStructure( $status->getValue() );
			// Pages without any section heading have no opening text
			$extract = $structure->getOpeningText() ?? $structure->getMainText();
		} catch ( LogicException ) {
			// Thrown when the ParserOutput has no body HTML
			return null;
		}
		if ( $extract === '' ) {
			return null;
		}
		if ( mb_strlen( $extract ) > 350 ) {
			$extract = mb_substr( $extract, 0, 349 ) . '…';
		}
		return $extract;
	}

	/**
	 * Resolves the page image to a thumbnail URL
	 * @param ExistingPageRecord $page The page whose image to look up
	 * @return string|null Fully-qualified thumbnail URL, or null if the page has no usable image
	 */
	private function fetchThumbnailUrl( ExistingPageRecord $page ): ?string {
		// The lead image PageImages chose, restricted to the free-licensed
		// selection: non-free files may not be reused outside their articles
		$props = $this->pageProps->getProperties( $page, 'page_image_free' );
		$fileName = $props[$page->getId()] ?? null;
		if ( $fileName === null ) {
			return null;
		}
		$file = $this->repoGroup->findFile( $fileName );
		if ( !$file ) {
			return null;
		}
		// Production rejects non-standard thumbnail widths; 500 is in the
		// standard set (T414805)
		$thumb = $file->transform( [ 'width' => 500 ] );
		if ( !$thumb || $thumb->isError() ) {
			return null;
		}
		return $this->urlUtils->expand( $thumb->getUrl(), PROTO_CANONICAL );
	}

	public function run(): Response {
		if ( !$this->config->get( 'WMCDiscordPreviewEnabled' ) ) {
			return $this->getResponseFactory()
				->createHttpError( 404, [ 'message' => 'This feature is currently disabled' ] );
		}

		$params = $this->getValidatedParams();

		$titleString = $params['title'];

		$authority = $this->getAuthority();
		$this->contentHelper->init( $authority, [ "title" => $titleString ] );
		$this->contentHelper->checkAccess();
		$page = $this->contentHelper->getPage();
		if ( !$page ) {
			return $this->getResponseFactory()->createHttpError( 404 );
		}
		$title = $this->titleFactory->newFromPageIdentity( $page );
		$url = $title->getFullUrl();
		$formattedTitle = $title->getPrefixedText();

		$extract = $this->escapeMarkdown( $this->fetchExtract( $page ) ?? '' );
		$thumbnail = $this->fetchThumbnailUrl( $page );

		// Tack the wprov tracking param onto the URL, preserving any query params
		$wprov = $params['wprov'] ?? null;
		if ( $wprov !== null && $wprov !== '' ) {
			$parsedUrl = $this->urlUtils->parse( $url );
			if ( $parsedUrl !== null ) {
				$query = wfCgiToArray( $parsedUrl['query'] ?? '' );
				$query['wprov'] = $wprov;
				$parsedUrl['query'] = wfArrayToCgi( $query );
				$url = $this->urlUtils->assemble( $parsedUrl );
			}
		}

		$components = [];
		// An unencoded ')' in the URL would end the markdown link early
		$markdownUrl = str_replace( [ '(', ')' ], [ '%28', '%29' ], $url );
		$markdownTitle = $this->escapeMarkdown( $formattedTitle );

		$markdown = "# [$markdownTitle]($markdownUrl)\n\n$extract";
		$components[] = [ "type" => 10, "content" => $markdown ];
		if ( $thumbnail !== null ) {
			$components[] = [ "type" => 12, "items" => [ "media" => [ "url" => $thumbnail ] ] ];
		}

		$container = [ "type" => 17, "components" => $components ];

		$response = $this->getResponseFactory()->createJson( $container );

		$response->setHeader( 'Cache-Control', 'public, s-maxage=3600, max-age=300' );
		return $response;
	}

	public function getParamSettings(): array {
		return [
			'title' => [
				Handler::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'wprov' => [
				Handler::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			]
		];
	}
}
