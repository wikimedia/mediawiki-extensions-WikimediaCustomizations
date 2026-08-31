<?php
namespace MediaWiki\Extension\WikimediaCustomizations\DonorIdentification;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\TestKitchen\Sdk\ExperimentManager;
use MediaWiki\Hook\UserLoginCompleteHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;

class DonorIdentificationHookHandler implements
	GetPreferencesHook,
	LocalUserCreatedHook,
	UserLoginCompleteHook
{
	/**
	 * Name of the user option in which donor identification is stored
	 */
	private const DONOR_PREF = 'wikimedia-donor';

	/**
	 * Campaigns whose name begins with this prefix (or _are_ this prefix) mark the account as having arrived through
	 * the reader donor account creation flow
	 */
	private const CAMPAIGN_PREFIX = 'reader-donor-account';

	/** @inheritDoc */
	public function __construct(
		private readonly UserOptionsManager $userOptionsManager,
		private readonly ?ExperimentManager $experimentManager = null,
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

	/**
	 * Fetch the campaign submitted with the current request, if any.
	 */
	private function getCampaign(): ?string {
		return RequestContext::getMain()->getRequest()->getVal( 'campaign' );
	}

	/**
	 * Check the campaign value to see if this is a reader donor related campaign, then set the user's donor status as
	 * permissive if it's not already set
	 */
	public function setDonorStatusFromCampaign( User $user ): void {
		$campaign = $this->getCampaign();

		// if there is no campaign or we are not in a reader donor campaign, exit early
		if ( !$campaign || !str_starts_with( $campaign, self::CAMPAIGN_PREFIX ) ) {
			return;
		}

		// otherwise, if we are in a reader donor campaign and the user is currently opted out of donor ID, opt them in
		if ( $this->userOptionsManager->getOption( $user, self::DONOR_PREF ) === '' ) {
			// if we're in the experiment, attempt to include that information
			$experimentName = 'donor-status-consent';
			$experiment = $this->experimentManager?->getExperiment( $experimentName );
			$group = $experiment?->getAssignedGroup();

			$source = $group ? "$campaign/$experimentName/$group" : $campaign;

			$pref = json_encode( [
				'value' => 1,
				'timestamp' => (int)round( microtime( true ) * 1000 ),
				'source' => $source,
			] );

			$this->userOptionsManager->setOption( $user, self::DONOR_PREF, $pref, UserOptionsManager::GLOBAL_CREATE );
			$this->userOptionsManager->saveOptions( $user );
		}
	}

	/**
	 * Record the donor preference when a user logs in through a
	 * reader-donor-account campaign.
	 *
	 * @inheritDoc
	 */
	public function onUserLoginComplete( $user, &$inject_html, $direct ): void {
		$this->setDonorStatusFromCampaign( $user );
	}

	/**
	 * Record the donor preference when a user creates an account through a
	 * reader-donor-account campaign.
	 *
	 * @inheritDoc
	 */
	public function onLocalUserCreated( $user, $autocreated ): void {
		if ( $autocreated ) {
			return;
		}

		$this->setDonorStatusFromCampaign( $user );
	}
}
