<template>
	<div>
		<cdx-dialog
			v-model:open="isOpen"
			class="ext-wc-confirmation-dialog"
			:title="title"
			:use-close-button="true"
			:primary-action="primaryAction"
			:default-action="defaultAction"
			:render-in-place="true"
			@primary="onPrimaryAction"
			@default="onDefaultAction"
			@update:open="onUpdateOpen"
		>
			<!-- Styled in ext.wikimediaCustomizations.donorDelightBadge.styles/styles.less. -->
			<div class="ext-wc-confirmation-dialog__badge-image">
				<div
					class="ext-wc-confirmation-dialog__badge-image__image"
					:aria-hidden="true"
				></div>
			</div>

			<p>{{ body }}</p>
		</cdx-dialog>
	</div>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxDialog } = require( '../../codex.js' );

/**
 * Dialog shown to anonymous users when they click the bookmark button, prompting sign-in.
 */
// @vue/component
module.exports = exports = {
	name: 'ConfirmationDialog',
	components: {
		CdxDialog
	},
	props: {
		onDialogClose: {
			type: Function,
			default: () => {}
		}
	},
	setup( props ) {
		const isOpen = ref( true );

		const title = computed( () => mw.msg( 'wikimediacustomizations-donordelightbadge-dialog-title' ) );
		const body = computed( () => mw.msg( 'wikimediacustomizations-donordelightbadge-dialog-body' ) );
		const primaryActionLabel = computed( () => mw.msg( 'wikimediacustomizations-donordelightbadge-dialog-primary-action' ) );
		const defaultActionLabel = computed( () => mw.msg( 'wikimediacustomizations-donordelightbadge-dialog-default-action' ) );

		const primaryAction = {
			label: primaryActionLabel,
			actionType: 'destructive'
		};

		const defaultAction = { label: defaultActionLabel };

		function onPrimaryAction() {
			isOpen.value = false;
			props.onDialogClose( true );
		}

		function onDefaultAction() {
			isOpen.value = false;
			props.onDialogClose( false );
		}

		/**
		 * Clean up dialog if user closes it via close button, esc key, etc.
		 *
		 * @param {boolean} newOpenState
		 */
		function onUpdateOpen( newOpenState ) {
			if ( !newOpenState ) {
				props.onDialogClose( false );
			}
		}

		return {
			isOpen,
			title,
			body,
			primaryAction,
			defaultAction,
			onPrimaryAction,
			onDefaultAction,
			onUpdateOpen
		};
	}
};
</script>
