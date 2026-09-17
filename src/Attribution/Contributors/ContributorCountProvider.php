<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors;

use MediaWiki\Page\ExistingPageRecord;

/**
 * Strategy interface for retrieving the contributor counts of a page.
 */
interface ContributorCountProvider {

	/**
	 * Retrieve the contributor counts for a given page.
	 *
	 * @return array{
	 *    total_unique: int,
	 *    logged_in_users: int,
	 *    unregistered_users: int,
	 *    known_bots: int
	 * }|null
	 */
	public function getContributorCounts( ExistingPageRecord $page ): ?array;
}
