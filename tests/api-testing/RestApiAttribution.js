'use strict';

const { action, assert, REST, utils } = require( 'api-testing' );
// TODO: Re-enable spec validation tests. Currently we are disabling them
// because they are not working as expected within CI in the extension.

// const chai = require( 'chai' );
// const expect = chai.expect;
// const chaiResponseValidator = require( 'chai-openapi-response-validator' ).default;

describe( 'Attribution API tests', () => {
	let mindy;
	const title = utils.title( 'AttributionTest_' );
	const client = new REST( 'rest.php' );

	before( async () => {
		mindy = await action.mindy();
		await mindy.edit( title, { text: 'Test page for Attribution API' } );

		// const { status, text } = await client.get( '/specs/v0/module/attribution/v0-beta' );
		// assert.deepEqual( status, 200 );

		// const openApiSpec = JSON.parse( text );
		// chai.use( chaiResponseValidator( openApiSpec ) );
	} );

	describe( 'GET /pages/{title}/signals', () => {
		it( 'Should successfully return a response', async () => {
			const response = await client.get( `/attribution/v0-beta/pages/${ title }/signals` );

			assert.deepEqual( response.status, 200 );

			// expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should return essential properties', async () => {
			const response = await client.get( `/attribution/v0-beta/pages/${ title }/signals` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.essential.title );
			assert.isDefined( response.body.essential.license );
			assert.isDefined( response.body.essential.link );
			assert.isDefined( response.body.essential.default_brand_marks );
			assert.isDefined( response.body.essential.source_wiki );

			assert.isUndefined( response.body.trust_and_relevance );
			assert.isUndefined( response.body.calls_to_action );

			// expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return trust_and_relevance properties', async () => {
			const response = await client.get( `/attribution/v0-beta/pages/${ title }/signals?expand=trust_and_relevance` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.trust_and_relevance.last_modified );
			assert.isDefined( response.body.trust_and_relevance.page_views );
			assert.isDefined( response.body.trust_and_relevance.contributor_counts );

			assert.isUndefined( response.body.calls_to_action );

			// expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return calls_to_action properties', async () => {
			const response = await client.get( `/attribution/v0-beta/pages/${ title }/signals?expand=calls_to_action` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.calls_to_action.donation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta.talk_page );

			assert.isUndefined( response.body.trust_and_relevance );

			// expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return both trust_and_relevance and calls_to_action properties', async () => {
			const response = await client.get( `/attribution/v0-beta/pages/${ title }/signals?expand=trust_and_relevance,calls_to_action` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.calls_to_action.donation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta.talk_page );

			assert.isDefined( response.body.trust_and_relevance.last_modified );
			assert.isDefined( response.body.trust_and_relevance.page_views );
			assert.isDefined( response.body.trust_and_relevance.contributor_counts );

			// expect( response ).to.satisfyApiSpec;
		} );
	} );

} );
