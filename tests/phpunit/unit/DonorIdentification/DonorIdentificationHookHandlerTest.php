<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\DonorIdentification;

use MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorIdentificationHookHandler;
use MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorPreferenceFilter;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorIdentificationHookHandler
 */
class DonorIdentificationHookHandlerTest extends MediaWikiUnitTestCase {

	private function newHookHandler( UserOptionsManager $optionsManager ): DonorIdentificationHookHandler {
		return new DonorIdentificationHookHandler( $optionsManager );
	}

	public function testValidateDonorPreferenceValue(): void {
		$hookHandler = $this->newHookHandler( $this->createMock( UserOptionsManager::class ) );
		$this->assertTrue( $hookHandler::validateDonorPreferenceValue( '' ) );
		$this->assertTrue( $hookHandler::validateDonorPreferenceValue( '{ "value": 0 }' ) );
		$this->assertTrue( $hookHandler::validateDonorPreferenceValue( '{ "value": 100 }' ) );
		$this->assertTrue( $hookHandler::validateDonorPreferenceValue( '{ "value": 100, "consent": "2025" }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": -1 }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '5' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "x": 0 }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": "foo" }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": "2" }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": {} }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": [] }' ) );
		$this->assertFalse( $hookHandler::validateDonorPreferenceValue( '{ "value": true }' ) );
	}

	public function testOnGetPreferencesWithoutDonorStatus(): void {
		$optionsManager = $this->createMock( UserOptionsManager::class );
		$optionsManager->method( 'getOption' )->willReturn( '' );

		$prefs = [];
		$this->newHookHandler( $optionsManager )->onGetPreferences(
			$this->createMock( User::class ),
			$prefs
		);

		// Registered (so the consent API can write to it) but never displayed.
		$this->assertSame( 'api', $prefs['wikimedia-donor']['type'] );
		$this->assertArrayNotHasKey( 'section', $prefs['wikimedia-donor'] );
	}

	public function testOnGetPreferencesWithDonorStatus(): void {
		$optionsManager = $this->createMock( UserOptionsManager::class );
		$optionsManager->method( 'getOption' )->willReturn( '{"value":2}' );

		$prefs = [];
		$this->newHookHandler( $optionsManager )->onGetPreferences(
			$this->createMock( User::class ),
			$prefs
		);

		$this->assertSame( 'toggle', $prefs['wikimedia-donor']['type'] );
		$this->assertSame( 'personal/email/donor', $prefs['wikimedia-donor']['section'] );
		$this->assertSame( 'wikimediacustomizations-donor-identify-label', $prefs['wikimedia-donor']['label-message'] );
		$this->assertInstanceOf( DonorPreferenceFilter::class, $prefs['wikimedia-donor']['filter'] );
	}
}
