/* global mw */
'use strict';

const { mount } = require( '@vue/test-utils' );

const mockConsent = jest.fn();
jest.mock( 'ext.wikimediaCustomizations.donor', () => ( {
	consent: mockConsent
} ), { virtual: true } );

const AccountCreationDialog = require(
	'../../../modules/DonorIdentification/ext.wikimediaCustomizations.donorAccountCreation.dialog/AccountCreationDialog.vue'
);

const STORAGE_KEY = 'test-storage-key';
const PAGE_NAME = 'Test_Page';
const RETURN_URL = '/wiki/Special:CreateAccount?returnto=Test_Page';
const SUCCESS_MESSAGE_KEY = 'wc-donor-account-creation-success-message';

const cdxDialogStub = {
	name: 'CdxDialog',
	props: [ 'open', 'title', 'useCloseButton' ],
	emits: [ 'update:open' ],
	template: `
		<div v-if="open" class="cdx-dialog" role="dialog">
			<header class="cdx-dialog__header">{{ title }}</header>
			<div class="cdx-dialog__body"><slot /></div>
			<slot name="footer" />
		</div>
	`
};

const cdxButtonStub = {
	name: 'CdxButton',
	props: [ 'weight', 'action' ],
	emits: [ 'click' ],
	template: '<button class="cdx-button" @click="$emit( \'click\', $event )"><slot /></button>'
};

function mountDialog( props = {} ) {
	return mount( AccountCreationDialog, {
		props: Object.assign( {
			storageKey: STORAGE_KEY
		}, props ),
		global: {
			stubs: {
				CdxDialog: cdxDialogStub,
				CdxButton: cdxButtonStub
			}
		}
	} );
}

describe( 'AccountCreationDialog', () => {
	let wrapper;

	beforeEach( () => {
		global.mw = {
			config: {
				get: jest.fn( ( key ) => {
					if ( key === 'wgPageName' ) {
						return PAGE_NAME;
					}
					return null;
				} )
			},
			msg: jest.fn( ( key ) => key ),
			message: jest.fn( ( key ) => ( {
				key,
				parse: jest.fn( () => key )
			} ) ),
			user: {
				isNamed: jest.fn( () => true )
			},
			util: {
				getUrl: jest.fn( () => RETURN_URL )
			},
			storage: {
				set: jest.fn()
			},
			notify: jest.fn()
		};
	} );

	afterEach( () => {
		if ( wrapper ) {
			wrapper.unmount();
			wrapper = null;
		}
	} );

	describe( 'for users in the control group', () => {
		test( 'matches the snapshot', () => {
			wrapper = mountDialog( { group: 'control' } );
			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'for users in the treatment group', () => {
		test( 'matches the snapshot', () => {
			wrapper = mountDialog( { group: 'treatment' } );
			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'for authenticated users', () => {
		beforeEach( () => {
			mw.user.isNamed.mockReturnValue( true );
		} );

		test( 'matches the snapshot', () => {
			wrapper = mountDialog();
			expect( wrapper.element ).toMatchSnapshot();
		} );

		describe( 'when the user clicks the yes button', () => {
			beforeEach( () => {
				wrapper = mountDialog( { campaign: 'reader-donor-account' } );
				const yesBtn = wrapper.findAll( '.cdx-button' )[ 0 ];
				return yesBtn.trigger( 'click' );
			} );

			test( 'records donor consent with the current campaign', () => {
				expect( mockConsent ).toHaveBeenCalledWith( {
					source: 'reader-donor-account'
				} );
			} );

			test( 'sets the suppress-overlay storage flag with no expiry', () => {
				expect( mw.storage.set ).toHaveBeenCalledWith( STORAGE_KEY, '1' );
			} );

			test( 'shows the success notification', () => {
				expect( mw.message ).toHaveBeenCalledWith( SUCCESS_MESSAGE_KEY );
				expect( mw.notify ).toHaveBeenCalledTimes( 1 );
			} );

			test( 'closes the dialog', () => {
				expect( wrapper.findComponent( cdxDialogStub ).props( 'open' ) ).toBe( false );
			} );
		} );
	} );

	describe( 'for anonymous users', () => {
		beforeEach( () => {
			mw.user.isNamed.mockReturnValue( false );
		} );

		test( 'matches the snapshot', () => {
			wrapper = mountDialog();
			expect( wrapper.element ).toMatchSnapshot();
		} );
	} );

	describe( 'when the user clicks the no button', () => {
		test( 'sets the suppress-overlay storage flag with no expiry and closes the dialog', async () => {
			wrapper = mountDialog();
			// The no button is the second button (yes button is index 0 for auth'd users).
			await wrapper.findAll( '.cdx-button' )[ 1 ].trigger( 'click' );

			expect( mw.storage.set ).toHaveBeenCalledWith( STORAGE_KEY, '1' );
			expect( wrapper.findComponent( cdxDialogStub ).props( 'open' ) ).toBe( false );
		} );

		test( 'does not record donor consent', async () => {
			wrapper = mountDialog();
			await wrapper.findAll( '.cdx-button' )[ 1 ].trigger( 'click' );

			expect( mockConsent ).not.toHaveBeenCalled();
			expect( mw.notify ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'when the user clicks "remind me later"', () => {
		test( 'sets the suppress-overlay storage flag with a 3-hour expiry', async () => {
			wrapper = mountDialog();
			await wrapper.findAll( '.cdx-button' )[ 2 ].trigger( 'click' );

			expect( mw.storage.set ).toHaveBeenCalledWith( STORAGE_KEY, '1', 60 * 60 * 3 );
		} );

		test( 'closes the dialog without recording consent', async () => {
			wrapper = mountDialog();
			await wrapper.findAll( '.cdx-button' )[ 2 ].trigger( 'click' );

			expect( mockConsent ).not.toHaveBeenCalled();
			expect( wrapper.findComponent( cdxDialogStub ).props( 'open' ) ).toBe( false );
		} );
	} );

	describe( 'when the dialog is closed via Esc or backdrop click', () => {
		test( 'sets the suppress-overlay storage flag with a 3-hour expiry', () => {
			wrapper = mountDialog();
			wrapper.findComponent( cdxDialogStub ).vm.$emit( 'update:open', false );

			expect( mw.storage.set ).toHaveBeenCalledWith( STORAGE_KEY, '1', 60 * 60 * 3 );
		} );

		test( 'does not record donor consent', () => {
			wrapper = mountDialog();
			wrapper.findComponent( cdxDialogStub ).vm.$emit( 'update:open', false );

			expect( mockConsent ).not.toHaveBeenCalled();
			expect( mw.notify ).not.toHaveBeenCalled();
		} );

		test( 'invokes the caller-supplied onClose', () => {
			const onClose = jest.fn();
			wrapper = mountDialog( { onClose } );
			wrapper.findComponent( cdxDialogStub ).vm.$emit( 'update:open', false );

			expect( onClose ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
