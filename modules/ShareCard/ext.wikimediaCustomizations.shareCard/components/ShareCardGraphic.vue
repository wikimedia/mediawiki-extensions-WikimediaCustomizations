<template>
	<div
		ref="rootElement"
		class="ext-wc-share-card"
		:class="{
			'ext-wc-share-card--no-image': !image,
			'ext-wc-share-card--loading': loading
		}"
		:style="{
			'--dominant-color-hex': dominantColorHex,
			'--dominant-color-contrasting': dominantColorContrasting
		}"
	>
		<img
			v-if="image"
			class="ext-wc-share-card__image"
			:class="{
				'ext-wc-share-card__image--graphic': isGraphic,
				'ext-wc-share-card__image--plain-fill': isDarkTransparent
			}"
			:src="image"
			crossorigin="anonymous"
			@load="onImageLoad"
			@error="( event ) => ( $emit( 'img-error', event.target.src ) )"
		>
		<div class="ext-wc-share-card__body">
			<div class="ext-wc-share-card__title">
				{{ title.getMainText() }}
			</div>
			<div
				v-if="text"
				ref="textElement"
				class="ext-wc-share-card__text"
			>
				{{ fittedText }}
			</div>
			<div
				v-if="stats.length"
				class="ext-wc-share-card__stats"
			>
				<span
					v-for="stat in stats"
					:key="stat.key"
					class="ext-wc-share-card__stat"
				>
					<cdx-icon :icon="stat.icon" size="small"></cdx-icon>
					{{ stat.label }}
				</span>
			</div>
			<div class="ext-wc-share-card__branding">
				<div class="ext-wc-share-card__wordmark">
					<inline-svg
						v-if="wordmark"
						:src="wordmark.src"
						:fill="dominantColorContrasting"
						:alt="wordmark.alt"
						:style="{
							'--wordmark-width': wordmark.width + 'px',
							'--wordmark-height': wordmark.height + 'px'
						}"
					></inline-svg>
					<template v-else>
						{{ siteName }}
					</template>
				</div>
				<div class="ext-wc-share-card__license">
					<inline-svg
						v-for="licenseIcon in licenseIcons"
						:key="licenseIcon.src"
						:src="licenseIcon.src"
						:fill="dominantColorContrasting"
						:alt="licenseIcon.alt"
					></inline-svg>
				</div>
			</div>
			<div
				v-if="imageCredit"
				class="ext-wc-share-card__image-credit"
			>
				{{ imageCredit }}
			</div>
		</div>
	</div>
</template>

<script>
const { computed, nextTick, ref, toRef, watch } = require( 'vue' );
const { CdxIcon } = require( '@wikimedia/codex' );
const icons = require( '../icons.json' );
const { licenseIcons, wordmark: wordmarkConfig } = require( '../config.json' );
const InlineSvg = require( './InlineSvg.vue' );
const averageColor = require( '../utils/averageColor.js' );
const fitText = require( '../utils/fitText.js' );

