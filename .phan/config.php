<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$dirs = [
	'../../extensions/AntiSpoof',
	'../../extensions/CentralAuth',
	'../../extensions/cldr',
	'../../extensions/EmailAuth',
	'../../extensions/FlaggedRevs',
	'../../extensions/IPReputation',
	'../../extensions/LoginNotify',
	'../../extensions/OATHAuth',
	'../../extensions/WikimediaEvents',
	'../../extensions/PageViewInfo',
];

$cfg['directory_list'] = array_merge( $cfg['directory_list'], $dirs );
$cfg['exclude_analysis_directory_list'] = array_merge( $cfg['exclude_analysis_directory_list'], $dirs );

$cfg['warn_about_undocumented_exceptions_thrown_by_invoked_functions'] = true;

$cfg['plugins'] = array_merge( $cfg['plugins'], [
	'PHPDocRedundantPlugin',
] );

return $cfg;
