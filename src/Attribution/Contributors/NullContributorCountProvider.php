<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors;

use MediaWiki\Page\ExistingPageRecord;

/**
 * Null provider to satisfy AttributionDataBuilder
 */
class NullContributorCountProvider implements ContributorCountProvider {

	public function getContributorCounts( ExistingPageRecord $page ): ?array {
		return null;
	}
}
