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
		if ( count( $commonEditors ) < 2 ) {
			// At least 2 users are needed. In other cases, don't display the link
			return;
		}

		// Keep the order of URL parameters consistent, so that the links retain their visited status
		// regardless of the order in which users are passed to the hook (which is undefined)
		usort( $commonEditors, static fn ( $a, $b ) => $a->getId() <=> $b->getId() );

		$startTime = ConvertibleTimestamp::convert( TS::MW, $metadataItem->getFirstEditTimestamp() );
		$endTime = ConvertibleTimestamp::convert( TS::MW, $metadataItem->getLastEditTimestamp() );

		// editorinteract expects YYYYMMDD date format, which ConvertibleTimestamp doesn't support
		// the closest supported option is YYYYMMDDHHMMSS, so take first 8 characters
		$startTime = substr( $startTime, 0, 8 );
		$endTime = substr( $endTime, 0, 8 );

		$linkHref = 'https://sigma.toolforge.org/editorinteract.py' .
			'?server=' . WikiMap::getCurrentWikiId() .
			'&startdate=' . $startTime .
			'&enddate=' . $endTime;

		foreach ( $commonEditors as $user ) {
			$linkHref .= '&users=' . urlencode( $user->getName() );
		}

		$msgText = '<span ' .
		'class="mw-usertoollinks-interaction-timeline mw-checkuser-suggestedinvestigations-custom-instrument" ' .
		'data-subtype="interaction-timeline"' .
		'>[$1 $2]</span>';
		$newMessage = new RawMessage( $msgText, [ $linkHref, $originalMessage ] );
		$metadataItem->overrideMessage( $newMessage );
	}
}
