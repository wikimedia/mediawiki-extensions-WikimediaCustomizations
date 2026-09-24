/**
 * @param {string} text
 * @param {number} maxLength
 * @return {string} Text truncated to maxLength, with an ellipsis
 */
module.exports = function truncateText( text, maxLength ) {
	if ( text.length <= maxLength ) {
		return text;
	}
	return text.slice( 0, maxLength - 1 ).trim() + '\u2026';
};
