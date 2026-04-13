<?php

use MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\WikiMap\WikiMap;

require_once __DIR__ . '/../../../../maintenance/DonorIdentification/syncDonorStatus.php';

/**
 * @group Database
 */
class SyncDonorStatusTest extends MaintenanceBaseTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			$this->markTestSkipped( 'CentralAuth must be enabled for this test.' );
		}
	}

	protected function getMaintenanceClass() {
		return SyncDonorStatus::class;
	}

	private function getLocalUserIdentity( int $centralId ) {
		return MediaWikiServices::getInstance()
			->getCentralIdLookupFactory()
			->getLookup()
			->localUserFromCentralId( $centralId );
	}

	private function setGlobalDonorPreference( $userIdentity, string $value ): void {
		$this->getServiceContainer()->getUserOptionsManager()->setOption(
			$userIdentity,
			'wikimedia-donor',
			$value,
			UserOptionsManager::GLOBAL_CREATE
		);
	}

	private function saveUserOptions( $userIdentity ): void {
		MediaWikiServices::getInstance()->getUserOptionsManager()
			->saveOptions( $userIdentity );
	}

	private function getDonorPreference( $userIdentity ) {
		return MediaWikiServices::getInstance()->getUserOptionsManager()
			->getOption( $userIdentity, 'wikimedia-donor' );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute() {
		// create our test csv
		$filename = $this->getNewTempFile();
		$file = new SplFileObject( $filename, 'w' );

		$file->fputcsv( [ 'unverified@example.org', 2 ], ',', '"', '\\' );
		$file->fputcsv( [ 'nonconsenting@example.org', 5 ], ',', '"', '\\' );
		$file->fputcsv( [ 'valid@example.org', 8 ], ',', '"', '\\' );

		// setup our users
		$unverified = new TestUser( 'Test', 'test', 'unverified' );
		$nonconsenting = new TestUser( 'Test2', 'test2', 'nonconsenting' );
		$valid = new TestUser( 'Test3', 'test3', 'valid' );

		$unverified_global = new CentralAuthTestUser(
			$unverified->getUser()->getName(),
			$unverified->getPassword(),
			[ 'gu_id' => 1000, 'gu_email' => 'unverified@example.org', 'gu_email_authenticated' => null ],
			[ [ WikiMap::getCurrentWikiId(), 'new' ] ]
		);
		$unverified_global->save( $this->getDb() );

		$nonconsenting_global = new CentralAuthTestUser(
			$nonconsenting->getUser()->getName(),
			$nonconsenting->getPassword(),
			[ 'gu_id' => 1001, 'gu_email' => 'nonconsenting@example.org', 'gu_email_authenticated' => 1 ],
			[ [ WikiMap::getCurrentWikiId(), 'new' ] ]
		);
		$nonconsenting_global->save( $this->getDb() );

		$valid_global = new CentralAuthTestUser(
			$valid->getUser()->getName(),
			$valid->getPassword(),
			[ 'gu_id' => 1002, 'gu_email' => 'valid@example.org', 'gu_email_authenticated' => 1 ],
			[ [ WikiMap::getCurrentWikiId(), 'new' ] ]
		);
		$valid_global->save( $this->getDb() );

		$unverified_user_identity = $this->getLocalUserIdentity( 1000 );
		$nonconsenting_user_identity = $this->getLocalUserIdentity( 1001 );
		$valid_user_identity = $this->getLocalUserIdentity( 1002 );

		$this->setGlobalDonorPreference( $unverified_user_identity, '{ "value": 0 }' );
		$this->setGlobalDonorPreference( $nonconsenting_user_identity, '{ "value": 0 }' );
		$this->setGlobalDonorPreference( $valid_user_identity, '{ "value": 1 }' );

		$this->saveUserOptions( $unverified_user_identity );
		$this->saveUserOptions( $nonconsenting_user_identity );
		$this->saveUserOptions( $valid_user_identity );

		// run the script
		$this->maintenance->setArg( 'file', $filename );

		$this->maintenance->execute();

		// check results
		$unverified_pref = $this->getDonorPreference( $unverified_user_identity );
		$this->assertSame( '{ "value": 0 }', $unverified_pref );

		$nonconsenting_pref = $this->getDonorPreference( $nonconsenting_user_identity );
		$this->assertSame( '{ "value": 0 }', $nonconsenting_pref );

		$valid_pref = $this->getDonorPreference( $valid_user_identity );
		$this->assertSame( '{"value":8}', $valid_pref );
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecuteRejectsNonCsvInput() {
		$filename = $this->getNewTempFile();
		$file = new SplFileObject( $filename, 'w' );
		$file->fwrite( "<?php\n" );

		$this->maintenance->setArg( 'file', $filename );
		$this->expectCallToFatalError();
		$this->maintenance->execute();
	}

	/**
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecuteSkipsUnchangedPreference() {
		$filename = $this->getNewTempFile();
		$file = new SplFileObject( $filename, 'w' );
		$file->fwrite( 'valid@example.org,8' );

		$valid = new TestUser( 'Test4', 'test4', 'valid4' );
		$validGlobal = new CentralAuthTestUser(
			$valid->getUser()->getName(),
			$valid->getPassword(),
			[ 'gu_id' => 1003, 'gu_email' => 'valid@example.org', 'gu_email_authenticated' => 1 ],
			[ [ WikiMap::getCurrentWikiId(), 'new' ] ]
		);
		$validGlobal->save( $this->getDb() );

		$validUserIdentity = $this->getLocalUserIdentity( 1003 );

		$this->setGlobalDonorPreference( $validUserIdentity, '{"value":8}' );
		$this->saveUserOptions( $validUserIdentity );

		$this->maintenance->setArg( 'file', $filename );
		$this->maintenance->execute();

		$validPref = $this->getDonorPreference( $validUserIdentity );
		$this->assertSame( '{"value":8}', $validPref );
	}
}
