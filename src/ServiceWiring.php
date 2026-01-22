<?php

use MediaWiki\Extension\WikimediaCustomizations\BadEmailDomain\BadEmailDomainChecker;
use MediaWiki\MediaWikiServices;

return [
	'WikimediaCustomizations.Config' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'WikimediaCustomizations' );
	},

	'WikimediaCustomizations.BadEmailDomainChecker' => static function ( MediaWikiServices $services ) {
		return new BadEmailDomainChecker(
			$services->get( 'WikimediaCustomizations.Config' ),
			$services->getLocalServerObjectCache(),
		);
	},
];
