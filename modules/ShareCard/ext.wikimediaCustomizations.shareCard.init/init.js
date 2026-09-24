const PageData = require( './PageData.js' );

let pageData = null;

/**
 * The current page's data, shared by the share link and the dialog.
 *
 * @return {PageData}
 */
function getPageData() {
	if ( !pageData ) {
		pageData = new PageData( mw.config.get( 'wgPageName' ) );
	}
	return pageData;
}

// Takes over the core share link, whose own handler skips clicks already
// claimed with preventDefault(). Core's share() remains the fallback if the
// dialog fails to load.
const link = document.querySelector( 'a.mw-page-share[data-mw-interface]' );
if ( link ) {
	link.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		// Start on the card's data while the dialog's code loads. Failures
		// are left to the dialog, which asks again.
		getPageData().getSummary().catch( () => {} );
		getPageData().getSignals();
		getPageData().getImageInfo().catch( () => {} );
		mw.loader.using( 'ext.wikimediaCustomizations.shareCard' ).then(
			( req ) => req( 'ext.wikimediaCustomizations.shareCard' ).open(),
			() => require( 'mediawiki.page.ready' ).share( { url: link.href } )
		);
	} );
}

module.exports = {
	getPageData
};
