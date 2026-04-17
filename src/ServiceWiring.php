<?php

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\FlaggedRevsReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'WikimediaCustomizations.Config' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'WikimediaCustomizations' );
	},

	'WikimediaCustomizations.BadEmailDomainChecker' => static function (
		MediaWikiServices $services
	): BadEmailDomainChecker {
		return new BadEmailDomainChecker(
			$services->get( 'WikimediaCustomizations.Config' ),
			$services->getLocalServerObjectCache(),
		);
	},

	'WikimediaCustomizations.AttributionDataBuilder' => static function (
		MediaWikiServices $services
	): AttributionDataBuilder {
		global $wgConf;
		$parserOutputAccess = $services->getParserOutputAccess();
		$referenceCountProvider = new ParsoidReferenceCountProvider( $parserOutputAccess );
		$pageViewService = null;

		if ( $services->getExtensionRegistry()->isLoaded( 'FlaggedRevs' ) ) {
			$referenceCountProvider = new FlaggedRevsReferenceCountProvider(
				$services->get( 'FlaggedRevsParserCacheFactory' ),
				$referenceCountProvider
			);
		}
		if ( $services->getExtensionRegistry()->isLoaded( 'PageViewInfo' ) ) {
			$pageViewService = $services->get( 'PageViewService' );
		}

		return new AttributionDataBuilder(
			$services->get( 'MainConfig' ),
			$services->get( 'UrlUtils' ),
			$services->get( 'RepoGroup' ),
			$services->get( 'Tracer' ),
			$wgConf,
			LoggerFactory::getInstance( 'Attribution' ),
			$services->get( 'StatsFactory' ),
			$referenceCountProvider,
			$pageViewService
		);
	},

];
