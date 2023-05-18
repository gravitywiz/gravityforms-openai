<?php
/**
 * Plugin Name: Gravity Forms OpenAI
 * Description: Pair the magic of OpenAI's robust models with Gravity Forms' flexibility.
 * Plugin URI: https://gravitywiz.com/gravity-forms-openai/
 * Version: 1.0-beta-1.3
 * Author: Gravity Wiz
 * Author URI: https://gravitywiz.com/
 * License: GPL2
 * Text Domain: gravityforms-openai
 * Domain Path: /languages
 *
 * @package gravityforms-openai
 * @copyright Copyright (c) 2022, Gravity Wiz, LLC
 * @author Gravity Wiz <support@gravitywiz.com>
 * @license GPLv2
 * @link https://github.com/gravitywiz/gravityforms-openai
 */

define( 'GWIZ_GF_OPENAI_VERSION', '1.0-beta-1.3' );

defined( 'ABSPATH' ) || die();

add_action( 'gform_loaded', function() {
	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}

	require plugin_dir_path( __FILE__ ) . 'class-gwiz-gf-openai.php';

	GFAddOn::register( 'GWiz_GF_OpenAI' );
}, 0 ); // Load before Gravity Flow

/*
 * Gravity Flow compatibility.
 */
add_action( 'gravityflow_loaded', function() {
	require_once( gwiz_gf_openai()->get_base_path() . '/includes/class-gravity-flow-step-feed-gwiz-gf-openai.php' );

	Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_GWiz_GF_OpenAI() );
} );

/**
 * Returns an instance of the GWiz_GF_OpenAI class
 *
 * @see    GWiz_GF_OpenAI::get_instance()
 *
 * @return GWiz_GF_OpenAI
 */
function gwiz_gf_openai() {
	return GWiz_GF_OpenAI::get_instance();
}
