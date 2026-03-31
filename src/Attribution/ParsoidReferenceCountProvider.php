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

	public function __construct(
		private readonly ParserOutputAccess $parserOutputAccess
	) {
	}

	public function getReferenceCount( ExistingPageRecord $page ): ?int {
		$parserOptions = $this->makeParserOptions( $page );
		$parserOptions->setUseParsoid( true );
		$parserOptions->setRenderReason( 'attribution' );

		$status = $this->parserOutputAccess->getParserOutput( $page, $parserOptions );
		if ( !$status->isOK() ) {
			return null;
		}

		return preg_match_all( '/\bid="cite_note-/', $status->getValue()->getContentHolderText() );
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
