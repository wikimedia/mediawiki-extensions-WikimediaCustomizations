<template>
	<cdx-dialog
		v-model:open="open"
		class="ext-wc-donor-account-creation-dialog"
		:title="titleText"
		:subtitle="subtitleText"
		:use-close-button="false"
	>
		<div class="ext-wc-donor-account-creation-dialog__body">
			<div
				v-if="group === 'treatment'"
				class="ext-wc-donor-account-creation-dialog__benefits">
				<p v-html="benefitsLabelText"></p>
				<ul>
					<li>{{ benefitsList1 }}</li>
					<li>{{ benefitsList2 }}</li>
					<li>{{ benefitsList3 }}</li>
				</ul>
			</div>
			<div>
				<p>
					{{ bodyText }}
					<span v-html="learnHtml"></span>
				</p>
			</div>
		</div>

		<div class="ext-wc-donor-account-creation-dialog__actions">
			<cdx-button
				weight="primary"
				action="progressive"
				@click="yesClick"
			>
				{{ yesBtnText }}
			</cdx-button>
			<cdx-button @click="noClick">
				{{ noBtnText }}
			</cdx-button>
			<cdx-button
				weight="quiet"
				@click="laterClick"
			>
				{{ laterBtnText }}
			</cdx-button>
		</div>
	</cdx-dialog>
</template>

<script>
const { computed, ref } = require( 'vue' );
const { CdxDialog, CdxButton } = require( '../../codex.js' );

/**
 * Confirmation dialog inviting recent donors to create an account.
 */
// @vue/component
module.exports = exports = {
	name: 'AccountCreationDialog',
	components: {
		CdxDialog,
		CdxButton
	},
	props: {
		group: {
			type: String,
			default: 'treatment'
		},
		onClose: {
			type: Function,
			default: () => {}
		}
	},
	setup( props ) {
		const open = ref( true );

		const titleText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-title' ) );
		const subtitleText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-subtitle' ) );
		const bodyText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-body' ) );
		const learnHtml = computed( () => mw.message( 'wc-donor-account-creation-dialog-learn' ).parse() );
		const benefitsLabelText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-benefits-label' ) );
		const benefitsList1 = computed( () => mw.msg( 'wc-donor-account-creation-dialog-benefits-list-1' ) );
		const benefitsList2 = computed( () => mw.msg( 'wc-donor-account-creation-dialog-benefits-list-2' ) );
		const benefitsList3 = computed( () => mw.msg( 'wc-donor-account-creation-dialog-benefits-list-3' ) );
		const yesBtnText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-yes' ) );
		const noBtnText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-no' ) );
		const laterBtnText = computed( () => mw.msg( 'wc-donor-account-creation-dialog-later' ) );

		// "Yes" — user consents to account creation.
		function yesClick() {
			// @todo: wire up account creation consent.
			// eslint-disable-next-line no-alert
			alert( 'todo' );
		}

		// "No thanks" - dismisses the dialog.
		function noClick() {
			open.value = false;
			props.onClose();
		}

		// "Remind me later" — dismiss the dialog for now.
		function laterClick() {
			// @todo: wire up remind-me-later handling.
			// eslint-disable-next-line no-alert
			alert( 'todo' );
			noClick();
		}

		return {
			open,
			titleText,
			subtitleText,
			bodyText,
			learnHtml,
			benefitsLabelText,
			benefitsList1,
			benefitsList2,
			benefitsList3,
			yesBtnText,
			noBtnText,
			laterBtnText,
			noClick,
			yesClick,
			laterClick
		};
	}
};
</script>

<style lang="less">
/*
 * Donor account creation confirmation dialog.
 * Experimental popup inviting recent donors to create an account.
 */
@import 'mediawiki.skin.variables.less';

.ext-wc-donor-account-creation-dialog {
	// Stack the content boxes vertically.
	&__body {
		display: flex;
		flex-direction: column;
		gap: @spacing-100;
	}

	&__actions {
		display: flex;
		flex-flow: column;
		align-items: center;
		gap: @spacing-50;

		.cdx-button {
			width: 100%;
			max-width: none;
		}
	}

	.cdx-dialog__header {
		border-bottom: 0;

		&__subtitle {
			margin-top: @spacing-100;
		}
	}

	li {
		// Remove Minerva default margins.
		margin: 0;
	}
}
</style>
