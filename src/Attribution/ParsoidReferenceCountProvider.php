<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

/**
 * Counts unique references by reading from the Parsoid parser cache.
 * On a cache miss, Parsoid automatically triggers a parse.
 */
class ParsoidReferenceCountProvider implements ReferenceCountProvider {

	public const PROVIDER_SHORT_NAME = 'parsoid';

	public function __construct(
		private readonly ParserOutputAccess $parserOutputAccess
	) {
	}

	public function getReferenceCount( ExistingPageRecord $page ): ReferenceCountResult {
		$parserOptions = $this->makeParserOptions( $page );
		$parserOptions->setUseParsoid( true );
		$parserOptions->setRenderReason( 'attribution' );
		$operation = ReferenceCountResult::CACHE_HIT;
		$cached = $this->parserOutputAccess->getCachedParserOutput( $page, $parserOptions );
		if ( $cached === null ) {
			$operation = ReferenceCountResult::CACHE_MISS;
			// skip cache check as we already know the page is not cached
			$status = $this->parserOutputAccess->getParserOutput( $page, $parserOptions, null, [
				ParserOutputAccess::OPT_NO_CHECK_CACHE => true,
			] );
			if ( !$status->isOK() ) {
				return new ReferenceCountResult(
					null,
					self::PROVIDER_SHORT_NAME,
					ReferenceCountResult::ERROR
				);
			}
			$html = $status->getValue()->getContentHolderText();

		} else {
			$html = $cached->getContentHolderText();
		}
		return new ReferenceCountResult(
			preg_match_all( '/\bid="cite_note-/', $html ),
			self::PROVIDER_SHORT_NAME,
			$operation
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
}
