/**
 * Renders the card to a PNG and hands it to the Web Share API, falling back to
 * a download where the browser can't share files.
 */

const { ref } = require( 'vue' );
const { share } = require( 'mediawiki.page.ready' );
const { buildShareUrl } = require( 'ext.wikimediaCustomizations.shareCard.init' );
const imageGenerator = require( '../utils/imageGenerator.js' );
const truncateText = require( '../utils/truncateText.js' );

/** Maximum characters for the extract in the share text body. */
const MAX_SHARE_TEXT_LENGTH = 200;

/**
 * @param {string} articleTitle
 * @return {string} Filename for the generated PNG
 */
function imageFilename( articleTitle ) {
	const safeTitle = articleTitle
		// Replace only what filesystems object to, so titles in non-Latin
		// scripts keep their name.
		.replace( /[\\/:*?"<>|\s]+/g, '-' )
		.replace( /^[-.]+|[-.]+$/g, '' );
	return ( safeTitle || 'share-card' ) + '.png';
}

/**
 * Whether the Web Share API can share files, not just text.
 *
 * @return {boolean}
 */
function canShareFiles() {
	if ( !navigator.share || !navigator.canShare ) {
		return false;
	}
	try {
		const testFile = new File( [ '' ], 'test.png', { type: 'image/png' } );
		return navigator.canShare( { files: [ testFile ] } );
	} catch ( e ) {
		return false;
	}
}

/**
 * @return {Object} Share functionality and state
 */
module.exports = function useShareCard() {
	const isProcessing = ref( false );
	const error = ref( null );

	/**
	 * Track a share or download as it runs, recording any failure.
	 *
	 * @param {Promise<boolean>} promise
	 * @return {Promise<boolean>} False on failure
	 */
	const track = function ( promise ) {
		isProcessing.value = true;
		error.value = null;
		return promise
			.catch( ( e ) => {
				error.value = e;
				// eslint-disable-next-line no-console
				console.error( 'Share card failed:', e );
				return false;
			} )
			.then( ( result ) => {
				isProcessing.value = false;
				return result;
			} );
	};

	/**
	 * @param {HTMLElement} cardElement
	 * @param {string} articleTitle
	 * @param {string} text The article text shown on the card
	 * @return {Promise<boolean>} True if the reader completed the share
	 */
	const shareCard = function ( cardElement, articleTitle, text ) {
		return track( imageGenerator.generateImageBlob( cardElement ).then( ( imageBlob ) => {
			const siteName = mw.config.get( 'wgSiteName' );
			const shareData = {
				files: [ imageGenerator.blobToFile( imageBlob, imageFilename( articleTitle ) ) ],
				title: mw.msg(
					'wikimediacustomizations-sharecard-share-title',
					articleTitle,
					siteName
				),
				text: mw.msg(
					'wikimediacustomizations-sharecard-share-text',
					truncateText( text, MAX_SHARE_TEXT_LENGTH ),
					buildShareUrl( articleTitle ),
					siteName
				)
			};

			// share() resolves false for this, as for a cancelled share.
			if ( !navigator.canShare( shareData ) ) {
				throw new Error( 'Sharing not supported for this content' );
			}

			return share( shareData );
		} ) );
	};

	/**
	 * @param {HTMLElement} cardElement
	 * @param {string} articleTitle
	 * @return {Promise<boolean>} True if the download started
	 */
	const downloadCardImage = function ( cardElement, articleTitle ) {
		return track( imageGenerator.generateImageBlob( cardElement ).then( ( imageBlob ) => {
			imageGenerator.downloadBlob( imageBlob, imageFilename( articleTitle ) );
			return true;
		} ) );
	};

	return {
		canShareFiles: canShareFiles(),
		isProcessing,
		error,
		shareCard,
		downloadCardImage
	};
};
