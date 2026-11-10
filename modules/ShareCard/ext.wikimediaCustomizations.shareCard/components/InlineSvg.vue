<template>
	<span v-if="imgSource">
		<img :src="imgSource" :alt="alt">
	</span>
	<span v-else-if="alt">{{ alt }}</span>
</template>

<script>

/**
 * Loads a same-origin SVG, recolors it, and renders it as an <img> with a
 * data URI.
 */

const { ref, toRef, watch } = require( 'vue' );

// @vue/component
module.exports = exports = {
	name: 'InlineSvg',
	props: {
		/**
		 * URL to source image; must be local-origin.
		 */
		src: {
			type: [ String, null ],
			default: null
		},

		/**
		 * Color applied to every filled path.
		 */
		// eslint-disable-next-line vue/no-unused-properties
		fill: {
			type: [ String, null ],
			default: null
		},

		alt: {
			type: [ String, null ],
			default: null
		}
	},
	setup: function ( props ) {
		const imgSource = ref( null );
		const svgSource = ref( null );

		watch(
			toRef( props, 'src' ),
			async ( src ) => {
				if ( !src ) {
					svgSource.value = null;
					return;
				}

				let result = null;
				try {
					const response = await fetch( src );
					if ( response.ok ) {
						result = await response.text();
					}
				} catch ( e ) {
					// The card shows the alt text in place of the image.
					// This is an acceptable fallback for a network
					// failure, so do not log it as an error.
				}
				if ( props.src !== src ) {
					// stale result
					return;
				}

				svgSource.value = result;
			},
			{ immediate: true }
		);

		watch(
			[ svgSource, toRef( props, 'fill' ) ],
			( [ svg, fill ] ) => {
				if ( !svg ) {
					imgSource.value = null;
					return;
				}

				// html-to-image converts SVGs to data URIs anyway, which
				// drops any CSS styling. So apply the fill directly to the
				// paths first, then convert here, making the result immune to
				// styles that wouldn't survive the export.
				const doc = new DOMParser().parseFromString( svg, 'image/svg+xml' );
				if ( fill ) {
					Array.from( doc.querySelectorAll( 'path:not( [ fill="none" ] )' ) ).forEach( ( path ) => {
						path.style.fill = fill;
					} );
				}

				const styledSvg = new XMLSerializer().serializeToString( doc );
				imgSource.value = `data:image/svg+xml;base64,${ btoa( styledSvg ) }`;
			},
			{ immediate: true }
		);

		return {
			imgSource
		};
	}
};

</script>
