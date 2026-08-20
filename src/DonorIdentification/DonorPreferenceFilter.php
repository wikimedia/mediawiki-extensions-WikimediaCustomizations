<?php
namespace MediaWiki\Extension\WikimediaCustomizations\DonorIdentification;

use MediaWiki\Preferences\Filter;

/**
 * Preferences filter for the wikimedia-donor toggle on Special:Preferences.
 *
 * The preference is stored as a JSON blob describing the donor's segment, but
 * it is presented to the user as a single checkbox that lets them unlink their
 * account from their donor status. Leaving the checkbox ticked keeps the stored
 * value untouched; unticking it clears the value to an empty string.
 *
 * Clearing is irreversible: once the value is empty the checkbox is no longer
 * offered (see DonorIdentificationHookHandler::onGetPreferences), so the user
 * cannot restore their donor status from here.
 */
class DonorPreferenceFilter implements Filter {

	/**
	 * @param string $storedValue The user's current (pre-save) donor preference
	 *   value, used to preserve the JSON blob when the checkbox stays ticked.
	 */
	public function __construct(
		private readonly string $storedValue
	) {
	}

	/**
	 * Convert the stored value into the checkbox state: ticked whenever a donor
	 * status is present.
	 *
	 * @param string $value
	 * @return bool
	 */
	public function filterForForm( $value ) {
		return $value !== '';
	}

	/**
	 * Convert the checkbox state back into a stored value: keep the original
	 * value when ticked, clear it when unticked.
	 *
	 * @param bool $value
	 * @return string
	 */
	public function filterFromForm( $value ) {
		return $value ? $this->storedValue : '';
	}
}
