<?php
defined( 'ABSPATH' ) || die();

class Gravity_Flow_Step_Feed_GWiz_GF_OpenAI extends Gravity_Flow_Step_Feed_Add_On {
	/**
	 * The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'gwiz_gf_openai';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @var string
	 */
	protected $_class_name = 'GWiz_GF_OpenAI';

	/**
	 * Returns the step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'OpenAI';
	}

	/**
	 * Returns the step icon.
	 */
	public function get_icon_url() {
		return gwiz_gf_openai()->get_base_url() . '/icon.svg';
	}

	/**
	 * Returns the feed name.
	 *
	 * @param array $feed The Emma feed properties.
	 *
	 * @return string
	 */
	public function get_feed_label( $feed ) {
		$label = $feed['meta']['feed_name'];

		return $label;
	}
}
