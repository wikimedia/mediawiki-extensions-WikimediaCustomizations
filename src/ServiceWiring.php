<?php

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\AttributionDataBuilder;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\DataGatewayContributorCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\Contributors\NullContributorCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\FlaggedRevsReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\Attribution\ParsoidReferenceCountProvider;
use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWiki\Extension\WikimediaCustomizations\PageTrending\PageviewTrendingRelativeStore;
use MediaWiki\Extension\WikimediaCustomizations\PrivilegedGroups\PrivilegedGroups;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
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

	'WikimediaCustomizations.PrivilegedGroups' => static function (
		MediaWikiServices $services
	): PrivilegedGroups {
		return new PrivilegedGroups(
			$services->get( 'WikimediaCustomizations.Config' ),
			$services->getExtensionRegistry(),
			$services->getUserGroupManager(),
		);
	},

	'WikimediaCustomizations.AttributionDataBuilder' => static function (
		MediaWikiServices $services
	): AttributionDataBuilder {
		global $wgConf;
		$statsFactory = $services->getStatsFactory()->withComponent( 'Attribution' );
		$logger = LoggerFactory::getInstance( 'Attribution' );
		$parserOutputAccess = $services->getParserOutputAccess();
		$referenceCountProvider = new ParsoidReferenceCountProvider( $parserOutputAccess );
		$pageViewService = null;
		$mainConfig = $services->get( 'MainConfig' );
		$wmcConfig = $services->get( 'WikimediaCustomizations.Config' );

		if ( $services->getExtensionRegistry()->isLoaded( 'FlaggedRevs' ) ) {
			$referenceCountProvider = new FlaggedRevsReferenceCountProvider(
				$services->get( 'FlaggedRevsParserCacheFactory' ),
				$referenceCountProvider
			);
		}
		$trendingRelativeStore = $services->get( 'WikimediaCustomizations.PageviewTrendingRelativeStore' );

		if ( $services->getExtensionRegistry()->isLoaded( 'PageViewInfo' ) ) {
			$pageViewService = $services->get( 'PageViewService' );
		}

		$endpointTemplate = $wmcConfig->get( 'WMCContributorCountsEndpoint' );

		$contributorCountProvider = $endpointTemplate
			? new DataGatewayContributorCountProvider(
				$services->getWANObjectCache(),
				$services->getHttpRequestFactory(),
				$statsFactory,
				$logger,
				$mainConfig->get( MainConfigNames::DBname ),
				(string)$endpointTemplate
			)
			: new NullContributorCountProvider();

		return new AttributionDataBuilder(
			$mainConfig,
			$services->get( 'UrlUtils' ),
			$services->get( 'RepoGroup' ),
			$services->get( 'Tracer' ),
			$wgConf,
			$logger,
			$statsFactory,
			$trendingRelativeStore,
			$referenceCountProvider,
			$contributorCountProvider,
			$services->getLanguageNameUtils(),
			$services->getSpecialPageFactory(),
			$pageViewService
		);
	},

	'WikimediaCustomizations.PageviewTrendingRelativeStore' => static function (
		MediaWikiServices $services
	): PageviewTrendingRelativeStore {
		return new PageviewTrendingRelativeStore(
			$services->getMainObjectStash(),
			$services->getWANObjectCache()
		);
	},

];
