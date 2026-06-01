<?php

namespace MediaWiki\Tests\Maintenance;

use CentralAuthTestUser;
use GlobalPreferences\GlobalPreferencesServices;
use MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus;
use MediaWiki\MediaWikiServices;
use SplFileObject;
use TestUser;

require_once __DIR__ . '/../../../../maintenance/DonorIdentification/syncDonorStatus.php';

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus
 * @group Database
 */
class SyncDonorStatusTest extends MaintenanceBaseTestCase {
	protected function getMaintenanceClass() {
		return SyncDonorStatus::class;
	}

	/**
	 * We want to be able to make use of the TestSelectQueryBuilder class, and the global preferences table is in a
	 * separate DB than the default (local), so override this method as we'll only be directly accessing the global
	 * preferences table
	 *
	 * @return IDatabase
	 */
	protected function getDb() {
		return GlobalPreferencesServices::wrap( MediaWikiServices::getInstance() )
			->getGlobalPreferencesConnectionProvider()
			->getPrimaryDatabase();
	}

	public function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );
	}

	/**
	 * If the verbose flag is not set, nothing should be output
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::outputIfVerbose
	 */
	public function testOutputIfVerbose_notVerbose() {
		$this->expectOutputRegex( '/^((?!test).)*$/' );

		$this->maintenance->outputIfVerbose( 'test' );
	}

	/**
	 * If the verbose flag is set, message should be output
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::outputIfVerbose
	 */
	public function testOutputIfVerbose_verbose() {
		// for some reason expectOutputString is giving false positives, so use "regex" for more accurate detection
		$this->expectOutputRegex( '/test/' );

		$this->maintenance->setOption( 'verbose', true );

		$this->maintenance->outputIfVerbose( 'test' );
	}

	/**
	 * If the file is present, we should return immediately and not sleep
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::blockAndWaitForFile
	 */
	public function testBlockAndWaitForFile_filePresent() {
		$temp = $this->getNewTempFile();
		file_put_contents( $temp, 'test' );
		$this->maintenance->setArg( 'file', $temp );

		$mock_usleep = function () {
			// ensure usleep is not called
			$this->assertTrue( false );
		};

		$file = $this->maintenance->blockAndWaitForFile( $mock_usleep );

		$this->assertNotNull( $file );
		$this->assertSame( 'test', $file->fgets() );
	}

	/**
	 * If the file is not present, we should wait the full 100 seconds
	 * tested using dependency injection so we don't sleep for 2 minutes 🙃
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::blockAndWaitForFile
	 */
	public function testBlockAndWaitForFile_notPresent() {
		$this->maintenance->setArg( 'file', 'absent file' );

		$count = 0;
		$mock_usleep = function ( $micros ) use ( &$count ) {
			$this->assertSame( 100000, $micros );
			$count++;
		};

		$file = $this->maintenance->blockAndWaitForFile( $mock_usleep );

		$this->assertNull( $file );
		$this->assertSame( 1000, $count );
	}

	/**
	 * Since we're using dependency injection, we can also test the case the file _becomes_ available, which is in fact
	 * the presumed typical case here
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::blockAndWaitForFile
	 */
	public function testBlockAndWaitForFile_becomesPresent() {
		// this is a bit strange, but now I'm sure the filepath is available, and it will be cleaned up after the tests
		$temp = $this->getNewTempFile();
		unlink( $temp );

		$this->maintenance->setArg( 'file', $temp );

		$count = 0;
		$mock_usleep = function ( $micros ) use ( &$count, $temp ) {
			$this->assertSame( 100000, $micros );

			if ( $count === 3 ) {
				file_put_contents( $temp, 'test' );
			} else {
				$count++;
			}
		};

		$file = $this->maintenance->blockAndWaitForFile( $mock_usleep );

		$this->assertNotNull( $file );
		$this->assertSame( 3, $count );
		$this->assertSame( 'test', $file->fgets() );
	}

	/**
	 * Make sure empty files don't count
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::blockAndWaitForFile
	 */
	public function testBlockAndWaitForFile_fileEmpty() {
		$temp = $this->getNewTempFile();
		$this->maintenance->setArg( 'file', $temp );

		$count = 0;
		$mock_usleep = function ( $micros ) use ( &$count ) {
			$this->assertSame( 100000, $micros );
			$count++;
		};

		$file = $this->maintenance->blockAndWaitForFile( $mock_usleep );

		$this->assertNull( $file );
		$this->assertSame( 1000, $count );
	}

	/**
	 * If the call to fgetcsv fails, fatal
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_noRow() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/failed to parse CSV row/' );

		$this->maintenance->validateRow( false );
	}

	/**
	 * If the line is empty, don't fatal but return false
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_emptyLine() {
		$ret = $this->maintenance->validateRow( [ null ] );

		$this->assertFalse( $ret );
	}

	/**
	 * If too few columns are received, fatal
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_oneColumn() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/expected two columns, received: \[\"test\"\]/' );

		$this->maintenance->validateRow( [ 'test' ] );
	}

	/**
	 * If too many columns are received, fatal
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_threeColumns() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/expected two columns, received: \[1,2,3\]/' );

		$this->maintenance->validateRow( [ 1, 2, 3 ] );
	}

	/**
	 * If the email address is invalid, fatal
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_invalidEmail() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/invalid email test/' );

		$this->maintenance->validateRow( [ 'test', '0' ] );
	}

	/**
	 * If the donor status id is not an int, fatal
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_invalidDonorStatusId() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/invalid donor status id test/' );

		$this->maintenance->validateRow( [ 'test@test.com', 'test' ] );
	}

	/**
	 * If the row is good, return true
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::validateRow
	 */
	public function testValidateRow_valid() {
		$ret = $this->maintenance->validateRow( [ 'test@test.com', '0' ] );

		$this->assertTrue( $ret );
	}

	/**
	 * If invalid json, throw error
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::checkCurrentPreference
	 */
	public function testCheckCurrentPreference_invalidJson() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/failed to decode json {test/' );

		$this->maintenance->checkCurrentPreference( '{test' );
	}

	/**
	 * If no value field is present, throw error
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::checkCurrentPreference
	 */
	public function testCheckCurrentPreference_noValue() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/no value present in preference {"test":0}/' );

		$this->maintenance->checkCurrentPreference( '{"test":0}' );
	}

	/**
	 * If the value is not an integer, throw error
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::checkCurrentPreference
	 */
	public function testCheckCurrentPreference_notInt() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/preference value is not an integer {"value":"test"}/' );

		$this->maintenance->checkCurrentPreference( '{"value":"test"}' );
	}

	/**
	 * If the value is zero, return false
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::checkCurrentPreference
	 */
	public function testCheckCurrentPreference_zeroValue() {
		$ret = $this->maintenance->checkCurrentPreference( '{"value":0}' );

		$this->assertFalse( $ret );
	}

	/**
	 * If the value is not zero, return true
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::checkCurrentPreference
	 */
	public function testCheckCurrentPreference_nonZeroValue() {
		$ret = $this->maintenance->checkCurrentPreference( '{"value":1}' );

		$this->assertTrue( $ret );
	}

	/**
	 * If the ID list is empty, exit early
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::batchSavePreference
	 */
	public function testBatchSavePreference_noIds() {
		$ret = $this->maintenance->batchSavePreference( [], 'test' );

		$this->assertSame( 0, $ret );
	}

	/**
	 * Inserts a single row into an empty table correctly
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::batchSavePreference
	 */
	public function testBatchSavePreference_singleRow() {
		$rows = $this->maintenance->batchSavePreference( [ 0 ], 'test' );

		$this->assertSame( 1, $rows );

		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->where( [
				'gp_user' => 0,
				'gp_property' => 'wikimedia-donor',
			] )
			->assertFieldValue( 'test' );
	}

	/**
	 * Inserts multiple lines into an empty table correctly
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::batchSavePreference
	 */
	public function testBatchSavePreference_multiRow() {
		$ids = range( 0, 499 );

		$rows = $this->maintenance->batchSavePreference( $ids, 'test' );

		$this->assertSame( 500, $rows );

		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->where( [
				'gp_property' => 'wikimedia-donor',
				'gp_value' => 'test',
			] )
			->assertResultSet( array_map( static function ( int $id ) {
				return [ $id ];
			}, $ids ) );
	}

	/**
	 * Make sure we're correctly removing existing preferences if they exist
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::batchSavePreference
	 */
	public function testBatchSavePreference_replace() {
		$ids = range( 0, 499 );

		// seed the DB with values
		$this->maintenance->batchSavePreference( $ids, 'old' );

		// then overwrite with our new value
		$rows = $this->maintenance->batchSavePreference( $ids, 'new' );

		$this->assertSame( 500, $rows );

		// confirm the old values are no longer present
		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->where( [ 'gp_value' => 'old' ] )
			->assertEmptyResult();

		// confirm the new values have been written
		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->where( [
				'gp_property' => 'wikimedia-donor',
				'gp_value' => 'new',
			] )
			->assertResultSet( array_map( static function ( int $id ) {
				return [ $id ];
			}, $ids ) );
	}

	/**
	 * Update preference values and set new ones in the same operation
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::batchSavePreference
	 */
	public function testBatchSavePreference_mixed() {
		$this->maintenance->batchSavePreference( [ 1, 4, 7, 8 ], 'old' );

		$rows = $this->maintenance->batchSavePreference( [ 2, 4, 6, 8 ], 'new' );

		$this->assertSame( 4, $rows );

		// expect old values that have not been overwritten
		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->where( [
				'gp_property' => 'wikimedia-donor',
				'gp_value' => 'old',
			] )
			->assertResultSet( [
				[ 1 ],
				[ 7 ],
			] );

		// expect new values that have been inserted or overwritten
		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->where( [
				'gp_property' => 'wikimedia-donor',
				'gp_value' => 'new',
			] )
			->assertResultSet( [
				[ 2 ],
				[ 4 ],
				[ 6 ],
				[ 8 ],
			] );
	}

	/**
	 * Correctly updates the preference of a single eligible user
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_singleUser() {
		// create user and global user
		$user = new TestUser( 'Test', 'test', 'test email' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		// manually set the existing preference to opt in to identification
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		// call the method to process the user
		$this->maintenance->processBatch( [ 'test email' ], 2 );

		// verify the value is now 2
		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->assertFieldValue( '{"value":2}' );
	}

	/**
	 * Correctly updates the preferences of multiple eligible users
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_multiUser() {
		$users = [];
		$global_users = [];

		for ( $i = 0; $i < 3; $i++ ) {
			$users[$i] = new TestUser( "Test$i", "test$i", "test email $i" );
			$users[$i]->getUser()->setEmailAuthenticationTimestamp( 1 );

			$global_users[$i] = CentralAuthTestUser::newFromTestUser( $users[$i] );
			$global_users[$i]->save( $this->getDb() );
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( array_map( static function ( $global_user ) {
				return [
					'gp_user' => $global_user->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}'
				];
			}, $global_users ) )
			->execute();

		$this->maintenance->processBatch( [ 'test email 0', 'test email 1', 'test email 2' ], 2 );

		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->assertResultSet( array_map( static function ( $global_user ) {
				return [ $global_user->getCentralUser()->getId() ];
			}, $global_users ) );
	}

	/**
	 * Does not add preferences if there are no users
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_noUsers() {
		$this->maintenance->processBatch( [ 'test' ], 0 );

		$this->newSelectQueryBuilder()
			->select( 'gp_user' )
			->from( 'global_preferences' )
			->assertEmptyResult();
	}

	/**
	 * Does not add preferences if there are users, but not the _correct_ users
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_noSpecifiedUsers() {
		$user = new TestUser( 'Test', 'test', 'email' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$this->maintenance->processBatch( [ 'different email' ], 0 );

		// assert table hasn't changes since above method run
		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertRowValue( [ $global_user->getCentralUser()->getId(), '{"value":1}' ] );
	}

	/**
	 * Does not add preferences if the email is not verified
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_emailUnverified() {
		$user = new TestUser( 'Test', 'test', 'email' );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$this->maintenance->processBatch( [ 'email' ], 0 );

		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertRowValue( [ $global_user->getCentralUser()->getId(), '{"value":1}' ] );
	}

	/**
	 * Does not add preferences if the user is not opted in to identification
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_optedOut() {
		$user = new TestUser( 'Test', 'test', 'email' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->maintenance->processBatch( [ 'email' ], 1 );

		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->assertEmptyResult();
	}

	/**
	 * Test that everything works together
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::processBatch
	 */
	public function testProcessBatch_mixed() {
		$users = [];
		$global_users = [];

		for ( $i = 0; $i < 5; $i++ ) {
			$users[$i] = new TestUser( "Test$i", "test$i", "test email $i" );
			$users[$i]->getUser()->setEmailAuthenticationTimestamp( 1 );

			$global_users[$i] = CentralAuthTestUser::newFromTestUser( $users[$i] );
			$global_users[$i]->save( $this->getDb() );
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( [
				[
					'gp_user' => $global_users[1]->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":0}',
				],
				[
					'gp_user' => $global_users[2]->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}',
				],
				[
					'gp_user' => $global_users[3]->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":100}',
				],
				[
					'gp_user' => $global_users[4]->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}',
				],
			] )
			->execute();

		$this->maintenance->processBatch( [
			'test email 0',
			'test email 1',
			'test email 2',
			'test email 3',
			'test email 5',
		], 10 );

		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertResultSet( [
				[ $global_users[1]->getCentralUser()->getId(), '{"value":0}' ],
				[ $global_users[2]->getCentralUser()->getId(), '{"value":10}' ],
				[ $global_users[3]->getCentralUser()->getId(), '{"value":10}' ],
				[ $global_users[4]->getCentralUser()->getId(), '{"value":1}' ],
			] );
	}

	/**
	 * Script skips empty lines without fataling
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_emptyLine() {
		$user = new TestUser( 'Test', 'test', 'test@email.com' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$temp = $this->getNewTempFile();
		file_put_contents( $temp, "\n\n\ntest@email.com,2" );

		$this->maintenance->setArg( 'file', $temp );

		$this->maintenance->execute();

		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->assertFieldValue( '{"value":2}' );
	}

	/**
	 * Script proactively runs batches when the bucket fills up
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_maxBatchSize() {
		$users = [];
		$global_users = [];
		$temp = $this->getNewTempFile();
		$file = new SplFileObject( $temp, 'w' );

		for ( $i = 0; $i < 5; $i++ ) {
			$users[$i] = new TestUser( "Test$i", "test$i", "test$i@email.com" );
			$users[$i]->getUser()->setEmailAuthenticationTimestamp( 1 );

			$global_users[$i] = CentralAuthTestUser::newFromTestUser( $users[$i] );
			$global_users[$i]->save( $this->getDb() );

			$file->fputcsv( [ "test$i@email.com", 2 ], escape: "\\" );
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( array_map( static function ( $global_user ) {
				return [
					'gp_user' => $global_user->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}'
				];
			}, $global_users ) )
			->execute();

		$this->maintenance->setArg( 'file', $temp );
		$this->maintenance->setOption( 'batch-size', 3 );

		$this->maintenance->execute();

		$this->assertSame( 2, $this->getDb()->affectedRows() );

		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertResultSet( array_map( static function ( $global_user ) {
				return [ $global_user->getCentralUser()->getId(), '{"value":2}' ];
			}, $global_users ) );
	}

	/**
	 * Skips empty buckets at the end without fataling
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_emptyBucket() {
		$user = new TestUser( 'Test', 'test', 'test@email.com' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$temp = $this->getNewTempFile();
		file_put_contents( $temp, "test@email.com,2" );

		$this->maintenance->setArg( 'file', $temp );
		$this->maintenance->setOption( 'batch-size', 1 );

		$this->maintenance->execute();

		$this->assertSame( 1, $this->getDb()->affectedRows() );

		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->assertFieldValue( '{"value":2}' );
	}

	/**
	 * Script can process multiple values
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_multi() {
		$users = [];
		$global_users = [];
		$temp = $this->getNewTempFile();
		$file = new SplFileObject( $temp, 'w' );

		for ( $i = 0; $i < 5; $i++ ) {
			$users[$i] = new TestUser( "Test$i", "test$i", "test$i@email.com" );
			$users[$i]->getUser()->setEmailAuthenticationTimestamp( 1 );

			$global_users[$i] = CentralAuthTestUser::newFromTestUser( $users[$i] );
			$global_users[$i]->save( $this->getDb() );

			$file->fputcsv( [ "test$i@email.com", $i ], escape: "\\" );
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( array_map( static function ( $global_user ) {
				return [
					'gp_user' => $global_user->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}'
				];
			}, $global_users ) )
			->execute();

		$this->maintenance->setArg( 'file', $temp );

		$this->maintenance->execute();

		$this->assertSame( 1, $this->getDb()->affectedRows() );

		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertResultSet( array_map( static function ( $global_user, $i ) {
				return [ $global_user->getCentralUser()->getId(), "{\"value\":$i}" ];
			}, $global_users, range( 0, count( $global_users ) - 1 ) ) );
	}

	/**
	 * Script can process multiple batches of multiple values
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_batchMulti() {
		$users = [];
		$global_users = [];
		$temp = $this->getNewTempFile();
		$file = new SplFileObject( $temp, 'w' );

		for ( $i = 0; $i < 10; $i++ ) {
			$users[$i] = new TestUser( "Test$i", "test$i", "test$i@email.com" );
			$users[$i]->getUser()->setEmailAuthenticationTimestamp( 1 );

			$global_users[$i] = CentralAuthTestUser::newFromTestUser( $users[$i] );
			$global_users[$i]->save( $this->getDb() );

			$file->fputcsv( [ "test$i@email.com", $i % 5 ], escape: "\\" );
		}

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->rows( array_map( static function ( $global_user ) {
				return [
					'gp_user' => $global_user->getCentralUser()->getId(),
					'gp_property' => 'wikimedia-donor',
					'gp_value' => '{"value":1}'
				];
			}, $global_users ) )
			->execute();

		$this->maintenance->setArg( 'file', $temp );

		$this->maintenance->execute();

		$this->assertSame( 2, $this->getDb()->affectedRows() );

		$this->newSelectQueryBuilder()
			->select( [ 'gp_user', 'gp_value' ] )
			->from( 'global_preferences' )
			->assertResultSet( array_map( static function ( $global_user, $i ) {
				$value = $i % 5;
				return [ $global_user->getCentralUser()->getId(), "{\"value\":$value}" ];
			}, $global_users, range( 0, count( $global_users ) - 1 ) ) );
	}

	/**
	 * Ensure dry runs do not write to the DB
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_dryRun() {
		$user = new TestUser( 'Test', 'test', 'test@email.com' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$temp = $this->getNewTempFile();
		file_put_contents( $temp, "test@email.com,2" );

		$this->maintenance->setArg( 'file', $temp );
		$this->maintenance->setOption( 'dry-run', true );

		$this->maintenance->execute();

		$this->newSelectQueryBuilder()
			->select( 'gp_value' )
			->from( 'global_preferences' )
			->assertFieldValue( '{"value":1}' );
	}

	/**
	 * Ensure no emails are output by the script
	 *
	 * @covers \MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification\SyncDonorStatus::execute
	 */
	public function testExecute_noEmailsOutput() {
		$this->expectOutputRegex( '/^((?!test@email\.com).)*$/s' );

		$user = new TestUser( 'Test', 'test', 'test@email.com' );
		$user->getUser()->setEmailAuthenticationTimestamp( 1 );

		$global_user = CentralAuthTestUser::newFromTestUser( $user );
		$global_user->save( $this->getDb() );

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'global_preferences' )
			->row( [
				'gp_user' => $global_user->getCentralUser()->getId(),
				'gp_property' => 'wikimedia-donor',
				'gp_value' => '{"value":1}'
			] )
			->execute();

		$temp = $this->getNewTempFile();
		file_put_contents( $temp, "test@email.com,2" );

		$this->maintenance->setArg( 'file', $temp );
		$this->maintenance->setOption( 'verbose', true );

		$this->maintenance->execute();
	}
}
