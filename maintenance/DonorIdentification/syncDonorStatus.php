<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Maintenance\DonorIdentification;

use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\Options\UserOptionsManager;
use SplFileObject;
use Throwable;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * Transfer donor status from CiviCRM to a global preference in CentralAuth
 * for users who have consented to donor identification.
 *
 * See T420548
 *
 * Usage:
 *  php syncDonorStatus.php [--dry-run] [--verbose]
 *
 * @ingroup Maintenance
 */
class SyncDonorStatus extends Maintenance {

	private const DONOR_PREFERENCE_NAME = 'wikimedia-donor';

	public function __construct() {
		parent::__construct();
		$this->addArg( 'file', 'CSV file provided by CiviCRM' );
		$this->addOption(
			'dry-run',
			'Run the script without making any changes',
			false,
			false
		);
		$this->addOption(
			'verbose',
			'Show detailed output for each user processed',
			false,
			false
		);
	}

	public function execute() {
		$isDryRun = $this->hasOption( 'dry-run' );

		// Tracking variables for reporting at the end
		$total = 0;
		$invalidData = 0;
		$usersNotFound = 0;
		$consentedUsers = 0;
		$nonconsentedUsers = 0;
		$unchangedUsers = 0;
		$lookupFailures = 0;
		$localUserFailures = 0;
		$saveFailures = 0;

		$path = $this->getArg( 'file' );
		if ( !is_readable( $path ) ) {
			$this->fatalError( "CSV not readable: $path" );
		}

		$file = new SplFileObject( $path );
		$this->validateCsvFile( $file, $path );

		$mwInstance = MediaWikiServices::getInstance();

		// Loop through the CSV file and process each row
		while ( !$file->eof() ) {
			$row = $file->fgetcsv( ',', '"', '\\' );

			// Skip empty rows
			if ( $row === false || $row === [ null ] ) {
				continue;
			}

			// Skip rows with incorrect data format or empty rows
			if ( !is_array( $row ) || count( $row ) !== 2 ) {
				$total++;
				$this->outputProgress( "Row $total" );
				$this->outputProgress( "INVALID CSV DATA\n" );
				$invalidData++;
				continue;
			}

			[ $email_address, $donor_status_id ] = $row;
			$email_address = trim( $email_address );

			// Skip header row
			if ( $email_address == 'email_address' && $donor_status_id == 'donor_status_id' ) {
				continue;
			}

			$total++;

			$this->outputProgress( "Row $total" );

			// Validate the email address
			if ( $email_address === '' || !filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
				$this->outputProgress( "INVALID EMAIL\n" );
				$invalidData++;
				continue;
			}

			// if multiple accounts are associated with the same authenticated email address,
			// we can set the donor status preference for all of them.
			try {
				$usersGenerator = CentralAuthServices::getGlobalUserSelectQueryBuilderFactory()
					->newGlobalUserSelectQueryBuilder()
					->where( [ 'gu_email' => $email_address, 'gu_email_authenticated IS NOT NULL' ] )
					->caller( __METHOD__ )
					->fetchCentralAuthUsers();

				// Convert generator to an array to avoid re-traversal issues
				$users = iterator_to_array( $usersGenerator );
			} catch ( Throwable ) {
				$this->outputProgress( "LOOKUP FAILED\n" );
				$lookupFailures++;
				continue;
			}

			// If there are no users with a matching validated email address, skip to the next row
			if ( $users === [] ) {
				$this->outputProgress( "NOT FOUND\n" );
				$usersNotFound++;
				continue;
			}

			$userOptionsManager = $mwInstance->getUserOptionsManager();
			$centralIdLookup = $mwInstance->getCentralIdLookupFactory()->getLookup();

			// Most of the time this will be a single user account, if any
			// but it's possible to have multiple accounts registered to the same email
			foreach ( $users as $user ) {
				// Check if the user has consented to donor identification
				$user_identity = $centralIdLookup->localUserFromCentralId( $user->getId() );
				if ( !$user_identity || !$user_identity->isRegistered() ) {
					$this->outputProgress( "LOCAL USER NOT FOUND\n" );
					$localUserFailures++;
					continue;
				}

				// todo reason further about global vs local preferences
				$donor_consent = $userOptionsManager->getOption(
					$user_identity,
					self::DONOR_PREFERENCE_NAME
				);
				if ( $donor_consent ) {
					$donor_consent_data = json_decode( $donor_consent, true );

					if ( !isset( $donor_consent_data['value'] ) || !is_numeric( $donor_consent_data['value'] ) ) {
						$this->outputProgress( "INVALID CONSENT DATA\n" );
						$invalidData++;
						continue;
					}

					if ( (int)$donor_consent_data['value'] === 0 ) {
						$this->outputProgress( "NOT SAVED FOR NON-CONSENTING USER\n" );
						$nonconsentedUsers++;
						continue;
					}
				}

				$preferenceValue = json_encode( [ 'value' => (int)$donor_status_id ] );
				if ( $donor_consent === $preferenceValue ) {
					$this->outputProgress( "UNCHANGED\n" );
					$unchangedUsers++;
					continue;
				}

				if ( !$isDryRun ) {
					try {
						$userOptionsManager->setOption(
							$user_identity,
							self::DONOR_PREFERENCE_NAME,
							$preferenceValue,
							UserOptionsManager::GLOBAL_CREATE
						);
						$userOptionsManager->saveOptions( $user_identity );
					} catch ( Throwable ) {
						$this->outputProgress( "SAVE FAILED\n" );
						$saveFailures++;
						continue;
					}
				}

				$consentedUsers++;
				$this->outputProgress( "SAVED\n" );
			}
		}

		$this->outputSummary(
			$total,
			$consentedUsers,
			$nonconsentedUsers,
			$unchangedUsers,
			$usersNotFound,
			$invalidData,
			$lookupFailures,
			$localUserFailures,
			$saveFailures
		);
	}

