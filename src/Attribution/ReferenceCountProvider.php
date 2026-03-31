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
	 * @return int|null The reference count, or null if the count cannot be determined.
	 */
	public function getReferenceCount( ExistingPageRecord $page ): ?int;
}
