<?php

namespace FoxApp\GitHub\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * @property bool $plugin_activated
 */
class Updater {
	//public mixed $file;
	//public mixed $plugin;
	//public mixed $basename;
	public string $plugin_active;
	/*----*/
	public string $plugin_version;
	public string $plugin_file;
	public string $plugin_name;
	public string $plugin_slug;
	public array $plugin_data;
	public bool $plugin_activated;
	public string $plugin_real_slug;
	public string $github_username;
	public string $github_repository;
	public string $github_authorize_token;
	private string $response_transient_key;
	private string $transient_key_prefix;
	public string $slug;
	public string $download_link;
	public string $change_log;
	/**
	 * @var mixed|string
	 */
	public $plugin_text_domain;

	public function __construct( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		//Current Plugin Info which want to be updated
		$this->plugin_file          = $plugin_file['plugin_file'];
		$this->plugin_slug          = $plugin_file['plugin_slug'];
		$this->plugin_real_slug     = $plugin_file['plugin_real_slug'];
		$this->transient_key_prefix = $plugin_file['transient_key_prefix'];
		$this->plugin_data          = get_plugin_data( $this->plugin_file );
		$this->plugin_name          = $this->plugin_data['Name'] ?? '';
		$this->plugin_version       = $this->plugin_data['Version'] ?? '';
		$this->plugin_text_domain   = $this->plugin_data['TextDomain'] ?? '';

		//GitHub Settings for Remote Request
		$this->github_username        = $plugin_file['github_username'];
		$this->github_repository      = $plugin_file['github_repository'];
		$this->github_authorize_token = $plugin_file['github_authorize_token'];

		//Helper Settings
		$this->response_transient_key = md5( sanitize_key( $this->plugin_slug ) . 'response_transient' );

		$this->change_log = '';

		$this->setup_helper_hooks();
		$this->core_update_delete_transients();
	}

