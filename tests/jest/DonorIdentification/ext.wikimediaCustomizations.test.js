'use strict';

const relationships = require( '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donor/relationships.json' );
const {
	recentlyDonated,
	hasConsented,
	isRelationship,
	isDonor,
	consent,
	revokeConsent
} = require( '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donor' );

const DONOR_KEY = 'wikimedia-donor';

/**
 * @param {Object} [overrides]
 * @return {Object}
 */
function setupMw( overrides = {} ) {
	const optionValues = {};
	const saveOption = jest.fn( () => Promise.resolve() );
	const Api = jest.fn( () => ( { saveOption } ) );

	global.mw = {
		cookie: {
			get: jest.fn( () => '' )
		},
		user: {
			isNamed: jest.fn( () => true ),
			options: {
				values: optionValues,
				get: jest.fn( () => null ),
				set: jest.fn( ( key, value ) => {
					optionValues[ key ] = value;
				} )
			}
		},
		Api,
		...overrides
	};

	return { saveOption, Api, optionValues };
}

/**
 * @param {number} value
 * @return {string}
 */
function donorOption( value ) {
	return JSON.stringify( { value } );
}

describe( 'DonorIdentification', () => {
	beforeEach( () => {
		document.documentElement.className = '';
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	test( 'recentlyDonated (no cookie)', () => {
		global.mw = {
			cookie: {
				get: jest.fn(
					() => ''
				)
			}
		};
		expect( recentlyDonated() ).toBe( false );
	} );

	test( 'recentlyDonated (now)', () => {
		const created = Date.now() / 1000;
		global.mw = {
			cookie: {
				get: jest.fn(
					() => JSON.stringify( {
						v: 1,
						created,
						reason: 'donate'
					} )
				)
			}
		};
		expect( recentlyDonated( 0 ) ).toBe( true );
	} );

	test( 'recentlyDonated (within 30 days)', () => {
		// 30 days + 1 hr ago
		const created = (
			new Date( Date.now() - ( 60 * 60 * 25 * 30 * 1000 ) )
		) / 1000;
		global.mw = {
			cookie: {
				get: jest.fn(
					() => JSON.stringify( {
						v: 1,
						created,
						reason: 'donate'
					} )
				)
			}
		};

		expect( recentlyDonated( 0 ) ).toBe( false );
		expect( recentlyDonated( 1 ) ).toBe( false );
		expect( recentlyDonated( 29 ) ).toBe( false );
		expect( recentlyDonated( 30 ) ).toBe( false );
		expect( recentlyDonated( 31 ) ).toBe( true );
		expect( recentlyDonated( 32 ) ).toBe( true );
		expect( recentlyDonated() ).toBe( true );
	} );

	test( 'recentlyDonated (no created timestamp)', () => {
		setupMw( {
			cookie: {
				get: jest.fn( () => JSON.stringify( { v: 1, reason: 'donate' } ) )
			}
		} );
		expect( recentlyDonated() ).toBe( false );
		expect( recentlyDonated( 30 ) ).toBe( false );
	} );

	describe( 'hasConsented', () => {
		test( 'throws for anonymous users', () => {
			setupMw();
			global.mw.user.isNamed.mockReturnValue( false );
			expect( () => hasConsented() ).toThrow(
				'Donor consent API is only supported for logged-in users'
			);
		} );

		test( 'returns false when relationship value is 0', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( 0 ) );
			expect( hasConsented() ).toBe( false );
		} );

		test( 'returns false when preference is missing or invalid JSON', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( null );
			expect( hasConsented() ).toBe( false );

			global.mw.user.options.get.mockReturnValue( '' );
			expect( hasConsented() ).toBe( false );

			global.mw.user.options.get.mockReturnValue( 'not-json' );
			expect( hasConsented() ).toBe( false );
		} );

		test( 'returns true when relationship value is greater than 0', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Recent ) );
			expect( hasConsented() ).toBe( true );
		} );
	} );

	describe( 'isRelationship', () => {
		test( 'throws for anonymous users', () => {
			setupMw();
			global.mw.user.isNamed.mockReturnValue( false );
			expect( () => isRelationship( 'Recent' ) ).toThrow(
				'Donor consent API is only supported for logged-in users'
			);
		} );

		test( 'throws for an unknown relationship type', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Recent ) );
			expect( () => isRelationship( 'Unknown' ) ).toThrow(
				`type parameter should be one of: ${ Object.keys( relationships ).join( ',' ) }`
			);
		} );

		test.each( Object.keys( relationships ) )(
			'returns true only for matching type %s',
			( type ) => {
				setupMw();
				global.mw.user.options.get.mockReturnValue( donorOption( relationships[ type ] ) );
				Object.keys( relationships ).forEach( ( otherType ) => {
					expect( isRelationship( otherType ) ).toBe( otherType === type );
				} );
			}
		);
	} );

	describe( 'isDonor', () => {
		test( 'throws for anonymous users', () => {
			setupMw();
			global.mw.user.isNamed.mockReturnValue( false );
			expect( () => isDonor() ).toThrow(
				'Donor consent API is only supported for logged-in users'
			);
		} );

		test( 'returns false when the user has not consented', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( 0 ) );
			expect( isDonor() ).toBe( false );
		} );

		test( 'returns false for Contactable donors', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Contactable ) );
			expect( isDonor() ).toBe( false );
		} );

		test( 'returns true for consented non-Contactable relationships', () => {
			setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Recent ) );
			expect( isDonor() ).toBe( true );

			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Sustaining ) );
			expect( isDonor() ).toBe( true );
		} );
	} );

	describe( 'consent', () => {
		test( 'throws for anonymous users', () => {
			setupMw();
			global.mw.user.isNamed.mockReturnValue( false );
			expect( () => consent() ).toThrow(
				'Donor consent API is only supported for logged-in users'
			);
		} );

		test( 'does nothing when the user has already consented', async () => {
			const { saveOption } = setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Contactable ) );

			consent();
			await Promise.resolve();

			expect( saveOption ).not.toHaveBeenCalled();
		} );

		test( 'stores Recent when a fundraising cookie is present', async () => {
			jest.spyOn( Date, 'now' ).mockReturnValue( 1700000000000 );
			const { saveOption, optionValues } = setupMw( {
				cookie: {
					get: jest.fn( () => JSON.stringify( {
						v: 1,
						created: Date.now() / 1000,
						reason: 'donate'
					} ) )
				}
			} );
			global.mw.user.options.get.mockReturnValue( donorOption( 0 ) );
			document.documentElement.classList.add( 'wikimedia-donor-clientpref-0' );

			consent( { campaign: 'spring' } );
			await Promise.resolve();

			const expected = JSON.stringify( {
				campaign: 'spring',
				value: relationships.Recent,
				timestamp: 1700000000000
			} );
			expect( saveOption ).toHaveBeenCalledWith(
				DONOR_KEY,
				expected,
				{ global: 'create' }
			);
			expect( optionValues[ DONOR_KEY ] ).toBe( expected );
			expect( global.mw.user.options.set ).toHaveBeenCalledWith( DONOR_KEY, expected );
			expect( document.documentElement.classList.contains( 'wikimedia-donor-clientpref-0' ) )
				.toBe( false );
			expect( document.documentElement.classList.contains(
				`wikimedia-donor-clientpref-${ relationships.Recent }`
			) ).toBe( true );
		} );

		test( 'stores Contactable when no fundraising cookie is present', async () => {
			jest.spyOn( Date, 'now' ).mockReturnValue( 1700000000000 );
			const { saveOption } = setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( 0 ) );

			consent();
			await Promise.resolve();

			expect( saveOption ).toHaveBeenCalledWith(
				DONOR_KEY,
				JSON.stringify( {
					value: relationships.Contactable,
					timestamp: 1700000000000
				} ),
				{ global: 'create' }
			);
		} );
	} );

	describe( 'revokeConsent', () => {
		test( 'throws for anonymous users', () => {
			setupMw();
			global.mw.user.isNamed.mockReturnValue( false );
			expect( () => revokeConsent() ).toThrow(
				'Donor consent API is only supported for logged-in users'
			);
		} );

		test( 'clears the preference and updates clientpref classes', async () => {
			const { saveOption, optionValues } = setupMw();
			global.mw.user.options.get.mockReturnValue( donorOption( relationships.Recent ) );
			document.documentElement.classList.add(
				`wikimedia-donor-clientpref-${ relationships.Recent }`
			);

			revokeConsent();
			await Promise.resolve();

			expect( saveOption ).toHaveBeenCalledWith(
				DONOR_KEY,
				'',
				{ global: 'create' }
			);
			expect( optionValues[ DONOR_KEY ] ).toBe( '' );
			expect( global.mw.user.options.set ).toHaveBeenCalledWith( DONOR_KEY, '' );
			expect( document.documentElement.classList.contains(
				`wikimedia-donor-clientpref-${ relationships.Recent }`
			) ).toBe( false );
			expect( document.documentElement.classList.contains( 'wikimedia-donor-clientpref-0' ) )
				.toBe( true );
		} );
	} );
} );
