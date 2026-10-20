<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\Integration\PhpVersion;

use MediaWiki\Context\RequestContext;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\PhpVersion\PhpVersionHookHandler
 * @group Database
 */
class PhpVersionHookHandlerTest extends ApiTestCase {

	private const SHORT_PHP_VERSION = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

	public function testSpecialVersion() {
		$specialPage = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Version' );
		$context = new RequestContext();
		$context->setTitle( SpecialPage::getTitleFor( 'Version' ) );
		$specialPage->setContext( $context );
		$specialPage->execute( '' );
		$html = $context->getOutput()->getHTML();

		$this->assertStringContainsString( self::SHORT_PHP_VERSION . ' (' . PHP_SAPI . ')', $html );
		$this->assertStringNotContainsString( PHP_VERSION, $html );
	}

	public function testSiteinfo() {
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query', 'meta' => 'siteinfo', 'siprop' => 'general|sbom' ],
			null,
			false,
			$this->getTestUser()->getAuthority()
		);

		$this->assertSame( self::SHORT_PHP_VERSION, $result['query']['general']['phpversion'] );
		$this->assertStringNotContainsString( PHP_VERSION, json_encode( $result['query']['sbom'] ) );
		$this->assertStringContainsString(
			'"version":"' . self::SHORT_PHP_VERSION . '"',
			json_encode( $result['query']['sbom'] )
		);
	}
}
