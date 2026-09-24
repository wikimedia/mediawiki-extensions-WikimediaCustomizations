/**
 * The longest start of `text` that fits in `element`'s maximum height,
 * ending in `ellipsis` if it had to be cut. Measures a hidden copy of the
 * element, so its rendering is left to Vue.
 *
 * Cutting the text itself, rather than clamping it with CSS, keeps the
 * ellipsis in the generated PNG, which drops line-clamp's.
 *
 * @param {HTMLElement} element Must have a maximum height and hide overflow
 * @param {string} text
 * @param {string} ellipsis
 * @return {string}
 */
module.exports = function fitText( element, text, ellipsis ) {
	const probe = element.cloneNode( false );
	probe.style.position = 'absolute';
	probe.style.visibility = 'hidden';
	probe.style.width = element.clientWidth + 'px';
	element.parentNode.appendChild( probe );

	const fits = ( candidate ) => {
		probe.textContent = candidate;
		return probe.scrollHeight <= probe.clientHeight;
	};

	let result = text;
	if ( !fits( text ) ) {
		// The longest cut that fits with the ellipsis.
		let low = 0;
		let high = text.length;
		while ( low < high ) {
			const mid = Math.ceil( ( low + high ) / 2 );
			if ( fits( text.slice( 0, mid ).replace( /\s+$/, '' ) + ellipsis ) ) {
				low = mid;
			} else {
				high = mid - 1;
			}
		}
		let cut = text.slice( 0, low );
		// End on a whole word where there's one nearby; scripts without
		// spaces keep the character cut.
		const lastSpace = cut.lastIndexOf( ' ' );
		if ( lastSpace > cut.length * 0.75 ) {
			cut = cut.slice( 0, lastSpace );
		}
		// Drop trailing punctuation, which would run into the ellipsis, as in
		// "worldwide....".
		result = cut.replace( /[\s.,;:–—-]+$/, '' ) + ellipsis;
	}

	probe.remove();
	return result;
};
