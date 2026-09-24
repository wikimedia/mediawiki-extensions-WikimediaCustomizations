<template>
	<share-card-dialog
		v-model:open="isOpen"
		:title="title"
	></share-card-dialog>
</template>

<script>
const { ref } = require( 'vue' );
const { getPageData } = require( 'ext.wikimediaCustomizations.shareCard.init' );
const ShareCardDialog = require( './components/ShareCardDialog.vue' );

// @vue/component
module.exports = exports = {
	name: 'App',
	components: {
		ShareCardDialog
	},
	setup: function ( props, { expose } ) {
		const isOpen = ref( false );
		const title = mw.Title.newFromText( getPageData().pageName );

		expose( {
			open: () => ( isOpen.value = true )
		} );

		return {
			isOpen,
			title
		};
	}
};
</script>
