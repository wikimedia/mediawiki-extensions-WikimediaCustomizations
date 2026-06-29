<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\SecurityLogs;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Extension\WikimediaCustomizations\SecurityLogs\SecurityLogsHookHandler;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Message\Message;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Status\Status;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use StatusValue;
use TestLogger;
use Wikimedia\Message\MessageSpecifier;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\SecurityLogs\SecurityLogsHookHandler
 */
class SecurityLogsHookHandlerTest extends MediaWikiUnitTestCase {

	protected function getHandler(
		?array $userIdentityByName = null,
		array $loggers = [],
		?array $securityLogContext = null,
	): SecurityLogsHookHandler {
		if ( $userIdentityByName !== null ) {
			$userIdentityLookup = $this->createNoOpMock( UserIdentityLookup::class, [ 'getUserIdentityByName' ] );
			$userIdentityLookup->method( 'getUserIdentityByName' )
				->willReturnCallback( static function ( string $username ) use ( $userIdentityByName ) {
					return $userIdentityByName[$username] ?? null;
				} );
		} else {
			$userIdentityLookup = $this->createNoOpMock( UserIdentityLookup::class );
		}

		$formatterFactory = $this->createNoOpMock( FormatterFactory::class, [ 'getStatusFormatter' ] );
		$statusFormatter = $this->createNoOpMock( StatusFormatter::class, [ 'getWikiText' ] );
		$formatterFactory->method( 'getStatusFormatter' )->willReturn( $statusFormatter );
		$statusFormatter->method( 'getWikiText' )->willReturnCallback( function ( Status $status ) {
			$error = $status->getMessages( 'error' )[0] ?? null;
			$this->assertInstanceOf( MessageSpecifier::class, $error );
			return $error->getKey();
		} );

		return new class(
			$userIdentityLookup, $formatterFactory, $this, $loggers, $securityLogContext
		) extends SecurityLogsHookHandler {
			public function __construct(
				UserIdentityLookup $userIdentityLookup,
				FormatterFactory $formatterFactory,
				private TestCase $testCase,
				private array $loggers,
				private ?array $securityLogContext
			) {
				parent::__construct( $userIdentityLookup, $formatterFactory );
			}

			protected function getLogger( string $channel ): LoggerInterface {
				return $this->loggers[$channel] ?? $this->testCase->fail( "Unexpected log channel $channel" );
			}

			protected function getSecurityLogContext( ?UserIdentity $user ): array {
				return $this->securityLogContext ?? $this->testCase->fail( 'Security log context wasnt set up' );
			}
		};
	}

	public function testOnGetSecurityLogContext(): void {
		$request = new FauxRequest();
		$request->setHeaders( [
			'x-trusted-request' => 'yes',
			'x-is-browser' => '100',
			'x-ua-contact' => 'example@example.com',
			'x-ja3n' => 'abc123',
			'x-ja4h' => 'def456',
			'x-provenance' => 'foo=bar;boom;baz=bang',
		] );
		$request->setCookie( 'GeoIP', 'London', '' );

		$handler = $this->getHandler();
		$context = [];
		$handler->onGetSecurityLogContext( [ 'request' => $request ], $context );

		$this->assertArrayEquals( [
			'x-trusted-request' => 'yes',
			'x-is-browser' => '100',
			'x-ua-contact' => 'example@example.com',
			'x-ja3n' => 'abc123',
			'x-ja4h' => 'def456',
			'geocookie' => 'London',
			'x-provenance' => [ 'foo' => 'bar', 'boom' => '', 'baz' => 'bang' ],
		], $context, named: true );
	}