// @vue/component
module.exports = exports = {
	name: 'ShareCardGraphic',
	components: {
		CdxIcon,
		InlineSvg
	},
	props: {
		/**
		 * The article title.
		 */
		title: {
			type: /** @type {mw.Title} */ ( Object ),
			required: true,
			validator: ( title ) => ( title instanceof mw.Title )
		},
		/**
		 * The text to display.
		 */
		text: {
			type: String,
			default: null
		},
		/**
		 * Src of the article image to display.
		 */
		image: {
			type: String,
			default: null
		},
		/**
		 * Author of the article image.
		 */
		imageAuthor: {
			type: String,
			default: null
		},
		/**
		 * License of the article image.
		 */
		imageLicense: {
			type: String,
			default: null
		},
		/**
		 * Page views over the last 30 days.
		 */
		pageViews: {
			type: Number,
			default: null
		},
		/**
		 * Number of unique references cited.
		 */
		referenceCount: {
			type: Number,
			default: null
		},
		/**
		 * Whether the card's data is still loading. The card shows only its
		 * outline until then, so it appears once, complete.
		 */
		loading: {
			type: Boolean,
			default: false
		}
	},
	emits: [
		'img-error',
		'img-load'
	],
	setup: function ( props, { emit, expose } ) {
		const rootElement = ref( null );

		// The image's average color, which the card takes as its background.
		const color = ref( null );
		const onImageLoad = ( event ) => {
			color.value = averageColor( event.target );
			if ( event.target.complete ) {
				emit( 'img-load', event.target.src );
			}
		};

		// The text, cut to fit the card with an ellipsis if needed.
		const textElement = ref( null );
		const fittedText = ref( props.text );
		watch( toRef( props, 'text' ), async ( text ) => {
			fittedText.value = text;
			await nextTick();
			if ( text && textElement.value ) {
				fittedText.value = fitText( textElement.value, text, mw.msg( 'ellipsis' ) );
			}
		}, { immediate: true } );

		watch( toRef( props, 'image' ), ( image ) => {
			if ( !image ) {
				color.value = null;
			}
		} );

		const siteName = mw.config.get( 'wgSiteName' );
		const wordmark = wordmarkConfig && Object.assign( { alt: siteName }, wordmarkConfig );

		// Overlay can't lighten a near-black fill, which would hide a dark
		// logo on a transparent background.
		const isDarkTransparent = computed( () => !!color.value &&
			color.value.isDark && color.value.hasTransparency );

		// Logos, maps and diagrams lose meaning when cropped, so they're shown
		// whole. SVGs are almost always such graphics; transparency catches
		// raster ones.
		const isGraphic = computed( () => !!props.image && (
			/\.svg\b/i.test( props.image ) ||
			( !!color.value && color.value.hasTransparency )
		) );

		const imageCredit = computed( () => {
			if ( props.imageLicense && props.imageAuthor ) {
				return mw.msg(
					'wikimediacustomizations-sharecard-image-credit',
					props.imageLicense,
					props.imageAuthor
				);
			}
			const known = props.imageLicense || props.imageAuthor;
			return known ? mw.msg( 'wikimediacustomizations-sharecard-image-credit-partial', known ) : null;
		} );

		const numberFormat = new Intl.NumberFormat(
			mw.language.bcp47( mw.config.get( 'wgUserLanguage' ) ),
			{ notation: 'compact' }
		);
		const stats = computed( () => {
			const result = [];
			if ( props.pageViews !== null ) {
				result.push( {
					key: 'views',
					icon: icons.cdxIconChart,
					label: mw.msg(
						'wikimediacustomizations-sharecard-page-views',
						props.pageViews,
						numberFormat.format( props.pageViews )
					)
				} );
			}
			if ( props.referenceCount !== null ) {
				result.push( {
					key: 'references',
					icon: icons.cdxIconReferences,
					label: mw.msg(
						'wikimediacustomizations-sharecard-references',
						props.referenceCount,
						numberFormat.format( props.referenceCount )
					)
				} );
			}
			return result;
		} );

		// Not 'var( --background-color-base-fixed )' and friends: these styles
		// get applied onto standalone SVGs, which lack the document's context.
		const dominantColorHex = computed( () => color.value ? color.value.hex : /* --background-color-base-fixed */ '#fff' );
		const dominantColorContrasting = computed( () => {
			if ( !color.value ) {
				return /* --color-base-fixed */ '#202122';
			} else if ( CSS.supports( 'color', 'oklch( from white l c h )' ) ) {
				return `oklch( from ${ color.value.hex } ${ color.value.isDark ? 1 : 0 } c h )`;
			} else {
				return color.value.isDark ? '#fff' : '#000';
			}
		} );

		// The parent hands this to the image generator.
		expose( { rootElement: rootElement } );

		return {
			siteName,
			rootElement,
			textElement,
			fittedText,
			onImageLoad,
			isDarkTransparent,
			isGraphic,
			imageCredit,
			stats,
			wordmark,
			licenseIcons,
			dominantColorHex,
			dominantColorContrasting
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

@opacity-secondary: 0.6;

.ext-wc-share-card {
	display: flex;
	flex-direction: column;
	border-radius: 0.125rem;
	overflow: hidden;
	color-scheme: only light;
	forced-color-adjust: none;
	background-color: var( --dominant-color-hex );
	color: var( --dominant-color-contrasting );

	// Preview size, 512px tall; the PNG renders at 2x.
	width: calc( 512px * 9 / 16 );
	aspect-ratio: 9/16;
	box-sizing: border-box;

	// Only as tall as its text.
	&--no-image {
		aspect-ratio: auto;
		// Codex's @background-color-interactive, which isn't fixed and so
		// would follow night mode.
		background-color: #eaecf0;
		color: @color-base-fixed;

		.ext-wc-share-card__text {
			height: auto;
		}
	}

	// Contents stay in place, so the image loads and its color is read.
	&--loading {
		background-color: #eaecf0;

		> * {
			visibility: hidden;
		}
	}

	// Takes whatever height the text leaves.
	&__image {
		// Shows behind transparent images.
		background-color: var( --dominant-color-hex );
		background-image: linear-gradient( rgba( 255, 255, 255, 0.5 ), rgba( 255, 255, 255, 0.5 ) );
		background-blend-mode: overlay;
		flex: 1 1 0;
		min-height: 0;
		width: 100%;
		display: block;
		object-fit: cover;
		object-position: center top;

		&--graphic {
			box-sizing: border-box;
			padding: @spacing-100;
			object-fit: contain;
			object-position: center;
		}

		&--plain-fill {
			background-blend-mode: normal;
		}
	}

	&__body {
		display: flex;
		flex-direction: column;
		padding: @spacing-75;

		> * + * {
			margin-top: @spacing-50;
		}
	}

	&__title {
		overflow: hidden;
		font-family: @font-family-serif;
		font-size: @font-size-x-large;
		// The font size's matching line height; a tighter one clips
		// descenders, as overflow is hidden.
		line-height: @line-height-x-large;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	// Five lines, cut to fit by fitText with an ellipsis. The fixed height
	// keeps the image and everything below the text in the same place on
	// every card. @line-height-small is unitless, so scale it by the font
	// size.
	&__text {
		height: calc( 5em * @line-height-small );
		max-height: calc( 5em * @line-height-small );
		overflow: hidden;
		font-family: @font-family-system-sans;
		font-size: @font-size-small;
		line-height: @line-height-small;
		overflow-wrap: break-word;
	}

	&__stats {
		display: flex;
		flex-wrap: wrap;
		font-family: @font-family-system-sans;
		font-size: @font-size-x-small;
		opacity: @opacity-secondary;
	}

	&__stat {
		display: inline-flex;
		align-items: center;
		margin-right: @spacing-100;

		.cdx-icon {
			margin-right: @spacing-25;
			color: inherit;
		}
	}

	// Sets the branding apart from the article content above it. No Codex
	// token matches the design's 18px.
	&__branding {
		margin-top: 18px;
		display: flex;
		justify-content: space-between;
		align-items: center;
		font-family: @font-family-system-sans;
		font-size: @font-size-x-small;
		opacity: @opacity-secondary;
	}

	&__image-credit {
		margin-top: @spacing-25;
		overflow: hidden;
		font-family: @font-family-system-sans;
		font-size: @font-size-x-small;
		white-space: nowrap;
		text-overflow: ellipsis;
		opacity: @opacity-secondary;
	}

	&__wordmark img {
		width: var( --wordmark-width );
		height: var( --wordmark-height );
	}

	&__license {
		display: flex;

		> * + * {
			margin-left: @spacing-12;
		}
	}
}
</style>
