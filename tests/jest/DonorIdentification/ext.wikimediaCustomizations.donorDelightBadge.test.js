'use strict';

jest.mock( 'ext.wikimediaCustomizations.donor', () => ( {
	recentlyDonated: jest.fn( () => false )
} ), { virtual: true } );

// Constants mirrored from the module for use in timing calculations.
const DURATION = 2500;
const HIDE_DURATION = 300;
const STOP_FADE_DURATION = 400;
const DISCARD_FADE_DURATION = 200;
const COLOR_TRANSITION_DELAY = 150;
const BURST_OFFSETS = [ 0, 150, 300 ];
const POST_BURST_DELAY = 80;
const TAP_DELAY = 200;
// Sum of counts across all three burst definitions.
const BURST_SIZE = 19;
const MAX_HEARTS = 70;

const MODULE_PATH = '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donorDelightBadge/index.js';

function setupDOM() {
	document.body.innerHTML = `
		<div id="content">
			<div id="minerva-badge"></div>
		</div>
	`;
}

describe( 'DonorDelightBadge', () => {
	let badge, popover, removeBtn, contentBox;
	let rafCallbacks;
	const mockNow = 1000;

	beforeEach( () => {
		jest.resetModules();
		jest.useFakeTimers();

		rafCallbacks = [];
		global.requestAnimationFrame = jest.fn( ( cb ) => {
			rafCallbacks.push( cb );
			return rafCallbacks.length - 1;
		} );
		global.cancelAnimationFrame = jest.fn();
		global.performance = { now: jest.fn( () => mockNow ) };

		// jsdom does not ship PointerEvent; define a minimal polyfill so the
		// module's `if ( window.PointerEvent )` branch is exercised.
		global.PointerEvent = class PointerEvent extends Event {
			constructor( type, opts = {} ) {
				super( type, opts );
				this.pointerType = opts.pointerType || '';
			}
		};

		setupDOM();
		global.mw = {
			config: { get: jest.fn( () => 'c' ) },
			msg: jest.fn( () => '' ),
			user: { clientPrefs: { set: jest.fn() } },
			util: { $content: [ document.getElementById( 'content' ) ] },
			hook: jest.fn( () => ( { fire: jest.fn() } ) )
		};
		global.$ = jest.fn().mockImplementation( ( cb ) => cb() );

		require( MODULE_PATH );
		badge = document.getElementById( 'minerva-badge' );
		removeBtn = document.getElementById( 'minerva-badge-button-remove' );
		contentBox = global.mw.util.$content[ 0 ];
		popover = document.getElementById( 'minerva-badge-popover' );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	// Flush all queued requestAnimationFrame callbacks with a given timestamp.
	function flushRAF( now ) {
		const cbs = rafCallbacks.splice( 0 );
		cbs.forEach( ( cb ) => cb( now ) );
	}

	function fireBadgeTap() {
		badge.dispatchEvent( new Event( 'click' ) );
	}

	describe( 'renders', () => {
		test( 'all appropriate elements', () => {
			jest.resetModules();
			expect( document.body ).toMatchSnapshot();
		} );
	} );

	// Null guard — early return when required elements are absent.
	describe( 'null guard', () => {
		test( 'does nothing when badge is absent', () => {
			jest.resetModules();
			document.body.innerHTML = '<button id="minerva-badge-button-remove"></button>';
			expect( () => require( MODULE_PATH ) ).not.toThrow();
		} );

	} );

	// Client preference.
	describe( 'client preference', () => {
		test( 'sets minerva-badge preference when user recently donated', () => {
			jest.resetModules();
			require( 'ext.wikimediaCustomizations.donor' ).recentlyDonated.mockReturnValue( true );
			setupDOM();
			global.mw.util.$content = [ document.getElementById( 'content' ) ];
			require( MODULE_PATH );
			expect( global.mw.user.clientPrefs.set ).toHaveBeenCalledWith( 'minerva-badge', '1' );
		} );

		test( 'does not set preference when user has not recently donated', () => {
			expect( global.mw.user.clientPrefs.set ).not.toHaveBeenCalled();
		} );
	} );

	// IIFE auto-initialization.
	describe( 'auto-initialization', () => {
		test( 'attaches listeners when badge element is present', () => {
			fireBadgeTap();
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( true );
		} );
	} );

	describe( 'click fallback', () => {
		test( 'fires hearts on click', () => {
			jest.resetModules();
			const savedPointerEvent = window.PointerEvent;
			delete window.PointerEvent;

			setupDOM();
			global.mw.util.$content = [ document.getElementById( 'content' ) ];
			require( MODULE_PATH );
			badge = document.getElementById( 'minerva-badge' );

			badge.dispatchEvent( new Event( 'click' ) );
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( true );

			window.PointerEvent = savedPointerEvent;
		} );
	} );

	// `fireHearts`
	describe( 'fireHearts', () => {
		test( 'adds is-cooldown class to badge', () => {
			fireBadgeTap();
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( true );
		} );

		test( 'removes popover when it has a parentNode', () => {
			expect( popover.parentNode ).not.toBeNull();
			fireBadgeTap();
			expect( document.getElementById( 'minerva-badge-popover' ) ).toBeNull();
		} );

		test( 'does not throw when popover has no parentNode', () => {
			popover.remove();
			expect( () => fireBadgeTap() ).not.toThrow();
		} );

		test( 'does nothing when already hidden', () => {
			removeBtn.dispatchEvent( new Event( 'click' ) );
			// `hidden = true;` next tap must be a no-op.
			fireBadgeTap();
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( false );
		} );

		test( 'does nothing during tapCooldown', () => {
			fireBadgeTap();
			popover.remove();
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( true );
			// Second tap while cooldown is active should be silently dropped.
			fireBadgeTap();
			// Still no burst timers for the second tap; `requestAnimationFrame` queue
			// size unchanged.
			const rafAfterFirstTap = rafCallbacks.length;
			expect( rafCallbacks.length ).toBe( rafAfterFirstTap );
		} );

		test( 'releases cooldown after last burst offset + TAP_DELAY', () => {
			fireBadgeTap();
			const lastBurst = BURST_OFFSETS[ BURST_OFFSETS.length - 1 ];
			jest.advanceTimersByTime( lastBurst + TAP_DELAY );
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( false );
		} );

		test( 'shows remove button after last burst + DURATION + POST_BURST_DELAY', () => {
			fireBadgeTap();
			const lastBurst = BURST_OFFSETS[ BURST_OFFSETS.length - 1 ];
			jest.advanceTimersByTime( lastBurst + DURATION + POST_BURST_DELAY );
			expect( removeBtn.classList.contains( 'is-visible' ) ).toBe( true );
		} );

		test( 'discards oldest hearts when overflow cap is exceeded', () => {
			// 60 + 19 (BURST_SIZE) - 70 (MAX_HEARTS) = 9 to discard.
			const liveCount = 60;
			const hearts = [];
			for ( let i = 0; i < liveCount; i++ ) {
				const el = document.createElement( 'div' );
				el.className = 'fly-heart-box';
				contentBox.appendChild( el );
				hearts.push( el );
			}

			fireBadgeTap();

			const overflow = liveCount + BURST_SIZE - MAX_HEARTS;
			for ( let i = 0; i < overflow; i++ ) {
				expect( hearts[ i ].style.opacity ).toBe( '0' );
			}
			for ( let i = overflow; i < liveCount; i++ ) {
				expect( hearts[ i ].style.opacity ).toBe( '' );
			}
		} );

		test( 'removes discarded hearts after DISCARD_FADE_DURATION', () => {
			const liveCount = 60;
			for ( let i = 0; i < liveCount; i++ ) {
				const el = document.createElement( 'div' );
				el.className = 'fly-heart-box';
				el.id = `pre-heart-${ i }`;
				contentBox.appendChild( el );
			}

			fireBadgeTap();
			const overflow = liveCount + BURST_SIZE - MAX_HEARTS; // 9
			// Only advance past the discard timeout, no burst timers (burst 0 fires at 0ms too,
			// so run timers up to DISCARD_FADE_DURATION and then count pre-existing hearts).
			jest.advanceTimersByTime( DISCARD_FADE_DURATION + 1 );

			for ( let i = 0; i < overflow; i++ ) {
				expect( document.getElementById( `pre-heart-${ i }` ) ).toBeNull();
			}
		} );

		test( 'does not discard hearts when under the cap', () => {
			const el = document.createElement( 'div' );
			el.className = 'fly-heart-box';
			contentBox.appendChild( el );

			// 1 + 19 - 70 = -50 < 0, no overflow.
			fireBadgeTap();

			expect( el.style.opacity ).toBe( '' );
		} );

		test( 'creates fly-heart-box elements on burst fire', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );
			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBeGreaterThan( 0 );
		} );
	} );

	// Color cycle
	describe( 'color cycle', () => {
		test( 'applies palette color to hearts on the color tap', () => {
			// With Math.random() = 0, nextColorInterval = COLOR_TAP_MIN + 0 = 4.
			// The 4th tap is the first color tap.
			jest.spyOn( Math, 'random' ).mockReturnValue( 0 );
			jest.resetModules();
			setupDOM();
			global.mw.util.$content = [ document.getElementById( 'content' ) ];
			require( MODULE_PATH );
			badge = document.getElementById( 'minerva-badge' );
			contentBox = global.mw.util.$content[ 0 ];

			const lastBurst = BURST_OFFSETS[ BURST_OFFSETS.length - 1 ];
			for ( let tap = 0; tap < 3; tap++ ) {
				fireBadgeTap();
				// Advance past cooldown to allow the next tap.
				jest.advanceTimersByTime( lastBurst + TAP_DELAY + 1 );
			}
			// 4th tap — the color tap.
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + COLOR_TRANSITION_DELAY + 1 );

			const colored = Array.from( contentBox.querySelectorAll( '.fly-heart' ) ).filter(
				( h ) => h.style.backgroundColor !== ''
			);
			expect( colored.length ).toBeGreaterThan( 0 );
		} );

		test( 'does not apply color on non-color taps', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + COLOR_TRANSITION_DELAY + 1 );

			const colored = Array.from( contentBox.querySelectorAll( '.fly-heart' ) ).filter(
				( h ) => h.style.backgroundColor !== ''
			);
			expect( colored.length ).toBe( 0 );
		} );
	} );

	// `stopAnimation`
	describe( 'stopAnimation (triggered by remove button)', () => {
		test( 'immediately sets opacity 0 on all flying hearts', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );

			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBeGreaterThan( 0 );

			removeBtn.dispatchEvent( new Event( 'click' ) );

			contentBox.querySelectorAll( '.fly-heart-box' ).forEach( ( el ) => {
				expect( el.style.opacity ).toBe( '0' );
			} );
		} );

		test( 'removes hearts after STOP_FADE_DURATION', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );

			removeBtn.dispatchEvent( new Event( 'click' ) );
			jest.advanceTimersByTime( STOP_FADE_DURATION + 1 );

			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBe( 0 );
		} );

		test( 'cancels pending burst timers', () => {
			fireBadgeTap();
			removeBtn.dispatchEvent( new Event( 'click' ) );

			// Advance past all burst offsets — no new hearts should appear because timers
			// were cleared by stopAnimation.
			jest.advanceTimersByTime( BURST_OFFSETS[ BURST_OFFSETS.length - 1 ] + 1 );
			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBe( 0 );
		} );
	} );

	// Heart animation tick.
	describe( 'heart animation tick', () => {
		test( 'requeues RAF when elapsed time is negative (burst not yet started)', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );
			// flushRAF empties rafCallbacks; each heart's tick re-queues on elapsed < 0.
			flushRAF( mockNow - 500 ); // elapsed = -500 < 0
			expect( rafCallbacks.length ).toBeGreaterThan( 0 );
		} );

		test( 'continues animation and requeues RAF when t < 1', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );

			const heartCount = contentBox.querySelectorAll( '.fly-heart-box' ).length;
			flushRAF( mockNow + 1000 ); // elapsed = 1000 < DURATION(2500), t ≈ 0.4
			// Hearts still in DOM and RAF re-queued.
			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBe( heartCount );
			expect( rafCallbacks.length ).toBeGreaterThan( 0 );
		} );

		test( 'removes heart element when animation completes (t = 1)', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );

			const heartCountBefore = contentBox.querySelectorAll( '.fly-heart-box' ).length;
			flushRAF( mockNow + DURATION ); // elapsed = DURATION, t = 1 → el.remove()
			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBeLessThan( heartCountBefore );
		} );

		test( 'cancelled hearts skip the tick loop after stopAnimation', () => {
			fireBadgeTap();
			jest.advanceTimersByTime( BURST_OFFSETS[ 0 ] + 1 );

			removeBtn.dispatchEvent( new Event( 'click' ) );

			const opacitySnapshot = Array.from(
				contentBox.querySelectorAll( '.fly-heart-box' )
			).map( ( h ) => h.style.opacity );

			// Flushing RAF on cancelled hearts must not alter opacity or transform.
			flushRAF( mockNow + DURATION );
			contentBox.querySelectorAll( '.fly-heart-box' ).forEach( ( el, i ) => {
				expect( el.style.opacity ).toBe( opacitySnapshot[ i ] );
			} );
		} );
	} );

	// Bucket B — static popover with remove link, no animation.
	describe( 'bucket B', () => {
		let popoverRemoveBtn;

		beforeEach( () => {
			jest.resetModules();
			global.mw.config.get.mockReturnValue( 'b' );
			global.mw.msg.mockClear();
			setupDOM();
			global.mw.util.$content = [ document.getElementById( 'content' ) ];
			require( MODULE_PATH );
			badge = document.getElementById( 'minerva-badge' );
			popover = document.getElementById( 'minerva-badge-popover' );
			contentBox = global.mw.util.$content[ 0 ];
			popoverRemoveBtn = document.getElementById( 'minerva-badge-popover-remove-btn' );
		} );

		test( 'uses the B-specific body message', () => {
			expect( global.mw.msg ).toHaveBeenCalledWith(
				'wikimediacustomizations-donordelightbadge-popover-body-b'
			);
		} );

		test( 'does not use the C body message', () => {
			expect( global.mw.msg ).not.toHaveBeenCalledWith(
				'wikimediacustomizations-donordelightbadge-popover-body-c'
			);
		} );

		test( 'appends a remove button to the popover', () => {
			expect( popoverRemoveBtn ).not.toBeNull();
			expect( popoverRemoveBtn.tagName ).toBe( 'BUTTON' );
		} );

		test( 'remove link uses the remove message', () => {
			expect( global.mw.msg ).toHaveBeenCalledWith(
				'wikimediacustomizations-donordelightbadge-remove-btn'
			);
		} );

		test( 'clicking remove link removes the popover and adds is-hidden to badge', () => {
			popoverRemoveBtn.dispatchEvent( new Event( 'click', { cancelable: true } ) );
			expect( document.getElementById( 'minerva-badge-popover' ) ).toBeNull();
			expect( badge.classList.contains( 'is-hidden' ) ).toBe( true );
		} );

		test( 'clicking remove link removes badge from DOM after 300ms', () => {
			popoverRemoveBtn.dispatchEvent( new Event( 'click', { cancelable: true } ) );
			expect( document.getElementById( 'minerva-badge' ) ).not.toBeNull();
			jest.advanceTimersByTime( HIDE_DURATION + 1 );
			expect( document.getElementById( 'minerva-badge' ) ).toBeNull();
		} );

		test( 'tapping badge does not fire hearts', () => {
			badge.dispatchEvent( new Event( 'touchstart', { cancelable: true, bubbles: true } ) );
			expect( badge.classList.contains( 'is-cooldown' ) ).toBe( false );
			expect( contentBox.querySelectorAll( '.fly-heart-box' ).length ).toBe( 0 );
		} );
	} );

	// Remove button.
	describe( 'remove button click', () => {
		test( 'adds is-hidden class to badge and remove button', () => {
			removeBtn.dispatchEvent( new Event( 'click' ) );
			expect( badge.classList.contains( 'is-hidden' ) ).toBe( true );
			expect( removeBtn.classList.contains( 'is-hidden' ) ).toBe( true );
		} );

		test( 'removes badge and remove button from the DOM after HIDE_DURATION', () => {
			removeBtn.dispatchEvent( new Event( 'click' ) );
			expect( document.getElementById( 'minerva-badge' ) ).not.toBeNull();

			jest.advanceTimersByTime( HIDE_DURATION + 1 );
			expect( document.getElementById( 'minerva-badge' ) ).toBeNull();
			expect( document.getElementById( 'minerva-badge-button-remove' ) ).toBeNull();
		} );
	} );
} );