	private function outputSummary(
		int $total,
		int $consentedUsers,
		int $nonconsentedUsers,
		int $unchangedUsers,
		int $usersNotFound,
		int $invalidData,
		int $lookupFailures,
		int $localUserFailures,
		int $saveFailures
	): void {
		$this->output( "\nProcessed $total rows:\n" );
		if ( $consentedUsers > 0 ) {
			$this->output( " - Updated donor status for $consentedUsers users\n" );
		}
		if ( $nonconsentedUsers > 0 ) {
			$this->output( " - Found $nonconsentedUsers non-consenting users\n" );
		}
		if ( $unchangedUsers > 0 ) {
			$this->output( " - Skipped $unchangedUsers users whose donor status was already current\n" );
		}
		if ( $usersNotFound > 0 ) {
			$this->output( " - Could not find $usersNotFound users with a confirmed email address\n" );
		}
		if ( $invalidData > 0 ) {
			$this->output( " - Skipped $invalidData rows with invalid data\n" );
		}
		if ( $lookupFailures > 0 ) {
			$this->output( " - Failed to look up CentralAuth users for $lookupFailures rows\n" );
		}
		if ( $localUserFailures > 0 ) {
			$this->output( " - Failed to resolve $localUserFailures local users from CentralAuth IDs\n" );
		}
		if ( $saveFailures > 0 ) {
			$this->output( " - Failed to save donor status for $saveFailures users\n" );
		}
	}

	/**
	 * Sanity-check the input before processing.
	 *
	 * This validates only the first non-empty row and then rewinds the file so
	 * execute() can process it from the beginning.
	 */
	private function validateCsvFile( SplFileObject $file, string $path ): void {
		$file->rewind();

		while ( !$file->eof() ) {
			$row = $file->fgetcsv( ',', '"', '\\' );
			if ( $row === false || $row === [ null ] ) {
				continue;
			}

			if ( !is_array( $row ) || count( $row ) !== 2 ) {
				$this->fatalError( "Input file is not a valid 2-column CSV: $path" );
			}

			$file->rewind();
			return;
		}

		$this->fatalError( "CSV is empty: $path" );
	}

	private function outputProgress( string $message ): void {
		$verbose = $this->hasOption( 'verbose' );
		if ( $verbose ) {
			$this->output( $message );
		}
	}
}

$maintClass = SyncDonorStatus::class;
require_once RUN_MAINTENANCE_IF_MAIN;
