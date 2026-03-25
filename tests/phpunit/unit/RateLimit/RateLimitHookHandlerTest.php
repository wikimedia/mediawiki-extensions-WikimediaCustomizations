<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\RateLimit;

use MediaWiki\Config\Config;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\WikimediaCustomizations\RateLimit\RateLimitHookHandler;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\RateLimit\RateLimitHookHandler
 */
class RateLimitHookHandlerTest extends MediaWikiUnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( !class_exists( CentralAuthUser::class ) ) {
			$this->markTestSkipped( "CentralAuth not loaded" );
		}
	}

	private function createCaUser( array $getterValues ): CentralAuthUser {
		$allowedMethods = array_keys( $getterValues );
		$caUser = $this->createNoOpMock( CentralAuthUser::class, $allowedMethods );

		foreach ( $getterValues as $method => $value ) {
			$caUser->method( $method )->willReturn( $value );
		}

		return $caUser;
	}

	private function getHandler(
		array $config,
		CentralAuthUser $caUser
	): RateLimitHookHandler {
		$extensionRegistry = $this->createNoOpMock( ExtensionRegistry::class, [ 'isLoaded' ] );
		$extensionRegistry->method( 'isLoaded' )->willReturn( true );

		$editCounter = $this->createNoOpMock(
			CentralAuthEditCounter::class,
			[ 'getCountIfInitialized' ]
		);

		$editCounter->method( 'getCountIfInitialized' )
			->willReturn( $caUser->getGlobalEditCount() );

		$config = new HashConfig( $config );
		return new class( $config, $extensionRegistry, $caUser, $editCounter ) extends RateLimitHookHandler {
			public function __construct(
				Config $config,
				ExtensionRegistry $extensionRegistry,
				private readonly CentralAuthUser $caUser,
				private readonly CentralAuthEditCounter $editCounter,
			) {
				parent::__construct( $config, $extensionRegistry );
			}

			protected function getCentralAuthUser( UserIdentity $user ): CentralAuthUser {
				return $this->caUser;
			}

			protected function getEditCounter(): CentralAuthEditCounter {
				return $this->editCounter;
			}
		};
	}

	public static function provideOnGetSessionJwtData() {
		// seconds per day
		$days = 60 * 60 * 24;

		yield "nothing" => [ [], [], 'authed-user' ];

		yield 'group membership sets rlc for new user' => [
			'config' => [
				'WMCGlobalGroupToRateLimitClass' => [
					'global-bot' => 'approved-bot',
				],
			],
			// new bot account
			'userInfo' => [
				'getGlobalEditCount' => 100,
				'getRegistration' => MWTimestamp::convert( TimestampFormat::MW, time() - 1 * $days ),
				'getGlobalGroups' => [ 'whatever', 'global-bot' ],
			],
			'expected' => 'approved-bot',
		];

		yield 'group membership sets rlc for confirmed user' => [
			'config' => [
				'WMCGlobalGroupToRateLimitClass' => [
					'global-bot' => 'approved-bot',
				],
			],
			// confirmed bot account
			'userInfo' => [
				'getGlobalEditCount' => 100_000,
				'getRegistration' => MWTimestamp::convert( TimestampFormat::MW, time() - 1000 * $days ),
				'getGlobalGroups' => [ 'whatever', 'global-bot' ],
			],
			'expected' => 'approved-bot',
		];

		yield 'group membership does not match, user is new' => [
			'config' => [
				'WMCGlobalGroupToRateLimitClass' => [
					'global-bot' => 'approved-bot',
				],
			],
			'userInfo' => [
				'getGlobalGroups' => [ 'whatever', 'something' ],
			],
			'expected' => 'authed-user',
		];

		yield 'high edit count, but account too new' => [
			'config' => [],
			'userInfo' => [
				'getGlobalEditCount' => 10_000,
				'getRegistration' => MWTimestamp::convert( TimestampFormat::MW, time() - 1 * $days ),
			],
			'expected' => 'authed-user',
		];

		yield 'account is old, but edit count is too low' => [
			'config' => [],
			'userInfo' => [
				'getGlobalEditCount' => 100,
				'getRegistration' => MWTimestamp::convert( TimestampFormat::MW, time() - 1000 * $days ),
			],
			'expected' => 'authed-user',
		];

		yield 'not new: edit count > 1000, age > 7 days' => [
			'config' => [],
			'userInfo' => [
				'getGlobalEditCount' => 2000,
				'getRegistration' => MWTimestamp::convert( TimestampFormat::MW, time() - 10 * $days ),
			],
			'expected' => 'established-user',
		];
	}

	/**
	 * @dataProvider provideOnGetSessionJwtData
	 */
	public function testOnGetSessionJwtData( array $config, array $userInfo, ?string $expected ): void {
		$config += [
			'WMCGlobalGroupToRateLimitClass' => [],
		];

		$userInfo += [
			'exists' => true,
			'getGlobalEditCount' => 0,
			'getRegistration' => MWTimestamp::now(),
			'getGlobalGroups' => [],
		];

		$caUser = $this->createCaUser( $userInfo );
		$handler = $this->getHandler( $config, $caUser );

		$user = UserIdentityValue::newRegistered( 17, "Test" );
		$jwtData = [];
		$handler->onGetSessionJwtData( $user, $jwtData );

		$this->assertSame( $expected, $jwtData['rlc'] ?? null );
	}

	public function testOnGetSessionJwtData_owner_only(): void {
		$config = [
			'WMCGlobalGroupToRateLimitClass' => [
				'global-bot' => 'approved-bot',
			],
		];

		$caUser = $this->createCaUser( [
			'exists' => true,
			'getGlobalGroups' => [ 'global-bot' ],
			'getGlobalEditCount' => 1234,
		] );

		$handler = $this->getHandler( $config, $caUser );

		$user = UserIdentityValue::newRegistered( 17, "Test" );
		$jwtData = [ 'ownerOnly' => true ];

		// we expect no rlc field to be set for owner_only tokens
		$handler->onGetSessionJwtData( $user, $jwtData );
		$this->assertSame( [ 'ownerOnly' => true ], $jwtData, 'rlc field should not be set' );
	}

	public function testOnGetSessionJwtData_anon_user(): void {
		$config = [
			'WMCGlobalGroupToRateLimitClass' => [
				'global-bot' => 'approved-bot',
			],
		];

		$caUser = $this->createCaUser( [
			// User does not exist!
			'exists' => false,
			'getGlobalGroups' => [ 'global-bot' ],
			'getGlobalEditCount' => 1234,
		] );

		$handler = $this->getHandler( $config, $caUser );

		$user = UserIdentityValue::newRegistered( 17, "Test" );
		$jwtData = [ 'ownerOnly' => true ];

		// we expect no rlc field to be set for owner_only tokens
		$handler->onGetSessionJwtData( $user, $jwtData );
		$this->assertSame( [ 'ownerOnly' => true ], $jwtData, 'rlc field should not be set' );
	}

}
