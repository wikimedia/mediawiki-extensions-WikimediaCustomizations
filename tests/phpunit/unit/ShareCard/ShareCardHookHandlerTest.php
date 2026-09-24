<?php

namespace MediaWiki\Extension\WikimediaCustomizations\Tests\ShareCard;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaCustomizations\ShareCard\ShareCardHookHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaCustomizations\ShareCard\ShareCardHookHandler
 */
class ShareCardHookHandlerTest extends MediaWikiUnitTestCase {

	public static function provideOnBeforePageDisplay(): array {
		$page = [
			'enabled' => true,
			'skin' => 'minerva',
			'coreSkins' => [ 'minerva' ],
			'contentPage' => true,
			'exists' => true,
			'action' => 'view',
		];
		return [
			'core link shown in Minerva' => [ $page, true ],
			'card disabled' => [ [ 'enabled' => false ] + $page, false ],
			'another skin' => [ [ 'skin' => 'vector-2022' ] + $page, false ],
			'core link off in Minerva' => [ [ 'coreSkins' => [ 'vector-2022' ] ] + $page, false ],
			'not a content page' => [ [ 'contentPage' => false ] + $page, false ],
			'page does not exist' => [ [ 'exists' => false ] + $page, false ],
			'not the view action' => [ [ 'action' => 'history' ] + $page, false ],
		];
	}

	/**
	 * @dataProvider provideOnBeforePageDisplay
	 */
	public function testOnBeforePageDisplay( array $page, bool $expected ): void {
		$title = $this->createMock( Title::class );
		$title->method( 'isContentPage' )->willReturn( $page['contentPage'] );
		$title->method( 'exists' )->willReturn( $page['exists'] );

		$skin = $this->createMock( Skin::class );
		$skin->method( 'getTitle' )->willReturn( $title );
		$skin->method( 'getSkinName' )->willReturn( $page['skin'] );
		$skin->method( 'getActionName' )->willReturn( $page['action'] );

		$out = $this->createMock( OutputPage::class );
		$out->expects( $expected ? $this->once() : $this->never() )
			->method( 'addModules' )
			->with( 'ext.wikimediaCustomizations.shareCard.init' );

		$handler = new ShareCardHookHandler(
			new HashConfig( [ 'WMCShareCardEnabled' => $page['enabled'] ] ),
			new HashConfig( [ MainConfigNames::PageShareSkinsEnabled => $page['coreSkins'] ] )
		);
		$handler->onBeforePageDisplay( $out, $skin );
	}
}
