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

				const response = await fetch( src );
				if ( props.src !== src ) {
					// stale result
					return;
				}

				const result = await response.text();
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
