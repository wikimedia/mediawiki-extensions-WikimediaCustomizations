const { createMwApp } = require( 'vue' );
const donor = require( 'ext.wikimediaCustomizations.donor' );
const ConfirmationDialog = require( './ConfirmationDialog.vue' );

const bucket = mw.config.get( 'wgDonorDelightBadgeBucket' );
const FLY_HEART_BOX_CLASS = 'ext-wc-fly-heart-box';
const FLY_HEART_CLASS = 'ext-wc-fly-heart';
const FLY_HEART_BOX_SELECTOR = `.${ FLY_HEART_BOX_CLASS }`;
const VISIBLE_CLASS = 'ext-wc-is-visible';
const COOLDOWN_CLASS = 'ext-wc-is-cooldown';
const HIDDEN_CLASS = 'ext-wc-is-hidden';
// Dismiss transition for badge/minerva-badge-button-remove before removal (matches CSS `0.3s`).
const HIDE_DURATION = 300;

function makePopover( badge ) {
	const popover = document.createElement( 'div' );
	const popoverHeading = document.createElement( 'h3' );
	const popoverBody = document.createElement( 'p' );
	popover.id = 'minerva-badge-popover';
	if ( bucket === 'treatment-b-simple' ) {
		popoverBody.textContent = mw.msg( 'wikimediacustomizations-donordelightbadge-popover-body-b' );
	} else if ( bucket === 'treatment-c-delightful' ) {
		popoverBody.innerHTML = mw.msg( 'wikimediacustomizations-donordelightbadge-popover-body-c' );
	}
	popoverHeading.textContent = mw.msg( 'wikimediacustomizations-donordelightbadge-popover-heading' );
	popover.appendChild( popoverHeading );
	popover.appendChild( popoverBody );

	// dismiss popover when clicking outside of it or the badge
	document.body.addEventListener( 'click', ( e ) => {
		if ( popover.parentElement && !popover.classList.contains( HIDDEN_CLASS ) ) {
			if ( !badge.contains( e.target ) && !popover.contains( e.target ) ) {
				popover.classList.toggle( HIDDEN_CLASS );
			}
		}
	} );

	return popover;
}

function makeRemoveButton() {
	const removeBtn = document.createElement( 'button' );
	removeBtn.textContent = mw.msg( 'wikimediacustomizations-donordelightbadge-remove-btn' );
	if ( bucket === 'treatment-b-simple' ) {
		removeBtn.id = 'minerva-badge-popover-remove-btn';
		removeBtn.className = 'cdx-button cdx-button--size-small cdx-button--weight-quiet cdx-button--action-progressive';
	}
	if ( bucket === 'treatment-c-delightful' ) {
		removeBtn.id = 'minerva-badge-button-remove';
		removeBtn.className = 'cdx-button cdx-button--action-progressive';
		const removeIcon = document.createElement( 'span' );
		removeIcon.className = 'minerva-icon minerva-icon--eyeClosed';
		removeBtn.prepend( removeIcon );
	}
	return removeBtn;
}

