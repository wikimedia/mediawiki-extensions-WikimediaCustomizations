<?php

namespace MediaWiki\Extension\WikimediaCustomizations\SecurityLogs;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\Hook\AuthManagerLoginAuthenticateAuditHook;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\GetSecurityLogContextHook;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Message\Message;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\Hook\ChangeAuthenticationDataAuditHook;
use MediaWiki\Status\Status;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Psr\Log\LoggerInterface;

/**
 * Various security related Logstash logging hooks:
 * - enrich security-related log records with various traffic-related info
 * - log information about logins, on four channels, split by success and account privileges:
 *   goodpass, badpass, goodpass-priv, badpass-priv
 * - log password changes for privileged accounts to badpass-priv
 */
class SecurityLogsHookHandler implements
	GetSecurityLogContextHook,
	AuthManagerLoginAuthenticateAuditHook,
	ChangeAuthenticationDataAuditHook
{

	public function __construct(
		private UserIdentityLookup $userIdentityLookup,
		private FormatterFactory $formatterFactory
	) {
	}

	/** @inheritDoc */
	public function onGetSecurityLogContext( array $info, array &$context ): void {
		/** @var WebRequest $request */
		$request = $info['request'];

		$headers = [
			// https://wikitech.wikimedia.org/wiki/CDN/Backend_api
			'x-trusted-request',
			'x-is-browser',
			'x-ua-contact',
			// https://gerrit.wikimedia.org/g/operations/puppet/+/production/modules/profile/files/cache/ja3n.lua
			'x-ja3n',
			// https://gerrit.wikimedia.org/g/operations/puppet/+/production/modules/profile/files/cache/ja4h.lua
			'x-ja4h',
		];

		foreach ( $headers as $header ) {
			$context[$header] = (string)$request->getHeader( $header );
		}

		// https://wikitech.wikimedia.org/wiki/X-Provenance
		$provenance = [];
		$provenanceString = $request->getHeader( 'X-Provenance' ) ?: '';
		foreach ( explode( ';', $provenanceString ) as $item ) {
			[ $label, $value ] = explode( '=', $item, 2 ) + [ 1 => '' ];
			if ( $label !== '' ) {
				$provenance[$label] = $value;
			}
		}

		$context += [
			'geocookie' => $request->getCookie( 'GeoIP', '' ),
			'x-provenance' => $provenance,
		];
	}

	/** @inheritDoc */
	public function onAuthManagerLoginAuthenticateAudit( $response, $user, $username, $extraData ) {
		$guessed = false;
		if ( !$user && $username ) {
			$user = $this->userIdentityLookup->getUserIdentityByName( $username );
			$guessed = true;
		}
		if ( !$user || !in_array( $response->status,
				[ AuthenticationResponse::PASS, AuthenticationResponse::FAIL ], true )
		) {
			return;
		}

		$context = $this->getSecurityLogContext( $user );
		$privileged = $context['user_is_privileged'];
		$successful = $response->status === AuthenticationResponse::PASS;
		$message = $response->message ? Message::newFromSpecifier( $response->message ) : null;

		$channel = $successful ? 'goodpass' : 'badpass';
		if ( $privileged ) {
			$channel .= '-priv';
		}
		$logger = $this->getLogger( $channel );
		$verb = $successful ? 'succeeded' : 'failed';

		$logger->info( "Login $verb for {priv} {user} from {clientIp} - {ua} - {geocookie}: {messagestr}", [
			'successful' => $successful,
			// Backwards compatibility
			'name' => $context['user'],
			// Backwards compatibility
			'clientip' => $context['clientIp'],
			'priv' => ( $privileged ? 'elevated' : 'normal' ),
			'guessed' => $guessed,
			'msgname' => $message?->getKey() ?? '-',
			'messagestr' => $message?->inLanguage( 'en' )?->text() ?? '',
		] + $context );
	}

	/** @inheritDoc */
	public function onChangeAuthenticationDataAudit( $req, $status ) {
		$user = $this->userIdentityLookup->getUserIdentityByName( $req->username );
		$status = Status::wrap( $status );
		if ( $req instanceof PasswordAuthenticationRequest ) {
			$context = $this->getSecurityLogContext( $user );
			$privileged = $context['user_is_privileged'];
			if ( $privileged ) {
				$logger = $this->getLogger( 'badpass' );
				$logger->info(
					'Password change in prefs for {priv} {user}: {status} - {clientIp} - {ua} - {geocookie}',
					[
						// Backwards compatibility
						'name' => $context['user'],
						// Backwards compatibility
						'clientip' => $context['clientIp'],
						'priv' => 'elevated',
						'status' => $status->isGood()
							? 'ok'
							: $this->getStatusFormatter()->getWikiText( $status, [ 'lang' => 'en' ] ),
					] + $context );
			}
		}
	}

	protected function getLogger( string $channel ): LoggerInterface {
		return LoggerFactory::getInstance( $channel );
	}

	protected function getSecurityLogContext( ?UserIdentity $user ): array {
		return RequestContext::getMain()->getRequest()->getSecurityLogContext( $user );
	}

	protected function getStatusFormatter(): StatusFormatter {
		return $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
	}

}