	private function setup_helper_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ], 50 );
		add_action( 'delete_site_transient_update_plugins', [ $this, 'delete_plugin_transients' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api_filter' ], 10, 3 );

		//FIXME: Huivoznaiu cum lucreaza dar merge zaibisi, PS. nu are documentatie oficiala
		//remove_action( 'after_plugin_row_' . str_replace( '-', '_', $this->plugin_slug ), 'wp_plugin_update_row' );
		//add_action( 'after_plugin_row_' . str_replace( '-', '_', $this->plugin_slug ), [
		//	$this,
		//	'show_update_notification'
		//], 10, 2 );


		add_filter( "http_request_args", array( $this, "github_request_args" ), 10, 2 );
		add_filter( "upgrader_post_install", array( $this, "github_post_install" ), 10, 3 );
		add_filter( "upgrader_pre_install", array( $this, "github_pre_install" ), 10, 3 );
		//////////add_filter( 'upgrader_post_install', array( $this, "modify_plugin_update_message" ), 10, 4 );

		//add_filter('update_feedback', [$this, 'modify_plugin_update_feedback'], 10, 3);

		add_action( 'upgrader_process_complete', [ $this, 'display_custom_updated_message' ], 10, 2 );


		//add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );


		//FIXME: On future to add api hook to record plugin version updated
		//add_action( 'upgrader_process_complete', function ( $upgrader_object, $hook_extra ) {
		//	$this->clean_get_version_cache( $upgrader_object, $hook_extra );
		//}, 10, 2 );
		/**/
	}

	public function display_custom_updated_message( $upgrader_object, $options ) {
		// Check if it's a plugin update
		if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {
			// Check if it's your plugin being updated
			if ( in_array( 'your-plugin-folder/your-plugin-file.php', $options['plugins'] ) ) {
				// Display custom "Updated!" message
				echo 'Updated plugin with success!';
			}
		}
	}

	public function modify_plugin_update_feedback( $feedback, $upgrader, $hook_extra ) {
		var_dump( $feedback, $upgrader, $hook_extra );
		die();
		if ( $hook_extra['action'] === 'update-plugin' ) {
			// Modify the feedback message
			$feedback['response'] = 'Custom update response message';
			$feedback['error']    = true; // Optionally, set error to true or false based on your needs
		}

		return $feedback;
	}

	public function modify_plugin_update_message( $upgrader_object, $options ) {

		var_dump( $upgrader_object, $options );
		die();
		if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {
			// Specify the plugin slug and the new version number
			$plugin_slug = 'your-plugin-slug';
			$new_version = '1.0.1';

			// Modify the update message for the plugin
			$plugin_data            = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_slug . '/' . $plugin_slug . '.php', false, false );
			$plugin_data['Version'] = $new_version;
			update_option( 'plugin_' . $plugin_slug, $plugin_data );
		}
	}

	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( $this->plugin_real_slug === $plugin_file ) {
			$plugin_slug = $this->plugin_slug;
			$plugin_name = esc_html__( 'Elementor Pro', 'elementor-pro' );

			$row_meta    = [
				'view-details' => sprintf( '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
					esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $this->plugin_slug . '&TB_iframe=true&width=600&height=550' ) ),
					esc_attr( sprintf( esc_html__( 'More information about %s', $this->plugin_text_domain ), $this->plugin_name ) ),
					esc_attr( $this->plugin_name ),
					__( 'View details new', $this->plugin_text_domain )
				),
				//'changelog' => '<a href="https://go.elementor.com/pro-changelog/" title="' . esc_attr( esc_html__( 'View Elementor Pro Changelog', $this->plugin_text_domain ) ) . '" target="_blank">' . esc_html__( 'Changelog', $this->plugin_text_domain ) . '</a>',
			];
			$plugin_meta = array_merge( $plugin_meta, $row_meta );
		}

		return $plugin_meta;
	}

	public function show_update_notification( $file, $plugin ) {
		if ( is_network_admin() ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		if ( ! is_multisite() ) {
			return;
		}


		//if ( $this->plugin_real_slug !== $file ) {
		//	return;
		//}

		// Remove our filter on the site transient
		remove_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );

		$update_cache = get_site_transient( 'update_plugins' );
		$update_cache = $this->check_transient_data( $update_cache );
		set_site_transient( 'update_plugins', $update_cache );

		// Restore our filter
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
	}

	protected function get_transient( $cache_key ) {
		$cache_data = get_option( $cache_key );

		if ( empty( $cache_data['timeout'] ) || current_time( 'timestamp' ) > $cache_data['timeout'] ) {
			// Cache is expired.
			return false;
		}

		return $cache_data['value'];
	}

	protected function set_transient( $cache_key, $value, $expiration = 0 ) {
		if ( empty( $expiration ) ) {
			$expiration = strtotime( '+12 hours', current_time( 'timestamp' ) );
		}

		$data = [
			'timeout' => $expiration,
			'value'   => $value,
		];

		update_option( $cache_key, $data, 'no' );
	}

	private function clean_get_version_cache( $upgrader_object = null, $options = null ): void {
		$transient_cache_key = $this->transient_key_prefix;

		if ( $options['action'] !== 'update' && $options['type'] !== 'plugin' && ! isset( $options['plugins'] ) ) {
			return;
		}
		// Get the plugin file name
		$plugin_slug = $options['plugins'][0];

		// Specify the new version you want to set
		$new_version = '1.2.3';

		// Check if the updated plugin is your plugin
		if ( $plugin_slug === $this->plugin_real_slug ) {//'my-plugin-folder/my-plugin.php'

			// Update the plugin version number in the header information
			$plugin_data               = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_slug );
			$plugin_data['Version']    = $new_version;//$this->plugin_version
			$plugin_data['newVersion'] = $new_version;
			$this_plugin               = array( $plugin_slug => $plugin_data );
			update_option( 'plugin_' . $plugin_slug, $plugin_data );

			//// Update the plugin version number in the readme.txt file
			//$readme_file = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/readme.txt';

			//$readme_content     = file_get_contents( $readme_file );
			//$new_readme_content = preg_replace( '/Stable tag: (.*)/', 'Stable tag: 1.2.0', $readme_content ); // Replace with your new version number
			//file_put_contents( $readme_file, $new_readme_content );
		}

		delete_option( $transient_cache_key );
	}

	public function delete_plugin_transients(): void {
		$this->delete_transient( $this->response_transient_key );
	}

	protected function delete_transient( $transient_cache_key ): void {
		delete_option( $transient_cache_key );
	}

	private function core_update_delete_transients(): void {
		global $pagenow;

		if ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) ) {
			$this->delete_plugin_transients();
		}
	}

	/*
	public function get_slug_name( $slug ) {
		if ( str_contains( $slug, '/' ) ) {
			$pos  = strpos( $slug, '/' );
			$slug = substr( $slug, 0, $pos );
		}

		return $slug;
	}
	*/

	public function check_update( $transient_data ) {
		global $pagenow;

		if ( ! is_object( $transient_data ) ) {
			$transient_data = new \stdClass();
		}

		if ( 'plugins.php' === $pagenow && is_multisite() ) {
			return $transient_data;
		}

		return $this->check_transient_data( $transient_data );
	}


	public function check_transient_data( $transient_data ) {
		if ( ! is_object( $transient_data ) ) {
			$transient_data = new \stdClass();
		}

		//// If we have checked the plugin data before, don't re-check
		//var_dump( $this->plugin_real_slug, $transient_data->checked, $transient_data->checked[ $this->plugin_real_slug ] );
		//die();
		//if ( empty( $transient_data->checked ) || ! isset( $transient_data->checked[ $this->plugin_slug ] ) ) {
		//	var_dump($transient_data);
		//	die();
		//	return $transient_data;
		//}

		// default - don't update the plugin
		//$do_update = 0;

		// Get plugin & GitHub release information
		$remote_version_info = (object) API::get_repo_release_info(
			$this->github_username,
			$this->github_repository,
			$this->github_authorize_token,
			$this->transient_key_prefix,
			false /* Use Cache */,
			$this->check_version_name( $this->plugin_version )
		);

		if ( isset( $_GET['debug'] ) ) {
			echo '<pre>';
			print_r( $transient_data );
		}

		$plugin_info = (object) [
			'id'           => $this->plugin_slug,
			'slug'         => $this->plugin_slug,
			'plugin'       => $this->plugin_real_slug,
			'type'         => 'plugin',
			'new_version'  => $this->check_version_name( $remote_version_info->new_version ?? 0 ),
			'version'      => $this->check_version_name( $this->plugin_version ),
			'url'          => $remote_version_info->website_url ?? '',
			'package'      => $remote_version_info->download_link ?? '',
			'requires'     => $remote_version_info->requires ?? '',
			'tested'       => $remote_version_info->tested ?? '',
			'requires_php' => $remote_version_info->requires_php ?? ''
		];

		if ( version_compare( $this->plugin_version, $plugin_info->new_version, '<' ) ) {
			$transient_data->response[ $this->plugin_real_slug ]              = $plugin_info;
			$transient_data->response[ $this->plugin_real_slug ]->new_version = $plugin_info->new_version;
			$transient_data->checked[ $this->plugin_real_slug ]               = $plugin_info->new_version;
		} else {
			$transient_data->no_update[ $this->plugin_real_slug ] = $plugin_info;
			$transient_data->checked[ $this->plugin_real_slug ]   = $this->plugin_version;
		}

		//echo '<pre>';
		//$plugin = plugin_basename( sanitize_text_field( wp_unslash( $this->plugin_real_slug ) ) );
		//print_r($plugin);
		//echo '</pre>'.PHP_EOL;

		if ( isset( $_GET['debug'] ) ) {
			echo '<br><br><br>';
			print_r( $transient_data );
			die();
		}

		$transient_data->last_checked = current_time( 'timestamp' );

		return $transient_data;
	}

	public function check_version_name( $version ) {
		$arr = [ 'v', 'v.', 'ver', 'ver.' ];

		return str_replace( $arr, '', $version );
	}

	public function plugins_api_filter( $data, $action, $args = null ) {
		if ( 'plugin_information' !== $action ) {
			return $data;
		}

		if ( ! isset( $args->slug ) || ( $args->slug !== $this->plugin_slug ) ) {
			return $data;
		}

		$cache_key = 'remote_api_request_' . substr( md5( serialize( $this->plugin_slug ) ), 0, 15 );

		// Get plugin & GitHub release information
		$api_request_transient = get_site_transient( $cache_key );

		if ( empty( $api_request_transient ) ) {
			$api_response          = (object) API::get_repo_release_info(
				$this->github_username,
				$this->github_repository,
				$this->github_authorize_token,
				$this->transient_key_prefix,
				true,
				$this->check_version_name( $this->plugin_version )
			);
			$api_request_transient = new \stdClass();

			// Add our plugin information
			$api_request_transient->name          = $this->plugin_data['Name'];
			$api_request_transient->slug          = $this->plugin_slug;
			$api_request_transient->last_updated  = $api_response->creation;
			$api_request_transient->plugin_name   = $this->plugin_data["Name"];
			$api_request_transient->author        = $this->plugin_data["AuthorName"];
			$api_request_transient->requires      = $api_response->requires;
			$api_request_transient->tested        = $api_response->tested;
			$api_request_transient->branch        = $api_response->branch;
			$api_request_transient->homepage      = "https://plugins.foxapp.net/" . $this->plugin_slug;
			$api_request_transient->version       = $this->check_version_name( $api_response->new_version );
			$api_request_transient->new_version   = $this->check_version_name( $api_response->new_version );
			$api_request_transient->last_updated  = $api_response->last_updated ?? '';
			$api_request_transient->download_link = $api_response->download_link;

			//FIXME: Fix banners they are from Elementor
			$api_request_transient->banners = [
				'high' => plugins_url( $this->plugin_slug . '/banners/high.png' ),
				'low'  => plugins_url( $this->plugin_slug . '/banners/low.png' ),
			];

			// Create tabs in the lightbox
			$change_log = $api_response->body;

			$matches = null;
			$count   = 0;
			preg_match_all( "/[##|-].*/", $api_response->body, $matches );
			if ( ! empty( $matches ) ) {
				if ( is_array( $matches ) ) {
					if ( count( $matches ) > 0 ) {
						$change_log = '<p>';
						foreach ( $matches[0] as $match ) {
							//Fixes for PHP 8.0
							//if ( str_contains( $match, '##' ) ) {
							if ( strpos( $match, '##' ) !== false ) {
								if ( $count > 0 ) {
									$change_log .= '<br>';
								}
								$count ++;
							}
							$change_log .= $match . '<br>';
						}
						$change_log .= '</p>';
					}
				}
			}

			// Gets the required version of WP if available
			$matches = null;
			preg_match( "/requires:\s([\d\.]+)/i", $this->change_log, $matches );
			if ( ! empty( $matches ) ) {
				if ( is_array( $matches ) ) {
					if ( count( $matches ) > 1 ) {
						$api_request_transient->requires = $matches[1];
					}
				}
			}

			// Gets the tested version of WP if available
			$matches = null;
			preg_match( "/tested:\s([\d\.]+)/i", $this->change_log, $matches );
			if ( ! empty( $matches ) ) {
				if ( is_array( $matches ) ) {
					if ( count( $matches ) > 1 ) {
						$api_request_transient->tested = $matches[1];
					}
				}
			}


			// Create tabs in the lightbox
			$api_request_transient->sections = [
				'Description' => $this->plugin_data["Description"],
				//FIXME: Fix on new release
				//'Updates'     => $api_response->body,
				'Changelog'   => ParseCodeDown::instance()->parse( $change_log )
			];

			//$api_request_transient->sections = unserialize( $api_response['sections']??'' );

			// Expires in 1 day
			//set_site_transient( $cache_key, $api_request_transient, DAY_IN_SECONDS );
			// Set the transient data to enable the update process
			set_site_transient( 'update_plugins', [ $this->plugin_slug => $api_request_transient ] );

		}

		$data = $api_request_transient;

		return $data;
	}

	public function github_post_install( $true, $hook_extra, $result ) {
		// Since we are hosted in GitHub, our plugin folder would have a dirname of
		// reponame-tagname change it to our original one:
		global $wp_filesystem;

		$plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $this->plugin_slug . DIRECTORY_SEPARATOR;
		$wp_filesystem->move( $result['destination'], $plugin_folder );
		$result['destination'] = $plugin_folder;

		// Re-activate plugin if needed
		if ( $this->plugin_activated ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	public function github_pre_install( $true, $args ): void {
		$this->plugin_activated = is_plugin_active( $this->plugin_slug );
	}

	public function github_request_args( $r, $url ) {
		$r['headers'] = array( 'Authorization' => 'Bearer ' . $this->github_authorize_token );

		return $r;
	}

	public function git_repository_is_live() {
		return true;
		/*
		$new_url = $this->host . "/2.0/repositories/" . $this->project_name . "/" . $this->repo;

		$request = wp_remote_get( $new_url, array( 'headers' => $this->get_headers() ) );

		if ( ! is_wp_error( $request ) && $request['response']['code'] == 200 ) {
			return true;
		}

		return false;
		*/
	}

	protected function get_changelog_content( $commit_hash ) {
		//$changelog = wp_remote_get( 'https://bitbucket.org/' . $this->project_name . '/' . $this->repo . '/raw/' . $commit_hash . '/CHANGELOG.md',
		//	array( 'headers' => $this->get_headers() ) );
		//
		//if ( is_wp_error( $changelog ) ) {
		//	return false;
		//}
		//
		//return $changelog['body'];
	}

	/*

	public function get_download_url() {
		return "{$this->download_host}/{$this->project_name}/{$this->repo}/get/{$this->version}.zip";
	}


	public function check_download_url() {
		return "{$this->download_host}/{$this->project_name}/{$this->repo}/get/";
	}

	public function get_tag_url() {
		return "{$this->host}/2.0/repositories/{$this->project_name}/{$this->repo}/refs/tags?sort=-target.date";
	}



	public function set_username($username) {
		$this->username = $username;
	}

	public function set_repository($repository) {
		$this->repository = $repository;
	}
	public function set_plugin_properties() {
		$this->plugin = get_plugin_data($this->file);
		$this->basename = plugin_basename($this->file);
		$this->active = is_plugin_active($this->basename);
	}

	public function authorize($token) {
		$this->authorize_token = $token;
	}

	private function get_repository_info() {
		if (is_null($this->github_response)) {
			$request_uri = sprintf('https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository);

			// Switch to HTTP Basic Authentication for GitHub API v3
			$curl = curl_init();

			curl_setopt_array($curl, [
				CURLOPT_URL => $request_uri,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "GET",
				CURLOPT_HTTPHEADER => [
					'X-GitHub-Api-Version: 2022-11-28',
					'Accept: application/vnd.github+json',
					'Authorization: Bearer '.$this->authorize_token,
					"User-Agent: FoxAppGitHubUpdater/1.2.3",
				]
			]);

			$response = curl_exec($curl);

			curl_close($curl);

			$response = json_decode($response, true);

			if (is_array($response)) {
				$response = current($response);
			}

			if ($this->authorize_token) {
				$response['zipball_url'] = add_query_arg('access_token', $this->authorize_token, $response['zipball_url']);
			}

			$this->github_response = $response;
		}
	}

	public function initialize() {
		var_dump('initialized');
		add_filter('pre_set_site_transient_update_plugins', [$this, 'modify_transient']);//, 10, 1

		add_filter('plugins_api', [$this, 'plugin_popup'], 10, 3);
		add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
	}

	public function modify_transient($transient) {
		echo '<pre>';
		print_r($transient);
		die();
		if (property_exists($transient, 'checked')) {
			if ($checked = $transient->checked) {
				$this->get_repository_info();

				$out_of_date = version_compare($this->github_response['tag_name'], $checked[$this->basename], 'gt');

				if ($out_of_date) {
					$new_files = $this->github_response['zipball_url'];
					$slug = current(explode('/', $this->basename));

					$plugin = [
						'url' => $this->plugin['PluginURI'],
						'slug' => $slug,
						'package' => $new_files,
						'new_version' => $this->github_response['tag_name']
					];

					$transient->response[$this->basename] = (object) $plugin;
				}
			}
		}

		return $transient;
	}

	public function plugin_popup($result, $action, $args) {
		if ($action !== 'plugin_information') {
			return false;
		}

		if (!empty($args->slug)) {
			if ($args->slug == current(explode('/' , $this->basename))) {
				$this->get_repository_info();

				$plugin = [
					'name' => $this->plugin['Name'],
					'slug' => $this->basename,
					'requires' => '5.3',
					'tested' => '5.4',
					'version' => $this->github_response['tag_name'],
					'author' => $this->plugin['AuthorName'],
					'author_profile' => $this->plugin['AuthorURI'],
					'last_updated' => $this->github_response['published_at'],
					'homepage' => $this->plugin['PluginURI'],
					'short_description' => $this->plugin['Description'],
					'sections' => [
						'Description' => $this->plugin['Description'],
						'Updates' => $this->github_response['body'],
					],
					'download_link' => $this->github_response['zipball_url']
				];


				return (object) $plugin;
			}
		}

		return $result;
	}

	public function after_install($response, $hook_extra, $result) {
		global $wp_filesystem;

		$install_directory = plugin_dir_path($this->file);
		$wp_filesystem->move($result['destination'], $install_directory);
		$result['destination'] = $install_directory;

		if ($this->active) {
			activate_plugin($this->basename);
		}

		return $result;
	}
	*/
}