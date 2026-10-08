<template>
	<cdx-dialog
		v-model:open="wrappedOpen"
		use-close-button
		class="ext-wc-share-card-dialog"
		:title="$i18n( 'wikimediacustomizations-sharecard-dialog-title' ).text()"
	>
		<div v-if="!error">
			<share-card-graphic
				ref="cardRef"
				:title="title"
				:text="text"
				:image="image"
				:image-author="imageAuthor"
				:image-license="imageLicense"
				:page-views="pageViews"
				:reference-count="referenceCount"
				:short-url="shortUrl"
				:loading="isLoading"
				:style="{ transform: 'scale(' + scale + ') translateX( calc( ( -1 * 0.5 * ( 1 - ' + scale + ' ) ) * 100% ) )' }"
				@img-load="onImageLoad"
				@img-error="onImageError"
			></share-card-graphic>
		</div>

		<cdx-message
			v-else
			type="error"
			class="ext-wc-share-card-dialog__error"
		>
			{{ $i18n( 'unknown-error' ).text() }}
		</cdx-message>

		<template
			v-if="!error"
			#footer
		>
			<!-- Announces button state changes to screen readers -->
			<div
				ref="liveRegionRef"
				aria-live="polite"
				class="ext-wc-share-card-dialog__sr-announcement"
			></div>
			<div class="ext-wc-share-card-dialog__footer">
				<div class="ext-wc-share-card-dialog__footer-action-secondary">
					<cdx-button-group
						v-if="canShareFiles"
						:class="{ 'ext-wc-share-card-dialog__button-group--label-visible': hasVisibleLabel }"
						:buttons="buttons"
						@click="onSecondaryClick"
					></cdx-button-group>
					<cdx-button
						v-else
						:aria-label="copyLinkLabel"
						@click="handleCopyLink"
					>
						<cdx-icon :icon="cdxIconLink"></cdx-icon>
						<span v-if="linkCopiedRef" aria-hidden="true">
							{{ $i18n( 'wikimediacustomizations-sharecard-link-copied' ).text() }}
						</span>
					</cdx-button>
				</div>

				<div class="ext-wc-share-card-dialog__footer-action-primary">
					<div
						v-if="isLoading || isProcessing"
						class="ext-wc-share-card-dialog__loading"
					>
						<cdx-progress-indicator show-label>
							{{ isLoading ? $i18n( 'wikimediacustomizations-sharecard-loading' ).text() : '' }}
							{{ isProcessing ? $i18n( 'wikimediacustomizations-sharecard-generating' ).text() : '' }}
						</cdx-progress-indicator>
					</div>

					<cdx-button
						v-else
						weight="primary"
						action="progressive"
						@click="handlePrimaryAction"
					>
						<cdx-icon v-if="canShareFiles" :icon="cdxIconShare"></cdx-icon>
						{{ primaryActionLabel }}
					</cdx-button>
				</div>
			</div>
		</template>
	</cdx-dialog>
</template>

<script>
const { ref, toRef, computed, watch } = require( 'vue' );
const { buildShareUrl } = require( 'ext.wikimediaCustomizations.shareCard.init' );
const { CdxButton, CdxButtonGroup, CdxDialog, CdxIcon, CdxMessage, CdxProgressIndicator, useModelWrapper } = require( '@wikimedia/codex' );
const icons = require( '../icons.json' );
const ShareCardGraphic = require( './ShareCardGraphic.vue' );
const useCardImage = require( '../composables/useCardImage.js' );
const usePageData = require( '../composables/usePageData.js' );
const useShareCard = require( '../composables/useShareCard.js' );

