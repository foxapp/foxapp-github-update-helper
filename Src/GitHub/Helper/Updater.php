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

		//GitHub Settings for Remote Request
		$this->github_username        = $plugin_file['github_username'];
		$this->github_repository      = $plugin_file['github_repository'];
		$this->github_authorize_token = $plugin_file['github_authorize_token'];

		//Helper Settings
		$this->response_transient_key = md5( sanitize_key( $this->plugin_slug ) . 'response_transient' );

		$this->change_log = '';

		$this->setup_helper_hooks();
		$this->core_update_delete_transients();

		return false;
	}

	private function setup_helper_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ], 50 );
		add_action( 'delete_site_transient_update_plugins', [ $this, 'delete_plugin_transients' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api_filter' ], 10, 3 );

		//FIXME: Huivoznaiu cum lucreaza dar merge zaibisi, PS. nu are documentatie oficiala
		remove_action( 'after_plugin_row_' . str_replace( '-', '_', $this->plugin_slug ), 'wp_plugin_update_row' );
		add_action( 'after_plugin_row_' . str_replace( '-', '_', $this->plugin_slug ), [
			$this,
			'show_update_notification'
		], 10, 2 );

		add_filter( "upgrader_post_install", array( $this, "github_post_install" ), 10, 3 );
		add_filter( "upgrader_pre_install", array( $this, "github_pre_install" ), 10, 3 );
		add_filter( "http_request_args", array( $this, "github_request_args" ), 10, 2 );

		add_action( 'update_option_WPLANG', function () {
			$this->clean_get_version_cache();
		} );

		add_action( 'upgrader_process_complete', function () {
			$this->clean_get_version_cache();
		} );
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

		//var_dump($this->plugin_real_slug, $file);die();
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

	private function clean_get_version_cache(): void {
		$transient_cache_key = $this->transient_key_prefix;

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
		//var_dump( $transient_data->checked, $transient_data->checked[ $this->plugin_slug ] );
		//die();
		//if ( empty( $transient_data->checked ) || ! isset( $transient_data->checked[ $this->plugin_slug ] ) ) {
		//	var_dump($transient_data);
		//	die();
		//	return $transient_data;
		//}

		// default - don't update the plugin
		$do_update = 0;

		// Get plugin & GitHub release information
		$remote_version_info = (object) API::get_repo_release_info(
			$this->github_username,
			$this->github_repository,
			$this->github_authorize_token,
			$this->transient_key_prefix,
			false /* Use Cache */
		);

		if ( isset( $_GET['debug'] ) ) {
			echo '<pre>';
			print_r( $transient_data );
		}

		$plugin_info = (object) [
			'id'           => $this->plugin_slug,
			'slug'         => $this->plugin_slug,
			'new_version'  => $this->check_version_name( $remote_version_info->new_version ?? 0 ),
			'version'      => $this->check_version_name( $this->plugin_version ),
			'url'          => $remote_version_info->website_url ?? '',
			'package'      => $remote_version_info->download_link ?? '',
			'requires'     => $remote_version_info->requires ?? '',
			'tested'       => $remote_version_info->tested ?? '',
			'requires_php' => $remote_version_info->requires_php ?? '',
		];

		if ( version_compare( $this->plugin_version, $plugin_info->new_version, '<' ) ) {
			$transient_data->response[ $this->plugin_real_slug ] = $plugin_info;
			$transient_data->checked[ $this->plugin_real_slug ]  = $plugin_info->new_version;
		} else {
			$transient_data->no_update[ $this->plugin_real_slug ] = $plugin_info;
			$transient_data->checked[ $this->plugin_real_slug ]   = $this->plugin_version;
		}

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

		// Get plugin & GitHub release information

		//$this->get_repo_release_info();

		$cache_key = 'remote_api_request_' . substr( md5( serialize( $this->plugin_slug ) ), 0, 15 );

		$api_request_transient = get_site_transient( $cache_key );

		if ( empty( $api_request_transient ) ) {
			$api_response = (object) API::get_repo_release_info(
				$this->github_username,
				$this->github_repository,
				$this->github_authorize_token,
				$this->transient_key_prefix
			);

			//var_dump($api_response);

			$api_request_transient = new \stdClass();

			// Add our plugin information
			$api_request_transient->name         = $this->plugin_data['Name'];
			$api_request_transient->slug         = $this->plugin_slug;
			$api_request_transient->last_updated = $api_response->creation;
			$api_request_transient->plugin_name  = $this->plugin_data["Name"];
			$api_request_transient->author       = $this->plugin_data["AuthorName"];
			$api_request_transient->requires     = $api_response->requires;
			$api_request_transient->tested       = $api_response->tested;
			$api_request_transient->branch       = $api_response->branch;
			$api_request_transient->homepage     = "https://plugins.foxapp.net/" . $this->plugin_slug;


			$api_request_transient->version       = $this->check_version_name( $api_response->new_version );
			$api_request_transient->last_updated  = $api_response->last_updated ?? '';
			$api_request_transient->download_link = $api_response->download_link;

			//FIXME: Fix banners they are from Elementor
			$api_request_transient->banners = [
				'high' => 'https://www.foxblog.net/plugins/fox-app-github-update-helper/assets/banner-1544x500.png',
				'low'  => 'https://www.foxblog.net/plugins/fox-app-github-update-helper/assets/banner-1544x500.png',
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
							if ( strpos($match, '##') !== false ) {
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
			set_site_transient( $cache_key, $api_request_transient, DAY_IN_SECONDS );

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