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
				getParamValue: jest.fn( () => overrides.campaign !== undefined ? overrides.campaign : null )
			},
			user: {
				isNamed: jest.fn( () => overrides.isNamed !== undefined ? overrides.isNamed : true ),
				isAnon: jest.fn( () => overrides.isAnon !== undefined ? overrides.isAnon : true )
			},
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
				campaign: `foo-${ DEFAULT_CAMPAIGN }-bar`
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
				campaign: DEFAULT_CAMPAIGN
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
			setupMw( { campaign: DEFAULT_CAMPAIGN, isNamed: true } );
			await init();
			expect( mw.testKitchen.getExperiment ).toHaveBeenCalledWith( 'donor-status-consent' );
		} );
	} );
} );
