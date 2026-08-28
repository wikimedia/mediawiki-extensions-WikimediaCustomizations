'use strict';

/**
 * Confirmation gate for unlinking donor status on the preferences form.
 *
 * The `wikimedia-donor` preference renders as a checkbox that starts ticked
 * whenever a donor status is present (see DonorPreferenceFilter::filterForForm).
 * Unticking it clears the status, which is irreversible. This intercepts the
 * form submit and asks the user to confirm before letting an unlink through.
 */

// HTMLForm prefixes field names with "wp"; OOUI renders a real checkbox input.
const DONOR_INPUT_NAME = 'wpwikimedia-donor';
const CHECKBOX_SELECTOR = `input[type="checkbox"][name="${ DONOR_INPUT_NAME }"]`;

function init() {
	let checkbox = document.querySelector( CHECKBOX_SELECTOR );
	// PHP only loads this module when the checkbox is offered, but stay safe if
	// the markup ever changes or the field is absent (e.g. rendered disabled).
	if ( !checkbox || !checkbox.form ) {
		return;
	}
	const form = checkbox.form;
	let confirmed = false;

	form.addEventListener( 'submit', ( event ) => {
		// on mobile the checkbox may have been re-rendered in an overlay so update
		// reference.
		checkbox = document.querySelector( CHECKBOX_SELECTOR );
		// The box starts ticked, so an unticked box at submit time always means
		// the user is unlinking - no need to record the initial state, which
		// also sidesteps any race with this module loading late.
		if ( confirmed || checkbox.checked ) {
			return;
		}

		event.preventDefault();
		OO.ui.confirm( mw.msg( 'wikimediacustomizations-donor-unlink-confirm' ) )
			.then( ( ok ) => {
				if ( !ok ) {
					// User declined: leave them on the form with the box as-is.
					return;
				}
				confirmed = true;
				// requestSubmit() re-fires the submit event (guarded by the
				// confirmed flag above) so HTMLForm's own submit handling still
				// runs, rather than bypassing it as form.submit() would.
				form.requestSubmit();
			} );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