// @vue/component
module.exports = exports = {
	name: 'ShareCardDialog',
	components: {
		CdxButton,
		CdxButtonGroup,
		CdxDialog,
		CdxIcon,
		CdxMessage,
		CdxProgressIndicator,
		ShareCardGraphic
	},
	props: {
		/**
		 * Whether the dialog is open.
		 */
		open: {
			type: Boolean,
			default: false
		},
		/**
		 * Title of the page to share.
		 */
		title: {
			type: /** @type {mw.Title} */ ( Object ),
			required: true,
			validator: ( title ) => ( title instanceof mw.Title )
		}
	},
	emits: [ 'update:open' ],
	setup: function ( props, { emit } ) {
		// Basic UI state
		const wrappedOpen = useModelWrapper( toRef( props, 'open' ), emit, 'update:open' );
		const isDownloadingRef = ref( false );
		const linkCopiedRef = ref( false );
		const hasVisibleLabel = computed( () => linkCopiedRef.value || isDownloadingRef.value );
		const error = ref( null );

		function onError( err ) {
			// Still throw, so other handlers (e.g. logging) see it.
			error.value = err;
			throw err;
		}

		// UI elements
		const cardRef = ref( null );
		const liveRegionRef = ref( null );

		// Data fetched from the API: summary text, attribution signals
		const { summary, signals, imageInfo, shortUrl } = usePageData( toRef( props, 'open' ), onError );

		// Extract computed properties for summary text, pageview count, and reference count
		const text = computed( () => {
			if ( summary.value && summary.value.extract ) {
				return summary.value.extract.trim();
			}
			return null;
		} );

		const pageViews = computed( () => signals.value && signals.value.pageViews );
		const referenceCount = computed( () => signals.value && signals.value.referenceCount );

		// Page image data
		const {
			image,
			isImageReady,
			onImageLoad,
			onImageError
		} = useCardImage( summary, imageInfo, onError );

		// Credit only an image the card actually shows.
		const shownImageInfo = computed( () => ( image.value ? imageInfo.value : null ) );
		const imageAuthor = computed( () => shownImageInfo.value && shownImageInfo.value.author );
		const imageLicense = computed( () => shownImageInfo.value && shownImageInfo.value.license );

		// Unified loading state: we are not ready until all the following have loaded:
		// summary, attribution signals, short URL, page image
		const isLoading = computed( () => summary.value === null ||
			signals.value === null ||
			shortUrl.value === null ||
			!isImageReady.value
		);

		// Share Card Behavior
		const {
			canShareFiles,
			isProcessing,
			error: shareError,
			shareCard,
			downloadCardImage
		} = useShareCard();

		watch(
			shareError,
			( err ) => ( err && onError( err ) ),
			{ immediate: true }
		);

		const copyLinkLabel = computed( () => mw.msg(
			linkCopiedRef.value ?
				'wikimediacustomizations-sharecard-link-copied' :
				'wikimediacustomizations-sharecard-copy-link'
		) );

		const primaryActionLabel = mw.msg(
			canShareFiles ?
				'wikimediacustomizations-sharecard-share' :
				'wikimediacustomizations-sharecard-download'
		);

		const downloadButtonLabel = computed( () => mw.msg(
			isDownloadingRef.value ?
				'wikimediacustomizations-sharecard-downloading' :
				'wikimediacustomizations-sharecard-download'
		) );

		const buttons = computed( () => [
			{
				value: 'copy',
				icon: icons.cdxIconLink,
				ariaLabel: copyLinkLabel.value,
				label: linkCopiedRef.value ? mw.msg( 'wikimediacustomizations-sharecard-link-copied' ) : null
			},
			{
				value: 'download',
				icon: icons.cdxIconDownload,
				ariaLabel: downloadButtonLabel.value,
				disabled: isLoading.value || isDownloadingRef.value,
				label: isDownloadingRef.value ? mw.msg( 'wikimediacustomizations-sharecard-downloading' ) : null
			}
		] );

		watch(
			() => props.open,
			( isOpen ) => {
				if ( isOpen ) {
					linkCopiedRef.value = false;
					isDownloadingRef.value = false;
					// Clear a previous failure, so reopening retries.
					error.value = null;
				}
			}
		);

		/**
		 * @return {HTMLElement|null} The element the PNG is rendered from
		 */
		function cardElement() {
			return cardRef.value ? cardRef.value.rootElement : null;
		}

		/**
		 * @param {boolean} success
		 */
		function closeOnSuccess( success ) {
			if ( success ) {
				wrappedOpen.value = false;
			}
		}

		/**
		 * Share where the browser supports it, download otherwise.
		 */
		function handlePrimaryAction() {
			const element = cardElement();
			if ( !element ) {
				return;
			}
			const articleTitle = props.title.getMainText();
			const action = canShareFiles ?
				shareCard( element, articleTitle, text.value || '' ) :
				downloadCardImage( element, articleTitle );
			action.then( closeOnSuccess );
		}

		/**
		 * The secondary download action, shown alongside Share.
		 */
		function handleDownload() {
			const element = cardElement();
			if ( !element ) {
				return;
			}
			isDownloadingRef.value = true;
			liveRegionRef.value.textContent = mw.msg( 'wikimediacustomizations-sharecard-downloading' );
			downloadCardImage( element, props.title.getMainText() )
				.then( ( success ) => {
					isDownloadingRef.value = false;
					liveRegionRef.value.textContent = '';
					closeOnSuccess( success );
				} );
		}

		/**
		 * Copy the article link to the clipboard.
		 */
		function handleCopyLink() {
			const url = buildShareUrl( props.title.getMainText() );
			// eslint-disable-next-line compat/compat
			navigator.clipboard.writeText( url ).then( () => {
				linkCopiedRef.value = true;
				liveRegionRef.value.textContent = mw.msg( 'wikimediacustomizations-sharecard-link-copied' );
				setTimeout( () => {
					linkCopiedRef.value = false;
					liveRegionRef.value.textContent = '';
				}, 2000 );
			} ).catch( ( e ) => {
				// eslint-disable-next-line no-console
				console.error( 'Failed to copy link:', e );
			} );
		}

		function onSecondaryClick( value ) {
			if ( value === 'copy' ) {
				handleCopyLink();
			} else if ( value === 'download' ) {
				handleDownload();
			}
		}

		const scale = computed( () => {
			const element = cardElement();
			if ( !element ) {
				return 1;
			}

			// Only ever scales down, and never by much, given the 320px
			// minimum dialog width. Height is not scaled: a card taller than
			// the available space scrolls instead.
			return element.parentElement.clientWidth / element.scrollWidth;
		} );

		return {
			cardRef,
			liveRegionRef,
			linkCopiedRef,
			scale,
			isLoading,
			isProcessing,
			image,
			imageAuthor,
			imageLicense,
			pageViews,
			referenceCount,
			shortUrl,
			text,
			error,
			wrappedOpen,
			copyLinkLabel,
			canShareFiles,
			primaryActionLabel,
			handlePrimaryAction,
			handleCopyLink,
			cdxIconLink: icons.cdxIconLink,
			cdxIconShare: icons.cdxIconShare,
			buttons,
			onSecondaryClick,
			onImageLoad,
			onImageError,
			hasVisibleLabel
		};
	}
};
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.ext-wc-share-card-dialog {
	@media ( max-width: @min-width-breakpoint-tablet ) {
		&.cdx-dialog {
			border-top: @border-subtle;
		}
	}

	&__error {
		margin-top: @spacing-100;
	}

	&__loading {
		color: @color-subtle;
	}

	// Copied from Codex's screen-reader-text() mixin.
	&__sr-announcement {
		display: block;
		clip: rect( @size-absolute-1, @size-absolute-1, @size-absolute-1, @size-absolute-1 );
		/* stylelint-disable-next-line declaration-no-important */
		position: absolute !important;
		width: @size-absolute-1;
		height: @size-absolute-1;
		// Negative `@size-absolute-1`, as the two are inherently connected.
		margin: calc( -1 * @size-absolute-1 );
		border: 0;
		padding: 0;
		overflow: hidden;
	}

	&__footer {
		display: flex;
		align-items: center;
		justify-content: space-between;
		width: 100%;
	}

	&__button-group--label-visible .cdx-button {
		padding-right: @spacing-25;
		padding-left: @spacing-25;
	}

	// Override the dialog width for a better preview.
	&.cdx-dialog {
		width: auto;
		// This leaves a window between the 320px minimum and roughly 336px
		// (288px fixed card width plus dialog padding).
		min-width: 320px;
		max-width: 100vw;
		// Fallback for older browsers.
		max-height: calc( 100vh - 2rem );

		// The dialog has a default `100vh - 2rem` max-height, but `vh` is
		// confusing with dynamic browser chrome (e.g. an address bar
		// appearing while swiping), and iOS Safari has been observed to get
		// it wrong. 100dvh expresses the intent more clearly.
		// stylelint-disable-next-line plugin/no-unsupported-browser-features
		max-height: calc( 100dvh - 2rem );
	}
}
</style>
