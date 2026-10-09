/**
 * Renders a DOM element to a PNG blob, via html-to-image.
 */

const htmlToImage = require( '../../../lib/html-to-image/html-to-image.js' );

/**
 * @param {HTMLElement} element - The DOM element to capture
 * @return {Promise<Blob>} PNG image as Blob, at 2x for high-density screens
 */
function generateImageBlob( element ) {
	const scale = 2;
	const backgroundColor = window.getComputedStyle( element ).backgroundColor;
	// The dialog preview-scales the live element with a transform. Pin the
	// clone to the card's natural size and neutralise that transform so the
	// PNG comes out full size; transformOrigin has to be reset too, or
	// inlineCss's own write offsets the clone inside the foreignObject. Only
	// the clone is affected, not the live preview.
	const toBlobOptions = {
		pixelRatio: scale,
		width: element.clientWidth,
		height: element.clientHeight,
		style: {
			transform: 'none',
			transformOrigin: '0 0'
		},
		// The card uses system fonts. Without this, the library reads every
		// stylesheet in the page, refetches any it can't read, and copies
		// their rules into the page's own (T440749).
		skipFonts: true,
		filter: function ( node ) {
			if ( node.tagName === 'SCRIPT' ) {
				return false;
			}
			return true;
		}
	};

	if ( backgroundColor ) {
		toBlobOptions.backgroundColor = backgroundColor;
	}

	// toCanvas rather than toBlob, so the article photo can be painted on
	// afterwards. See overdrawArticlePhoto.
	return htmlToImage.toCanvas( element, toBlobOptions ).then( ( canvas ) => {
		overdrawArticlePhoto( canvas, element, scale );
		return new Promise( ( resolve, reject ) => {
			canvas.toBlob( ( blob ) => {
				if ( !blob ) {
					reject( new Error( 'canvas.toBlob returned null' ) );
					return;
				}
				resolve( blob );
			}, 'image/png' );
		} );
	} );
}

/**
 * Paint the article photo onto html-to-image's canvas. The photo is the one
 * part of the card that iOS Safari fails to render through the foreignObject
 * pipeline (T426344); the page's IMG already holds the decoded bitmap, so
 * drawImage sidesteps the library's fetch and CORS path.
 *
 * getBoundingClientRect reports transformed coordinates while the clone
 * rendered at natural size, hence dividing the live scale back out.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {HTMLElement} element
 * @param {number} pixelRatio
 */
function overdrawArticlePhoto( canvas, element, pixelRatio ) {
	const img = element.querySelector( 'img.ext-wc-share-card__image' );
	if ( !img || !img.complete || !img.naturalWidth ) {
		// eslint-disable-next-line no-console
		console.warn(
			'[shareCard] overdrawArticlePhoto: img missing or not ready',
			'(found=', !!img, 'complete=', img && img.complete, 'naturalWidth=', img && img.naturalWidth, ')'
		);
		return;
	}

	let tScaleX = 1;
	let tScaleY = 1;
	const transformStr = window.getComputedStyle( element ).transform;
	if ( transformStr && transformStr !== 'none' ) {
		try {
			const m = new DOMMatrix( transformStr );
			tScaleX = m.a || 1;
			tScaleY = m.d || 1;
		} catch ( e ) {
			// Unparseable transform; fall back to identity.
		}
	}

	const cardRect = element.getBoundingClientRect();
	const imgRect = img.getBoundingClientRect();
	const imgCs = window.getComputedStyle( img );
	// Draw into the content box, inside any padding.
	const padLeft = parseFloat( imgCs.paddingLeft ) || 0;
	const padTop = parseFloat( imgCs.paddingTop ) || 0;
	const padRight = parseFloat( imgCs.paddingRight ) || 0;
	const padBottom = parseFloat( imgCs.paddingBottom ) || 0;
	let dx = ( ( imgRect.left - cardRect.left ) / tScaleX + padLeft ) * pixelRatio;
	let dy = ( ( imgRect.top - cardRect.top ) / tScaleY + padTop ) * pixelRatio;
	let dw = ( imgRect.width / tScaleX - padLeft - padRight ) * pixelRatio;
	let dh = ( imgRect.height / tScaleY - padTop - padBottom ) * pixelRatio;

	// Emulate the photo's `object-fit`: `cover` via drawImage's source rect,
	// `contain` by shrinking the destination rect, leaving the element's
	// background visible around it.
	if ( imgCs.objectFit === 'contain' ) {
		const fitScale = Math.min( dw / img.naturalWidth, dh / img.naturalHeight );
		const fitW = img.naturalWidth * fitScale;
		const fitH = img.naturalHeight * fitScale;
		dx += ( dw - fitW ) * parseObjectPositionAxis( imgCs.objectPosition, 0 );
		dy += ( dh - fitH ) * parseObjectPositionAxis( imgCs.objectPosition, 1 );
		dw = fitW;
		dh = fitH;
	}
	const crop = computeObjectFitCrop( img, dw, dh, imgCs.objectFit, imgCs.objectPosition );

	const ctx = canvas.getContext( '2d' );
	ctx.imageSmoothingEnabled = true;
	ctx.imageSmoothingQuality = 'high';
	try {
		ctx.drawImage( img, crop.sx, crop.sy, crop.sw, crop.sh, dx, dy, dw, dh );
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.warn( '[shareCard] overdrawArticlePhoto: drawImage failed', e );
	}
}

