const { apiBaseUri } = require( './config.json' );

const origin = apiBaseUri ? new URL( apiBaseUri ).origin : '';
const restUrl = apiBaseUri ?
	new URL( 'rest.php', apiBaseUri ).href :
	mw.config.get( 'wgScriptPath' ) + '/rest.php';
const actionApiUrl = apiBaseUri || mw.util.wikiScript( 'api' );

/** @type {{author: string, license: string, nonFree: boolean}} */
const NO_IMAGE_INFO = { author: '', license: '', nonFree: false };

/**
 * The visible text of an HTML fragment. Commons metadata repeats some values
 * in hidden elements for machines, as in "Unknown author<span
 * style="display: none;">Unknown author</span>", so those are dropped.
 *
 * @param {string} html
 * @return {string}
 */
function textFromHtml( html ) {
	const body = new DOMParser().parseFromString( html, 'text/html' ).body;
	Array.from( body.querySelectorAll( '[style]' ) )
		.filter( ( element ) => element.style.display === 'none' )
		.forEach( ( element ) => element.remove() );
	return body.textContent.trim();
}

/**
 * @param {string} url
 * @return {Promise<Object>}
 */
async function fetchJson( url ) {
	const response = await fetch( url );
	if ( !response.ok ) {
		throw new Error( `${ url } returned ${ response.status }` );
	}
	// A wiki that doesn't serve this endpoint can still answer 200, with an
	// HTML page. Point $wgWMCShareCardApiBaseUri at one that does.
	const contentType = response.headers.get( 'content-type' ) || '';
	if ( !contentType.includes( 'json' ) ) {
		throw new Error( `${ url } returned ${ contentType }, expected JSON` );
	}
	return response.json();
}

/**
 * The data a page's share card shows. Each request is made once and shared
 * by every caller; a failed one is forgotten, so asking again retries it.
 */
class PageData {
	/**
	 * @param {string} pageName Prefixed title in DB form, e.g. "Chamber_music",
	 *  as from wgPageName or mw.Title#getPrefixedDb
	 */
	constructor( pageName ) {
		this.pageName = pageName;
		this.summaryRequest = null;
		this.signalsRequest = null;
		this.imageInfoRequest = null;
	}

	/**
	 * @return {Promise<Object>} The REST API page summary
	 */
	getSummary() {
		if ( !this.summaryRequest ) {
			this.summaryRequest = fetchJson(
				`${ origin }/api/rest_v1/page/summary/${ encodeURIComponent( this.pageName ) }`
			);
			this.summaryRequest.catch( () => ( this.summaryRequest = null ) );
		}
		return this.summaryRequest;
	}

	/**
	 * Page views over the last 30 days and reference count, from the
	 * Attribution API. Either is null if unavailable; the card renders
	 * without them, so this never rejects.
	 *
	 * @return {Promise<{pageViews: number|null, referenceCount: number|null}>}
	 */
	getSignals() {
		if ( !this.signalsRequest ) {
			this.signalsRequest = fetchJson(
				`${ restUrl }/attribution/v0-beta/pages/${ encodeURIComponent( this.pageName ) }/signals?expand=trust_and_relevance`
			);
			this.signalsRequest.catch( () => ( this.signalsRequest = null ) );
		}
		return this.signalsRequest.then( ( data ) => {
			const values = data.trust_and_relevance || {};
			return {
				pageViews: typeof values.page_views === 'number' ? values.page_views : null,
				referenceCount: typeof values.reference_count === 'number' ? values.reference_count : null
			};
		}, ( e ) => {
			// eslint-disable-next-line no-console
			console.warn( 'Page signals unavailable:', e );
			return { pageViews: null, referenceCount: null };
		} );
	}

	/**
	 * Author, license and non-free status of the summary's image, from its
	 * extended metadata. Waits for the summary, which names the image.
	 *
	 * @return {Promise<{author: string, license: string, nonFree: boolean}>}
	 *  Author and license are plain text, each empty if unknown, or both if
	 *  the page has no image. `nonFree` is true for files marked non-free,
	 *  such as album covers and film posters used under fair use
	 */
	getImageInfo() {
		if ( !this.imageInfoRequest ) {
			this.imageInfoRequest = this.getSummary().then( ( summary ) => {
				// Take the name from the summary's own thumbnail URL.
				const parsedUrl = summary.thumbnail &&
					mw.util.parseImageUrl( summary.thumbnail.source );
				if ( !parsedUrl ) {
					return NO_IMAGE_INFO;
				}

				const params = new URLSearchParams( {
					action: 'query',
					prop: 'imageinfo',
					titles: 'File:' + parsedUrl.name,
					iiprop: 'extmetadata',
					iiextmetadatafilter: 'Artist|LicenseShortName|NonFree',
					iiextmetadatalanguage: mw.config.get( 'wgUserLanguage' ),
					format: 'json',
					formatversion: 2
				} );
				if ( apiBaseUri ) {
					// Anonymous cross-origin request.
					params.set( 'origin', '*' );
				}
				return fetchJson( `${ actionApiUrl }?${ params }` ).then( ( response ) => {
					const page = response.query.pages[ 0 ];
					const metadata = ( page.imageinfo && page.imageinfo[ 0 ].extmetadata ) || {};
					return {
						author: metadata.Artist ? textFromHtml( metadata.Artist.value ) : '',
						license: metadata.LicenseShortName ? textFromHtml( metadata.LicenseShortName.value ) : '',
						nonFree: !!metadata.NonFree && metadata.NonFree.value === 'true'
					};
				} );
			} );
			this.imageInfoRequest.catch( () => ( this.imageInfoRequest = null ) );
		}
		return this.imageInfoRequest;
	}
}

module.exports = PageData;
