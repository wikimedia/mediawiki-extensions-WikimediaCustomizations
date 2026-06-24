<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaCustomizations\SuggestedInvestigations;

use MediaWiki\Extension\CheckUser\Hook\CheckUserSuggestedInvestigationsCaseMetadataBeforeDisplayHook;
use MediaWiki\Extension\CheckUser\SuggestedInvestigations\Model\SuggestedInvestigationsSharedPagesSummary;
use MediaWiki\Language\RawMessage;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

class SuggestedInvestigationsHookHandler implements CheckUserSuggestedInvestigationsCaseMetadataBeforeDisplayHook {

	/** @inheritDoc */
	public function onCheckUserSuggestedInvestigationsCaseMetadataBeforeDisplay(
		int $caseId,
		array &$metadata
	): void {
		foreach ( $metadata as $item ) {
			if ( $item instanceof SuggestedInvestigationsSharedPagesSummary ) {
				$this->adjustSharedPagesMetadata( $item );
			}
		}
	}

	private function adjustSharedPagesMetadata( SuggestedInvestigationsSharedPagesSummary $metadataItem ): void {
		$originalMessage = $metadataItem->getMessage();
		if ( $originalMessage === null ) {
			// Nothing is being displayed for this item, so there is nothing to link.
			return;
		}
		$commonEditors = $metadataItem->getCommonEditors();
		if ( count( $commonEditors ) !== 2 ) {
			// Interaction timeline supports only exactly 2 users. In other cases, don't display the link
			return;
		}

		// Keep the order of URL parameters consistent, so that the links retain their visited status
		// regardless of the order in which users are passed to the hook (which is undefined)
		usort( $commonEditors, static fn ( $a, $b ) => $a->getId() <=> $b->getId() );
		$user1 = $commonEditors[0]->getName();
		$user2 = $commonEditors[1]->getName();

		$startTime = ConvertibleTimestamp::convert( TS::UNIX, $metadataItem->getFirstEditTimestamp() );
		$endTime = ConvertibleTimestamp::convert( TS::UNIX, $metadataItem->getLastEditTimestamp() );

		$linkHref = 'https://interaction-timeline.toolforge.org/' .
			'?wiki=' . WikiMap::getCurrentWikiId() .
			'&user=' . urlencode( $user1 ) .
			'&user=' . urlencode( $user2 ) .
			'&startDate=' . $startTime .
			'&endDate=' . $endTime;
		$newMessage = new RawMessage( '[$1 $2]', [ $linkHref, $originalMessage ] );
		$metadataItem->overrideMessage( $newMessage );
	}
}
