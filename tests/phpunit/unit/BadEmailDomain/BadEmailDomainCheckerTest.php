<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\BadEmailDomain;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker
 */
class BadEmailDomainCheckerTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideGetDomain
	 */
	public function testGetDomain( string $email, string|false $expectedDomain ) {
		$checker = new BadEmailDomainChecker(
			new HashConfig( [] ),
			new HashBagOStuff(),
		);
		$this->assertSame( $expectedDomain, $checker->getDomain( $email ) );
	}

	public function provideGetDomain() {
		return [
			[ 'john.doe@gmail.com', 'gmail.com' ],
			[ 'foo@bar.boom', 'bar.boom' ],
			[ '', false ],
			[ 'a@b@c.d', 'c.d' ],
		];
	}

	/**
	 * @dataProvider provideIsBad
	 */
	public function testIsBad( string $email, bool $expected ) {
		$file = realpath( __DIR__ . '/../../data/BadEmailDomain/bad-domains.txt' );
		$checker = new BadEmailDomainChecker(
			new HashConfig( [
				'WMCBadEmailDomainsFile' => $file,
			] ),
			new HashBagOStuff(),
		);
		$this->assertSame( $expected, $checker->isBad( $email ) );
		// once more to test caching
		$this->assertSame( $expected, $checker->isBad( $email ) );
	}

	public function provideIsBad() {
		return [
			[ 'good.com', false ],
			[ 'evil.com', true ],
			[ 'shady.net', true ],
		];
	}

	public function testIsBad_noFile() {
		$checker = new BadEmailDomainChecker(
			new HashConfig( [
				'WMCBadEmailDomainsFile' => false,
			] ),
			new HashBagOStuff(),
		);
		$this->assertFalse( $checker->isBad( 'foo@bar.boom' ) );
	}

}
