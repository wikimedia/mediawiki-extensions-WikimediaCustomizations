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
		private readonly ?PageViewService $pageViewService = null
	) {
		$this->dbname = $this->mainConfig->get( MainConfigNames::DBname );
	}

	public function getAttributionData(
		Title $title, ?ExistingPageRecord $page, array $metadata, array $paramsToExpand,
		Authority $authority
	): array {
		$base = $this->getEssential( $title, $page, $metadata, $authority );

		// TODO: Add back the ALLOWED_EXPAND_KEYS constant
		// See  https://gerrit.wikimedia.org/r/c/mediawiki/extensions/WikimediaCustomizations/+/1239925
		if ( in_array( 'trust_and_relevance', $paramsToExpand ) ) {
			$base[ 'trust_and_relevance' ] = $this->getTrustAndRelevance( $title, $metadata );
		}
		if ( in_array( 'calls_to_action', $paramsToExpand ) ) {
			$base[ 'calls_to_action' ] = $this->getCallsToAction( $title );
		}
		return $base;
	}

	/**
	 * Get the essential attribution fields
	 *
	 * @param Title $title The title of the wiki
	 * @param ?ExistingPageRecord $page The page of the resource
	 * @param array $metadata The page of the resource
	 * @param Authority $authority The current acting authority
	 * @return array The default essential attribution data
	 */
	private function getEssential(
		Title $title, ?ExistingPageRecord $page, array $metadata,
		Authority $authority
	): array {
		$essential = [ 'essential' => [
			'title' => $metadata['title'],
			'license' => $metadata['license'],
			'link' => $title->getCanonicalURL(),
			'default_brand_marks' => $this->getSiteBrandMarksObject( $title->getPageLanguage()->getCode() ),
			'source_wiki' => [
				'site_id' => $this->dbname,
				'site_language' => $this->mainConfig->get( MainConfigNames::LanguageCode ),
				'page_language' => $title->getPageLanguage()->getHtmlCode(),
			],
		] ];

		// If this is a file page, we'll add the author to the response.
		$file =
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->repoGroup->findFile( $page, [ 'private' => $authority ] ) ?: null;
		// TODO: Do a generalized media checks to not show citations and pageviews for files
		// Also confirm on other conditional files responses.
		if ( $file ) {
			$essential = $this->injectFileMetadata( $file, $essential );
		}
		return $essential;
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
			// Placeholder for the contributor counts, will be implemented in a future version.
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
		// For the moment, we'll get the icon logo, and fall back on 1x if the icon logo is not set.
		$logos = SkinModule::getAvailableLogos( $this->mainConfig, $langCode );
		if ( !$logos ) {
			return [];
		}

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
}
