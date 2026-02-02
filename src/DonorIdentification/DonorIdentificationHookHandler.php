<?php
namespace MediaWiki\Extension\WikimediaCustomizations\DonorIdentification;

use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\User;

class DonorIdentificationHookHandler implements
	GetPreferencesHook
{

	/**
	 * Checks if the current value of the donor preference is a valid one.
	 * @return bool
	 */
	public static function validateDonorPreferenceValue( string $prefValue ) {
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
		$prefs += [
			'wikimedia-donor' => [
				'type' => 'hidden',
				'validation-callback' => [ self::class, 'validateDonorPreferenceValue' ],
			],
		];
	}
}
