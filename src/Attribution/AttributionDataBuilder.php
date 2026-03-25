<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Config\Config;
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
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly ParserOptions $parserOptions,
		private readonly TracerInterface $tracer,
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
			$base[ 'trust_and_relevance' ] = $this->getTrustAndRelevance( $metadata );

			// If this is an article we'll add the reference count, trending data, page views
			// and contributor counts.
			if ( !$file ) {
				// Placeholder for the contributor counts will be implemented in a future version.
				$base['trust_and_relevance']['contributor_counts'] = null;
				$base['trust_and_relevance']['page_views'] = $this->getPageViews( $title );
				$base['trust_and_relevance']['reference_count'] = $this->getReferenceCount( $page );
				// TEMPORARY: placeholder for demo purposes only. See: T419157
				$base['trust_and_relevance']['trending'] = [
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
		}
		if ( in_array( 'calls_to_action', $paramsToExpand ) ) {
			$base[ 'calls_to_action' ] = $this->getCallsToAction();
		}

		return $base;
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

		$extMeta = $this->getExtMetaData( $file, $format );

		$artist       = $this->getExtMetaValue( $extMeta, 'Artist' );
		$licenseTitle = $this->getExtMetaValue( $extMeta, 'LicenseShortName' );
		$licenseUrl   = $this->getExtMetaValue( $extMeta, 'LicenseUrl' );

		$base['essential']['credit'] = $artist;
		$base['essential']['license'] = [
			'title' => $licenseTitle,
			'url' => $licenseUrl,
		];
		return $base;
	}

	/**
	 * Get the trust and relevance data.
	 *
	 * @param array $metadata the content metadata
	 * @return array The trust and relevance attribution data
	 */
	private function getTrustAndRelevance( array $metadata ): array {
		$span = $this->tracer->createSpan( 'Attribution TrustAndRelevance' )->start();
		return [
			'last_updated' => $metadata['latest']['timestamp']
		];
	}

	/**
	 * Get the calls to action attribution data.
	 *
	 * @return array The calls to action attribution data
	 */
	private function getCallsToAction(): array {
		// TEMPORARY: placeholder for demo purposes only. See: T419157
		$donationCtas = [
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
		];

		// TEMPORARY: CTAs below have not yet been reviewed by owning teams. See: T419157
		$participationCtas = [
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

		return [
			'donation_ctas' => $donationCtas,
			'participation_ctas' => $participationCtas,
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

		$status = $this->pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		if ( !$status->isOK() ) {
			return null;
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

	private function getExtMetaData( File $file, FormatMetadata $format ): array {
		$format->setSingleLanguage( true );
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
		$span = $this->tracer->createSpan( 'Attribution GetReferenceCount' )->start();

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
