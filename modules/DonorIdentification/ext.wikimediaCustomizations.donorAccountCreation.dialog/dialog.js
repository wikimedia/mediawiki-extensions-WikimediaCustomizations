'use strict';

const { createMwApp } = require( 'vue' );
const AccountCreationDialog = require( './AccountCreationDialog.vue' );

/**
 * Mount and display the donor account creation confirmation dialog.
 */
function launch( props ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const app = createMwApp( AccountCreationDialog, Object.assign( {}, props, {
		onClose: () => {
			app.unmount();
			container.remove();
		}
	} ) );
	app.mount( container );
}

module.exports = {
	launch
};
