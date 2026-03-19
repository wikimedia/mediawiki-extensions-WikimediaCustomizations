'use strict';
const { recentlyDonated } = require( '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donor' );

describe( 'DonorIdentification', () => {
	beforeEach( () => {
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
} );
