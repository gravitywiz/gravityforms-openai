<?php
/**
 * @package gravityforms-openai
 * @copyright Copyright (c) 2022, Gravity Wiz, LLC
 * @author Gravity Wiz <support@gravitywiz.com>
 * @license GPLv2
 * @link https://github.com/gravitywiz/gravityforms-openai
 */
defined( 'ABSPATH' ) || die();

GFForms::include_feed_addon_framework();

/*
 * @todo Make notes configurable
 * @todo Make saving to meta configurable
 */
class GWiz_GF_OpenAI extends GFFeedAddOn {

	/**
	 * @var array The default settings to pass to OpenAI
	 */
	public $default_settings = array(
		'completions'      => array(
			'max_tokens'        => 500,
			'temperature'       => 1,
			'top_p'             => 1,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
			'timeout'           => 15,
		),
		'chat/completions' => array(
			'max_tokens'        => 1000,
			'temperature'       => 1,
			'top_p'             => 1,
			'frequency_penalty' => 0,
			'presence_penalty'  => 0,
			'timeout'           => 15,
		),
		'edits'            => array(
			'temperature' => 1,
			'top_p'       => 1,
			'timeout'     => 15,
		),
		'moderations'      => array(
			'timeout' => 5,
		),
	);

	/**
	 * @var GWiz_GF_OpenAI\Dependencies\Inc2734\WP_GitHub_Plugin_Updater\Bootstrap The updater instance.
	 */
	public $updater;

	/**
	 * @var null|GWiz_GF_OpenAI
	 */
	private static $instance = null;

	protected $_version     = GWIZ_GF_OPENAI_VERSION;
	protected $_path        = 'gravityforms-openai/gravityforms-openai.php';
	protected $_full_path   = __FILE__;
	protected $_slug        = 'gravityforms-openai';
	protected $_title       = 'Gravity Forms OpenAI';
	protected $_short_title = 'OpenAI';

	/**
	 * Defines the capabilities needed for the Add-On.
	 *
	 * @var array $_capabilities The capabilities needed for the Add-On
	 */
	protected $_capabilities = array(
		'gravityforms-openai',
		'gravityforms-openai_uninstall',
		'gravityforms-openai_results',
		'gravityforms-openai_settings',
		'gravityforms-openai_form_settings',
	);

	/**
	 * Defines the capability needed to access the Add-On settings page.
	 *
	 * @var string $_capabilities_settings_page The capability needed to access the Add-On settings page.
	 */
	protected $_capabilities_settings_page = 'gravityforms-openai_settings';

	/**
	 * Defines the capability needed to access the Add-On form settings page.
	 *
	 * @var string $_capabilities_form_settings The capability needed to access the Add-On form settings page.
	 */
	protected $_capabilities_form_settings = 'gravityforms-openai_form_settings';

	/**
	 * Defines the capability needed to uninstall the Add-On.
	 *
	 * @var string $_capabilities_uninstall The capability needed to uninstall the Add-On.
	 */
	protected $_capabilities_uninstall = 'gravityforms-openai_uninstall';

	/**
	 * Disable async feed processing for now as it can prevent results mapped to fields from working in notifications.
	 *
	 * @var bool
	 */
	protected $_async_feed_processing = false;

	/**
	 * Allow re-ordering of feeds.
	 *
	 * @var bool
	 */
	protected $_supports_feed_ordering = true;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Give the form settings and plugin settings panels a nice shiny icon.
	 */
	public function get_menu_icon() {
		return $this->get_base_url() . '/icon.svg';
	}

	/**
	 * Defines the minimum requirements for the add-on.
	 *
	 * @return array
	 */
	public function minimum_requirements() {
		return array(
			'gravityforms' => array(
				'version' => '2.5',
			),
			'wordpress'    => array(
				'version' => '4.8',
			),
		);
	}

	/**
	 * Load dependencies and initialize auto-updater
	 */
	public function pre_init() {
		parent::pre_init();

		$this->setup_autoload();
		$this->init_auto_updater();

		add_filter( 'gform_export_form', array( $this, 'export_feeds_with_form' ) );
		add_action( 'gform_forms_post_import', array( $this, 'import_feeds_with_form' ) );
	}

	/**
	 * @credit https://github.com/google/site-kit-wp
	 */
	public function setup_autoload() {
		$class_map = array_merge(
			include plugin_dir_path( __FILE__ ) . 'third-party/vendor/composer/autoload_classmap.php'
		);

		spl_autoload_register(
			function ( $class ) use ( $class_map ) {
				if ( isset( $class_map[ $class ] ) && substr( $class, 0, 27 ) === 'GWiz_GF_OpenAI\\Dependencies' ) {
					require_once $class_map[ $class ];
				}
			},
			true,
			true
		);
	}

	/**
	 * Initialize the auto-updater.
	 */
	public function init_auto_updater() {
		// Initialize GitHub auto-updater
		add_filter(
			'inc2734_github_plugin_updater_plugins_api_gravitywiz/gravityforms-openai',
			array( $this, 'filter_auto_updater_response' ), 10, 2
		);

		$this->updater = new GWiz_GF_OpenAI\Dependencies\Inc2734\WP_GitHub_Plugin_Updater\Bootstrap(
			plugin_basename( plugin_dir_path( __FILE__ ) . 'gravityforms-openai.php' ),
			'gravitywiz',
			'gravityforms-openai',
			array(
				'description_url' => 'https://raw.githubusercontent.com/gravitywiz/gravityforms-openai/master/readme.md',
				'changelog_url'   => 'https://raw.githubusercontent.com/gravitywiz/gravityforms-openai/master/changelog.txt',
				'icons'           => array(
					'svg' => 'https://raw.githubusercontent.com/gravitywiz/gravityforms-openai/master/icon.svg',
				),
				'banners'         => array(
					'low' => 'https://gravitywiz.com/wp-content/uploads/2022/12/gfoai-by-dalle-1.png',
				),
				'requires_php'    => '5.6.0',
			)
		);
	}

	/**
	 * Filter the GitHub auto-updater response to remove sections we don't need and update various fields.
	 *
	 * @param stdClass $obj
	 * @param stdClass $response
	 *
	 * @return stdClass
	 */
	public function filter_auto_updater_response( $obj, $response ) {
		$remove_sections = array(
			'installation',
			'faq',
			'screenshots',
			'reviews',
			'other_notes',
		);

		foreach ( $remove_sections as $section ) {
			if ( isset( $obj->sections[ $section ] ) ) {
				unset( $obj->sections[ $section ] );
			}
		}

		if ( isset( $obj->active_installs ) ) {
			unset( $obj->active_installs );
		}

		$obj->homepage = 'https://gravitywiz.com/gravity-forms-openai/';
		$obj->author   = '<a href="https://gravitywiz.com/" target="_blank">Gravity Wiz</a>';

		$parsedown = new GWiz_GF_OpenAI\Dependencies\Parsedown();
		$changelog = trim( $obj->sections['changelog'] );

		// Remove the "Changelog" h1.
		$changelog = preg_replace( '/^# Changelog/m', '', $changelog );

		// Remove the tab before the list item so it's not treated as code.
		$changelog = preg_replace( '/^\t- /m', '- ', $changelog );

		// Convert h2 to h4 to avoid weird styles that add a lot of whitespace.
		$changelog = preg_replace( '/^## /m', '#### ', $changelog );

		$obj->sections['changelog'] = $parsedown->text( $changelog );

		return $obj;
	}

