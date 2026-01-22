<?php

namespace MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain;

use MediaWiki\Config\Config;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\BagOStuff;

class BadEmailDomainChecker {

	public function __construct(
		private Config $config,
		private BagOStuff $cache
	) {
	}

	/**
	 * Checks email domains against a configured deny-list.
	 * @param string $domain The domain part of an email address (e.g. `gmail.com`).
	 * @return bool
	 */
	public function isBad( string $domain ): bool {
		return array_key_exists( $domain, $this->getBlockedHosts() );
	}

	/**
	 * Convenience function to get the domain part of an email address.
	 * @param string $email
	 * @return string|false Domain, or false if the address couldn't be parsed.
	 */
	public function getDomain( string $email ): string|false {
		$emailBits = parse_url( 'mailto://' . $email );
		if ( !$emailBits || !isset( $emailBits['host'] ) || !$emailBits['host'] ) {
			return false;
		}
		return $emailBits['host'];
	}

	/**
	 * @return array [ domain => true ]
	 */
	private function getBlockedHosts(): array {
		$key = $this->cache->makeGlobalKey( 'WikimediaCustomizations', 'BadEmailDomains' );
		return $this->cache->getWithSetCallback( $key, ExpirationAwareness::TTL_HOUR, function () {
			$file = $this->config->get( 'WMCBadEmailDomainsFile' );
			if ( $file === false ) {
				return [];
			}
			$text = file_get_contents( $file );
			if ( $text === false ) {
				LoggerFactory::getInstance( 'security' )->error( 'Bad email domain file {file} not found', [
					'file' => $file,
				] );
				return [];
			}
			$domains = array_filter( explode( PHP_EOL, $text ), static fn ( $l ) => strlen( $l ) > 0 );
			return array_fill_keys( $domains, true );
		} );
	}

}
