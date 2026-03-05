<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;

/**
 * Builds attribution information about a page returned by the Attribution
 * handler
 */
class AttributionDataBuilder {

	private string $dbname;

	public function __construct(
		private readonly Config $mainConfig,
		private readonly UrlUtils $urlUtils,
		private readonly RepoGroup $repoGroup,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly ParserOptions $parserOptions,
		private readonly ?PageViewService $pageViewService = null
	) {
		$this->dbname = $this->mainConfig->get( MainConfigNames::DBname );
	}

	public function getAttributionData(
		Title $title, ExistingPageRecord $page, array $metadata, array $paramsToExpand,
		Authority $authority
	): array {
		$base = [];
		$base[ 'essential' ] = $this->getEssential( $title, $metadata );

		// Start conditional response based on whether this is as file or an article.
		// TODO: Do a generalized media checks to not show citations and pageviews for files
		// Also confirm on other conditional files responses.
		$file = $this->repoGroup->findFile( $page, [ 'private' => $authority ] ) ?: null;

		// If this is a file page, we'll inject file metadata into the essential response.
		if ( $file ) {
			$base = $this->injectFileMetadata( $file, $base );
		}

		// TODO: Add back the ALLOWED_EXPAND_KEYS constant.
		// See  https://gerrit.wikimedia.org/r/c/mediawiki/extensions/WikimediaCustomizations/+/1239925
		if ( in_array( 'trust_and_relevance', $paramsToExpand ) ) {
			$base[ 'trust_and_relevance' ] = $this->getTrustAndRelevance( $title, $metadata );

			// If this is an article we'll add the reference count.
			if ( !$file ) {
				$base['trust_and_relevance']['reference_count'] = $this->getReferenceCount( $page );
			}
		}
		if ( in_array( 'calls_to_action', $paramsToExpand ) ) {
			$base[ 'calls_to_action' ] = $this->getCallsToAction( $title );
		}

		return $base;
	}

	/**
	 * Get the essential attribution fields.
	 *
	 * @param Title $title The title of the wiki
	 * @param array $metadata The page of the resource
	 * @return array The default essential attribution data
	 */
	private function getEssential( Title $title, array $metadata ): array {
		return [
			'title' => $metadata['title'],
			'license' => $metadata['license'],
			'link' => $title->getCanonicalURL(),
			'default_brand_marks' => $this->getSiteBrandMarksObject( $title->getPageLanguage()->getCode() ),
			'source_wiki' => [
				'site_id' => $this->dbname,
				'site_language' => $this->mainConfig->get( MainConfigNames::LanguageCode ),
				'page_language' => $title->getPageLanguage()->getHtmlCode(),
			],
		];
	}

	/**
	 * Inject the Artist/License metadata into attribution data
	 */
	private function injectFileMetadata( File $file, array $essential ): array {
		$extMeta = $this->getExtMetaData( $file );

		$artist       = $this->getExtMetaValue( $extMeta, 'Artist' );
		$licenseTitle = $this->getExtMetaValue( $extMeta, 'LicenseShortName' );
		$licenseUrl   = $this->getExtMetaValue( $extMeta, 'LicenseUrl' );

		$essential['essential']['author'] = $artist;
		$essential['essential']['license'] = [
			'title' => $licenseTitle,
			'url' => $licenseUrl,
		];
		return $essential;
	}

	/**
	 * Get the trust and relevance data.
	 *
	 * @param Title $title The title of the wiki
	 * @param array $metadata
	 * @return array The trust and relevance attribution data
	 */
	private function getTrustAndRelevance( Title $title, array $metadata ): array {
		return [
			'last_modified' => $metadata['latest']['timestamp'],
			'page_views' => $this->getPageViews( $title ),
			// Placeholder for the contributor counts will be implemented in a future version.
			'contributor_counts' => 0,
		];
	}

	/**
	 * Get the trust and relevance data.
	 *
	 * @param Title $title The title of the wiki
	 * @return array The calls to action attribution data
	 */
	private function getCallsToAction( Title $title ): array {
		$talkPage = $title->getTalkPageIfDefined();
		return [
			'donation_cta' => [
				'default' => 'https://donate.wikimedia.org',
				'foundation' => 'https://donate.wikimedia.org',
				'special' => 'https://donate.wikipedia25.org/',
			],
			'participation_cta' => [
				'talk_page' => $talkPage ? $talkPage->getCanonicalURL() : '',
			],
		];
	}

	/**
	 * Get the site brand marks for a given title and create the response object.
	 *
	 * @param string $langCode The language code of the page
	 */
	private function getSiteBrandMarksObject( string $langCode ): array {
		// Get the site brand mark logo from the config
		// For the moment, we'll get the icon logo and fall back on 1x if the icon logo is not set.
		$logos = SkinModule::getAvailableLogos( $this->mainConfig, $langCode );
		if ( !$logos ) {
			return [];
		}

		// Collect site brand marks into an array
		$brandMarks = [
			[
				'name' => 'Default logo',
				// 1x version always exists and falls back on config if not set
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
			'url' => $this->mainConfig->get( 'WMCAttributionBrandmarkUrl' ),
			'type' => 'audio',
		];

		return $brandMarks;
	}

	/**
	 * Get the page views for a given title, summed over the last 30 days.
	 * Note: This is copied from PageViewInfo\Hooks::onInfoAction, as it is doing exactly what we need.
	 *
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

	/**
	 * Retrieves a sanitized value from the extmetadata array by key.
	 *
	 * Returns the plain-text value at `$extMeta[$key]['value']`, or null if the key
	 * is not present. Uses Sanitizer::stripAllTags() which relies on a proper HTML
	 * tokenizer (RemexHtml) to correctly strip tags and decode entities, avoiding
	 * the pitfalls of strip_tags().
	 *
	 * @param array $extMeta Associative array of extmetadata entries, each containing a 'value' key
	 * @param string $key The metadata key to look up
	 * @return string|null The sanitized value, or null if the key is not present
	 * @see T418503 for more details about the null return value
	 */
	private function getExtMetaValue( array $extMeta, string $key ): ?string {
		return isset( $extMeta[$key]['value'] )
			? Sanitizer::stripAllTags( $extMeta[$key]['value'] )
			: null;
	}

	private function getExtMetaData( File $file ): array {
		$format = new FormatMetadata();
		$format->setSingleLanguage( true );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'en' );
		$format->setContext( $context );

		return $format->fetchExtendedMetadata( $file );
	}

	/**
	 * Count the number of unique references on a page by fetching its Parsoid HTML and counting
	 * occurrences of 'id="cite_note-'. Each unique reference in the footnotes section gets a
	 * single element with this ID pattern, so this counts unique sources rather than the total
	 * number of inline citations (which may cite the same source multiple times).
	 *
	 * @return int|null The reference count, or null if the count cannot be determined.
	 */
	private function getReferenceCount( ExistingPageRecord $page ): ?int {
		$this->parserOptions->setUseParsoid( true );
		$this->parserOptions->setRenderReason( 'attribution' );

		// Note: on wikis using FlaggedRevisions (e.g. dewiki, ruwiki), this returns the latest
		// revision's output rather than the stable (reader-visible) one. The count may therefore
		// differ from what readers see if there are pending unreviewed edits. See T414359, T322426.
		$status = $this->parserOutputAccess->getParserOutput( $page, $this->parserOptions );
		if ( !$status->isOK() ) {
			return null;
		}

		$html = $status->getValue()->getContentHolderText();
		return preg_match_all( '/\bid="cite_note-/', $html );
	}
}
