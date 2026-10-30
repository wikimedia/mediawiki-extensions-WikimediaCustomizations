/** Kept, without attributes. */
const KEPT_ELEMENTS = [ 'B', 'I', 'SUB', 'SUP' ];

/** Path of a formula image from the Math extension's REST API. */
const MATH_PATH = /\/media\/math\/render\/svg\/[0-9a-f]+$/;

/**
 * @param {string} style
 * @param {string} property
 * @return {string} The property's length in ex, e.g. "-0.505ex", or empty
 */
function exLength( style, property ) {
	const match = style.match( new RegExp( '(?:^|;)\\s*' + property + ':\\s*(-?[\\d.]+ex)' ) );
	return match ? match[ 1 ] : '';
}

/**
 * Copies a Math image, keeping its size in ex. Display formulas go inline.
 *
 * @param {HTMLImageElement} source
 * @return {HTMLImageElement|null}
 */
function copyMathImage( source ) {
	let url;
	try {
		url = new URL( source.getAttribute( 'src' ) );
	} catch ( e ) {
		return null;
	}
	if ( url.protocol !== 'https:' || !MATH_PATH.test( url.pathname ) ) {
		return null;
	}
	const image = document.createElement( 'img' );
	image.className = 'ext-wc-share-card__math';
	image.src = url.href;
	// For the image generator.
	image.crossOrigin = 'anonymous';
	image.alt = '';
	const style = source.getAttribute( 'style' ) || '';
	[ 'width', 'height', 'vertical-align' ].forEach( ( property ) => {
		const length = exLength( style, property );
		if ( length ) {
			image.style.setProperty( property, length );
		}
	} );
	return image;
}

/**
 * @param {Node} source
 * @param {Node} target
 */
function copyContent( source, target ) {
	Array.from( source.childNodes ).forEach( ( node ) => {
		if ( node.nodeType === Node.TEXT_NODE ) {
			// Word joiners around inline formulas stop spaces from collapsing.
			target.appendChild( document.createTextNode(
				node.textContent.replace( /⁠/g, '' ).replace( /\s+/g, ' ' )
			) );
			return;
		}
		if ( node.nodeType !== Node.ELEMENT_NODE || [ 'SCRIPT', 'STYLE' ].includes( node.tagName ) ) {
			return;
		}
		if ( node.tagName === 'IMG' ) {
			const image = copyMathImage( node );
			if ( image ) {
				target.appendChild( image );
			}
		} else if ( node.tagName === 'LI' ) {
			copyListItem( node, target );
		} else if ( KEPT_ELEMENTS.includes( node.tagName ) ) {
			const element = document.createElement( node.tagName );
			copyContent( node, element );
			target.appendChild( element );
		} else {
			copyContent( node, target );
			// Paragraphs and lists run on.
			if ( [ 'P', 'UL', 'OL' ].includes( node.tagName ) ) {
				target.appendChild( document.createTextNode( ' ' ) );
			}
		}
	} );
}

/**
 * Joins a list item to the next with a semicolon, or a space after
 * punctuation or a conjunction, as in "…, and".
 *
 * @param {HTMLLIElement} item
 * @param {Node} target
 */
function copyListItem( item, target ) {
	const wrapper = document.createElement( 'span' );
	copyContent( item, wrapper );
	const text = wrapper.textContent.trim();
	let separator;
	if ( item.nextElementSibling ) {
		separator = /([,;:.]|\b(and|or))$/.test( text ) ? ' ' : '; ';
	} else {
		separator = /[.!?]$/.test( text ) ? ' ' : '. ';
	}
	Array.from( wrapper.childNodes ).forEach( ( node ) => target.appendChild( node ) );
	target.appendChild( document.createTextNode( separator ) );
}

/**
 * @param {Node} root
 */
function normalizeWhitespace( root ) {
	root.normalize();
	const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
	const textNodes = [];
	while ( walker.nextNode() ) {
		textNodes.push( walker.currentNode );
	}
	textNodes.forEach( ( node ) => {
		node.textContent = node.textContent.replace( /\s+/g, ' ' ).replace( / ([,.;:])/g, '$1' );
	} );
	if ( textNodes.length ) {
		textNodes[ 0 ].textContent = textNodes[ 0 ].textContent.replace( /^\s+/, '' );
		const last = textNodes[ textNodes.length - 1 ];
		last.textContent = last.textContent.replace( /\s+$/, '' );
	}
}

/**
 * The card's text from the summary's `extract_html`, which keeps the
 * formulas and formatting that `extract` drops. Builds new elements, so no
 * attribute from the summary reaches the page.
 *
 * @param {string} html The summary's `extract_html` or display title
 * @return {string} HTML safe to render
 */
module.exports = function summaryHtml( html ) {
	const body = new DOMParser().parseFromString( html, 'text/html' ).body;
	const result = document.createElement( 'div' );
	copyContent( body, result );
	normalizeWhitespace( result );
	return result.innerHTML;
};
