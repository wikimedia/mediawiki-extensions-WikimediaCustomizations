<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution;

use MediaWiki\Page\ExistingPageRecord;

/**
 * Strategy interface for counting unique references on a page.
 */
interface ReferenceCountProvider {

	/**
	 * Count the number of unique references on a page by looking for occurrences
	 * of 'id="cite_note-' in the page HTML.
	 *
	 * @return ReferenceCountResult The reference count with metadata whether was a cache-hit,
	 * miss or error
	 */
	public function getReferenceCount( ExistingPageRecord $page ): ReferenceCountResult;
}
