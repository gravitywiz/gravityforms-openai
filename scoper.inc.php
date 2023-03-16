<?php
/**
 * PHP-Scoper configuration file.
 *
 * Based on https://github.com/google/site-kit-wp
 */

use Isolated\Symfony\Component\Finder\Finder;

$wp_classes   = json_decode( file_get_contents( dirname( __FILE__ ) . '/php-scoper/vendor/sniccowp/php-scoper-wordpress-excludes/generated/exclude-wordpress-classes.json' ), true );
$wp_functions = json_decode( file_get_contents( dirname( __FILE__ ) . '/php-scoper/vendor/sniccowp/php-scoper-wordpress-excludes/generated/exclude-wordpress-functions.json' ), true );
$wp_constants = json_decode( file_get_contents( dirname( __FILE__ ) . '/php-scoper/vendor/sniccowp/php-scoper-wordpress-excludes/generated/exclude-wordpress-constants.json' ), true );

return array(
	'prefix'            => 'GWiz_GF_OpenAI\\Dependencies',
	'finders'           => array(
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
	'exclude-classes'   => $wp_classes,
	'exclude-functions' => $wp_functions,
	'exclude-constants' => $wp_constants,
);
