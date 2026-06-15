<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\NoReferrerLinks;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaCustomizations\NoReferrerLinks\NoReferrerLinksHookHandler;
use MediaWiki\Title\TitleValue;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\NoReferrerLinks\NoReferrerLinksHookHandler
 */
class NoReferrerLinksHookHandlerTest extends MediaWikiUnitTestCase {

	private function getHandler( array $domains ): NoReferrerLinksHookHandler {
		return new NoReferrerLinksHookHandler(
			new HashConfig( [ 'WMCNoReferrerDomains' => $domains ] ),
			new UrlUtils( [ UrlUtils::SERVER => 'https://wiki.example' ] )
		);
	}

	public static function provideRel(): iterable {
		yield 'matching domain, no prior rel' => [
			[ 'example.org' ], 'https://example.org/abc', null, 'noreferrer noopener',
		];
		yield 'matching domain keeps existing rel' => [
			[ 'example.org' ], 'https://example.org/abc', 'nofollow', 'nofollow noreferrer noopener',
		];
		yield 'subdomain matches' => [
			[ 'example.org' ], 'https://www.example.org/abc', null, 'noreferrer noopener',
		];
		yield 'protocol-relative URL matches' => [
			[ 'example.org' ], '//example.org/abc', null, 'noreferrer noopener',
		];
		yield 'second domain in list matches' => [
			[ 'example.org', 'example.net' ], 'https://example.net/xyz', null, 'noreferrer noopener',
		];
		yield 'uppercase host matches case-insensitively' => [
			[ 'example.org' ], 'https://EXAMPLE.ORG/abc', null, 'noreferrer noopener',
		];
		yield 'uppercase domain in config matches' => [
			[ 'EXAMPLE.ORG' ], 'https://example.org/abc', null, 'noreferrer noopener',
		];
		yield 'non-matching domain untouched' => [
			[ 'example.org' ], 'https://example.com/abc', 'nofollow', 'nofollow',
		];
		yield 'non-matching domain with no rel stays unset' => [
			[ 'example.org' ], 'https://example.com/abc', null, null,
		];
		yield 'empty config is a no-op' => [
			[], 'https://example.org/abc', 'nofollow', 'nofollow',
		];
		yield 'existing rel tokens are not duplicated' => [
			[ 'example.org' ], 'https://example.org/abc', 'noreferrer noopener', 'noreferrer noopener',
		];
	}

	/**
	 * @dataProvider provideRel
	 */
	public function testOnLinkerMakeExternalLinkWithContext(
		array $domains, string $url, ?string $rel, ?string $expectedRel
	): void {
		$attribs = [];
		if ( $rel !== null ) {
			$attribs['rel'] = $rel;
		}
		$text = 'link';

		$this->getHandler( $domains )->onLinkerMakeExternalLinkWithContext(
			$url, $text, $attribs, '', new TitleValue( NS_MAIN, 'Test' )
		);

		$this->assertSame( $expectedRel, $attribs['rel'] ?? null );
	}

	public function testBlockedLinkIsIgnored(): void {
		$attribs = [ 'rel' => 'nofollow' ];
		$text = 'link';
		$url = null;

		$this->getHandler( [ 'example.org' ] )->onLinkerMakeExternalLinkWithContext(
			$url, $text, $attribs, '', new TitleValue( NS_MAIN, 'Test' )
		);

		$this->assertSame( 'nofollow', $attribs['rel'] );
	}
}
