<?php
/**
 * Plugin Name: Gravity Forms OpenAI
 * Description: Pair the magic of OpenAI's robust models with Gravity Forms' flexibility.
 * Plugin URI: https://gravitywiz.com/gravity-forms-openai/
 * Version: 1.0-alpha-1
 * Author: Gravity Wiz
 * Author URI: https://gravitywiz.com/
 * License: GPL2
 * Perk: True
 * Text Domain: gravityforms-openai
 * Domain Path: /languages
 *
 * @package gravityforms-openai
 * @copyright Copyright (c) 2022, Gravity Wiz, LLC
 * @author Gravity Wiz <support@gravitywiz.com>
 * @license GPLv2
 * @link https://github.com/gravitywiz/gravityforms-openai
 */

define( 'GWIZ_GF_OPENAI_VERSION', '1.0-alpha-1' );

defined( 'ABSPATH' ) || die();

add_action( 'gform_loaded', function() {
	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}

	require plugin_dir_path( __FILE__ ) . 'class-gwiz-gf-openai.php';

	GFAddOn::register( 'GWiz_GF_OpenAI' );
}, 5 );

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
