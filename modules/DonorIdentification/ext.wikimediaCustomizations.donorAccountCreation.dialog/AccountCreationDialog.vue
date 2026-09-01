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
				v-if="userIsAuthenticated"
				weight="primary"
				action="progressive"
				@click="yesClick"
			>
				{{ yesBtnText }}
			</cdx-button>
			<a
				v-else
				:class="fakeButtonClasses"
				:href="createAccountLink"
			>
				{{ yesBtnText }}
			</a>
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
 * Confirmation dialog inviting recent donors to create an account or link an existing account.
 */
// @vue/component
module.exports = exports = {
	name: 'AccountCreationDialog',
	components: {
		CdxDialog,
		CdxButton
	},
	props: {
		/**
		 * Experiment group.
		 */
		group: {
			type: String,
			default: 'treatment'
		},
		/**
		 * Campaign machine name from query string.
		 */
		campaign: {
			type: String,
			default: ''
		},
		/**
		 * Local storage key for whether to suppress this dialog.
		 */
		storageKey: {
			type: String,
			required: true
		},
		/**
		 * Function to run on dialog close.
		 */
		onClose: {
			type: Function,
			default: () => {}
		}
	},
	setup( props ) {
		const open = ref( true );
		const userIsAuthenticated = computed( () => mw.user.isNamed() );
		const fakeButtonClasses = [
			'cdx-button',
			'cdx-button--fake-button',
			'cdx-button--fake-button--enabled',
			'cdx-button--action-progressive',
			'cdx-button--weight-primary'
		];
		const returnTo = mw.config.get( 'wgPageName' );
		const createAccountLink = computed( () => mw.util.getUrl( 'Special:CreateAccount', {
			returnto: returnTo,
			returntoquery: 'newdonoraccount=1',
			campaign: props.campaign,
			showlogin: 1
		} ) );

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

		/**
		 * Close dialog and unmount the app.
		 */
		function closeDialog() {
			open.value = false;
			props.onClose();
		}

		/**
		 * Handle authenticated user's consent to link their account.
		 */
		function yesClick() {
			// Record consent, notify the user, and close the dialog.
			require( 'ext.wikimediaCustomizations.donor' ).consent( {
				campaign: props.campaign
			} );

			// Suppress this dialog with no expiry (in case user revokes consent later).
			mw.storage.set( props.storageKey, '1' );

			mw.notify( mw.message( 'wc-donor-account-creation-success-message' ) );
			closeDialog();
		}

		/**
		 * Handle refusal of consent.
		 */
		function noClick() {
			// Suppress this dialog with no expiration date.
			mw.storage.set( props.storageKey, '1' );
			closeDialog();
		}

		/**
		 * Handle "remind me later".
		 */
		function laterClick() {
			// Suppress this dialog for 3 hours.
			mw.storage.set( props.storageKey, '1', 60 * 60 * 3 );
			closeDialog();
		}

		return {
			open,
			userIsAuthenticated,
			fakeButtonClasses,
			createAccountLink,
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
