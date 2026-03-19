'use strict';

const FUNDRAISING_COOKIE = 'centralnotice_hide_fundraising';

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

module.exports = {
	recentlyDonated
};
