const { computed } = require( 'vue' );
const { getPageData } = require( 'ext.wikimediaCustomizations.shareCard.init' );
const usePromised = require( './usePromised.js' );

/**
 * The current page's summary, signals and image information, loaded
 * while `isOpen` is true.
 *
 * @param {import('vue').Ref<boolean>} isOpen
 * @param {function(Error): void} onError Called if the summary or image
 *  information fails
 * @return {Object} Refs, each null while loading: `summary`, the REST API
 *  page summary; `signals`, { pageViews, referenceCount }, either of which
 *  may be null, and which never fails; `imageInfo`,
 *  { author, license, nonFree }, author and license empty if unknown
 */
module.exports = function usePageData( isOpen, onError ) {
	const pageData = getPageData();
	// usePromised loads whenever its source changes, so switching between
	// the page data and null on open and close is what retries.
	const source = computed( () => ( isOpen.value ? pageData : null ) );

	return {
		summary: usePromised( source, ( page ) => page.getSummary(), onError ),
		signals: usePromised( source, ( page ) => page.getSignals() ),
		imageInfo: usePromised( source, ( page ) => page.getImageInfo(), onError )
	};
};
