/**
 * @param {HTMLCanvasElement} canvas
 * @return {Promise<string>} Object URL of a PNG of the canvas
 */
function canvasUrl( canvas ) {
	return new Promise( ( resolve, reject ) => {
		canvas.toBlob( ( blob ) => {
			if ( blob ) {
				resolve( URL.createObjectURL( blob ) );
			} else {
				reject( new Error( 'canvas.toBlob returned null' ) );
			}
		}, 'image/png' );
	} );
}

/**
 * @param {Blob} blob
 * @return {Promise<HTMLCanvasElement>} The middle frame
 */
async function middleFrame( blob ) {
	// Only called where the browser has ImageDecoder.
	// eslint-disable-next-line compat/compat
	const decoder = new window.ImageDecoder( { data: blob.stream(), type: 'image/gif' } );
	try {
		await decoder.completed;
		const frameIndex = Math.floor( decoder.tracks.selectedTrack.frameCount / 2 );
		const { image } = await decoder.decode( { frameIndex } );
		const canvas = document.createElement( 'canvas' );
		canvas.width = image.displayWidth;
		canvas.height = image.displayHeight;
		canvas.getContext( '2d' ).drawImage( image, 0, 0 );
		image.close();
		return canvas;
	} finally {
		decoder.close();
	}
}

/**
 * @param {Blob} blob
 * @return {Promise<HTMLCanvasElement>} The first frame
 */
async function firstFrame( blob ) {
	const image = new Image();
	image.src = URL.createObjectURL( blob );
	try {
		await image.decode();
		// Canvas draws the first frame of an animated image, by the HTML spec.
		const canvas = document.createElement( 'canvas' );
		canvas.width = image.naturalWidth;
		canvas.height = image.naturalHeight;
		canvas.getContext( '2d' ).drawImage( image, 0, 0 );
		return canvas;
	} finally {
		URL.revokeObjectURL( image.src );
	}
}

/**
 * A still of a GIF. Animations often start or end on an empty frame, so this
 * is the middle frame where the browser can decode frames (ImageDecoder),
 * otherwise the first.
 *
 * @param {string} src URL of the GIF, on a host that allows CORS
 * @return {Promise<string>} Object URL of a PNG, which the caller revokes
 */
module.exports = async function stillFrame( src ) {
	const response = await fetch( src );
	if ( !response.ok ) {
		throw new Error( `${ src } returned ${ response.status }` );
	}
	const blob = await response.blob();
	let canvas;
	if ( window.ImageDecoder ) {
		canvas = await middleFrame( blob ).catch( () => firstFrame( blob ) );
	} else {
		canvas = await firstFrame( blob );
	}
	return canvasUrl( canvas );
};
