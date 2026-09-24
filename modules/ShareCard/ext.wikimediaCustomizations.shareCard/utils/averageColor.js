/**
 * @typedef {Object} ColorResult
 * @property {string} hex
 * @property {boolean} isDark
 * @property {boolean} hasTransparency
 */

/**
 * Mean color of a loaded image's pixels, weighted by opacity.
 *
 * @param {HTMLImageElement} imageElement Must be CORS-enabled, or its pixels
 *  can't be read
 * @return {ColorResult|null} null if the pixels can't be read, or are all
 *  transparent
 */
module.exports = function averageColor( imageElement ) {
	const width = imageElement.naturalWidth;
	const height = imageElement.naturalHeight;
	const canvas = document.createElement( 'canvas' );
	canvas.width = width;
	canvas.height = height;
	const context = canvas.getContext( '2d', { willReadFrequently: true } );
	context.drawImage( imageElement, 0, 0 );

	let data;
	try {
		data = context.getImageData( 0, 0, width, height ).data;
	} catch ( e ) {
		// Not CORS-enabled.
		return null;
	}

	const totals = [ 0, 0, 0 ];
	let alphaTotal = 0;
	let hasTransparency = false;
	for ( let i = 0; i < data.length; i += 4 ) {
		const alpha = data[ i + 3 ];
		hasTransparency = hasTransparency || alpha < 255;
		totals[ 0 ] += data[ i ] * alpha;
		totals[ 1 ] += data[ i + 1 ] * alpha;
		totals[ 2 ] += data[ i + 2 ] * alpha;
		alphaTotal += alpha;
	}
	if ( !alphaTotal ) {
		return null;
	}

	const [ r, g, b ] = totals.map( ( total ) => Math.round( total / alphaTotal ) );
	return {
		hex: '#' + [ r, g, b ].map( ( c ) => c.toString( 16 ).padStart( 2, '0' ) ).join( '' ),
		// https://www.w3.org/TR/AERT/#color-contrast
		isDark: ( r * 299 + g * 587 + b * 114 ) / 1000 < 128,
		hasTransparency
	};
};
