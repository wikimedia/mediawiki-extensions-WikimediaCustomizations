<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\ShareCard;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaCustomizations\ShareCard\ShareCardHookHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\ShareCard\ShareCardHookHandler
 */
class ShareCardHookHandlerTest extends MediaWikiUnitTestCase {

	private const PAGE = [
		'enabled' => true,
		'skin' => 'minerva',
		'coreSkins' => [ 'minerva' ],
		'contentPage' => true,
		'exists' => true,
		'action' => 'view',
		'urlFlag' => false,
	];

	public static function provideOnBeforePageDisplay(): array {
		$page = self::PAGE;
		$flagOnly = [ 'enabled' => false, 'coreSkins' => [], 'urlFlag' => true ] + $page;
		return [
			'core link shown in Minerva' => [ $page, true ],
			'card disabled' => [ [ 'enabled' => false ] + $page, false ],
			'another skin' => [ [ 'skin' => 'vector-2022' ] + $page, false ],
			'core link off in Minerva' => [ [ 'coreSkins' => [ 'vector-2022' ] ] + $page, false ],
			'not a content page' => [ [ 'contentPage' => false ] + $page, false ],
			'page does not exist' => [ [ 'exists' => false ] + $page, false ],
			'not the view action' => [ [ 'action' => 'history' ] + $page, false ],
			'URL flag alone' => [ $flagOnly, true ],
			'URL flag in another skin' => [ [ 'skin' => 'vector-2022' ] + $flagOnly, false ],
			'URL flag on a page that does not exist' => [ [ 'exists' => false ] + $flagOnly, false ],
			'URL flag on another action' => [ [ 'action' => 'history' ] + $flagOnly, false ],
		];
	}

	/**
	 * @dataProvider provideOnBeforePageDisplay
	 */
	public function testOnBeforePageDisplay( array $page, bool $expected ): void {
		$out = $this->createMock( OutputPage::class );
		$out->expects( $expected ? $this->once() : $this->never() )
			->method( 'addModules' )
			->with( 'ext.wikimediaCustomizations.shareCard.init' );

		$this->makeHandler( $page )->onBeforePageDisplay( $out, $this->makeSkin( $page ) );
	}

	public function testAddsShareLinkForUrlFlag(): void {
		$page = [ 'enabled' => false, 'coreSkins' => [], 'urlFlag' => true ] + self::PAGE;
		$links = [ 'views' => [] ];

		$this->makeHandler( $page )
			->onSkinTemplateNavigation__Universal( $this->makeSkin( $page ), $links );

		$this->assertSame(
			[
				'class' => 'mw-page-share',
				'text' => 'Share',
				'icon' => 'share',
				'href' => '/wiki/Test',
				'data' => [ 'mw-interface' => '1' ],
			],
			$links['views']['share']
		);
	}

	public function testKeepsCoreShareLink(): void {
		$coreLink = [ 'class' => 'mw-page-share', 'text' => 'Core' ];
		$links = [ 'views' => [ 'share' => $coreLink ] ];

		$this->makeHandler( self::PAGE )
			->onSkinTemplateNavigation__Universal( $this->makeSkin( self::PAGE ), $links );

		$this->assertSame( $coreLink, $links['views']['share'] );
	}

	public function testNoShareLinkWhenDisabled(): void {
		$page = [ 'enabled' => false, 'coreSkins' => [] ] + self::PAGE;
		$links = [ 'views' => [] ];

		$this->makeHandler( $page )
			->onSkinTemplateNavigation__Universal( $this->makeSkin( $page ), $links );

		$this->assertArrayNotHasKey( 'share', $links['views'] );
	}

	private function makeHandler( array $page ): ShareCardHookHandler {
		return new ShareCardHookHandler(
			new HashConfig( [ 'WMCShareCardEnabled' => $page['enabled'] ] ),
			new HashConfig( [ MainConfigNames::PageShareSkinsEnabled => $page['coreSkins'] ] )
		);
	}

	private function makeSkin( array $page ): SkinTemplate {
		$title = $this->createMock( Title::class );
		$title->method( 'isContentPage' )->willReturn( $page['contentPage'] );
		$title->method( 'exists' )->willReturn( $page['exists'] );
		$title->method( 'getLocalURL' )->willReturn( '/wiki/Test' );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getFuzzyBool' )->willReturnCallback(
			static function ( $name ) use ( $page ) {
				if ( $name === 'sharecard' ) {
					return $page['urlFlag'];
				}
				return false;
			}
		);

		$message = $this->createMock( Message::class );
		$message->method( 'text' )->willReturn( 'Share' );

		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getTitle' )->willReturn( $title );
		$skin->method( 'getSkinName' )->willReturn( $page['skin'] );
		$skin->method( 'getActionName' )->willReturn( $page['action'] );
		$skin->method( 'getRequest' )->willReturn( $request );
		$skin->method( 'msg' )->willReturn( $message );
		return $skin;
	}
}
