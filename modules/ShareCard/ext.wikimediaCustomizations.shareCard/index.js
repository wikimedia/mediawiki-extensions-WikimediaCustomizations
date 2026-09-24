const Vue = require( 'vue' );
const App = require( './App.vue' );

let app = null;

/**
 * Open the share dialog, mounting the app on first use.
 */
function open() {
	if ( !app ) {
		const container = document.createElement( 'div' );
		document.getElementById( 'content' ).appendChild( container );
		app = Vue.createMwApp( App ).mount( container );
	}
	app.open();
}

module.exports = {
	open
};
