// Reuses ShareHighlight's already-registered value rather than claiming a new
// one. @see https://wikitech.wikimedia.org/wiki/Provenance
const WPROV_VALUE = 'shhu0';

/**
 * @param {string} articleTitle - Article title (display or DB key form)
 * @return {string} Full URL, with the provenance parameter
 */
function buildShareUrl( articleTitle ) {
	const articlePath = mw.util.getUrl( articleTitle );
	const url = new URL( articlePath, location.origin );
	url.searchParams.set( 'wprov', WPROV_VALUE );
	return url.href;
}

module.exports = {
	buildShareUrl
};
