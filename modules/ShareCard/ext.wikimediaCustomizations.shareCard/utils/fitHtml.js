/**
 * @param {Node} root
 * @return {Node[]} Text nodes and images, in document order
 */
function leaves( root ) {
	const result = [];
	const walker = document.createTreeWalker( root, NodeFilter.SHOW_ALL );
	while ( walker.nextNode() ) {
		const node = walker.currentNode;
		if ( node.nodeType === Node.TEXT_NODE || node.tagName === 'IMG' ) {
			result.push( node );
		}
	}
	return result;
}

/**
 * A copy of `source` cut after `length` units: one per character or image.
 *
 * @param {HTMLElement} source
 * @param {number} length
 * @return {HTMLElement}
 */
function cut( source, length ) {
	const copy = source.cloneNode( true );
	let remaining = length;
	leaves( copy ).forEach( ( leaf ) => {
		const size = leaf.nodeType === Node.TEXT_NODE ? leaf.textContent.length : 1;
		if ( remaining >= size ) {
			remaining -= size;
		} else if ( remaining > 0 && leaf.nodeType === Node.TEXT_NODE ) {
			leaf.textContent = leaf.textContent.slice( 0, remaining );
			remaining = 0;
		} else {
			leaf.remove();
		}
	} );
	// Drop elements the cut left empty.
	Array.from( copy.querySelectorAll( '*:not(img)' ) )
		.reverse()
		.filter( ( element ) => !element.childNodes.length )
		.forEach( ( element ) => element.remove() );
	return copy;
}

/**
 * Ends on a whole word if one is near, drops trailing punctuation and adds
 * the ellipsis.
 *
 * @param {HTMLElement} copy
 * @param {string} ellipsis
 * @param {boolean} wholeWord
 * @return {HTMLElement} `copy`
 */
function finish( copy, ellipsis, wholeWord ) {
	const nodes = leaves( copy );
	const last = nodes[ nodes.length - 1 ];
	if ( last && last.nodeType === Node.TEXT_NODE ) {
		let text = last.textContent;
		// As in fitText, measured over the whole text. Scripts without spaces
		// keep the character cut.
		const lastSpace = text.lastIndexOf( ' ' );
		const offset = copy.textContent.length - text.length;
		if ( wholeWord && lastSpace >= 0 && offset + lastSpace > copy.textContent.length * 0.75 ) {
			text = text.slice( 0, lastSpace );
		}
		last.textContent = text.replace( /[\s.,;:–—-]+$/, '' );
	}
	copy.appendChild( document.createTextNode( ellipsis ) );
	return copy;
}

/**
 * As fitText, for HTML. Images count as one character, and need a size set
 * before they load.
 *
 * @param {HTMLElement} element Must have a maximum height and hide overflow
 * @param {string} html Trusted HTML, as from summaryHtml
 * @param {string} ellipsis
 * @return {string} HTML
 */
module.exports = function fitHtml( element, html, ellipsis ) {
	const probe = element.cloneNode( false );
	probe.style.position = 'absolute';
	probe.style.visibility = 'hidden';
	probe.style.width = element.clientWidth + 'px';
	element.parentNode.appendChild( probe );

	const source = document.createElement( 'div' );
	source.innerHTML = html;
	const fits = ( candidate ) => {
		probe.textContent = '';
		Array.from( candidate.childNodes ).forEach( ( node ) => probe.appendChild( node ) );
		return probe.scrollHeight <= probe.clientHeight;
	};

	let result = html;
	if ( !fits( source.cloneNode( true ) ) ) {
		const total = leaves( source ).reduce( ( sum, leaf ) => sum +
			( leaf.nodeType === Node.TEXT_NODE ? leaf.textContent.length : 1 ), 0 );
		let low = 0;
		let high = total;
		while ( low < high ) {
			const mid = Math.ceil( ( low + high ) / 2 );
			if ( fits( finish( cut( source, mid ), ellipsis, false ) ) ) {
				low = mid;
			} else {
				high = mid - 1;
			}
		}
		result = finish( cut( source, low ), ellipsis, true ).innerHTML;
	}

	probe.remove();
	return result;
};
