'use strict';

/**
 * Gate for the Discord arrival survey (T431352).
 *
 * Decides whether the current pageview should see the survey before any
 * QuickSurveys code is loaded: the survey is registered dormant (see
 * DiscordSurveyHookHandler), so readers who don't pass the checks below
 * pay no QuickSurveys cost at all.
 */

const config = require( './config.json' );
const { wprov } = require( 'mediawiki.page.ready' );

if ( config.wprov && wprov === config.wprov ) {
	mw.loader.using( 'mediawiki.storage' ).then( () => {
		// QuickSurveys records dismissal by storing '~' under this key
		// (see getSurveyStorageKey in ext.quicksurveys.lib). The forced
		// showSurvey() path below skips that check, so honor it here.
		if ( mw.storage.get( 'ext-quicksurvey-' + config.surveyName ) === '~' ) {
			return;
		}
		// Per-landing sampling: every qualifying pageview gets an
		// independent draw, so repeat arrivals get repeat chances.
		if ( Math.random() >= config.coverage ) {
			return;
		}
		// If an unrelated survey is running site-wide, QuickSurveys' own
		// init can insert it on this pageview too; a sampled Discord
		// arrival could then see two surveys. Accepted: the overlap is
		// the product of both coverages.
		return mw.loader.using( 'ext.quicksurveys.lib' ).then( ( req ) => {
			// forceDisplay bypasses coverage/audience checks (handled
			// above instead). includeSensitiveData matches what
			// QuickSurveys' init passes for its own surveys, so response
			// events carry the same landing-page context.
			req( 'ext.quicksurveys.lib' ).showSurvey( config.surveyName, null, true, true );
		} );
	} );
}
