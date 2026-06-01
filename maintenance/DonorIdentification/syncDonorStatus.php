<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification;

use GlobalPreferences\GlobalPreferencesServices;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use SplFileObject;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../../maintenance/Maintenance.php';

class SyncDonorStatus extends Maintenance {
	private const PREFERENCE_NAME = 'wikimedia-donor';
	private const DEFAULT_BATCH_SIZE = 500;

	private int $total_eligible = 0;
	private int $total_updated = 0;

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'CentralAuth' );
		$this->requireExtension( 'GlobalPreferences' );

		$this->addArg( 'file', 'CSV file provided by CiviCRM' );

		$this->addOption( 'batch-size', 'Max users per batch (default: 500)', false, true );
		$this->addOption( 'dry-run', 'Run the script without making any changes' );
		$this->addOption( 'verbose', 'Show detailed output' );
	}

	public function execute() {
		$batch_size = intval( $this->getOption( 'batch-size', self::DEFAULT_BATCH_SIZE ) );
		$buckets = [];

		$this->outputIfVerbose( 'waiting for input file' );

		$file = $this->blockAndWaitForFile( 'usleep' );

		if ( !$file ) {
			$this->fatalError( 'file does not exist or is empty' );
		}

		$this->outputIfVerbose( 'file found, reading...' );

		// the file could be quite large, so stream it line by line:
		while ( $file->valid() ) {
			// without escape explicitly specified, this will fail linting only after attempting to merge
			$row = $file->fgetcsv( escape: "\\" );

			if ( !$this->validateRow( $row ) ) {
				// row can't be processed, but is not worth fataling over - skip to next row
				continue;
			}

			// row has been validated, extract the two values
			[ $email, $donor_status_id ] = $row;

			// we don't want to output email addresses, because they may not have opted in to identification
			$this->outputIfVerbose( "read email: [redacted], donor status id: $donor_status_id" );

			// if the corresponding bucket doesn't already exist, create it
			if ( !array_key_exists( $donor_status_id, $buckets ) ) {
				$buckets[$donor_status_id] = [];
			}

			// put email address in bucket indexed by donor status
			$buckets[$donor_status_id][] = $email;

			// if the bucket is full, process the batch of users rather than trying to load the whole file into memory
			if ( count( $buckets[$donor_status_id] ) >= $batch_size ) {
				$this->processBatch( $buckets[$donor_status_id], $donor_status_id );

				// then clear the bucket
				$buckets[$donor_status_id] = [];
			}
		}

		// after the file has been fully read, batch process each bucket
		foreach ( $buckets as $donor_status_id => $bucket ) {
			if ( count( $bucket ) ) {
				$this->processBatch( $bucket, $donor_status_id );
			}
		}

		// output summary
		$this->output( "updated $this->total_updated opted-in users out of $this->total_eligible eligible\n" );
	}

	/**
	 * If the verbose flag is set, output the specified message - otherwise, do nothing
	 *
	 * Also puts a newline at the end of the message because I like to minimize double quotes 😇
	 *
	 * @param string $message - the message to be output if verbose
	 */
	public function outputIfVerbose( string $message ): void {
		if ( $this->hasOption( 'verbose' ) ) {
			$this->output( "$message\n" );
		}
	}

	/**
	 * Wait until the file becomes available, up to ~100 seconds
	 *
	 * Since the file can be quite large, we need to use kubectl cp to bypass the filesize limitations - this requires
	 * the container to already be running, hence the busy wait
	 *
	 * @param callable $usleep - pass the sleep function for testability
	 * @return ?SplFileObject - the open file, or null if we timeout
	 */
	public function blockAndWaitForFile( callable $usleep ): ?SplFileObject {
		$filename = $this->getArg( 'file' );

		// retry 1,000 times, sleeping for 100 milliseconds (.1 second) in between
		// for a total of 100 seconds, plus some micros
		for ( $i = 0; $i < 1000; $i++ ) {
			if ( file_exists( $filename ) && filesize( $filename ) ) {
				return new SplFileObject( $filename );
			} else {
				$usleep( 100000 );
			}
		}

		// if we exit the loop and the file is still not around, return null and expect
		// the caller to error out (and sorry for the unnecessary final .1 second sleep)
		return null;
	}

	/**
	 * Confirm a given row is two fields and contains a valid email address and integer
	 *
	 * Note: if the script needs to exit because a row fails validation, we will fatal from in here
	 *
	 * @param false|array $row - the return value from fgetscsv
	 * @return bool - false if a blank line, true otherwise (fatals if validation fails)
	 */
	public function validateRow( false|array $row ): bool {
		// if the csv parse fails, assume the worst and fatal
		if ( !$row ) {
			$this->fatalError( 'failed to parse CSV row' );
		}

		// if the line is empty, it's valid but we'll skip it
		if ( $row === [ null ] ) {
			$this->outputIfVerbose( 'skipping empty line' );
			return false;
		}

		// this could potentially be skipped, but again assuming the worst and fataling
		if ( count( $row ) !== 2 ) {
			$this->fatalError( 'expected two columns, received: ' . json_encode( $row ) );
		}

		// finally, validate the fields
		if ( filter_var( $row[0], FILTER_VALIDATE_EMAIL ) === false ) {
			$this->fatalError( "invalid email {$row[0]}" );
		}

		if ( filter_var( $row[1], FILTER_VALIDATE_INT ) === false ) {
			$this->fatalError( "invalid donor status id {$row[1]}" );
		}

		// if everything looks good, return true
		return true;
	}

	/**
	 * Ensure that a donor has given permission to be identified
	 *
	 * @param string $json - the undecoded json string value of the user's current global preference
	 * @return bool - whether the donor has given permission to be identified or not
	 */
	public function checkCurrentPreference( string $json ): bool {
		// attempt to decode preference value as json
		$preference = json_decode( $json, true );

		// handle errors
		if ( !$preference ) {
			$this->fatalError( "failed to decode json $json" );
		}

		if ( !array_key_exists( 'value', $preference ) ) {
			$this->fatalError( "no value present in preference $json" );
		}

		if ( !is_int( $preference['value'] ) ) {
			$this->fatalError( "preference value is not an integer $json" );
		}

		// finally, return false if the value is 0 indicating the user has opted out, else true
		return $preference['value'] !== 0;
	}

	/**
	 * Given a list of global IDs, set the global preference for all applicable users in a single transaction
	 *
	 * Note: I would like nothing more than for this method or one like it to live outside of this maintenance script
	 * and for us to be calling into it, but unfortunately such a method does not exist and the changes required to add
	 * one would entail updating at least five files across at least three repos 😰 sadly the public global preferences
	 * interface will remain user-specific for now
	 *
	 * @param array<int> $ids - a list of global IDs for users to have their preference saved
	 * @param string $preference - the preference as it is to be written to the DB
	 * @return int - number of rows updated
	 */
	public function batchSavePreference( array $ids, string $preference ): int {
		// in theory, this should never happen
		if ( !count( $ids ) ) {
			return 0;
		}

		// form the rows
		$rows = array_map( static function ( int $id ) use ( $preference ) {
			return [
				'gp_user' => $id,
				'gp_property' => self::PREFERENCE_NAME,
				'gp_value' => $preference,
			];
		}, $ids );

		// get the DB and use replace so existing rows will be deleted before insert
		$dbw = GlobalPreferencesServices::wrap( MediaWikiServices::getInstance() )
			->getGlobalPreferencesConnectionProvider()
			->getPrimaryDatabase();

		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'global_preferences' )
			->uniqueIndexFields( [ 'gp_user', 'gp_property' ] )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		// return the number of rows for validation/logging purposes
		return $dbw->affectedRows();
	}

	/**
	 * Process a batch of email addresses - look up the corresponding users, confirm consent to be donor identified,
	 * and finally set the corresponding donor status ID for eligible users
	 *
	 * @param array<string> $emails - the bucket of emails of associated users
	 * @param int $donor_status_id - the preference value that will eventually get set
	 */
	public function processBatch( array $emails, int $donor_status_id ): void {
		$batch_size = count( $emails );

		$this->outputIfVerbose( "processing batch of $batch_size emails (donor status id $donor_status_id)" );

		// look up all users with emails in the list provided whose email has been authenticated
		// note: due to emails not being unique, the number of accounts could be larger than the number of emails
		$users_generator = CentralAuthServices::getGlobalUserSelectQueryBuilderFactory()
			->newGlobalUserSelectQueryBuilder()
			->where( [ 'gu_email' => $emails, 'gu_email_authenticated IS NOT NULL' ] )
			->caller( __METHOD__ )
			->fetchCentralAuthUsers();

		// convert generator to an array to avoid re-traversal issues
		$users = iterator_to_array( $users_generator );

		$num_users = count( $users );

		// in the semi-unlikely case that no authenticated users exist, return early
		if ( !$num_users ) {
			return;
		}

		$this->outputIfVerbose( "found $num_users authenticated users" );
		$this->total_eligible += $num_users;

		// extract usernames from the array of user objects for batch function, then get current statuses
		$usernames = array_map( static function ( CentralAuthUser $user ) {
			return $user->getName();
		}, $users );

		$current_preferences = MediaWikiServices::getInstance()
			->getUserOptionsManager()
			->getOptionBatchForUserNames( $usernames, self::PREFERENCE_NAME );

		// filter out users that have not opted in to being identified
		$to_update = array_filter( $current_preferences, $this->checkCurrentPreference( ... ) );

		$to_update_count = count( $to_update );

		// in the slightly-more-likely case that no authenticated users have opted in to being identified, return early
		if ( !$to_update_count ) {
			return;
		}

		$this->outputIfVerbose( "updating $to_update_count opted-in users" );

		// if we have users to update, get the list of IDs from the original query
		$users_to_update = array_filter( $users, static function ( CentralAuthUser $user ) use ( $to_update ) {
			return array_key_exists( $user->getName(), $to_update );
		} );

		$ids = array_map( static function ( CentralAuthUser $user ) {
			return $user->getId();
		}, $users_to_update );

		// finally, set the global preference for the relevant users
		if ( $this->hasOption( 'dry-run' ) ) {
			$this->outputIfVerbose( "dry run detected - skipping actual update" );
		} else {
			$rows = $this->batchSavePreference( $ids, json_encode( [ 'value' => $donor_status_id ] ) );

			$this->outputIfVerbose( "updated $rows rows" );
			$this->total_updated += $rows;
		}
	}
}

$maintClass = SyncDonorStatus::class;
require_once RUN_MAINTENANCE_IF_MAIN;