	/**
	 * Initialize the add-on. Similar to construct, but done later.
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		load_plugin_textdomain( $this->_slug, false, basename( dirname( __file__ ) ) . '/languages/' );

		// Filters/actions
		add_filter( 'gform_tooltips', array( $this, 'tooltips' ) );
		add_filter( 'gform_validation', array( $this, 'moderations_endpoint_validation' ) );
		add_filter( 'gform_validation_message', array( $this, 'modify_validation_message' ), 15, 2 );
		add_filter( 'gform_entry_is_spam', array( $this, 'moderations_endpoint_spam' ), 10, 3 );
		add_filter( 'gform_pre_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
	}

	/**
	 * Defines the available models.
	 */
	public function get_openai_models() {
		$models = array(
			'completions'      => array(
				'text-davinci-003' => array(
					'type'        => 'GPT-3',
					'description' => __( 'Most capable GPT-3 model. Can do any task the other models can do, often with higher quality, longer output and better instruction-following. Also supports <a href="https://beta.openai.com/docs/guides/completion/inserting-text" target="_blank">inserting</a> completions within text.', 'gravityforms-openai' ),
				),
				'text-curie-001'   => array(
					'type'        => 'GPT-3',
					'description' => __( 'Very capable, but faster and lower cost than Davinci.', 'gravityforms-openai' ),
				),
				'text-babbage-001' => array(
					'type'        => 'GPT-3',
					'description' => __( 'Capable of straightforward tasks, very fast, and lower cost.', 'gravityforms-openai' ),
				),
				'text-ada-001'     => array(
					'type'        => 'GPT-3',
					'description' => __( 'Capable of very simple tasks, usually the fastest model in the GPT-3 series, and lowest cost.', 'gravityforms-openai' ),
				),
				'code-davinci-002' => array(
					'type'        => 'Codex',
					'description' => __( 'Most capable Codex model. Particularly good at translating natural language to code. In addition to completing code, also supports <a href="https://beta.openai.com/docs/guides/code/inserting-code" target="_blank">inserting</a> completions within code.', 'gravityforms-openai' ),
				),
				'code-cushman-001' => array(
					'type'        => 'Codex',
					'description' => __( 'Almost as capable as Davinci Codex, but slightly faster. This speed advantage may make it preferable for real-time applications.', 'gravityforms-openai' ),
				),
			),
			'chat/completions' => array(
				'gpt-3.5-turbo'     => array(
					'description' => __( 'The same model used by <a href="https://chat.openai.com" target="_blank">ChatGPT</a>.', 'gravityforms-openai' ),
				),
				'gpt-3.5-turbo-16k' => array(
					'description' => __( 'Same capabilities as the standard gpt-3.5-turbo model but with 4x the context length.', 'gravityforms-openai' ),
				),
				'gpt-4'             => array(
					'description' => __( 'More capable than any GPT-3.5 model, able to do more complex tasks, and optimized for chat. Will be updated with the latest model iteration.', 'gravityforms-openai' ),
				),
				'gpt-4-32k'         => array(
					'description' => __( 'Same capabilities as the base gpt-4 mode but with 4x the context length. Will be updated with the latest model iteration.', 'gravityforms-openai' ),
				),
			),
			'edits'            => array(
				'text-davinci-edit-001' => array(
					'type'        => 'GPT-3',
					'description' => __( 'Most capable GPT-3 model. Can do any task the other models can do, often with higher quality, longer output and better instruction-following. Also supports <a href="https://beta.openai.com/docs/guides/completion/inserting-text" target="_blank">inserting</a> completions within text.', 'gravityforms-openai' ),
				),
				'code-davinci-edit-001' => array(
					'type'        => 'Codex',
					'description' => __( 'Most capable Codex model. Particularly good at translating natural language to code. In addition to completing code, also supports <a href="https://beta.openai.com/docs/guides/code/inserting-code" target="_blank">inserting</a> completions within code.', 'gravityforms-openai' ),
				),
			),
			'moderations'      => array(
				'text-moderation-stable' => array(
					'type' => 'Moderation',
				),
				'text-moderation-latest' => array(
					'type' => 'Moderation',
				),
			),
		);

		return apply_filters( 'gf_openai_models', $models );
	}

	/**
	 * Gets models owned by the user or organization.
	 */
	public function get_user_models() {
		$url = 'https://api.openai.com/v1/models';

		$cache_key = sha1( $url );
		$transient = 'gform_openai_cache_' . $cache_key;

		if ( get_transient( $transient ) ) {
			return get_transient( $transient );
		}

		$response = wp_remote_get( $url, array(
			'headers' => $this->get_headers(),
			'timeout' => 5,
		) );

		$models = array();

		if ( ! is_wp_error( $response ) ) {
			try {
				$response = wp_remote_retrieve_body( $response );
				$response = json_decode( $response, true );

				// Filter results to only models owned by the user/org.
				foreach ( $response['data'] as $model ) {
					if ( strpos( $model['owned_by'], 'user-' ) === 0 || strpos( $model['owned_by'], 'org-' ) === 0 ) {
						$models[ $model['id'] ] = array_merge(
							$model,
							array(
								'user_model' => true,
							)
						);
					}
				}
			} catch ( Exception $e ) {
				// Do nothing, $models is already an empty array.
			}
		}

		// Cache for 5 minutes.
		set_transient( $transient, $models, 5 * MINUTE_IN_SECONDS );

		return $models;
	}

	/**
	 * Defines the settings for the plugin's global settings such as API key. Accessible via Forms » Settings
	 *
	 * @return array[]
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => $this->_title,
				'fields' => array(
					array(
						'name'        => 'secret_key',
						'tooltip'     => __( 'Enter your OpenAI secret key.', 'gravityforms-openai' ),
						'description' => '<a href="https://beta.openai.com/account/api-keys" target="_blank">'
											. __( 'Manage API keys' ) . '</a><br />'
											. sprintf(
												// translators: placeholder is a <code> element
												__( 'Example: %s', 'gravityforms-openai' ),
												'<code>sk-5q6D85X27xr1e1bNEUuLGQp6a0OANXvFxyIo1WnuUbsNb21Z</code>'
											),
						'label'       => 'Secret Key',
						'type'        => 'text',
						'input_type'  => 'password',
						'class'       => 'medium',
						'required'    => true,
					),
					array(
						'name'        => 'organization',
						'tooltip'     => __( 'Enter your OpenAI organization if you belong to multiple.', 'gravityforms-openai' ),
						'description' => '<a href="https://beta.openai.com/account/org-settings" target="_blank">'
											. __( 'Organization Settings' ) . '</a><br />'
											. sprintf(
												// translators: placeholder is a <code> element
												__( 'Example: %s', 'gravityforms-openai' ),
												'<code>org-st6H4JIzknQvU9MoNqRWxPst</code>'
											),
						'label'       => 'Organization',
						'type'        => 'text',
						'class'       => 'medium',
						'required'    => false,
					),
				),
			),
		);
	}

	/**
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feed_name' => __( 'Name', 'gravityforms-openai' ),
			'endpoint'  => __( 'OpenAI Endpoint', 'gravityforms-openai' ),
		);
	}

	/**
	 * Registers tooltips with Gravity Forms. Needed for some things like radio choices.
	 *
	 * @param $tooltips array Existing tooltips.
	 *
	 * @return array
	 */
	public function tooltips( $tooltips ) {
		foreach ( $this->get_openai_models() as $endpoint => $models ) {
			foreach ( $models as $model => $model_info ) {
				if ( ! rgar( $model_info, 'description' ) ) {
					continue;
				}

				$tooltips[ 'openai_model_' . $model ] = $model_info['description'];
			}
		}

		$tooltips['openai_endpoint_completions']      = __( 'Given a prompt, the model will return one or more predicted completions, and can also return the probabilities of alternative tokens at each position.', 'gravityforms-openai' );
		$tooltips['openai_endpoint_chat_completions'] = __( 'Given a single message, the model will return a model-generated message as an output.', 'gravityforms-openai' );
		$tooltips['openai_endpoint_edits']            = __( 'Given a prompt and an instruction, the model will return an edited version of the prompt.', 'gravityforms-openai' );
		$tooltips['openai_endpoint_moderations']      = __( 'Given a input text, outputs if the model classifies it as violating OpenAI\'s content policy.', 'gravityforms-openai' );

		return $tooltips;
	}

