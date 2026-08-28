'use strict';

const MODULE_PATH = '../../../modules/DonorIdentification/ext.wikimediaCustomizations.preferences/index.js';
const DONOR_INPUT_NAME = 'wpwikimedia-donor';
const CONFIRM_MESSAGE_KEY = 'wikimediacustomizations-donor-unlink-confirm';

/**
 * @param {Object} [options]
 * @param {boolean} [options.checked]
 * @return {{form: HTMLFormElement, checkbox: HTMLInputElement}}
 */
function buildForm( { checked = false } = {} ) {
	const form = document.createElement( 'form' );
	const checkbox = document.createElement( 'input' );
	checkbox.type = 'checkbox';
	checkbox.name = DONOR_INPUT_NAME;
	checkbox.checked = checked;
	form.appendChild( checkbox );
	document.body.appendChild( form );
	return { form, checkbox };
}

function load() {
	require( MODULE_PATH );
}

describe( 'ext.wikimediaCustomizations.preferences', () => {
	let confirmPromise;
	let resolveConfirm;

	beforeEach( () => {
		document.body.innerHTML = '';
		jest.resetModules();

		confirmPromise = new Promise( ( resolve ) => {
			resolveConfirm = resolve;
		} );

		global.mw = {
			msg: jest.fn( ( key ) => key )
		};
		global.OO = {
			ui: {
				confirm: jest.fn( () => confirmPromise )
			}
		};

		Object.defineProperty( document, 'readyState', {
			configurable: true,
			value: 'complete'
		} );
	} );

	afterEach( () => {
		delete global.mw;
		delete global.OO;
	} );

	test( 'submits normally when the checkbox is checked', () => {
		const { form } = buildForm( { checked: true } );
		load();

		const event = new Event( 'submit', { cancelable: true } );
		form.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( false );
		expect( global.OO.ui.confirm ).not.toHaveBeenCalled();
	} );

	test( 'waits for confirmation before allowing an unlink submit', () => {
		const { form } = buildForm( { checked: false } );
		load();

		const event = new Event( 'submit', { cancelable: true } );
		form.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
		expect( global.mw.msg ).toHaveBeenCalledWith( CONFIRM_MESSAGE_KEY );
		expect( global.OO.ui.confirm ).toHaveBeenCalledWith( CONFIRM_MESSAGE_KEY );
	} );

	test( 'resubmits the form once the user confirms', async () => {
		const { form } = buildForm( { checked: false } );
		load();
		form.requestSubmit = jest.fn();

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		resolveConfirm( true );
		await confirmPromise;

		expect( form.requestSubmit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'leaves the form unsubmitted when the user declines', async () => {
		const { form } = buildForm( { checked: false } );
		load();
		form.requestSubmit = jest.fn();

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		resolveConfirm( false );
		await confirmPromise;

		expect( form.requestSubmit ).not.toHaveBeenCalled();
	} );

	test( 'does not re-prompt once the user has confirmed', async () => {
		const { form } = buildForm( { checked: false } );
		load();
		form.requestSubmit = jest.fn();

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		resolveConfirm( true );
		await confirmPromise;

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );

		expect( global.OO.ui.confirm ).toHaveBeenCalledTimes( 1 );
		expect( form.requestSubmit ).toHaveBeenCalledTimes( 1 );
	} );
} );
