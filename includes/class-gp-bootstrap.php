<?php
/**
 * @version 1.3.1
 */

namespace GWiz_GF_OpenAI;

// The reason for this class_exists is due to WP-CLI autoloading this file before the actual plugin.
if ( ! class_exists( 'GWiz_GF_OpenAI\GP_Bootstrap' ) ) {
	class GP_Bootstrap {

		public $_root_file = null;
		public $_load_file = null;

		public function __construct( $load_file, $root_file ) {

			$this->_root_file = $root_file;
			$this->_load_file = $load_file;

			add_action( 'gperks_loaded', array( $this, 'load' ), 5 );
			add_action( 'after_plugin_row_' . plugin_basename( $this->_root_file ), array( $this, 'display_dependency_warning_after_plugin_row' ), 10, 2 );

		}

		public function load() {
			require_once( dirname( $this->_root_file ) . '/' . $this->_load_file );
		}

		public function display_dependency_warning_after_plugin_row( $plugin_file, $plugin_data ) {

			if ( class_exists( 'GWPerk' ) ) {
				return;
			}

			$is_activated         = ! is_network_admin() && is_plugin_active( plugin_basename( $plugin_file ) );
			$is_network_activated = is_network_admin() && is_plugin_active_for_network( plugin_basename( $plugin_file ) );

			if ( $is_activated || $is_network_activated ) : ?>

				<style type="text/css" scoped>
					<?php printf( '#%1$s td, #%1$s th', sanitize_title( $plugin_data['Name'] ) ); ?>
					,
					<?php printf( 'tr[data-slug="%1$s"] td, tr[data-slug="%1$s"] th', sanitize_title( $plugin_data['Name'] ) ); ?>
					{
						border-bottom: 0
					;
						box-shadow: none !important
					;
						-webkit-box-shadow: none !important
					;
					}
					.gwp-plugin-notice td {
						padding: 0 !important;
					}

					.gwp-plugin-notice .update-message p:before {
						content: '\f534';
						font-size: 18px;
					}
				</style>

				<tr class="plugin-update-tr active gwp-plugin-notice">
					<td colspan="3" class="colspanchange">
						<div class="update-message notice inline notice-error notice-alt">
							<?php // translators: Placeholders are opening and closing anchor tags to link to the Gravity Wiz website. ?>
							<p><?php printf( __( 'This plugin requires Gravity Perks. Activate it now or %1$spurchase it today!%2$s', 'gravityperks' ), '<a href="https://gravityperks.com">', '</a>' ); ?></p>
						</div>
					</td>
				</tr>

				<?php
			endif;

		}

	}
}
