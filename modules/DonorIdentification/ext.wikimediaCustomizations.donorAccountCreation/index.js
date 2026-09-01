const donor = require( 'ext.wikimediaCustomizations.donor' );
const EXPERIMENT_NAME = 'donor-status-consent';
// Default campaign used to attribute account creations from this dialog.
const DEFAULT_CAMPAIGN = 'reader-donor-account';
const STORAGE_KEY_SUPPRESS_OVERLAY = 'wc-donor-account-creation-suppress-consent-overlay';

async function getVariantGroup() {
	const experiment = await mw.testKitchen.getExperiment( EXPERIMENT_NAME );
	return experiment.getAssignedGroup();
}

async function canCreateAccount() {
	const api = new mw.Api();
	return api.ajax( {
		action: 'query',
		meta: 'userinfo',
		uiprop: 'blockinfo|cancreateaccount',
		format: 'json',
		formatversion: 2
	} ).then( ( { query } ) => {
		const ui = query ? query.userinfo : { cancreateaccount: true };
		return ui.cancreateaccount;
	} );
}

async function init() {
	// Handle user just returning from creating an account with consent.
	if ( mw.util.getParamValue( 'newdonoraccount' ) ) {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'newdonoraccount' );
		window.history.replaceState( null, '', url );

		// Suppress the account creation dialog with no expiry (in case user revokes consent later).
		mw.storage.set( STORAGE_KEY_SUPPRESS_OVERLAY, '1' );

		mw.notify( mw.message( 'wc-donor-account-creation-success-message' ) );
		return;
	}

	const campaign = mw.util.getParamValue( 'campaign' );
	const group = await getVariantGroup();
	const shouldSuppressOverlay = mw.storage.get( STORAGE_KEY_SUPPRESS_OVERLAY );
	const isEligible = ( campaign && campaign.includes( DEFAULT_CAMPAIGN ) ) ||
		( donor.recentlyDonated() && group !== null && !shouldSuppressOverlay );
	// temporary accounts and anonymous users are always lacking consent
	const lackingConsent = !mw.user.isNamed() || !donor.hasConsented();

	if ( isEligible && lackingConsent ) {
		// Don't show dialog to logged out and temp users that don't have permissions.
		// to create an account.
		if ( !mw.user.isNamed() ) {
			const canContinue = await canCreateAccount();
			if ( !canContinue ) {
				return;
			}
		}

		// Lazy load the confirmation dialog module, then render it.
		mw.loader.using( 'ext.wikimediaCustomizations.donorAccountCreation.dialog' )
			.then( ( req ) => {
				req( 'ext.wikimediaCustomizations.donorAccountCreation.dialog' ).launch( {
					group,
					campaign: campaign || DEFAULT_CAMPAIGN,
					storageKey: STORAGE_KEY_SUPPRESS_OVERLAY
				} );
			} );
	}
}

/* istanbul ignore if -- browser-only entry point; tests drive init() directly */
if ( typeof jest === 'undefined' ) {
	init();
}

module.exports = {
	init,
	canCreateAccount
};
