<?php

namespace MediaWiki\Extension\WikimediaCustomizations\PhpVersion;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\ApiResult;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\Specials\Hook\SoftwareInfoHook;

/**
 * Shows only the major and minor PHP version on Special:Version and in
 * action=query&meta=siteinfo, and in the X-Powered-By response header.
 *
 * This is a temporary measure.
 */
class PhpVersionHookHandler implements APIQueryAfterExecuteHook, SetupAfterCacheHook, SoftwareInfoHook {

	private const SITEINFO_PROPERTIES = [ 'general', 'sbom' ];

	/**
	 * @inheritDoc
	 */
	public function onSetupAfterCache() {
		// PHP adds this header when expose_php is on. Code cannot change expose_php.
		if ( !headers_sent() && ini_get( 'expose_php' ) ) {
			header( 'X-Powered-By: PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onSoftwareInfo( &$software ) {
		foreach ( $software as $name => $version ) {
			if ( is_string( $version ) ) {
				$software[$name] = $this->shortenPhpVersion( $version );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIQueryAfterExecute( $module ) {
		if ( !$module instanceof ApiQuerySiteinfo ) {
			return;
		}
		$result = $module->getResult();
		foreach ( self::SITEINFO_PROPERTIES as $property ) {
			$data = $result->getResultData( [ 'query', $property ] );
			if ( !is_array( $data ) ) {
				continue;
			}
			// Bundled PHP extensions in the SBOM report the PHP version too.
			// Some add a prefix, for example "mysqlnd".
			array_walk_recursive( $data, function ( &$value ) {
				if ( is_string( $value ) ) {
					$value = $this->shortenPhpVersion( $value );
				}
			} );
			// The new value is shorter, so the size limit check is not necessary.
			$result->addValue( 'query', $property, $data, ApiResult::OVERRIDE | ApiResult::NO_SIZE_CHECK );
		}
	}

	private function shortenPhpVersion( string $text ): string {
		return preg_replace(
			'/(?<![\d.])' . preg_quote( PHP_VERSION, '/' ) . '(?![\d.])/',
			PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			$text
		);
	}
}
