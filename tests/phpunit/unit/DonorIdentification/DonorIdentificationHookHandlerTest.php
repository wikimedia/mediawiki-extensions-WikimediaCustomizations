<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\DonorIdentification;

use MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorIdentificationHookHandler;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\DonorIdentification\DonorIdentificationHookHandler
 */
class DonorIdentificationHookHandlerTest extends MediaWikiUnitTestCase {

	public function testValidateDonorPreferenceValue(): void {
		$hookHandler = new DonorIdentificationHookHandler();
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

	public function testOnGetPreferences(): void {
		$user = $this->createMock( User::class );
		$hookHandler = new DonorIdentificationHookHandler();
		$prefs = [];
		$hookHandler->onGetPreferences( $user, $prefs );
		$this->assertTrue( isset( $prefs['wikimedia-donor'] ) );
	}
}
