'use strict';

const relationships = require( './relationships.json' );
const DONOR_KEY = 'wikimedia-donor';
const FUNDRAISING_COOKIE = 'centralnotice_hide_fundraising';

/**
 * Consent and relationship helpers only support named (logged-in) users.
 *
 * @throws {Error} If the user is anonymous or temporary.
 */
const requireNamedUser = () => {
	if ( !mw.user.isNamed() ) {
		throw new Error( 'Donor consent API is only supported for logged-in users' );
	}
};

const getDonorData = () => {
	requireNamedUser();
	try {
		return Object.assign(
			{
				value: 0
			},
			JSON.parse( mw.user.options.get( DONOR_KEY ) )
		);
	} catch ( e ) {
		return { value: 0 };
	}
};

const getDonorRelationship = () => {
	const donorData = getDonorData();
	return donorData.value;
};

/**
 * Check if a donation happened recently
 *
 * @param {number} [maxDays] maximum full days since donation (integers are not supported)
 *  If the donation is 25 hours ago, it will return false for 1 day.
 * @return {boolean}
 */
const recentlyDonated = ( maxDays ) => {
	const donationCookie = mw.cookie.get( FUNDRAISING_COOKIE, '' );
	const hasCookie = !!donationCookie;
	const donorInfo = hasCookie ? JSON.parse( donationCookie ) : {};
	// access the date and time of their donation (unix timestamp in seconds).
	const donationDate = donorInfo.created ? new Date( donorInfo.created * 1000 ) : null;
	// if there's no donor info or no created timestamp, indicate absence with false.
	if ( !donationDate ) {
		return false;
	}

	if ( maxDays !== undefined ) {
		const now = new Date();
		const msPerDay = 1000 * 60 * 60 * 24;
		const daysSince = Math.floor( ( now - donationDate ) / msPerDay );
		return daysSince <= maxDays;
	}
	return true;
};

const hasConsented = () => getDonorRelationship() > 0;

/**
 * @param {number} value the new relationship status code (0 = no consent).
 * @param {Object} [data] optional fields merged into the saved consent object
 *  (e.g. `{ campaign: 'x' }`). `value` and `timestamp` always take precedence.
 */
const storeDonorData = ( value, data ) => {
	requireNamedUser();
	const currentRelationship = getDonorRelationship();
	// value/timestamp last so callers cannot overwrite core consent fields via data.
	const prefValue = JSON.stringify(
		Object.assign( {}, data, { value, timestamp: Date.now() } )
	);
	const a = new mw.Api();
	// Note: global override not needed here, since its always a global.
	a.saveOption( DONOR_KEY, prefValue, {
		global: 'create'
	} ).then( () => {
		mw.user.options.values[ DONOR_KEY ] = prefValue;
		mw.user.options.set( DONOR_KEY, prefValue );
		const docClassList = document.documentElement.classList;
		docClassList.remove( `wikimedia-donor-clientpref-${ currentRelationship }` );
		docClassList.add( `wikimedia-donor-clientpref-${ value }` );
	} );
};

const isRelationship = ( type ) => {
	const relationship = relationships[ type ];
	const id = getDonorRelationship();
	if ( !relationship ) {
		throw new Error( `type parameter should be one of: ${ Object.keys( relationships ).join( ',' ) }` );
	}
	return id === relationship;
};

module.exports = {
	revokeConsent: () => {
		storeDonorData( 0 );
	},
	/**
	 * Grant consent. Optional data (e.g. `{ campaign: 'x' }`) is merged into the
	 * saved preference object alongside `value` and `timestamp`.
	 *
	 * @param {Object} [data]
	 */
	consent: ( data ) => {
		// Mark as donor (1) if donation cookie found, otherwise mark as contactable donor.
		const value = recentlyDonated() ? relationships.Recent : relationships.Contactable;
		if ( !hasConsented() ) {
			storeDonorData( value, data );
		}
	},
	recentlyDonated,
	hasConsented,
	isRelationship,
	/**
	 * @return {boolean} whether the donor consented and is a group other than "contactable".
	 */
	isDonor: () => hasConsented() && !isRelationship( 'Contactable' )
};
