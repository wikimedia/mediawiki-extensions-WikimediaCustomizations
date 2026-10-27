const { computed, ref, watch } = require( 'vue' );

// The card renders 288px wide, so 576px covers a 2x pixel ratio.
const CARD_IMAGE_WIDTH = 576;
// Smaller originals look too poor on the card to be worth using.
const MIN_ORIGINAL_IMAGE_WIDTH = 288;

/**
 * A thumbnail URL for the card, at a standard production width.
 *
 * @param {string} thumbnailSrc Any existing thumbnail URL of the file
 * @param {string} originalSrc
 * @param {number} originalWidth
 * @return {string|null} null if the original is too small to use
 */
function cardImageSrc( thumbnailSrc, originalSrc, originalWidth ) {
	if ( originalWidth < MIN_ORIGINAL_IMAGE_WIDTH ) {
		return null;
	}

	const parsedUrl = mw.util.parseImageUrl( thumbnailSrc );
	if ( !parsedUrl || !parsedUrl.resizeUrl ) {
		// If we can't construct a new thumbnail URL, default to the
		// original src instead of throwing on unusual image URL shapes.
		return originalSrc;
	}

	// Must be a standard production thumbnail size, or the
	// thumbnail is uncached and may be rate limited.
	// @see https://www.mediawiki.org/wiki/Common_thumbnail_sizes
	const width = mw.util.adjustThumbWidthForSteps(
		CARD_IMAGE_WIDTH,
		originalWidth
	);
	// width only equals originalWidth when the original was
	// already narrower than every step, and thumbnail URLs at
	// non-step widths no longer work.
	return width === originalWidth ? originalSrc : parsedUrl.resizeUrl( width );
}

/**
 * The card's image: which one to show, and whether it is ready.
 *
 * The browser loads the image in the card component, which relays its load
 * and error events here through `onImageLoad` and `onImageError`.
 *
 * @param {import('vue').Ref<Object|null>} summary The REST API page summary,
 *  from which the card takes its image (`thumbnail` and `originalimage`).
 *  Null while loading; until then, the page's og:image tag stands in, so the
 *  image can start loading early
 * @param {import('vue').Ref<Object|null>} imageInfo The image's
 *  { author, license, nonFree, badFile }, null while loading. Non-free and
 *  bad images aren't shown, and the image isn't ready until this is known
 * @param {function(Error): void} onError Called if the image fails to load
 * @return {Object} `image` (URL or null), `isImageReady`, and the
 *  `onImageLoad` and `onImageError` handlers, which take the image's src
 */
module.exports = function useCardImage( summary, imageInfo, onError ) {
	const ogImage = document.querySelector( 'meta[property="og:image"]' );
	const ogImageWidth = document.querySelector( 'meta[property="og:image:width"]' );

	const image = computed( () => {
		// Fair use doesn't extend to a shareable image of the article, and
		// the wiki's bad image list keeps some files out of articles.
		if ( imageInfo.value && ( imageInfo.value.nonFree || imageInfo.value.badFile ) ) {
			return null;
		}
		if ( summary.value === null ) {
			// While the summary is in flight, the opengraph tags indicate
			// which image we'll get. Their dimensions aren't the source
			// ones, but it's enough to start loading.
			if (
				ogImage && ogImage.hasAttribute( 'content' ) &&
				ogImageWidth && ogImageWidth.hasAttribute( 'content' )
			) {
				return cardImageSrc(
					ogImage.getAttribute( 'content' ),
					ogImage.getAttribute( 'content' ),
					parseInt( ogImageWidth.getAttribute( 'content' ) )
				);
			}
		} else if ( summary.value.thumbnail && summary.value.originalimage ) {
			return cardImageSrc(
				summary.value.thumbnail.source,
				summary.value.originalimage.source,
				summary.value.originalimage.width
			);
		}

		return null;
	} );

	// Reset on every image change.
	const imageLoadComplete = ref( false );
	watch( image, () => ( imageLoadComplete.value = false ) );

	// The element reports an absolute src, while `image` can be relative,
	// so compare the resolved forms.
	const isCurrentImage = ( src ) => !!image.value &&
		src === new URL( image.value, location.href ).href;
	const onImageLoad = ( src ) => ( imageLoadComplete.value = isCurrentImage( src ) );
	const onImageError = ( src ) => {
		if ( isCurrentImage( src ) ) {
			onError( new Error( `Image load failed for ${ src }` ) );
		}
	};

	// No image is ready at once; otherwise wait for it and its information.
	const isImageReady = computed( () => !image.value ||
		( imageLoadComplete.value && imageInfo.value !== null ) );

	return {
		image,
		isImageReady,
		onImageLoad,
		onImageError
	};
};