	public static function provideOnAuthManagerLoginAuthenticateAudit() {
		$pass = AuthenticationResponse::newPass( 'SomeUser' );
		$fail = AuthenticationResponse::newFail( Message::newFromKey( 'wrongpass' ) );
		$failWithExtraReasons = AuthenticationResponse::newFail( Message::newFromKey( 'wrongpass' ),
			[ 'reason1', 'reason2' ] );
		$normalSecurityLogContext = [
			'user_is_privileged' => false,
			'user' => 'SomeUser',
			'clientIp' => '1.2.3.4',
			'ua' => 'SomeBrowser/1.0',
			'geocookie' => 'San Francisco',
		];
		$privilegedSecurityLogContext = [ 'user_is_privileged' => true ] + $normalSecurityLogContext;

		$expectedContextBase = [
			'guessed' => false,
			'securityLevel' => '-',
			'failReasons' => [],
		];
		$expectedContextGood = $expectedContextBase + [
				'successful' => true,
				'msgname' => '-',
				'messagestr' => '',
			];
		$expectedContextFailed = $expectedContextBase + [
				'successful' => false,
				'msgname' => 'wrongpass',
				'messagestr' => '[wrongpass]',
			];

		// phpcs:disable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
		yield 'successful login, normal user' => [
			$pass, /*guessed:*/false, $normalSecurityLogContext, 'goodpass',
			'Login succeeded for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'normal' ] + $normalSecurityLogContext + $expectedContextGood,
		];
		yield 'successful login, privileged user' => [
			$pass, /*guessed:*/false, $privilegedSecurityLogContext, 'goodpass-priv',
			'Login succeeded for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'elevated' ] + $privilegedSecurityLogContext + $expectedContextGood,
		];
		yield 'failed login, normal user' => [
			$fail, /*guessed:*/true, $normalSecurityLogContext, 'badpass',
			'Login failed for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'normal', 'guessed' => true ] + $normalSecurityLogContext + $expectedContextFailed,
		];
		yield 'failed login, privileged user' => [
			$fail, /*guessed:*/true, $privilegedSecurityLogContext, 'badpass-priv',
			'Login failed for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'elevated', 'guessed' => true ] + $privilegedSecurityLogContext + $expectedContextFailed,
		];
		yield 'failed during secondary provider check' => [
			$fail, /*guessed:*/false, $normalSecurityLogContext, 'badpass',
			'Login failed for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'normal' ] + $normalSecurityLogContext + $expectedContextFailed,
		];
		yield 'successful reauthentication' => [
			$pass, /*guessed:*/false, $privilegedSecurityLogContext, 'goodpass-priv',
			'Reauthentication succeeded for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'elevated', 'securityLevel' => 'admin' ] + $privilegedSecurityLogContext
				+ $expectedContextGood,
			/*extraData:*/[ 'securityLevel' => 'admin' ],
		];
		yield 'failed reauthentication' => [
			$fail, /*guessed:*/true, $privilegedSecurityLogContext, 'badpass-priv',
			'Reauthentication failed for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'elevated', 'guessed' => true, 'securityLevel' => 'admin' ]
				+ $privilegedSecurityLogContext + $expectedContextFailed,
			/*extraData:*/[ 'securityLevel' => 'admin' ],
		];
		yield 'failed with extra reasons' => [
			$failWithExtraReasons, /*guessed:*/true, $privilegedSecurityLogContext, 'badpass-priv',
			'Login failed for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}',
			[ 'priv' => 'elevated', 'guessed' => true, 'failReasons' => [ 'reason1', 'reason2' ] ]
				+ $privilegedSecurityLogContext + $expectedContextFailed,
		];
		// phpcs:enable MediaWiki.WhiteSpace.SpaceBeforeSingleLineComment.NewLineComment
	}

	/**
	 * @dataProvider provideOnAuthManagerLoginAuthenticateAudit
	 */
	public function testOnAuthManagerLoginAuthenticateAudit(
		AuthenticationResponse $response,
		bool $userNameWasGuessed,
		array $securityLogContext,
		string $expectedLogChannel,
		?string $expectedLogMessage,
		?array $expectedLogContext = null,
		?array $extraData = [],
	): void {
		$logger = new TestLogger( collect: true, collectContext: true );
		$userIdentity = UserIdentityValue::newRegistered( 1, 'SomeUser' );
		$user = $userNameWasGuessed ? null : $this->createNoOpMock( User::class );

		if ( $response->message ) {
			$message = $this->createNoOpMock( Message::class, [ 'getKey', 'text', 'inLanguage' ] );
			$message->method( 'getKey' )->willReturn( $response->message->getKey() );
			$message->method( 'text' )->willReturn( '[' . $response->message->getKey() . ']' );
			$message->method( 'inLanguage' )->willReturn( $message );
			$response = clone $response;
			$response->message = $message;
		}

		$handler = $this->getHandler(
			userIdentityByName: [ 'SomeUser' => $userIdentity ],
			loggers: [ $expectedLogChannel => $logger ],
			securityLogContext: $securityLogContext,
		);
		$handler->onAuthManagerLoginAuthenticateAudit( $response, $user, 'SomeUser', $extraData );
		if ( $expectedLogMessage ) {
			$this->assertCount( 1, $logger->getBuffer() );
			[ $level, $logMessage, $logContext ] = $logger->getBuffer()[0];
			$this->assertSame( LogLevel::INFO, $level );
			$this->assertSame( $expectedLogMessage, $logMessage );
			$this->assertArrayEquals( $expectedLogContext, $logContext, named: true );
		} else {
			$this->assertSame( [], $logger->getBuffer() );
		}
	}

