/**
 * Unit tests for donor account creation workflows.
 *
 * Set the simulated URL of the window to avoid cross-domain errors:
 *
 * @jest-environment-options {"url": "https://example.test/wiki/Foo?newdonoraccount=1&other=keep"}
 */

/* global mw */
'use strict';

const mockRecentlyDonated = jest.fn( () => false );
const mockHasConsented = jest.fn( () => false );

jest.mock( 'ext.wikimediaCustomizations.donor', () => ( {
	recentlyDonated: mockRecentlyDonated,
	hasConsented: mockHasConsented
} ), { virtual: true } );

const { init } = require( '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donorAccountCreation/index.js' );

const DIALOG_MODULE = 'ext.wikimediaCustomizations.donorAccountCreation.dialog';
const DEFAULT_CAMPAIGN = 'reader-donor-account';
const STORAGE_KEY_SUPPRESS_OVERLAY = 'wc-donor-account-creation-suppress-consent-overlay';
const SUCCESS_MESSAGE_KEY = 'wc-donor-account-creation-success-message';

// init() fires the dialog launch on an un-awaited loader.using().then() chain,
// so yield once after it resolves to let that microtask settle.
const nextTick = () => Promise.resolve();

describe( 'donorAccountCreation init', () => {
	let mockLaunch;
	let mockAjax;

	// Build a fresh mw stub with sensible eligible-user defaults; individual
	// tests override the pieces they exercise.
	function setupMw( overrides = {} ) {
		mockLaunch = jest.fn();
		mockAjax = jest.fn( () => Promise.resolve( {
			query: { userinfo: { cancreateaccount: true } }
		} ) );

		const dialogModule = { launch: mockLaunch };

		global.mw = {
			testKitchen: {
				getExperiment: jest.fn( () => Promise.resolve( {
					getAssignedGroup: jest.fn( () => overrides.group !== undefined ? overrides.group : 'treatment' )
				} ) )
			},
			util: {
				getParamValue: jest.fn( ( name ) => {
					if ( name === 'newdonoraccount' ) {
						return overrides.newdonoraccount !== undefined ?
							overrides.newdonoraccount : null;
					}
					if ( name === 'campaign' ) {
						return overrides.campaign !== undefined ?
							overrides.campaign : null;
					}
					return null;
				} )
			},
			user: {
				isNamed: jest.fn( () => overrides.isNamed !== undefined ? overrides.isNamed : true ),
				isAnon: jest.fn( () => overrides.isAnon !== undefined ? overrides.isAnon : true )
			},
			storage: {
				get: jest.fn( ( key ) => {
					if ( key === STORAGE_KEY_SUPPRESS_OVERLAY ) {
						return overrides.suppressOverlay !== undefined ?
							overrides.suppressOverlay : null;
					}
					return null;
				} ),
				set: jest.fn()
			},
			message: jest.fn( ( key ) => ( { key } ) ),
			notify: jest.fn(),
			Api: jest.fn().mockImplementation( () => ( { ajax: mockAjax } ) ),
			loader: {
				using: jest.fn( () => Promise.resolve( () => dialogModule ) )
			}
		};
	}

	beforeEach( () => {
		mockRecentlyDonated.mockReturnValue( false );
		mockHasConsented.mockReturnValue( false );
	} );

	describe( 'eligibility', () => {
		test( 'launches the dialog when the campaign param matches', async () => {
			setupMw( { campaign: `foo-${ DEFAULT_CAMPAIGN }-bar`, isNamed: true } );
			await init();
			await nextTick();
			expect( mw.loader.using ).toHaveBeenCalledWith( DIALOG_MODULE );
			expect( mockLaunch ).toHaveBeenCalledWith( {
				group: 'treatment',
				campaign: `foo-${ DEFAULT_CAMPAIGN }-bar`,
				storageKey: STORAGE_KEY_SUPPRESS_OVERLAY
			} );
		} );

		test( 'launches the dialog when the user recently donated and has a group', async () => {
			mockRecentlyDonated.mockReturnValue( true );
			setupMw( { campaign: null, group: 'treatment', isNamed: true } );
			await init();
			await nextTick();
			expect( mockLaunch ).toHaveBeenCalledTimes( 1 );
			// Falls back to the default campaign when no param is present.
			expect( mockLaunch ).toHaveBeenCalledWith( {
				group: 'treatment',
				campaign: DEFAULT_CAMPAIGN,
				storageKey: STORAGE_KEY_SUPPRESS_OVERLAY
			} );
		} );

		test( 'does not launch when donor has no assigned group', async () => {
			mockRecentlyDonated.mockReturnValue( true );
			setupMw( { campaign: null, group: null, isNamed: true } );
			await init();
			await nextTick();
			expect( mockLaunch ).not.toHaveBeenCalled();
		} );

		test( 'does not launch when the user is not a recent donor and campaign does not match', async () => {
			mockRecentlyDonated.mockReturnValue( false );
			setupMw( { campaign: 'unrelated-campaign', group: 'treatment', isNamed: true } );
			await init();
			await nextTick();
			expect( mockLaunch ).not.toHaveBeenCalled();
		} );

		test( 'does not launch when the user has already consented', async () => {
			mockHasConsented.mockReturnValue( true );
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: true, isAnon: false } );
			await init();
			await nextTick();
			expect( mockLaunch ).not.toHaveBeenCalled();
		} );

		test( 'does not launch when the storage flag is set and only the donor path applies', async () => {
			mockRecentlyDonated.mockReturnValue( true );
			setupMw( {
				campaign: null,
				group: 'treatment',
				isNamed: true,
				suppressOverlay: '1'
			} );
			await init();
			await nextTick();

			expect( mockLaunch ).not.toHaveBeenCalled();
		} );

		test( 'launches when the campaign matches even if the storage flag is set', async () => {
			setupMw( {
				campaign: DEFAULT_CAMPAIGN,
				isNamed: true,
				suppressOverlay: '1'
			} );
			await init();
			await nextTick();

			expect( mockLaunch ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'account-creation check for IP blocked users', () => {
		test( 'launches when an anonymous user can create an account', async () => {
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: false } );
			await init();
			await nextTick();
			expect( mw.Api ).toHaveBeenCalled();
			expect( mockAjax ).toHaveBeenCalledWith( expect.objectContaining( {
				action: 'query',
				meta: 'userinfo',
				uiprop: 'blockinfo|cancreateaccount'
			} ) );
			expect( mockLaunch ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'does not launch when an anonymous user cannot create an account', async () => {
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: false } );
			mockAjax.mockReturnValue( Promise.resolve( {
				query: { userinfo: { cancreateaccount: false } }
			} ) );
			await init();
			await nextTick();
			expect( mockLaunch ).not.toHaveBeenCalled();
		} );

		test( 'treats a missing userinfo query as able to create an account', async () => {
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: false } );
			mockAjax.mockReturnValue( Promise.resolve( {} ) );
			await init();
			await nextTick();
			expect( mockLaunch ).toHaveBeenCalledTimes( 1 );
		} );

		test( 'skips the account-creation check for named users', async () => {
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: true } );
			await init();
			await nextTick();
			expect( mw.Api ).not.toHaveBeenCalled();
			expect( mockLaunch ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'experiment', () => {
		test( 'requests the donor-status-consent experiment group', async () => {
			setupMw( { isNamed: true } );
			await init();
			expect( mw.testKitchen.getExperiment ).toHaveBeenCalledWith( 'donor-status-consent' );
		} );
	} );

	describe( 'returning from account creation', () => {
		const INITIAL_URL = 'https://example.test/wiki/Foo?newdonoraccount=1&other=keep';

		beforeEach( () => {
			// Reset the URL between tests within this block, since init() mutates
			// window.location by stripping the newdonoraccount param.
			window.history.replaceState( null, '', INITIAL_URL );
		} );

		test( 'removes the newdonoraccount param from the URL', async () => {
			setupMw( { newdonoraccount: '1' } );
			await init();
			await nextTick();

			const url = new URL( window.location.href );
			expect( url.searchParams.has( 'newdonoraccount' ) ).toBe( false );
			// Other query params are preserved.
			expect( url.searchParams.get( 'other' ) ).toBe( 'keep' );
		} );

		test( 'sets the suppress-overlay storage flag with no expiry', async () => {
			setupMw( { newdonoraccount: '1' } );
			await init();
			await nextTick();

			expect( mw.storage.set ).toHaveBeenCalledWith(
				STORAGE_KEY_SUPPRESS_OVERLAY,
				'1'
			);
		} );

		test( 'shows the success notification', async () => {
			setupMw( { newdonoraccount: '1' } );
			await init();
			await nextTick();

			expect( mw.message ).toHaveBeenCalledWith( SUCCESS_MESSAGE_KEY );
			expect( mw.notify ).toHaveBeenCalledTimes( 1 );
			expect( mw.notify ).toHaveBeenCalledWith( { key: SUCCESS_MESSAGE_KEY } );
		} );

		test( 'does not launch the dialog', async () => {
			mockRecentlyDonated.mockReturnValue( true );
			setupMw( {
				newdonoraccount: '1',
				campaign: DEFAULT_CAMPAIGN,
				isNamed: true
			} );
			await init();
			await nextTick();

			expect( mockLaunch ).not.toHaveBeenCalled();
			// Also should not probe the experiment framework on the early-return path.
			expect( mw.testKitchen.getExperiment ).not.toHaveBeenCalled();
		} );
	} );
} );
