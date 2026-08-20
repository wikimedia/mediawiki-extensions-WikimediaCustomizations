<?php
namespace MediaWiki\Extension\WikimediaCustomizations\DonorIdentification;

use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;

class DonorIdentificationHookHandler implements
	GetPreferencesHook
{
	private const DONOR_PREF = 'wikimedia-donor';

	/** @inheritDoc */
	public function __construct(
		private readonly UserOptionsManager $userOptionsManager
	) {
	}

	/**
	 * Checks if the current value of the donor preference is a valid one.
	 * @return bool
	 */
	public static function validateDonorPreferenceValue( string $prefValue ) {
		if ( $prefValue === '' ) {
			return true;
		}
		$decoded = json_decode( $prefValue, true );
		if ( $decoded && isset( $decoded['value'] ) ) {
			$value = $decoded['value'];
			return is_int( $value ) && $decoded['value'] >= 0;
		}
		return false;
	}

	/**
	 * @param User $user user
	 * @param array &$prefs array of preference rows
	 */
	public function onGetPreferences( $user, &$prefs ): void {
		$donorStatus = (string)$this->userOptionsManager->getOption( $user, self::DONOR_PREF );

		if ( $donorStatus === '' ) {
			// The user has not consented to donor identification, so there is
			// nothing to show. The preference is still registered (but never
			// displayed) so the client-side consent flow can write to it via
			// the options API.
			$prefs[self::DONOR_PREF] = [
				'type' => 'api',
				'validation-callback' => [ self::class, 'validateDonorPreferenceValue' ],
			];
			return;
		}

		// The user has a donor status: offer a checkbox to unlink their account
		// from it. The filter keeps the stored value when the box stays ticked
		// and clears it to an empty string when unticked. Clearing is
		// irreversible - once empty the checkbox is no longer shown.
		$prefs[self::DONOR_PREF] = [
			'type' => 'toggle',
			'section' => 'personal/email/donor',
			'label-message' => 'wikimediacustomizations-donor-identify-label',
			'help-message' => 'wikimediacustomizations-donor-identify-help',
			'default' => true,
			'filter' => new DonorPreferenceFilter( $donorStatus ),
		];
	}
}
