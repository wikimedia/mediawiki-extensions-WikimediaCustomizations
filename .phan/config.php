<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$dirs = [
	'stubs',
	'../../extensions/cldr',
	'../../extensions/EmailAuth',
	'../../extensions/IPReputation',
	'../../extensions/LoginNotify',
	'../../extensions/OATHAuth',
	'../../extensions/WikimediaEvents',
	'../../extensions/PageViewInfo',
];

$cfg['directory_list'] = array_merge( $cfg['directory_list'], $dirs );
$cfg['exclude_analysis_directory_list'] = array_merge( $cfg['exclude_analysis_directory_list'], $dirs );

return $cfg;
