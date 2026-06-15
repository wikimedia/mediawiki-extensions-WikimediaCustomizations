<?php

namespace MediaWiki\Extension\WikimediaCustomizations\NoReferrerLinks;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Hook\LinkerMakeExternalLinkWithContextHook;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\Parsoid\Core\LinkTarget;

/**
 * Adds rel="noreferrer noopener" to external links pointing at configured
 * domains.
 *
 * Some sites serve different content or redirect based on the Referer header;
 * suppressing the referrer for those domains keeps the links usable. See
 * T429090.
 */
class NoReferrerLinksHookHandler implements LinkerMakeExternalLinkWithContextHook {

	public function __construct(
		private readonly Config $config,
		private readonly UrlUtils $urlUtils,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onLinkerMakeExternalLinkWithContext(
		?string &$url, string &$text, array &$attribs, string $linkType,
		LinkTarget $contextTitle
	): void {
		if ( $url === null ) {
			return;
		}
		$domains = $this->config->get( 'WMCNoReferrerDomains' );
		if ( !$domains ) {
			return;
		}
		// Hostnames are case-insensitive, but matchesDomainList compares the raw host.
		$domains = array_map( 'strtolower', $domains );
		if ( !$this->urlUtils->matchesDomainList( strtolower( $url ), $domains ) ) {
			return;
		}
		// Match core's rel normalization (LinkRenderer::makeExternalLink).
		$attribs['rel'] ??= [];
		Html::addClass( $attribs['rel'], 'noreferrer' );
		Html::addClass( $attribs['rel'], 'noopener' );
		$attribs['rel'] = Html::expandClassList( $attribs['rel'] );
	}
}
