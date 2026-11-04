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
		const vueApp = Vue.createMwApp( App );
		vueApp.config.errorHandler = ( error ) => {
			mw.errorLogger.logError( error, 'error.sharecard' );
			mw.log.error( error );
		};
		app = vueApp.mount( container );
	}
	app.open();
}

module.exports = {
	open
};
