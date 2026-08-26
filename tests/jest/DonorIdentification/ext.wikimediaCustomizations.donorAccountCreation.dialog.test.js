'use strict';

const mockMount = jest.fn();
const mockUnmount = jest.fn();
let mockAppProps;
const mockCreateMwApp = jest.fn( ( _component, props ) => {
	mockAppProps = props;
	return { mount: mockMount, unmount: mockUnmount };
} );

jest.mock( 'vue', () => ( { createMwApp: mockCreateMwApp } ), { virtual: true } );

const MOCK_COMPONENT = {};
jest.mock(
	'../../../modules/DonorIdentification/ext.wikimediaCustomizations.donorAccountCreation.dialog/AccountCreationDialog.vue',
	() => MOCK_COMPONENT
);

const MODULE_PATH = '../../../modules/DonorIdentification/ext.wikimediaCustomizations.donorAccountCreation.dialog/dialog.js';

describe( 'donorAccountCreation dialog launcher', () => {
	let dialog;

	beforeEach( () => {
		jest.resetModules();
		document.body.innerHTML = '';
		mockAppProps = undefined;
		dialog = require( MODULE_PATH );
	} );

	test( 'exposes a launch function', () => {
		expect( typeof dialog.launch ).toBe( 'function' );
	} );

	describe( 'launch()', () => {
		test( 'appends a container element to the body', () => {
			dialog.launch();
			expect( document.body.children.length ).toBe( 1 );
			expect( document.body.firstChild.tagName ).toBe( 'DIV' );
		} );

		test( 'creates the app with the dialog component', () => {
			dialog.launch();
			expect( mockCreateMwApp ).toHaveBeenCalledTimes( 1 );
			expect( mockCreateMwApp.mock.calls[ 0 ][ 0 ] ).toBe( MOCK_COMPONENT );
		} );

		test( 'mounts the app onto the container', () => {
			dialog.launch();
			const container = document.body.firstChild;
			expect( mockMount ).toHaveBeenCalledTimes( 1 );
			expect( mockMount ).toHaveBeenCalledWith( container );
		} );

		test( 'forwards the supplied props to the app', () => {
			dialog.launch( { group: 'control' } );
			expect( mockAppProps.group ).toBe( 'control' );
		} );

		test( 'passes an onClose callback to the app', () => {
			dialog.launch();
			expect( typeof mockAppProps.onClose ).toBe( 'function' );
		} );

		test( 'does not overwrite a caller-supplied onClose', () => {
			// The launcher's own onClose is merged last, so it wins over any
			// onClose passed in props.
			const callerClose = jest.fn();
			dialog.launch( { onClose: callerClose } );
			mockAppProps.onClose();
			expect( callerClose ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'onClose', () => {
		test( 'unmounts the app and removes the container', () => {
			dialog.launch();
			const container = document.body.firstChild;
			expect( document.body.contains( container ) ).toBe( true );

			mockAppProps.onClose();

			expect( mockUnmount ).toHaveBeenCalledTimes( 1 );
			expect( document.body.contains( container ) ).toBe( false );
		} );

		test( 'isolates each launched dialog to its own container', () => {
			dialog.launch();
			const firstContainer = document.body.firstChild;
			const firstClose = mockAppProps.onClose;

			dialog.launch();
			const secondContainer = document.body.lastChild;

			expect( document.body.children.length ).toBe( 2 );

			// Closing the first dialog leaves the second one untouched.
			firstClose();
			expect( document.body.contains( firstContainer ) ).toBe( false );
			expect( document.body.contains( secondContainer ) ).toBe( true );
		} );
	} );
} );
