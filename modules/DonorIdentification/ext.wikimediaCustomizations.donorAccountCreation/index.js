const donor = require( 'ext.wikimediaCustomizations.donor' );
const EXPERIMENT_NAME = 'donor-status-consent';
// Default campaign used to attribute account creations from this dialog.
const DEFAULT_CAMPAIGN = 'reader-donor-account';

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
	const campaign = mw.util.getParamValue( 'campaign' );
	const group = await getVariantGroup();
	const isEligible = ( campaign && campaign.includes( DEFAULT_CAMPAIGN ) ) ||
		( donor.recentlyDonated() && group !== null );
	const lackingConsent = mw.user.isAnon() || !donor.hasConsented();

	if ( isEligible && lackingConsent ) {
		// Don't show dialog to logged out and temp users that dont have permissions
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
					campaign: campaign || DEFAULT_CAMPAIGN
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