/**
 * Source rectangle for drawImage that emulates object-fit/object-position when
 * blitting `img` into a `dstW × dstH` destination.
 *
 * @param {HTMLImageElement} img
 * @param {number} dstW
 * @param {number} dstH
 * @param {string} objectFit
 * @param {string} objectPosition
 * @return {{sx: number, sy: number, sw: number, sh: number}}
 */
function computeObjectFitCrop( img, dstW, dstH, objectFit, objectPosition ) {
	const srcW = img.naturalWidth;
	const srcH = img.naturalHeight;
	if ( objectFit !== 'cover' ) {
		return { sx: 0, sy: 0, sw: srcW, sh: srcH };
	}
	const dstAspect = dstW / dstH;
	const srcAspect = srcW / srcH;
	if ( srcAspect > dstAspect ) {
		// Source is wider than the destination — crop horizontally.
		const sw = srcH * dstAspect;
		const sx = ( srcW - sw ) * parseObjectPositionAxis( objectPosition, 0 );
		return { sx: sx, sy: 0, sw: sw, sh: srcH };
	}
	// Source is taller than the destination — crop vertically.
	const sh = srcW / dstAspect;
	const sy = ( srcH - sh ) * parseObjectPositionAxis( objectPosition, 1 );
	return { sx: 0, sy: sy, sw: srcW, sh: sh };
}

/**
 * One axis (0=X, 1=Y) of a CSS `object-position` value, as a fraction in [0, 1].
 *
 * @param {string} str
 * @param {number} axis
 * @return {number}
 */
function parseObjectPositionAxis( str, axis ) {
	if ( !str ) {
		return 0.5;
	}
	const parts = str.trim().split( /\s+/ );
	const val = parts[ axis ] !== undefined ? parts[ axis ] : '50%';
	if ( val === 'top' || val === 'left' ) {
		return 0;
	}
	if ( val === 'bottom' || val === 'right' ) {
		return 1;
	}
	if ( val === 'center' || val === 'middle' ) {
		return 0.5;
	}
	if ( val.charAt( val.length - 1 ) === '%' ) {
		return parseFloat( val ) / 100;
	}
	return 0.5;
}

/**
 * @param {Blob} blob - Image blob
 * @param {string} [filename]
 * @return {File} File suitable for navigator.share()
 */
function blobToFile( blob, filename ) {
	return new File( [ blob ], filename || 'share-card.png', {
		type: blob.type || 'image/png',
		lastModified: Date.now()
	} );
}

/**
 * @param {Blob} blob - The blob to download
 * @param {string} [filename]
 */
function downloadBlob( blob, filename ) {
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename || 'share-card.png';
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
}

module.exports = {
	generateImageBlob: generateImageBlob,
	blobToFile: blobToFile,
	downloadBlob: downloadBlob
};
