'use strict';

const summaryHtml = require( '../../../modules/ShareCard/ext.wikimediaCustomizations.shareCard/utils/summaryHtml.js' );

const MATH_SRC = 'https://wikimedia.org/api/rest_v1/media/math/render/svg/a7464809a40f9e486de3a454745f572fbf8bb256';

describe( 'summaryHtml', () => {
	it( 'keeps bold, italic, subscripts and superscripts without attributes', () => {
		expect( summaryHtml(
			'<p><b class="x">Water</b> is <span class="chemf">H<sub class="y">2</sub>O</span>, <i>aqua</i>, x<sup>2</sup>.</p>'
		) ).toBe( '<b>Water</b> is H<sub>2</sub>O, <i>aqua</i>, x<sup>2</sup>.' );
	} );

	it( 'keeps Math images inline, sized in ex', () => {
		const html = summaryHtml(
			'<p>The equality <span class="mwe-math-element mwe-math-element-block">' +
			`<img src="${ MATH_SRC }" class="mwe-math-fallback-image-display" aria-hidden="true" ` +
			'style="vertical-align:-0.505ex;width:11.089ex;height:2.843ex" /></span> holds.</p>'
		);
		const image = new DOMParser().parseFromString( html, 'text/html' ).querySelector( 'img' );
		expect( image.getAttribute( 'src' ) ).toBe( MATH_SRC );
		expect( image.className ).toBe( 'ext-wc-share-card__math' );
		expect( image.getAttribute( 'crossorigin' ) ).toBe( 'anonymous' );
		expect( image.style.width ).toBe( '11.089ex' );
		expect( image.style.height ).toBe( '2.843ex' );
		expect( image.style.verticalAlign ).toBe( '-0.505ex' );
		expect( image.hasAttribute( 'aria-hidden' ) ).toBe( false );
	} );

	it( 'drops images that are not Math formulas', () => {
		expect( summaryHtml(
			'<p>A <img src="https://upload.wikimedia.org/x.png"> and ' +
			'<img src="javascript:alert(1)//media/math/render/svg/ab"> and ' +
			'<img src="http://wikimedia.org/api/rest_v1/media/math/render/svg/ab"> b</p>'
		) ).toBe( 'A and and b' );
	} );

	it( 'drops attributes and elements that could run script', () => {
		const html = summaryHtml(
			'<p onclick="x()"><b onmouseover="x()">A</b><script>x()</script>' +
			'<a href="javascript:x()">link</a></p>'
		);
		expect( html ).not.toMatch( /on\w+=|<script|<a|javascript/ );
		expect( html ).toBe( '<b>A</b>link' );
	} );

	it( 'removes word joiners around inline formulas', () => {
		expect( summaryHtml(
			'<p>for <span class="nowrap">⁠<span class="mwe-math-element">' +
			`<img src="${ MATH_SRC }" style="height:1.676ex"></span>⁠</span> and more</p>`
		) ).not.toContain( '⁠' );
	} );

	it( 'runs a list on, with semicolons between items', () => {
		expect( summaryHtml(
			'<p><b>Mercury</b> most commonly refers to:</p><ul>' +
			'<li>Mercury (planet), the closest planet to the Sun</li>\n' +
			'<li>Mercury (element), a chemical element</li>\n' +
			'<li>Mercury (mythology), a Roman deity</li></ul>'
		) ).toBe(
			'<b>Mercury</b> most commonly refers to: Mercury (planet), the closest planet to the Sun; ' +
			'Mercury (element), a chemical element; Mercury (mythology), a Roman deity.'
		);
	} );

	it( 'joins list items that end in punctuation or a conjunction with a space', () => {
		expect( summaryHtml(
			'<p>where</p><ul><li>e is a number,</li><li>i is a unit, and</li><li>π is pi.</li></ul>'
		) ).toBe( 'where e is a number, i is a unit, and π is pi.' );
	} );

	it( 'keeps the formatting of display titles', () => {
		expect( summaryHtml(
			'<span lang="zh-Hant-TW" dir="ltr"><span class="mw-page-title-main">長城</span></span>'
		) ).toBe( '長城' );
		expect( summaryHtml( '<i>Star Wars</i>' ) ).toBe( '<i>Star Wars</i>' );
		expect( summaryHtml( 'Vitamin B<sub>12</sub>' ) ).toBe( 'Vitamin B<sub>12</sub>' );
	} );
} );