	public static function provideOnChangeAuthenticationDataAudit() {
		$req = new PasswordAuthenticationRequest();
		$req->username = 'SomeUser';
		$wrongReq = TemporaryPasswordAuthenticationRequest::newInvalid();
		$wrongReq->username = 'SomeUser';

		$goodStatus = StatusValue::newGood();
		$failedStatus = StatusValue::newFatal( 'invalid-password' );

		$normalSecurityLogContext = [
			'user_is_privileged' => false,
			'user' => 'SomeUser',
			'clientIp' => '1.2.3.4',
			'ua' => 'SomeBrowser/1.0',
			'geocookie' => 'San Francisco',
		];
		$privilegedSecurityLogContext = [ 'user_is_privileged' => true ] + $normalSecurityLogContext;

		yield 'normal user, successful password change' => [
			$req,
			$goodStatus,
			$normalSecurityLogContext,
			'badpass',
			'Password change in prefs for {priv} {user}: {status} - {clientIp} - {ua} - {geocookie}',
			$normalSecurityLogContext + [
				'priv' => 'normal',
				'status' => 'ok',
			],
		];
		yield 'normal user, failed password change' => [
			$req,
			$failedStatus,
			$normalSecurityLogContext,
			'badpass',
			'Password change in prefs for {priv} {user}: {status} - {clientIp} - {ua} - {geocookie}',
			$normalSecurityLogContext + [
				'priv' => 'normal',
				'status' => 'invalid-password',
			],
		];
		yield 'privileged user, successful password change' => [
			$req,
			$goodStatus,
			$privilegedSecurityLogContext,
			'badpass-priv',
			'Password change in prefs for {priv} {user}: {status} - {clientIp} - {ua} - {geocookie}',
			$privilegedSecurityLogContext + [
				'priv' => 'elevated',
				'status' => 'ok',
			],
		];
		yield 'privileged user, failed password change' => [
			$req,
			$failedStatus,
			$privilegedSecurityLogContext,
			'badpass-priv',
			'Password change in prefs for {priv} {user}: {status} - {clientIp} - {ua} - {geocookie}',
			$privilegedSecurityLogContext + [
				'priv' => 'elevated',
				'status' => 'invalid-password',
			],
		];
		yield 'non-password change' => [
			$wrongReq,
			$goodStatus,
			[ 'user_is_privileged' => true ] + $normalSecurityLogContext,
			'badpass',
			null,
		];
	}

	/**
	 * @dataProvider provideOnChangeAuthenticationDataAudit
	 */
	public function testOnChangeAuthenticationDataAudit(
		AuthenticationRequest $req,
		StatusValue $status,
		array $securityLogContext,
		string $expectedLogChannel,
		?string $expectedLogMessage,
		?array $expectedLogContext = null,
	): void {
		$logger = new TestLogger( collect: true, collectContext: true );
		$userIdentity = UserIdentityValue::newRegistered( 1, 'SomeUser' );

		$handler = $this->getHandler(
			userIdentityByName: [ 'SomeUser' => $userIdentity ],
			loggers: [ $expectedLogChannel => $logger ],
			securityLogContext: $securityLogContext,
		);

		$handler->onChangeAuthenticationDataAudit( $req, $status );
		if ( !$expectedLogMessage ) {
			$this->assertSame( [], $logger->getBuffer() );
		} else {
			$this->assertCount( 1, $logger->getBuffer() );
			[ $level, $logMessage, $logContext ] = $logger->getBuffer()[0];
			$this->assertSame( LogLevel::INFO, $level );
			$this->assertSame( $expectedLogMessage, $logMessage );
			$this->assertArrayEquals( $expectedLogContext, $logContext, named: true );
		}
	}

}
