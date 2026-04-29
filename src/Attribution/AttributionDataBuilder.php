<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Config\Config;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Media\FormatMetadata;
use MediaWiki\Message\Message;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\SkinModule;
use MediaWiki\Title\Title;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Telemetry\TracerInterface;

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
		private readonly TracerInterface $tracer,
		private readonly SiteConfiguration $siteConfig,
		private readonly LoggerInterface $logger,
		private readonly StatsFactory $stats,
		private readonly ReferenceCountProvider $referenceCountProvider,
		private readonly ?PageViewService $pageViewService = null
	) {
		$this->dbname = $this->mainConfig->get( MainConfigNames::DBname );
	}

	public function getAttributionData(
		Title $title, ExistingPageRecord $page, array $metadata, array $paramsToExpand,
		Authority $authority, FormatMetadata $format
	): array {
		$base = [];
		$base[ 'essential' ] = $this->getEssential( $title, $metadata );

		// Start conditional response based on whether this is as file or an article.
		// TODO: Do a generalized media checks to not show citations and pageviews for files
		// Also confirm on other conditional files responses.
		$file = $this->repoGroup->findFile( $page, [ 'private' => $authority ] ) ?: null;

		// If this is a file page, we'll inject file metadata into the essential response.
		if ( $file ) {
			$base = $this->injectFileMetadata( $file, $base, $format );
		}

		// TODO: Add back the ALLOWED_EXPAND_KEYS constant.
		// See  https://gerrit.wikimedia.org/r/c/mediawiki/extensions/WikimediaCustomizations/+/1239925
		if ( in_array( 'trust_and_relevance', $paramsToExpand ) ) {
			$base[ 'trust_and_relevance' ] = $this->getTrustAndRelevance( $page, $title, $metadata, $file );
		}
		if ( in_array( 'calls_to_action', $paramsToExpand ) ) {
			$base[ 'calls_to_action' ] = $this->getCallsToAction( $title );
		}

		$this->trackResponseData( $title, $base, $file, $paramsToExpand );

		return $base;
	}

	/**
	 * Emit metrics and log for any nullable fields that returned null.
	 * Intentionally missing data (e.g. contributor_counts) is excluded.
	 *
	 * @param Title $title The article we're building attribution data for
	 * @param array $base The built attribution data array
	 * @param File|null $file The file object if this is a file page, or null
	 * @param array $paramsToExpand The list of requested expansion keys
	 */
	private function trackResponseData(
		Title $title, array $base, ?File $file, array $paramsToExpand
	): void {
		$isArticleWithAttributionData = !$file && $this->includeExtendedAttribution( $title );

		$missingFields = [];
		// Article-only fields — only present when trust_and_relevance is expanded
		if ( $isArticleWithAttributionData && in_array( 'trust_and_relevance', $paramsToExpand ) ) {
			if ( ( $base['trust_and_relevance']['page_views'] ?? null ) === null ) {
				$missingFields[] = 'page_views';
			}
			if ( ( $base['trust_and_relevance']['reference_count'] ?? null ) === null ) {
				$missingFields[] = 'reference_count';
			}
		}

		// File-only fields
		if ( $file ) {
			if ( $base['essential']['credit'] === null ) {
				$missingFields[] = 'credit';
			}
			if ( ( $base['essential']['license']['title'] ?? null ) === null ) {
				$missingFields[] = 'license_title';
			}
			if ( ( $base['essential']['license']['url'] ?? null ) === null ) {
				$missingFields[] = 'license_url';
			}
		}

		foreach ( $missingFields as $field ) {
			$this->stats->getCounter( 'missing_data_total' )
				->setLabel( 'field', $field )
				->increment();
		}

		$counter = $this->stats->getCounter( 'request_total' );
		sort( $paramsToExpand );

		$counter->setLabel( 'missing_fields', (string)count( $missingFields ) );
		$counter->setLabel( 'expand', $paramsToExpand ? implode( ',', $paramsToExpand ) : 'none' );
		$counter->setLabel( 'media_file', $file ? '1' : '0' );
		$counter->increment();
	}

	/**
	 * Get the essential attribution fields.
	 *
	 * @param Title $title The title of the wiki
	 * @param array $metadata The page metadata
	 * @return array The default essential attribution data
	 */
	private function getEssential( Title $title, array $metadata ): array {
		return [
			'title' => $metadata['title'],
			'license' => $metadata['license'],
			'link' => $title->getCanonicalURL(),
			'default_brand_marks' => $this->getSiteBrandMarksObject( $title->getPageLanguage()->getCode() ),
			'source_wiki' => $this->buildSourceWiki( $title )
		];
	}

	/**
	 * Inject the Artist/License metadata into attribution data
	 */
	private function injectFileMetadata(
		File $file, array $base, FormatMetadata $format
	): array {
		/**
		 * Although it looks like $span is unused, we need to keep it as local variable
		 * as SPANs follow RAII, it's similar to ScopedCallback, where the span will end itself
		 * once it gets out of scope ( via __destruct ). This way once PHP goes out of scope, it
		 * will automatically end the span. This solves the issue of the span not being ended in
		 * case of early exits/exceptions/etc.
		 */
		$span = $this->tracer->createSpan( 'Attribution FileEssentials' )->start();
		$timing = $this->stats->getTiming( 'get_ext_metadata_duration' )->start();
		$extMeta = $this->getExtMetaData( $file, $format );

		$artist       = $this->getExtMetaValue( $extMeta, 'Artist' );
		$licenseTitle = $this->getExtMetaValue( $extMeta, 'LicenseShortName' );
		$licenseUrl   = $this->getExtMetaValue( $extMeta, 'LicenseUrl' );

		$base['essential']['credit'] = $artist;
		$base['essential']['license'] = [
			'title' => $licenseTitle,
			'url' => $licenseUrl,
		];
		$timing->setLabel( 'has_credit', $artist ? '1' : '0' );
		$timing->setLabel( 'has_license', $licenseTitle ? '1' : '0' );
		$timing->stop();

		return $base;
	}

	/**
	 * Get the source wiki attribution data
	 *
	 * @return array{
	 *     site_name: string,
	 *     project_family: string,
	 *     site_id: string,
	 *     site_language: string,
	 *     page_language: string
	 * }
	 */
	private function buildSourceWiki( Title $title ): array {
		// If we can't resolve the wiki name, just use an empty string
		$wikiNameMessage = new Message(
			'project-localized-name-' . $this->dbname,
			[],
			$title->getPageLanguage()
		);

		$wikiName = !$wikiNameMessage->isBlank() ? $wikiNameMessage->plain() : '';

		return [
			'site_name' => $wikiName,
			'project_family' => $this->getProjectFamily(),
			'site_id' => $this->dbname,
			'site_language' => $this->mainConfig->get( MainConfigNames::LanguageCode ),
			'page_language' => $title->getPageLanguage()->getHtmlCode(),
		];
	}

	/**
	 * Get the trust and relevance data.
	 *
	 * @return array The trust and relevance attribution data
	 */
	private function getTrustAndRelevance(
		ExistingPageRecord $page,
		Title $title,
		array $metadata,
		?File $file
	): array {
		$span = $this->tracer->createSpan( 'Attribution TrustAndRelevance' )->start();
		$trustAndRelevance = [
			'last_updated' => $metadata['latest']['timestamp']
		];
		// If this is an article we'll add the reference count, trending data, page views
		// and contributor counts.
		if ( !$file && $this->includeExtendedAttribution( $title ) ) {
			// Placeholder for the contributor counts will be implemented in a future version.
			$trustAndRelevance['contributor_counts'] = null;
			$trustAndRelevance['page_views'] = $this->getPageViews( $title );
			$trustAndRelevance['reference_count'] = $this->getReferenceCount( $page );
			// TEMPORARY: placeholder for demo purposes only. See: T419157
			$trustAndRelevance['trending'] = [
				'top' => [
					'read' => false,
					'edited' => false,
					'read_and_edited' => false,
				],
				'relative' => [
					'read' => false,
					'edited' => false,
					'read_and_edited' => false,
				],
			];
		}
		return $trustAndRelevance;
	}

	/**
	 * Get the calls to action attribution data.
	 *
	 * @return array The calls to action attribution data
	 */
	private function getCallsToAction( Title $title ): array {
		$callsToAction = [
			// TEMPORARY: placeholder for demo purposes only. See: T419157
			'donation_ctas' => [
				'default' => [
					'url' => 'https://donate.wikimedia.org/w/index.php?title=Special:LandingPage'
						. '&country=US&uselang=en&wmf_medium=sidebar&wmf_source=donate'
						. '&wmf_campaign=en.wikipedia.org',
					'link_text' => 'Donate to Wikipedia',
					'description' => 'Wikipedia is the backbone of the internet\'s knowledge.'
						. ' If everyone reading this gave just a few dollars, we\'d protect'
						. ' the future of free knowledge for everyone for years to come,'
						. ' in just a few hours.',
				],
				'foundation' => [
					'url' => 'https://donate.wikimedia.org',
					'link_text' => 'Support the Wikimedia Foundation',
					'description' => 'Wikimedia Foundation hosts the technology infrastructure'
						. ' that makes possible billions of visits to Wikipedia on a monthly basis.'
						. ' Since our founding in 2003, we have supported the hundreds of thousands'
						. ' of volunteer editors who edit, expand and curate the Wikimedia projects.',
				],
				'special' => [
					'url' => 'https://donate.wikipedia25.org/',
					'link_text' => 'Celebrate 25 years of free knowledge',
					'description' => 'After 25 years, Wikipedia is still here. What started as a'
						. ' wildly ambitious and probably impossible dream is now an essential'
						. ' knowledge resource for humanity—funded by readers like you and filled'
						. ' with knowledge shared by volunteers all over the world.',
					],
				]
			];
		if ( $this->includeExtendedAttribution( $title ) ) {
			// TEMPORARY: CTAs below have not yet been reviewed by owning teams. See: T419157
			$callsToAction['participation_ctas'] = [
				'download_app' => [
					// TEMPORARY: defaulting to Android; waiting on OS-aware link from apps team. See: T419157
					'url' => 'https://play.google.com/store/apps/details?id=org.wikipedia',
					'link_text' => 'Download the Wikipedia app',
					'description' => 'Download the free Wikipedia app for the best way to explore'
						. ' knowledge on the go. The app delivers a rich, smooth mobile experience'
						. ' with exclusive features designed to make discovering, reading, and'
						. ' engaging with the world\'s largest encyclopedia faster and more'
						. ' enjoyable than ever.',
				],
				'create_account' => [
					'url' => 'https://auth.wikimedia.org/enwiki/wiki/Special:CreateAccount',
					'link_text' => 'Create a Wikipedia account',
					'description' => 'Create a free account and get more out of Wikipedia!'
						. ' While anyone can browse and even edit without signing in, an account'
						. ' unlocks a richer experience for readers and gives contributors the'
						. ' ability to build a reputation, save their work, and have a real say'
						. ' in how the world\'s largest encyclopedia takes shape.',
				],
				'learn_more' => [
					// TEMPORARY: should resolve to localised version if possible. See: T419157
					'url' => 'https://en.wikipedia.org/wiki/Help:Introduction_to_Wikipedia',
					'link_text' => 'Learn more about Wikipedia',
					'description' => 'Wikipedia is a free encyclopedia, written collaboratively'
						. ' by the people who use it. Since 2001, it has grown rapidly to become'
						. ' the world\'s largest reference website. Come learn how you can help'
						. ' shape its content and protect its future.',
				],
			];
		}
		return $callsToAction;
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
	 * @return int|null null if the page views are not supported or unavailable,
	 *  or the total number of views over the last 30 days.
	 */
	private function getPageViews( Title $title ): ?int {
		if (
			!$this->pageViewService ||
			!$this->pageViewService->supports(
				PageViewService::METRIC_VIEW,
				PageViewService::SCOPE_ARTICLE
			)
		) {
			return null;
		}
		$timing = $this->stats->getTiming( 'get_pageviews_duration' )->start();
		$status = $this->pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		if ( !$status->isOK() ) {
			$timing->stop();
			$this->stats->getCounter( 'pageviews_not_available' )->increment();
			return null;
		}
		$data = $status->getValue();
		$views = $data[$title->getPrefixedDBkey()] ?? null;
		$timing->stop();
		return is_array( $views ) ? array_sum( $views ) : null;
	}

	/**
	 * Retrieves a sanitized value from the extmetadata array by key.
	 *
	 * Returns the plain-text value at `$extMeta[$key]['value']`, or null if the key
	 * is not present. Hidden elements (display:none) are removed before stripping to
	 * avoid machine-readable Commons template output being concatenated into the
	 * visible text (e.g. "Unknown authorUnknown author"). Uses Sanitizer::stripAllTags()
	 * which relies on a proper HTML tokenizer (RemexHtml) to correctly strip remaining
	 * tags and decode entities.
	 *
	 * @param array $extMeta Associative array of extmetadata entries, each containing a 'value' key
	 * @param string $key The metadata key to look up
	 * @return string|null The sanitized value, or null if the key is not present
	 * @see T418503 for more details about the null return value
	 * @see T420780
	 */
	private function getExtMetaValue( array $extMeta, string $key ): ?string {
		return isset( $extMeta[$key]['value'] )
			? Sanitizer::stripAllTags( $this->stripDisplayNoneElements( $extMeta[$key]['value'] ) )
			: null;
	}

	/**
	 * Strips hidden HTML elements injected by Commons templates for machine-readable
	 * watermarking purposes (e.g. {{Unknown|author}}, Module:TagQS).
	 *
	 * Commons emits three known patterns:
	 *   <span style="display: none;">...</span>  — used by {{Unknown}} and similar
	 *   <div style="display: none;">...</div>    — used by Module:TagQS / {{Artwork}}
	 *   <p style="display: none;">...</p>        — used by Module:TagQS / {{Artwork}}
	 *
	 * We match these as literal strings rather than regex, per the same approach
	 * used by Commons' own Module:TagQS (see removeTag function).
	 *
	 * @see https://commons.wikimedia.org/wiki/Module:TagQS
	 * @see T420780
	 */
	private function stripDisplayNoneElements( string $html ): string {
		// if $html has any opening and closing tag, assume it is HTML and count the metric
		// this is just for analytics, we don't need an exact match
		if ( strpos( $html, '<' ) !== false && strpos( $html, '</' ) !== false ) {
			$this->stats->getCounter( 'found_html_in_metadata' )->increment();
		}
		$removed = false;
		foreach ( [ 'span', 'div', 'p' ] as $tag ) {
			$open  = "<$tag style=\"display: none;\">";
			$close = "</$tag>";

			$start = strpos( $html, $open );
			while ( $start !== false ) {
				$end = strpos( $html, $close, $start );
				if ( $end === false ) {
					break;
				}
				$html = substr( $html, 0, $start )
					. substr( $html, $end + strlen( $close ) );
				$start = strpos( $html, $open );
				$removed = true;
			}
		}
		if ( $removed ) {
			$this->stats->getCounter( 'html_display_none_removed' )->increment();
		}
		return $html;
	}

	private function getExtMetaData( File $file, FormatMetadata $format ): array {
		$format->setSingleLanguage( true );
		return $format->fetchExtendedMetadata( $file );
	}

	/**
	 * Count the number of unique references on a page.
	 * Delegates to the injected {@see ReferenceCountProvider}.
	 *
	 * @return int|null The reference count, or null if the count cannot be determined.
	 */
	private function getReferenceCount( ExistingPageRecord $page ): ?int {
		$span = $this->tracer->createSpan( 'Attribution GetReferenceCount' )->start();
		return $this->referenceCountProvider->getReferenceCount( $page );
	}

	/**
	 * Get the project family for the current wiki; "wikipedia", "wiktionary", "wikibooks", etc.
	 * Will return an empty string if the project name is not found.
	 *
	 * @return string The project name or an empty string if not found.
	 */
	private function getProjectFamily() {
		[ $site, ] = $this->siteConfig->siteFromDB( $this->dbname );
		return $site ?? '';
	}

	/**
	 * Whether to lookup all the possible signals ( like contributor counts, trending, etc )
	 * Pages stored with different content models don't follow the traditional authorship model
	 */
	private function includeExtendedAttribution( Title $title ): bool {
		return $title->getContentModel() === CONTENT_MODEL_WIKITEXT;
	}
}
