<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use FlaggableWikiPage;
use MediaWiki\Extension\FlaggedRevs\Backend\FlaggedRevsParserCacheFactory;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * Counts unique references by reading from the FlaggedRevs stable parser cache.
 *
 * On wikis using FlaggedRevs stable-by-default (e.g. dewiki, ruwiki), the stable
 * revision is served from a separate FlaggedRevs parser cache. On a cache miss,
 * null is returned because FlaggedRevs does not trigger an automatic parse.
 * See T421011, T420024.
 *
 * For pages not served from the FlaggedRevs stable cache, this provider delegates
 * to the fallback provider (typically {@see ParsoidReferenceCountProvider}).
 */
class FlaggedRevsReferenceCountProvider implements ReferenceCountProvider {

	public const PROVIDER_SHORT_NAME = 'flaggedrevs';

	public function __construct(
		private readonly FlaggedRevsParserCacheFactory $flaggedRevsParserCacheFactory,
		private readonly ReferenceCountProvider $fallbackProvider
	) {
	}

	public function getReferenceCount( ExistingPageRecord $page ): ReferenceCountResult {
		if ( !$this->pageUsesFlaggedRevsStable( $page ) ) {
			return $this->fallbackProvider->getReferenceCount( $page );
		}

		$parserOptions = $this->makeParserOptions( $page );
		// stable-parsoid-pcache is not warmed by FlaggablePageView (T421011),
		// so Parsoid must NOT be enabled when querying the FlaggedRevs cache.
		$parserOptions->setUseParsoid( false );
		$parserOptions->setRenderReason( 'attribution' );

		$parserCache = $this->flaggedRevsParserCacheFactory->getParserCache( $parserOptions );
		$parserOutput = $parserCache->get( $page, $parserOptions );
		if ( $parserOutput !== false ) {
			// The legacy parser encodes '_' as '&#95;' in id attributes; decode before matching.
			$html = html_entity_decode( $parserOutput->getContentHolderText(), ENT_QUOTES | ENT_HTML5 );
			return new ReferenceCountResult(
				preg_match_all( '/\bid="cite_note-/', $html ),
				self::PROVIDER_SHORT_NAME,
				ReferenceCountResult::CACHE_HIT
			);
		}
		// Cache miss: unlike Parsoid, FlaggedRevs cache does not trigger a parse on miss.
		return new ReferenceCountResult(
			null,
			self::PROVIDER_SHORT_NAME,
			ReferenceCountResult::CACHE_MISS
		);
	}

	/**
	 * Extracted into a protected method so it can be overridden in tests.
	 *
	 * @codeCoverageIgnore Covered by integration tests.
	 */
	protected function makeParserOptions( ExistingPageRecord $page ): ParserOptions {
		$title = Title::newFromPageIdentity( $page );
		return WikiPage::makeParserOptionsFromTitleAndModel( $title, $title->getContentModel(), 'canonical' );
	}

	/**
	 * Returns whether the given page is served from the FlaggedRevs stable parser cache by default.
	 * Extracted into a protected method so it can be overridden in tests.
	 *
	 * Note: we intentionally do NOT check revsArePending(). When pending revisions exist,
	 * readers still see the stable (reviewed) revision — which is exactly the revision whose
	 * reference count we want to report. Falling back to Parsoid in that case would yield the
	 * count for the latest unreviewed revision, which is not what readers see.
	 *
	 * @codeCoverageIgnore Covered by integration tests; FlaggedRevs is an optional dependency.
	 */
	protected function pageUsesFlaggedRevsStable( ExistingPageRecord $page ): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'FlaggedRevs' ) ) {
			return false;
		}
		$fwp = FlaggableWikiPage::getTitleInstance( $page );
		return $fwp->getStable() && $fwp->isStableShownByDefault();
	}
}
