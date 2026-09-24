const { ref, watch } = require( 'vue' );

/**
 * @callback errorHandler
 * @param {Error} error
 */

/**
 * The resolved value of `load( source )`, kept current as the source changes.
 * This composable is wrapped and re-used to represent different bits of
 * asynchronously-available data (summary, attribution signals, image credits).
 *
 * @param {import('vue').Ref<*>} sourceRef
 * @param {function(*): Promise<*>} load
 * @param {errorHandler} [onError]
 * @return {import('vue').Ref<*>} null while loading, or without a source
 */
module.exports = function usePromised(
	sourceRef,
	load,
	// eslint-disable-next-line no-unused-vars
	onError = ( error ) => {}
) {
	const result = ref( null );
	watch( sourceRef, ( source ) => {
		result.value = null;
		if ( !source ) {
			return;
		}
		load( source ).then(
			( value ) => {
				// A superseded request must not overwrite the current one.
				if ( sourceRef.value === source ) {
					result.value = value;
				}
			},
			( error ) => {
				if ( sourceRef.value === source ) {
					onError( error );
				}
			}
		);
	}, { immediate: true } );
	return result;
};
