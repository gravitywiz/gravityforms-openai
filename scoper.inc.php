<?php
/**
 * PHP-Scoper configuration file.
 *
 * Based on https://github.com/google/site-kit-wp
 */

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'                     => 'GWiz_GF_OpenAI\\Dependencies',
	'finders'                    => array(
		// General dependencies, except Google API services.
		Finder::create()
		      ->files()
		      ->ignoreVCS( true )
		      ->notName( '/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.(json|lock)/' )
		      ->exclude(
			      array(
				      'doc',
				      'test',
				      'test_old',
				      'tests',
				      'Tests',
				      'vendor-bin',
			      )
		      )
		      ->in( 'vendor' ),
	),
	'files-whitelist'            => array(),
	'whitelist'                  => array(),
	'whitelist-global-constants' => false,
	'whitelist-global-classes'   => false,
	'whitelist-global-functions' => false,
);
