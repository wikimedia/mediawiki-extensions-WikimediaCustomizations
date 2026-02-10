'use strict';

const { action, assert, REST, utils } = require( 'api-testing' );
const chai = require( 'chai' );
const expect = chai.expect;
const chaiResponseValidator = require( 'chai-openapi-response-validator' ).default;

describe( 'Attribution API tests', () => {
	let mindy;
	const title = utils.title( 'AttributionTest_' );
	const baseURL = '/rest.php/attribution/v0-beta';
	const client = new REST( baseURL );

	before( async () => {
		mindy = await action.mindy();
		await mindy.edit( title, { text: 'Test page for Attribution API' } );

		// Prepare the OpenAPI spec for validation
		// Fetch the OpenAPI spec using a separate client
		const specClient = new REST( 'rest.php' );
		const response = await specClient.get( '/specs/v0/module/attribution/v0-beta' );
		assert.deepEqual( response.status, 200 );

		const openApiSpec = JSON.parse( response.text );

		// Dynamically inject the test server URL into the spec's servers array
		// to match the actual request paths during validation (following WikibaseManifest pattern)
		openApiSpec.servers = openApiSpec.servers || [];
		openApiSpec.servers.push( {
			url: response.request.app + baseURL,
			description: 'Dynamically added CI test system'
		} );

		chai.use( chaiResponseValidator( openApiSpec ) );
	} );

	describe( 'GET /pages/{title}/signals', () => {
		it( 'Should successfully return a response', async () => {
			const response = await client.get( `pages/${ title }/signals` );

			assert.deepEqual( response.status, 200 );

			// eslint-disable-next-line no-unused-expressions
			expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should return essential properties', async () => {
			const response = await client.get( `pages/${ title }/signals` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.essential.title );
			assert.isDefined( response.body.essential.license );
			assert.isDefined( response.body.essential.link );
			assert.isDefined( response.body.essential.default_brand_marks );
			assert.isDefined( response.body.essential.source_wiki );

			assert.isUndefined( response.body.trust_and_relevance );
			assert.isUndefined( response.body.calls_to_action );

			// eslint-disable-next-line no-unused-expressions
			expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return trust_and_relevance properties', async () => {
			const response = await client.get( `pages/${ title }/signals?expand=trust_and_relevance` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.trust_and_relevance.last_modified );
			assert.isDefined( response.body.trust_and_relevance.page_views );
			assert.isDefined( response.body.trust_and_relevance.contributor_counts );

			assert.isUndefined( response.body.calls_to_action );

			// eslint-disable-next-line no-unused-expressions
			expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return calls_to_action properties', async () => {
			const response = await client.get( `pages/${ title }/signals?expand=calls_to_action` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.calls_to_action.donation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta.talk_page );

			assert.isUndefined( response.body.trust_and_relevance );

			// eslint-disable-next-line no-unused-expressions
			expect( response ).to.satisfyApiSpec;
		} );

		it( 'Should respect expand parameter and return both trust_and_relevance and calls_to_action properties', async () => {
			const response = await client.get( `pages/${ title }/signals?expand=trust_and_relevance,calls_to_action` );
			assert.deepEqual( response.status, 200 );

			assert.isDefined( response.body.calls_to_action.donation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta );
			assert.isDefined( response.body.calls_to_action.participation_cta.talk_page );

			assert.isDefined( response.body.trust_and_relevance.last_modified );
			assert.isDefined( response.body.trust_and_relevance.page_views );
			assert.isDefined( response.body.trust_and_relevance.contributor_counts );

			// eslint-disable-next-line no-unused-expressions
			expect( response ).to.satisfyApiSpec;
		} );
	} );

} );