// Activate badge for donors, read client preference.
function init() {
	let minervaBadgePref = mw.user.clientPrefs.get( 'minerva-badge' );
	const isFirstVisit = minervaBadgePref === '0';

	// This module has been loaded in an error state. We will later remove this
	// and ensure the module has not been loaded at all
	// FIXME: This can be removed once I38df62d5a8c85f61ff237a7bc909e930717d6c04 has
	// been in production for 2 weeks.
	if ( document.documentElement.classList.contains( 'wikimedia-donor-badge-' ) ) {
		mw.user.clientPrefs.set( 'minerva-badge', '0' );
		return;
	}

	const badge = document.getElementById( 'minerva-badge' );
	if ( !badge ) {
		return;
	}

	// `minervaBadgePref` falsy covers both `false` (pref never set) and `0` (default).
	if ( donor.recentlyDonated() ) {
		if ( bucket === 'control' ) {
			mw.user.clientPrefs.set( 'minerva-badge', 'disabled' );
		} else if ( !minervaBadgePref || minervaBadgePref === '0' ) {
			mw.user.clientPrefs.set( 'minerva-badge', '1' );
		}
		minervaBadgePref = mw.user.clientPrefs.get( 'minerva-badge' );
	}

	if ( minervaBadgePref !== '0' ) {
		const wasBadgeUserDisabled = bucket !== 'control' && minervaBadgePref === 'disabled';
		// Send the exposure event. Note this shows for any user who changed the default value of
		// the badge from 0 to either `disabled` (control) or `1` (treatment).
		// Per data analytics, it won't be logged as `experiment_exposure` if a user has
		// clicked 'hide badge', but user's visit will be logged with a `page_visit` event.
		mw.hook( 'wikimediaCustomizations.donor.recentDonor' ).fire( wasBadgeUserDisabled );
	}

	if ( bucket === 'control' || minervaBadgePref === 'disabled' ) {
		return;
	}

	let badgeIsHidden = false;
	const removeBtn = makeRemoveButton();
	const popover = makePopover( badge );

	if ( isFirstVisit ) {
		badge.parentNode.appendChild( popover );
	}

	function launchConfirmationDialog() {
		const dialogContainer = document.createElement( 'div' );
		document.body.appendChild( dialogContainer );

		const dialogApp = createMwApp( ConfirmationDialog, {
			onDialogClose: ( hideBadgeConfirmed ) => cleanup( hideBadgeConfirmed )
		} );
		dialogApp.mount( dialogContainer );

		/**
		 * Runs when the confirmation dialog closes.
		 *
		 * @param {boolean} hideBadgeConfirmed Whether the user confirmed they want the badge hidden.
		 */
		function cleanup( hideBadgeConfirmed ) {
			dialogApp.unmount();
			dialogContainer.remove();

			if ( hideBadgeConfirmed ) {
				// Hide badge now.
				setTimeout( () => {
					badge.remove();
					removeBtn.remove();
				}, HIDE_DURATION );
				// Extra check to prevent animation from firing.
				badgeIsHidden = true;
				// Hide badge permanently.
				mw.user.clientPrefs.set( 'minerva-badge', 'disabled' );
				// Fire hook for instrumentation.
				mw.hook( 'wikimediaCustomizations.donorDelightBadge.hide' ).fire();
			}
		}
	}

	if ( bucket === 'treatment-b-simple' ) {
		removeBtn.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			popover.remove();
			launchConfirmationDialog();
		} );
		badge.addEventListener( 'click', () => {
			if ( !popover.parentNode ) {
				badge.parentNode.appendChild( popover );
			} else {
				popover.classList.toggle( HIDDEN_CLASS );
			}
			mw.hook( 'wikimediaCustomizations.donorDelightBadge.click' ).fire();
		} );
		popover.appendChild( removeBtn );
		return;
	}

	// 'treatment-c-delightful' bucket
	badge.parentNode.appendChild( removeBtn );
	const contentBox = mw.util.$content[ 0 ];

	// Number of taps between colored burst cycles (every nth tap uses palette colors).
	// Lexical declaration need to be defined before `nextColorInterval` initialization.
	const COLOR_TAP_MIN = 4;
	const COLOR_TAP_MAX = 7;

	let tapCount = 0;
	// First color tap at a random 4–7th tap.
	let nextColorTap = nextColorInterval();
	let tapCooldown = false;
	// ms — prevents accidental double-fires.
	const TAP_DELAY = 200;

	// The angles of delivery.
	const ARC_START = 80;
	const ARC_END = 150;
	const ARC_SPAN = ARC_END - ARC_START;

	// Three bursts, each with its own size mix, fired 150ms apart.
	const BURSTS = [
		// Burst 0: first pop — medium-large anchors. (6 hearts)
		[
			{ size: 32, count: 1 },
			{ size: 28, count: 3 },
			{ size: 18, count: 2 }
		],
		// Burst 1: 150ms later — big + small mix. (6 hearts)
		[
			{ size: 34, count: 1 },
			{ size: 28, count: 2 },
			{ size: 16, count: 2 },
			{ size: 9, count: 1 }
		],
		// Burst 2: 300ms later — mostly small, couple chunky. (7 hearts)
		[
			{ size: 32, count: 1 },
			{ size: 26, count: 2 },
			{ size: 14, count: 2 },
			{ size: 8, count: 2 }
		]
	];

	// All the following timings are set in milliseconds (ms).
	// Offsets for each burst, measured from tap time.
	const BURST_OFFSETS = [ 0, 150, 300 ];
	// Duration per heart, measured from its burst launch.
	const DURATION = 2500;
	const MAX_HEARTS = 70;
	const BURST_SIZE = BURSTS.reduce( ( sum, b ) => sum + b.reduce(
		( s, { count } ) => s + count, 0 ), 0
	);
	// Extra delay after the last burst completes before showing the remove button.
	const POST_BURST_DELAY = 3200;
	// Fade duration when discarding overflow hearts (matches `opacity 0.2s` in stopAnimation).
	const DISCARD_FADE_DURATION = 200;
	// Fade duration when stopping all animation (matches `opacity 0.4s`).
	const STOP_FADE_DURATION = 400;

	// Harmonious palette: orange, pink, yellow, red.
	// Spawn hearts in a random palette color and transition to red on every 5th tap.
	// TODO: Before [ '#e8630a', '#d44d8a', '#e8b400', '#8b5fbf' ]
	const COLOR_PALETTE = [ '#f80', '#ff648d', '#ffc800', '#ff6d65' ];
	// Delay in `ms` – before heart shifts from red to palette color.
	const COLOR_TRANSITION_DELAY = 150;

	// Heart animation physics.
	// px — closest a heart can land
	const DISTANCE_MIN = 120;
	// px — random range added on top of DISTANCE_MIN
	const DISTANCE_SPREAD = 568;
	// degrees — ± randomisation applied to each evenly-spaced arc angle
	const ANGLE_JITTER = 12;
	// degrees — max total rotation across the flight
	const ROTATE_SPREAD = 70;
	// Fraction of DURATION at which fade can begin (earliest)
	const FADE_START_MIN = 0.5;
	// Additional random fraction added to FADE_START_MIN
	const FADE_JITTER = 0.33;
	// Exponent for the quartic ease-out position curve
	const EASE_EXPONENT = 4;
	// Initial scale (pop-in starts here)
	const SCALE_MIN = 0.3;
	// Scale range travelled during pop-in (precomputed to avoid per-frame subtraction).
	const SCALE_RANGE = 1 - SCALE_MIN;
	// Fraction of DURATION for the pop-in scale to complete
	const SCALE_IN_T = 0.18;

	function nextColorInterval() {
		return COLOR_TAP_MIN + Math.floor( Math.random() * ( COLOR_TAP_MAX - COLOR_TAP_MIN + 1 ) );
	}

	function getBadgeCenter( badgeEl ) {
		const rect = badgeEl.getBoundingClientRect();
		return {
			x: rect.left + rect.width / 2,
			y: rect.bottom
		};
	}

	function shuffle( arr ) {
		for ( let i = arr.length - 1; i > 0; i-- ) {
			const j = Math.floor( Math.random() * ( i + 1 ) );
			[ arr[ i ], arr[ j ] ] = [ arr[ j ], arr[ i ] ];
		}
		return arr;
	}

	// Track active setTimeout IDs and live heart elements so we can cancel mid-animation.
	let activeTimers = [];
	// Rely on a `WeakSet` instead of mis-using DOM for state (think `el.dataset`), to mark hearts
	// cancelled for fade-out.
	const cancelledHearts = new WeakSet();

	function launchBurst( configs, burstLaunchTime, useColor, container, badgeEl ) {
		const origin = getBadgeCenter( badgeEl );
		const els = [];

		configs.forEach( ( { size, count } ) => {
			for ( let i = 0; i < count; i++ ) {
				const box = document.createElement( 'div' );
				box.className = FLY_HEART_BOX_CLASS;
				box.style.width = size + 'px';
				box.style.height = size + 'px';
				box.style.left = ( origin.x - size / 2 ) + 'px';
				box.style.top = ( origin.y - size / 2 ) + 'px';
				box.style.opacity = '0';

				const heart = document.createElement( 'div' );
				heart.className = FLY_HEART_CLASS;

				if ( useColor ) {
					const targetColor = COLOR_PALETTE[ Math.floor(
						Math.random() * COLOR_PALETTE.length
					) ];
					setTimeout( () => {
						heart.style.backgroundColor = targetColor;
					}, COLOR_TRANSITION_DELAY );
				}

				box.appendChild( heart );
				container.appendChild( box );
				els.push( box );
			}
		} );

		const total = els.length;

		// Even spread across arc, shuffled so big hearts don't cluster at one angle.
		// Guard against total === 1 to avoid division by zero in the spacing formula.
		const angles = shuffle(
			Array.from( { length: total }, ( _, i ) => {
				const base = total > 1 ?
					ARC_START + ( ARC_SPAN / ( total - 1 ) ) * i :
					ARC_START + ARC_SPAN / 2;
				return base + ( Math.random() * 2 * ANGLE_JITTER - ANGLE_JITTER );
			} )
		);

		els.forEach( ( el, i ) => {
			const angleRad = ( angles[ i ] * Math.PI ) / 180;
			const distance = DISTANCE_MIN + Math.random() * DISTANCE_SPREAD;
			const tx = Math.cos( angleRad ) * distance;
			const ty = Math.sin( angleRad ) * distance;
			const rotate = ( Math.random() - 0.5 ) * ROTATE_SPREAD;
			// Each heart fades independently: window starts between 50%–92% through duration.
			const fadeStartT = FADE_START_MIN + Math.random() * FADE_JITTER;
			const fadeSpan = 1 - fadeStartT;
			// First two hearts in each burst are fully opaque; the rest get a
			// random opacity 0.5 → 0.9.
			const startOpacity = i < 2 ? 1 : 0.5 + Math.random() * 0.4;

			function tick( now ) {
				// Stop requestAnimationFrame loop if `stopAnimation()` has marked
				// this heart for fade-out.
				if ( cancelledHearts.has( el ) ) {
					return;
				}
				const elapsed = now - burstLaunchTime;
				if ( elapsed < 0 ) {
					requestAnimationFrame( tick );
					return;
				}

				const t = Math.min( elapsed / DURATION, 1 );
				const eased = 1 - Math.pow( 1 - t, EASE_EXPONENT );
				const fadeT = Math.max( 0, ( t - fadeStartT ) / fadeSpan );
				const scale = SCALE_MIN + SCALE_RANGE * Math.min( t / SCALE_IN_T, 1 );

				el.style.opacity = startOpacity * ( 1 - fadeT );
				el.style.transform = `translate(${ eased * tx }px, ${ eased * ty }px) rotate(${ eased * rotate }deg) scale(${ scale })`;

				if ( t < 1 ) {
					requestAnimationFrame( tick );
				} else {
					el.remove();
				}
			}
			requestAnimationFrame( tick );
		} );
	}

	function stopAnimation( container ) {
		// Cancel any pending burst timers.
		activeTimers.forEach( ( id ) => clearTimeout( id ) );
		activeTimers = [];

		// Mark cancelled so requestAnimationFrame tick loops stop, then fade out and remove.
		container.querySelectorAll( FLY_HEART_BOX_SELECTOR ).forEach( ( el ) => {
			cancelledHearts.add( el );
			el.style.transition = 'opacity 0.4s ease';
			el.style.opacity = '0';
			setTimeout( () => el.remove(), STOP_FADE_DURATION );
		} );
	}

	function fireHearts() {
		if ( badgeIsHidden || tapCooldown ) {
			return;
		}
		tapCooldown = true;
		badge.classList.add( COOLDOWN_CLASS );
		removeBtn.classList.remove( HIDDEN_CLASS );
		removeBtn.classList.add( VISIBLE_CLASS );

		// Cancel pending burst timers from the previous tap but keep existing hearts flying.
		activeTimers.forEach( ( id ) => clearTimeout( id ) );
		activeTimers = [];

		if ( popover.parentNode ) {
			popover.remove();
		}

		// Discard oldest hearts if adding a new burst would exceed the cap.
		const live = Array.from( contentBox.querySelectorAll( FLY_HEART_BOX_SELECTOR ) );
		const overflow = ( live.length + BURST_SIZE ) - MAX_HEARTS;
		if ( overflow > 0 ) {
			live.slice( 0, overflow ).forEach( ( el ) => {
				cancelledHearts.add( el );
				el.style.transition = 'opacity 0.2s ease';
				el.style.opacity = '0';
				setTimeout( () => el.remove(), DISCARD_FADE_DURATION );
			} );
		}

		tapCount++;
		const useColor = tapCount === nextColorTap;
		if ( useColor ) {
			nextColorTap = tapCount + nextColorInterval();
		}

		BURSTS.forEach( ( configs, b ) => {
			const offset = BURST_OFFSETS[ b ];
			const id = setTimeout( () => {
				launchBurst( configs, performance.now(), useColor, contentBox, badge );
			}, offset );
			activeTimers.push( id );
		} );

		const lastBurst = BURST_OFFSETS[ BURST_OFFSETS.length - 1 ];
		const doneId = setTimeout( () => {
			removeBtn.classList.add( HIDDEN_CLASS );
		}, lastBurst + DURATION + POST_BURST_DELAY );
		activeTimers.push( doneId );

		// Unlock after last burst offset + TAP_DELAY so rapid taps feel intentional.
		const cooldownId = setTimeout( () => {
			tapCooldown = false;
			badge.classList.remove( COOLDOWN_CLASS );
		}, lastBurst + TAP_DELAY );
		activeTimers.push( cooldownId );
	}

	badge.addEventListener( 'click', () => {
		fireHearts();
		mw.hook( 'wikimediaCustomizations.donorDelightBadge.click' ).fire();
	} );

	removeBtn.addEventListener( 'click', () => {
		launchConfirmationDialog();
		// While the confirmation dialog is open, stop animation and hide remove button.
		stopAnimation( contentBox );
		removeBtn.classList.add( HIDDEN_CLASS );
	} );
}

// since relying on mw.util.$content, ensure to use jQuery.ready
$( init );