	/**
	 * Convert our array of models to choices that a radio settings field can use.
	 *
	 * @param $endpoint string The endpoint we're getting models for.
	 *
	 * @return array
	 */
	public function get_openai_model_choices( $endpoint ) {
		$choices = array();
		$models  = rgar( $this->get_openai_models(), $endpoint );

		// Add user models to completions models.
		if ( $endpoint === 'completions' ) {
			$models = array_merge( $models, $this->get_user_models() );
		}

		if ( ! $models ) {
			return array();
		}

		foreach ( $models as $model => $model_info ) {
			$choices[] = array(
				'label'   => $model . ( rgar( $model_info, 'waitlist' ) ? ' (' . __( 'Requires Waitlist', 'gravityforms-openai' ) . ')' : '' ),
				'value'   => $model,
				'tooltip' => ! rgar( $model_info, 'user_model' ) ? 'openai_model_' . $model : null,
			);
		}

		return $choices;
	}

	public function can_duplicate_feed( $feed_id ) {
		return true;
	}

	public function feed_settings_fields() {
		return array(
			array(
				'title'  => 'General Settings',
				'fields' => array(
					array(
						'label'         => __( 'Name', 'gp-limit-submissions' ),
						'type'          => 'text',
						'name'          => 'feed_name',
						'default_value' => $this->get_default_feed_name(),
						'class'         => 'medium',
						'tooltip'       => __( 'Enter a name for this OpenAI feed. Only displayed on administrative screens.', 'gravityforms-openai' ),
						'required'      => true,
					),
					array(
						'name'          => 'endpoint',
						'tooltip'       => 'Select the OpenAI Endpoint to use.',
						'label'         => __( 'OpenAI Endpoint', 'gravityforms-openai' ),
						'type'          => 'radio',
						'choices'       => array(
							array(
								'value'   => 'completions',
								'label'   => __( 'Completions', 'gravityforms-openai' ),
								'tooltip' => 'openai_endpoint_completions',
							),
							array(
								'value'   => 'chat/completions',
								'label'   => __( 'Chat Completions', 'gravityforms-openai' ),
								'tooltip' => 'openai_endpoint_chat_completions',
							),
							array(
								'value'   => 'edits',
								'label'   => __( 'Edits', 'gravityforms-openai' ),
								'tooltip' => 'openai_endpoint_edits',
							),
							array(
								'value'   => 'moderations',
								'label'   => __( 'Moderations', 'gravityforms-openai' ),
								'tooltip' => 'openai_endpoint_moderations',
							),
						),
						'default_value' => 'completions',
					),
				),
			),
			array(
				'title'      => 'Completions',
				'fields'     => array(
					array(
						'name'     => 'completions_model',
						'tooltip'  => 'Select the OpenAI model to use.',
						'label'    => __( 'OpenAI Model', 'gravityforms-openai' ),
						'type'     => 'radio',
						'choices'  => $this->get_openai_model_choices( 'completions' ),
						'required' => true,
					),
					array(
						'name'     => 'completions_prompt',
						'tooltip'  => 'Enter the prompt to send to OpenAI.',
						'label'    => 'Prompt',
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					$this->feed_setting_enable_merge_tag( 'completions' ),
					$this->feed_setting_map_result_to_field( 'completions' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'completions' ),
						),
					),
				),
			),
			array(
				'title'      => 'Chat Completions',
				'fields'     => array(
					array(
						'name'     => 'chat_completions_model',
						'tooltip'  => 'Select the OpenAI model to use.',
						'label'    => __( 'OpenAI Model', 'gravityforms-openai' ),
						'type'     => 'radio',
						'choices'  => $this->get_openai_model_choices( 'chat/completions' ),
						'required' => true,
					),
					array(
						'name'     => 'chat_completions_message',
						'tooltip'  => 'Enter the message to send to OpenAI.',
						'label'    => 'Message',
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					$this->feed_setting_enable_merge_tag( 'chat/completions' ),
					$this->feed_setting_map_result_to_field( 'chat/completions' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'chat/completions' ),
						),
					),
				),
			),
			array(
				'title'      => 'Edits',
				'fields'     => array(
					array(
						'name'     => 'edits_model',
						'tooltip'  => 'Select the OpenAI model to use.',
						'label'    => __( 'OpenAI Model', 'gravityforms-openai' ),
						'type'     => 'radio',
						'choices'  => $this->get_openai_model_choices( 'edits' ),
						'required' => true,
					),
					array(
						'name'     => 'edits_input',
						'tooltip'  => __( 'The input text to use as a starting point for the edit.', 'gravityforms-openai' ),
						'label'    => 'Input',
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => false,
					),
					array(
						'name'     => 'edits_instruction',
						'tooltip'  => __( 'The instruction that tells the model how to edit the prompt.', 'gravityforms-openai' ),
						'label'    => __( 'Instruction', 'gravityforms-openai' ),
						'type'     => 'textarea',
						'class'    => 'medium merge-tag-support mt-position-right',
						'required' => true,
					),
					$this->feed_setting_enable_merge_tag( 'edits' ),
					$this->feed_setting_map_result_to_field( 'edits' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'edits' ),
						),
					),
				),
			),
			array(
				'title'      => 'Moderations',
				'fields'     => array(
					array(
						'name'     => 'moderations_model',
						'tooltip'  => 'Select the OpenAI model to use.',
						'label'    => __( 'OpenAI Model', 'gravityforms-openai' ),
						'type'     => 'radio',
						'choices'  => $this->get_openai_model_choices( 'moderations' ),
						'required' => true,
					),
					array(
						'name'          => 'moderations_input',
						'tooltip'       => 'Enter the input to send to OpenAI for moderation.',
						'label'         => 'Input',
						'default_value' => '{all_fields}',
						'type'          => 'textarea',
						'class'         => 'medium merge-tag-support mt-position-right',
						'required'      => true,
					),
					array(
						'name'     => 'moderations_behavior',
						'tooltip'  => 'What to do if moderations says the content policy is violated.',
						'label'    => 'Behavior',
						'type'     => 'select',
						'choices'  => array(
							array(
								'label' => __( 'Do nothing' ),
								'value' => '',
							),
							array(
								'label' => __( 'Mark entry as spam' ),
								'value' => 'spam',
							),
							array(
								'label' => __( 'Prevent submission by showing validation error' ),
								'value' => 'validation_error',
							),
						),
						'required' => false,
						'fields'   => array(
							array(
								'name'        => 'moderations_validation_message',
								'tooltip'     => __( 'The validation message to display if the content policy is violated.', 'gravityforms-openai' ),
								'label'       => 'Validation Message',
								'type'        => 'text',
								'placeholder' => $this->get_default_moderations_validation_message(),
								'dependency'  => array(
									'live'   => true,
									'fields' => array(
										array(
											'field'  => 'moderations_behavior',
											'values' => array( 'validation_error' ),
										),
									),
								),
							),
						),
					),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'moderations' ),
						),
					),
				),
			),
			//Conditional Logic
			array(
				'title'  => esc_html__( 'Conditional Logic', 'gravityforms-openai' ),
				'fields' => array(
					array(
						'label' => '',
						'name'  => 'conditional_logic',
						'type'  => 'feed_condition',
					),
				),
			),
			array(
				'title'      => 'Advanced Settings: Completions',
				'fields'     => array(
					$this->feed_advanced_setting_timeout( 'completions' ),
					$this->feed_advanced_setting_max_tokens( 'completions' ),
					$this->feed_advanced_setting_temperature( 'completions' ),
					$this->feed_advanced_setting_top_p( 'completions' ),
					$this->feed_advanced_setting_frequency_penalty( 'completions' ),
					$this->feed_advanced_setting_presence_penalty( 'completions' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'completions' ),
						),
					),
				),
			),
			array(
				'title'      => 'Advanced Settings: Chat Completions',
				'fields'     => array(
					$this->feed_advanced_setting_timeout( 'chat/completions' ),
					$this->feed_advanced_setting_max_tokens( 'chat/completions' ),
					$this->feed_advanced_setting_temperature( 'chat/completions' ),
					$this->feed_advanced_setting_top_p( 'chat/completions' ),
					$this->feed_advanced_setting_frequency_penalty( 'chat/completions' ),
					$this->feed_advanced_setting_presence_penalty( 'chat/completions' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'chat/completions' ),
						),
					),
				),
			),
			array(
				'title'      => 'Advanced Settings: Edits',
				'fields'     => array(
					$this->feed_advanced_setting_timeout( 'edits' ),
					$this->feed_advanced_setting_temperature( 'edits' ),
					$this->feed_advanced_setting_top_p( 'edits' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'edits' ),
						),
					),
				),
			),
			array(
				'title'      => 'Advanced Settings: Moderations',
				'fields'     => array(
					$this->feed_advanced_setting_timeout( 'moderations' ),
				),
				'dependency' => array(
					'live'   => true,
					'fields' => array(
						array(
							'field'  => 'endpoint',
							'values' => array( 'moderations' ),
						),
					),
				),
			),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_setting_enable_merge_tag( $endpoint ) {
		return array(
			'name'        => $endpoint . '_enable_merge_tag',
			'type'        => 'checkbox',
			'label'       => __( 'Merge Tag', 'gravityforms-openai' ),
			'description' => __( 'Enable getting the output of the OpenAI result using a merge tag.
								<br /><br />
								Pro Tip: This works with Gravity Forms Populate Anything\'s
								<a href="https://gravitywiz.com/documentation/gravity-forms-populate-anything/#live-merge-tags" target="_blank">Live Merge Tags</a>!', 'gravityforms-openai' ),
			'choices'     => array(
				array(
					'name'  => $endpoint . '_enable_merge_tag',
					'label' => __( 'Enable Merge Tag', 'gravityforms' ),
				),
			),
			'fields'      => array(
				array(
					'name'       => 'merge_tag_preview_' . $endpoint,
					'type'       => 'html',
					'html'       => rgget( 'fid' ) ? '<style>
									#openai_merge_tag_usage {
										line-height: 1.6rem;
									}

									#openai_merge_tag_usage ul {
										padding: 0 0 0 1rem;
									}

									#openai_merge_tag_usage ul li {
										list-style: disc;
									}
									</style>
									<div id="openai_merge_tag_usage"><strong>Usage:</strong><br />
									<ul>
										<li><code>{openai_feed_' . rgget( 'fid' ) . '}</code></li>
									</ul>
									<strong>Usage as a <a href="https://gravitywiz.com/documentation/gravity-forms-populate-anything/#live-merge-tags" target="_blank">Live Merge Tag</a>:</strong><br />
									<ul>
										<li><code>@{:FIELDID:openai_feed_' . rgget( 'fid' ) . '}</code><br />Replace <code>FIELDID</code> accordingly. Automatically refreshes in the form if the specified field ID changes.</li>
										<li><code>@{all_fields:openai_feed_' . rgget( 'fid' ) . '}</code><br />Automatically refreshes in the form if any field value changes.</li>
									</ul><div></div>' : 'Save feed to see merge tags.',
					'dependency' => array(
						'live'   => true,
						'fields' => array(
							array(
								'field' => $endpoint . '_enable_merge_tag',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_setting_map_result_to_field( $endpoint ) {
		return array(
			'name'        => $endpoint . '_map_result_to_field',
			'type'        => 'field_select',
			'label'       => __( 'Map Result to Field', 'gravityforms-openai' ),
			'description' => __( 'Take the result and attach it to a field\'s value upon submission.', 'gravityforms-openai' ),
		);
	}


	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_timeout( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'timeout' );

		return array(
			'name'          => $endpoint . '_' . 'timeout',
			'tooltip'       => 'Enter the number of seconds to wait for OpenAI to respond.',
			'label'         => 'Timeout',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code> seconds.', 'gravityforms-openai' ), $default ),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_max_tokens( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'max_tokens' );

		return array(
			'name'          => $endpoint . '_' . 'max_tokens',
			'tooltip'       => __( 'The maximum number of <a href="https://beta.openai.com/tokenizer" target="_blank">tokens</a> to generate in the completion.
								<br /><br />
								The token count of your prompt plus max_tokens cannot exceed the model\'s context
								length. Most models have a context length of 2048 tokens (except for the newest models, which support 4096).', 'gravityforms-openai' ),
			'label'         => 'Max Tokens',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code>', 'gravityforms-openai' ), $default ),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_temperature( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'temperature' );

		return array(
			'name'          => $endpoint . '_' . 'temperature',
			'tooltip'       => __( 'What <a href="https://towardsdatascience.com/how-to-sample-from-language-models-682bceb97277" target="_blank">sampling</a>
								temperature to use. Higher values means the model will take more risks. Try 0.9 for more
								creative applications, and 0 (argmax sampling) for ones with a well-defined answer.
								<br /><br />
								We generally recommend altering this or <code>top_p</code> but not both.', 'gravityforms-openai' ),
			'label'         => 'Temperature',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code>', 'gravityforms-openai' ), $default ),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_top_p( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'top_p' );

		return array(
			'name'          => $endpoint . '_' . 'top_p',
			'tooltip'       => __( 'An alternative to sampling with temperature, called nucleus sampling,
								where the model considers the results of the tokens with top_p probability mass. So 0.1
								means only the tokens comprising the top 10% probability mass are considered.
								<br /><br />
								We generally recommend altering this or temperature but not both.', 'gravityforms-openai' ),
			'label'         => 'Top-p',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code>', 'gravityforms-openai' ), $default ),

		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_frequency_penalty( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'frequency_penalty' );

		return array(
			'name'          => $endpoint . '_' . 'frequency_penalty',
			'tooltip'       => __( 'Number between -2.0 and 2.0. Positive values penalize new tokens based
								on their existing frequency in the text so far, decreasing the model\'s likelihood to
								repeat the same line verbatim.
								<br /><br />
								<a href="https://beta.openai.com/docs/api-reference/parameter-details" target="_blank">See more information about frequency and presence penalties.</a>', 'gravityforms-openai' ),
			'label'         => 'Frequency Penalty',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code>', 'gravityforms-openai' ), $default ),
		);
	}

	/**
	 * @param $endpoint string The OpenAI endpoint.
	 *
	 * @return array
	 */
	public function feed_advanced_setting_presence_penalty( $endpoint ) {
		$default = rgar( rgar( $this->default_settings, $endpoint ), 'presence_penalty' );

		return array(
			'name'          => $endpoint . '_' . 'presence_penalty',
			'tooltip'       => __( 'Number between -2.0 and 2.0. Positive values penalize new tokens based
								on whether they appear in the text so far, increasing the model\'s likelihood to talk
								about new topics.
								<br /><br />
								<a href="https://beta.openai.com/docs/api-reference/parameter-details" target="_blank">See more information about frequency and presence penalties.</a>', 'gravityforms-openai' ),
			'label'         => 'Presence Penalty',
			'type'          => 'text',
			'class'         => 'small',
			'required'      => false,
			'default_value' => $default,
			// translators: placeholder is a number
			'description'   => sprintf( __( 'Default: <code>%d</code>', 'gravityforms-openai' ), $default ),
		);
	}

	/**
	 * Processes the feed and sends the data to OpenAI.
	 *
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 *
	 * @return array|void|null
	 */
	public function process_feed( $feed, $entry, $form ) {
		$endpoint = $feed['meta']['endpoint'];

		switch ( $endpoint ) {
			case 'completions':
				$entry = $this->process_endpoint_completions( $feed, $entry, $form );
				break;

			case 'chat/completions':
				$entry = $this->process_endpoint_chat_completions( $feed, $entry, $form );
				break;

			case 'edits':
				$entry = $this->process_endpoint_edits( $feed, $entry, $form );
				break;

			case 'moderations':
				$this->process_endpoint_moderations( $feed, $entry, $form );
				break;

			default:
				// translators: placeholder is an unknown OpenAI endpoint.
				$this->add_feed_error( sprintf( __( 'Unknown endpoint: %s' ), $endpoint ), $feed, $entry, $form );
				break;
		}

		return $entry;
	}

	/**
	 * Process completions endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_completions( $feed, $entry, $form ) {
		$model  = $feed['meta']['completions_model'];
		$prompt = $feed['meta']['completions_prompt'];

		// Parse the merge tags in the prompt.
		$prompt = GFCommon::replace_variables( $prompt, $form, $entry, false, false, false, 'text' );

		GFAPI::add_note( $entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf( __( 'Sent request to OpenAI completions endpoint.', 'gravityforms-openai' ) ) );

		// translators: placeholders are the feed name, model, prompt
		$this->log_debug( __METHOD__ . '(): ' . sprintf( __( 'Sent request to OpenAI. Feed: %1$s, Endpoint: completions, Model: %2$s, Prompt: %3$s', 'gravityforms-openai' ), $feed['meta']['feed_name'], $model, $prompt ) );

		$response = $this->make_request( 'completions', array(
			'prompt' => $prompt,
			'model'  => $model,
		), $feed );

		if ( is_wp_error( $response ) ) {
			// If there was an error, log it and return.
			$this->add_feed_error( $response->get_error_message(), $feed, $entry, $form );
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode( $response['body'], true );

		if ( rgar( $response_data, 'error' ) ) {
			$this->add_feed_error( $response_data['error']['message'], $feed, $entry, $form );
			return $entry;
		}

		$text = $this->get_text_from_response( $response_data );

		if ( ! is_wp_error( $text ) ) {
			GFAPI::add_note( $entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text );
			$entry = $this->maybe_save_result_to_field( $feed, $entry, $form, $text );
		} else {
			$this->add_feed_error( $text->get_error_message(), $feed, $entry, $form );
		}

		gform_add_meta( $entry['id'], 'openai_response_' . $feed['id'], $response['body'] );

		return $entry;
	}

	/**
	 * Process chat endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_chat_completions( $feed, $entry, $form ) {
		$model   = $feed['meta']['chat_completions_model'];
		$message = $feed['meta']['chat_completions_message'];

		// Parse the merge tags in the message.
		$message = GFCommon::replace_variables( $message, $form, $entry, false, false, false, 'text' );

		GFAPI::add_note( $entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf( __( 'Sent request to OpenAI chat/completions endpoint.', 'gravityforms-openai' ) ) );

		// translators: placeholders are the feed name, model, prompt
		$this->log_debug( __METHOD__ . '(): ' . sprintf( __( 'Sent request to OpenAI. Feed: %1$s, Endpoint: chat, Model: %2$s, Message: %3$s', 'gravityforms-openai' ), $feed['meta']['feed_name'], $model, $message ) );

		$response = $this->make_request( 'chat/completions', array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $message,
				),
			),
			'model'    => $model,
		), $feed );

		if ( is_wp_error( $response ) ) {
			// If there was an error, log it and return.
			$this->add_feed_error( $response->get_error_message(), $feed, $entry, $form );
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode( $response['body'], true );

		if ( rgar( $response_data, 'error' ) ) {
			$this->add_feed_error( $response_data['error']['message'], $feed, $entry, $form );
			return $entry;
		}

		$text = $this->get_text_from_response( $response_data );

		if ( ! is_wp_error( $text ) ) {
			GFAPI::add_note( $entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text );
			$entry = $this->maybe_save_result_to_field( $feed, $entry, $form, $text );
		} else {
			$this->add_feed_error( $text->get_error_message(), $feed, $entry, $form );
		}

		gform_add_meta( $entry['id'], 'openai_response_' . $feed['id'], $response['body'] );

		return $entry;
	}

	/**
	 * Process edits endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return array Modified entry.
	 */
	public function process_endpoint_edits( $feed, $entry, $form ) {
		$model       = $feed['meta']['edits_model'];
		$input       = $feed['meta']['edits_input'];
		$instruction = $feed['meta']['edits_instruction'];

		// Parse the merge tags in the input and instruction
		$input       = GFCommon::replace_variables( $input, $form, $entry, false, false, false, 'text' );
		$instruction = GFCommon::replace_variables( $instruction, $form, $entry, false, false, false, 'text' );

		GFAPI::add_note( $entry['id'], 0, 'OpenAI Request (' . $feed['meta']['feed_name'] . ')', sprintf( __( 'Sent request to OpenAI edits endpoint.', 'gravityforms-openai' ) ) );

		// translators: placeholders are the feed name, model, prompt
		$this->log_debug( __METHOD__ . '(): ' . sprintf( __( 'Sent request to OpenAI. Feed: %1$s, Endpoint: edits, Model: %2$s, Input: %3$s, instruction: %4$s', 'gravityforms-openai' ), $feed['meta']['feed_name'], $model, $input, $instruction ) );

		$response = $this->make_request( 'edits', array(
			'input'       => $input,
			'instruction' => $instruction,
			'model'       => $model,
		), $feed );

		if ( is_wp_error( $response ) ) {
			// If there was an error, log it and return.
			$this->add_feed_error( $response->get_error_message(), $feed, $entry, $form );
			return $entry;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode( $response['body'], true );

		if ( rgar( $response_data, 'error' ) ) {
			$this->add_feed_error( $response_data['error']['message'], $feed, $entry, $form );
			return $entry;
		}

		$text = $this->get_text_from_response( $response_data );

		if ( ! is_wp_error( $text ) ) {
			GFAPI::add_note( $entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', $text );
			$entry = $this->maybe_save_result_to_field( $feed, $entry, $form, $text );
		} else {
			$this->add_feed_error( $text->get_error_message(), $feed, $entry, $form );
		}

		gform_add_meta( $entry['id'], 'openai_response_' . $feed['id'], $response['body'] );

		return $entry;
	}


	/**
	 * Saves the result to the selected field if configured.
	 *
	 * @return array Modified entry.
	 */
	public function maybe_save_result_to_field( $feed, $entry, $form, $text ) {
		$endpoint            = rgars( $feed, 'meta/endpoint' );
		$map_result_to_field = rgar( rgar( $feed, 'meta' ), $endpoint . '_map_result_to_field' );

		if ( ! is_numeric( $map_result_to_field ) ) {
			return $entry;
		}

		$field = GFAPI::get_field( $form, (int) $map_result_to_field );

		if ( rgar( $field, 'useRichTextEditor' ) ) {
			$text = wp_kses_post( $text );
		} else {
			// br2nl
			$text = preg_replace( '/<br\s?\/?>/ius', "\n", str_replace( "\n", '', str_replace( "\r", '', htmlspecialchars_decode( $text ) ) ) );
			$text = wp_strip_all_tags( $text );
		}

		$entry[ $map_result_to_field ] = $text;

		GFAPI::update_entry_field( $entry['id'], $map_result_to_field, $text );

		gf_do_action( array( 'gf_openai_post_save_result_to_field', $form['id'] ), $text );

		return $entry;
	}

	/**
	 * Process moderations endpoint.
	 *
	 * @param $feed array The current feed being processed.
	 * @param $entry array The current entry being processed.
	 * @param $form array The current form being processed.
	 *
	 * @return boolean Whether the entry was flagged or not.
	 */
	public function process_endpoint_moderations( $feed, $entry, $form ) {
		$model = $feed['meta']['moderations_model'];
		$input = $feed['meta']['moderations_input'];

		// Parse the merge tags in the input
		$input = GFCommon::replace_variables( $input, $form, $entry, false, false, false, 'text' );

		// translators: placeholders are the feed name, model, and input
		$this->log_debug( __METHOD__ . '(): ' . sprintf( __( 'Sent request to OpenAI. Feed: %1$s, Endpoint: moderations, Model: %2$s, Input: %3$s', 'gravityforms-openai' ), $feed['meta']['feed_name'], $model, $input ) );

		$response = $this->make_request( 'moderations', array(
			'input' => $input,
			'model' => $model,
		), $feed );

		// Do nothing if there is an API error.
		// @todo should this be configurable?
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Parse the response and add it as an entry note.
		$response_data = json_decode( $response['body'], true );

		$categories = rgars( $response_data, 'results/0/categories' );

		if ( ! is_array( $categories ) ) {
			return false;
		}

		if ( rgar( $entry, 'id' ) ) {
			GFAPI::add_note( $entry['id'], 0, 'OpenAI Response (' . $feed['meta']['feed_name'] . ')', print_r( rgar( $response_data, 'results' ), true ) );
		}

		// Check each category for true and if so, invalidate the form immediately.
		// @todo make categories configurable
		foreach ( $categories as $category => $value ) {
			if ( $value && apply_filters( 'gf_openai_moderations_reject_category', true, $category ) ) {
				return true;
			}
		}

		return false;
	}

	public function get_default_moderations_validation_message() {
		return __( 'This submission violates our content policy.', 'gravityforms-openai' );
	}

	/**
	 * Process moderations endpoint using gform_validation. We'll need to loop through all the feeds and find the
	 * ones using the moderations endpoint as they can't be handled using process_feed().
	 */
	public function moderations_endpoint_validation( $validation_result ) {
		$form = $validation_result['form'];

		// Loop through feeds for this form and find ones using the moderations endopint.
		foreach ( $this->get_feeds( $form['id'] ) as $feed ) {
			if ( $feed['meta']['endpoint'] !== 'moderations' ) {
				continue;
			}

			// Do not validate if the behavior is not set to validate.
			if ( $feed['meta']['moderations_behavior'] !== 'validation_error' ) {
				continue;
			}

			// Create dummy entry with what has been submitted
			$entry = GFFormsModel::create_lead( $form );

			if ( ! rgar( $feed, 'is_active' ) || ! $this->is_feed_condition_met( $feed, $form, $entry ) ) {
				return $validation_result;
			}

			if ( $this->process_endpoint_moderations( $feed, $entry, $form ) ) {
				$validation_result['is_valid'] = false;

				$this->moderations_validation_message = rgar( $feed['meta'], 'moderations_validation_message', $this->get_default_moderations_validation_message() );

				return $validation_result;
			}
		}

		return $validation_result;
	}

	public function modify_validation_message( $message, $form ) {
		if ( ! isset( $this->moderations_validation_message ) ) {
			return $message;
		}

		return $this->get_validation_error_markup( $this->moderations_validation_message, $form );
	}

	/**
	 * Returns validation error message markup.
	 *
	 * @param string $validation_message  The validation message to add to the markup.
	 * @param array  $form                The submitted form data.
	 *
	 * @return false|string
	 */
	protected function get_validation_error_markup( $validation_message, $form ) {
		$error_classes = $this->get_validation_error_css_classes( $form );
		ob_start();

		if ( ! $this->is_gravityforms_supported( '2.5' ) ) {
			?>
			<div class="<?php echo esc_attr( $error_classes ); ?>"><?php echo esc_html( $validation_message ); ?></div>
			<?php
			return ob_get_clean();
		}
		?>
		<h2 class="<?php echo esc_attr( $error_classes ); ?>">
			<span class="gform-icon gform-icon--close"></span>
			<?php echo esc_html( $validation_message ); ?>
		</h2>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get the CSS classes for the validation markup.
	 *
	 * @param array $form The submitted form data.
	 */
	protected function get_validation_error_css_classes( $form ) {
		$container_css = $this->is_gravityforms_supported( '2.5' ) ? 'gform_submission_error' : 'validation_error';

		return "{$container_css} hide_summary";
	}

	/**
	 * Process moderations endpoint using gform_validation. We'll need to loop through all the feeds and find the
	 * ones using the moderations endpoint as they can't be handled using process_feed().
	 *
	 * @param $is_spam boolean Whether the entry is spam or not.
	 * @param $form array The current form being processed.
	 * @param $entry array The current entry being processed.
	 *
	 * @return boolean Whether the entry is spam or not.
	 */
	public function moderations_endpoint_spam( $is_spam, $form, $entry ) {
		// Loop through feeds for this form and find ones using the moderations endpoint.
		foreach ( $this->get_feeds( $form['id'] ) as $feed ) {
			if ( $feed['meta']['endpoint'] !== 'moderations' ) {
				continue;
			}

			if ( $feed['meta']['moderations_behavior'] !== 'spam' ) {
				continue;
			}

			if ( ! rgar( $feed, 'is_active' ) || ! $this->is_feed_condition_met( $feed, $form, $entry ) ) {
				return '';
			}

			if ( $this->process_endpoint_moderations( $feed, $entry, $form ) ) {
				return true;
			}
		}

		return $is_spam;
	}

	/**
	 * @param array $response The JSON-decoded response from OpenAI.
	 *
	 * @return string|WP_Error
	 */
	public function get_text_from_response( $response ) {
		if ( rgars( $response, 'choices/0/text' ) ) {
			return trim( rgars( $response, 'choices/0/text' ) );
		}

		// Chat completions
		if ( rgars( $response, 'choices/0/message/content' ) ) {
			return trim( rgars( $response, 'choices/0/message/content' ) );
		}

		return trim( rgar( $response, 'text' ) );
	}

	/**
	 * Replace merge tags using the OpenAI response.
	 *
	 * @param string      $text       The text in which merge tags are being processed.
	 * @param false|array $form       The Form object if available or false.
	 * @param false|array $entry      The Entry object if available or false.
	 * @param bool        $url_encode Indicates if the urlencode function should be applied.
	 * @param bool        $esc_html   Indicates if the esc_html function should be applied.
	 * @param bool        $nl2br      Indicates if the nl2br function should be applied.
	 * @param string      $format     The format requested for the location the merge is being used. Possible values: html, text or url.
	 *
	 * @return string The text with merge tags processed.
	 */
	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		// Process merge tags only if they are an openai feed.
		if ( ! strpos( $text, 'openai_feed' ) ) {
			return $text;
		}

		preg_match_all( '/{[^{]*?:(\d+(\.\d+)?)(:(.*?))?}/mi', $text, $field_variable_matches, PREG_SET_ORDER );

		foreach ( $field_variable_matches as $match ) {
			$input_id      = $match[1];
			$i             = $match[0][0] === '{' ? 4 : 5;
			$modifiers_str = rgar( $match, $i );
			$modifiers     = $this->parse_modifiers( $modifiers_str );

			$feed_id = null;

			foreach ( $modifiers as $modifier ) {
				if ( strpos( $modifier, 'openai_feed_' ) === 0 ) {
					$feed_id = str_replace( 'openai_feed_', '', $modifier );
					break;
				}
			}

			// Do not process if we don't have a match on openai_feed_ as a modifier as it could impact other merge tags.
			if ( ! is_numeric( $feed_id ) ) {
				continue;
			}

			// Ensure our field in question has a value
			if ( ! rgar( $entry, $input_id ) ) {
				$text = str_replace( $match[0], '', $text );
				continue;
			}

			$nl2br = rgar( $modifiers, 'nl2br' ) ? true : $nl2br;

			$replacement = $this->get_merge_tag_replacement( $form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, $modifiers );
			$text        = str_replace( $match[0], $replacement, $text );
		}

		preg_match_all( '/{(all_fields:)?openai_feed_(\d+)}/mi', $text, $all_fields_matches, PREG_SET_ORDER );

		foreach ( $all_fields_matches as $match ) {
			$feed_id = $match[2];

			if ( ! is_numeric( $feed_id ) ) {
				continue;
			}

			$replacement = $this->get_merge_tag_replacement( $form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, array() );
			$text        = str_replace( $match[0], $replacement, $text );
		}

		return $text;
	}

	public function get_merge_tag_replacement( $form, $entry, $feed_id, $url_encode, $esc_html, $nl2br, $format, $modifiers ) {
		$feed     = $this->get_feed( $feed_id );
		$endpoint = rgars( $feed, 'meta/endpoint' );

		if ( ! $endpoint || (int) rgar( $feed, 'form_id' ) !== (int) rgar( $form, 'id' ) ) {
			return '';
		}

		if ( ! $feed['meta'][ $endpoint . '_enable_merge_tag' ] ) {
			return '';
		}

		if ( ! rgar( $feed, 'is_active' ) || ! $this->is_feed_condition_met( $feed, $form, $entry ) ) {
			return '';
		}

		$response_data = array();

		switch ( $endpoint ) {
			case 'completions':
				$model  = $feed['meta']['completions_model'];
				$prompt = $feed['meta']['completions_prompt'];

				$prompt = GFCommon::replace_variables( $prompt, $form, $entry, false, false, false, 'text' );

				// If prompt is empty, do not generate any completion response, skip with blank.
				if ( empty( $prompt ) ) {
					return '';
				}

				$response = $this->make_request( 'completions', array(
					'model'  => $model,
					'prompt' => $prompt,
				), $feed );

				if ( ! is_wp_error( $response ) ) {
					$response_data = json_decode( $response['body'], true );
				}
				break;

			case 'chat/completions':
				$model   = $feed['meta']['chat_completions_model'];
				$message = $feed['meta']['chat_completions_message'];

				$message = GFCommon::replace_variables( $message, $form, $entry, false, false, false, 'text' );

				// If message is empty, do not generate any chat response, skip with blank.
				if ( empty( $message ) ) {
					return '';
				}

				$response = $this->make_request( 'chat/completions', array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => $message,
						),
					),
					'model'    => $model,
				), $feed );

				if ( ! is_wp_error( $response ) ) {
					$response_data = json_decode( $response['body'], true );
				}
				break;

			case 'edits':
				$model       = $feed['meta']['edits_model'];
				$input       = $feed['meta']['edits_input'];
				$instruction = $feed['meta']['edits_instruction'];

				$input       = GFCommon::replace_variables( $input, $form, $entry, false, false, false, 'text' );
				$instruction = GFCommon::replace_variables( $instruction, $form, $entry, false, false, false, 'text' );

				// If input or instruction is empty, do not generate any edit response, skip with blank.
				if ( empty( $input ) || empty( $instruction ) ) {
					return '';
				}

				$response = $this->make_request( 'edits', array(
					'model'       => $model,
					'input'       => $input,
					'instruction' => $instruction,
				), $feed );

				if ( ! is_wp_error( $response ) ) {
					$response_data = json_decode( $response['body'], true );
				}
				break;

			default:
				return '';
		}

		if ( ! rgar( $modifiers, 'raw' ) ) {
			$text = $this->get_text_from_response( $response_data );
		} else {
			$text = rgars( $response_data, rgar( $modifiers, 'raw' ) );
		}

		$text = $url_encode ? urlencode( $text ) : $text;
		$text = $format === 'html' ? wp_kses_post( $text ) : wp_strip_all_tags( $text );
		$text = $nl2br ? nl2br( $text ) : $text;

		return $text;
	}

	/**
	 * @param string $modifiers_str
	 *
	 * @return array
	 */
	public function parse_modifiers( $modifiers_str ) {
		preg_match_all( '/([a-z_0-9]+)(?:(?:\[(.+?)\])|,?)/i', $modifiers_str, $modifiers, PREG_SET_ORDER );
		$parsed = array();

		foreach ( $modifiers as $modifier ) {

			list( $match, $modifier, $value ) = array_pad( $modifier, 3, null );
			if ( $value === null ) {
				$value = $modifier;
			}

			// Split '1,2,3' into array( 1, 2, 3 ).
			if ( strpos( $value, ',' ) !== false ) {
				$value = array_map( 'trim', explode( ',', $value ) );
			}

			$parsed[ strtolower( $modifier ) ] = $value;

		}

		return $parsed;
	}

	/**
	 * Helper method to send a request to the OpenAI API but also cache it using runtime cache and transients.
	 *
	 * @param string $endpoint The OpenAI endpoint.
	 * @param array $body Request parameters.
	 * @param array $feed The feed being processed.
	 *
	 * @return array|WP_Error The response or WP_Error on failure.
	 */
	public function make_request( $endpoint, $body, $feed ) {
		static $request_cache = array();

		$url = 'https://api.openai.com/v1/' . $endpoint;

		$cache_key = sha1( serialize( array(
			'url'            => $url,
			'body'           => $body,
			'request_params' => $this->get_request_params( $feed ),
		) ) );

		// Check runtime cache first and then transient (if enabled)
		if ( isset( $request_cache[ $cache_key ] ) ) {
			return $request_cache[ $cache_key ];
		}

		$transient = 'gform_openai_cache_' . $cache_key;
		$use_cache = gf_apply_filters( array( 'gf_openai_cache_responses', $feed['form_id'], $feed['id'] ), true, $feed['form_id'], $feed, $endpoint, $body );

		if ( $use_cache && get_transient( $transient ) ) {
			return get_transient( $transient );
		}

		switch ( $endpoint ) {
			case 'completions':
				$body['max_tokens']        = (float) rgar( $feed['meta'], $endpoint . '_' . 'max_tokens', $this->default_settings['completions']['max_tokens'] );
				$body['temperature']       = (float) rgar( $feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['completions']['temperature'] );
				$body['top_p']             = (float) rgar( $feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['completions']['top_p'] );
				$body['frequency_penalty'] = (float) rgar( $feed['meta'], $endpoint . '_' . 'frequency_penalty', $this->default_settings['completions']['frequency_penalty'] );
				$body['presence_penalty']  = (float) rgar( $feed['meta'], $endpoint . '_' . 'presence_penalty', $this->default_settings['completions']['presence_penalty'] );
				break;

			case 'chat/completions':
				$body['max_tokens']        = (float) rgar( $feed['meta'], $endpoint . '_' . 'max_tokens', $this->default_settings['chat/completions']['max_tokens'] );
				$body['temperature']       = (float) rgar( $feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['chat/completions']['temperature'] );
				$body['top_p']             = (float) rgar( $feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['chat/completions']['top_p'] );
				$body['frequency_penalty'] = (float) rgar( $feed['meta'], $endpoint . '_' . 'frequency_penalty', $this->default_settings['chat/completions']['frequency_penalty'] );
				$body['presence_penalty']  = (float) rgar( $feed['meta'], $endpoint . '_' . 'presence_penalty', $this->default_settings['chat/completions']['presence_penalty'] );
				break;

			case 'edits':
				$body['temperature'] = (float) rgar( $feed['meta'], $endpoint . '_' . 'temperature', $this->default_settings['edits']['temperature'] );
				$body['top_p']       = (float) rgar( $feed['meta'], $endpoint . '_' . 'top_p', $this->default_settings['edits']['top_p'] );
				break;
		}

		$body = apply_filters( 'gf_openai_request_body', $body, $endpoint, $feed );

		// Cache successful responses.
		$response = wp_remote_post($url, array_merge( array(
			'body' => json_encode( $body ),
		), $this->get_request_params( $feed ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$request_cache[ $cache_key ] = $response;

		if ( $use_cache ) {
			// Save as a transient for 5 minutes.
			set_transient( $transient, $request_cache[ $cache_key ], 5 * MINUTE_IN_SECONDS );
		}

		return $request_cache[ $cache_key ];
	}

	/**
	 * Helper method for common headers/settings for wp_remote_post.
	 *
	 * @param array $feed The feed being processed.
	 *
	 * @return array
	 */
	public function get_request_params( $feed ) {
		$endpoint        = rgars( $feed, 'meta/endpoint' );
		$default_timeout = rgar( rgar( $this->default_settings, $endpoint ), 'timeout' );
		$headers         = $this->get_headers();

		return array(
			'headers' => $headers,
			'timeout' => rgar( $feed['meta'], $endpoint . '_' . 'timeout', $default_timeout ),
		);
	}

	/**
	 * Gets headers used for virtually every request.
	 *
	 * @return array
	 */
	public function get_headers() {
		$settings     = $this->get_plugin_settings();
		$secret_key   = $settings['secret_key'];
		$organization = $settings['organization'];

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
		);

		if ( $organization ) {
			$headers['OpenAI-Organization'] = $organization;
		}

		return $headers;
	}

	/**
	 * Export OpenAI Add-On feeds when exporting forms.
	 *
	 * @param array $form The current form being exported.
	 *
	 * @return array
	 */
	public function export_feeds_with_form( $form ) {
		$feeds = $this->get_feeds( $form['id'] );

		if ( ! isset( $form['feeds'] ) ) {
			$form['feeds'] = array();
		}

		$form['feeds'][ $this->get_slug() ] = $feeds;

		return $form;
	}

	/**
	 * Import OpenAI Add-On feeds when importing forms.
	 *
	 * @param array $forms Imported forms.
	 */
	public function import_feeds_with_form( $forms ) {
		foreach ( $forms as $import_form ) {
			// Ensure the imported form is the latest.
			$form = GFAPI::get_form( $import_form['id'] );

			if ( ! rgars( $form, 'feeds/' . $this->get_slug() ) ) {
				continue;
			}

			foreach ( rgars( $form, 'feeds/' . $this->get_slug() ) as $feed ) {
				GFAPI::add_feed( $form['id'], $feed['meta'], $this->get_slug() );
			}

			// Remove feeds from the form array as it's no longer needed.
			unset( $form['feeds'][ $this->get_slug() ] );

			if ( empty( $form['feeds'] ) ) {
				unset( $form['feeds'] );
			}

			GFAPI::update_form( $form );
		}
	}
}
