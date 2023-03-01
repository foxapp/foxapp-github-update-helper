<?php

namespace FoxApp\GitHub\Helper;


use FoxApp\GitHub\Init;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class API extends Init {

	// Requests lock config.
	const REQUEST_LOCK_TTL = 0;//MINUTE_IN_SECONDS
	const REQUEST_LOCK_OPTION_NAME = 'remote_api_requests_lock';
	public static array $transient_data = [];

	public function __construct( $file ) {
		parent::__construct( $file );
	}

	public static function get_repo_release_info( $remote_username, $remote_repository, $remote_key, $transient_key, $force_check = true ): \WP_Error|array {
		$transient_cache_key = $transient_key . FOX_APP_GITHUB_UPDATE_HELPER_VERSION;
		$current_info_data   = get_transient( $transient_cache_key );

		if ( $force_check || false === $current_info_data ) {

			$remote_url = sprintf(
				'https://api.github.com/repos/%s/%s/releases',
				$remote_username,
				$remote_repository
			);

			if ( self::is_request_running( 'get_repo_release_info', md5($remote_repository) ) ) {
				return new \WP_Error( esc_html__( 'Another check is in progress.', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) );
			}

			$current_info_data = self::remote_request( $remote_url, $remote_key );

			self::set_transient( $transient_cache_key, $current_info_data );
		}

		return $current_info_data;
	}

	public static function set_transient( $cache_key, $value, $expiration = '+12 hours' ) {
		$data = [
			'timeout' => strtotime( $expiration, current_time( 'timestamp' ) ),
			'value'   => json_encode( $value ),
		];

		$updated = update_option( $cache_key, $data, false );
		if ( false === $updated ) {
			self::$transient_data[ $cache_key ] = $data;
		}
	}

	public static function is_request_running( $name , $dynamic_key) {
		$requests_lock = get_option( self::REQUEST_LOCK_OPTION_NAME.$dynamic_key, [] );

		if ( isset( $requests_lock[ $name.$dynamic_key ] ) ) {
			if ( $requests_lock[ $name.$dynamic_key ] > time() - self::REQUEST_LOCK_TTL ) {
				return true;
			}
		}

		$requests_lock[ $name.$dynamic_key ] = time();
		update_option( self::REQUEST_LOCK_OPTION_NAME.$dynamic_key, $requests_lock );

		return false;
	}

	private static function remote_request( $remote_url, $authorize_token ): \WP_Error|array {

		$args = [
			'headers'   => [
				'X-GitHub-Api-Version' => '2022-11-28',
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $authorize_token,
				'User-Agent'           => 'FoxAppGitHubUpdater/1.2.3',
			],
			'redirects' => 10
		];;
		$response = wp_remote_get( $remote_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $response_code ) {
			return new \WP_Error( $response_code, esc_html__( 'HTTP Error', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error( 'no_json', esc_html__( 'An error occurred, please try again', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) );
		}

		$response = wp_remote_retrieve_body( $response );
		$response = json_decode( $response );

		//Retrieve last Release if they are many
		if ( is_array( $response ) ) {
			$response = current( $response );
			$response = json_decode( json_encode( $response ), true );
		}

		$new_response = [];

		$new_response['new_version']   = self::check_version_name($response['tag_name']);
		$new_response['creation']      = date( 'Y-m-d H:i:s', strtotime( $response['published_at'] ) );
		$new_response['download_link'] = add_query_arg( 'access_token', $authorize_token, $response['zipball_url'] );
		$new_response['branch']        = $response['target_commitish'];

		//FIXME:: Add this feature on next release
		$new_response['website_url']  = $response['html_url'];
		$new_response['type']         = 'github-plugin';
		$new_response['body']         = $response['body'];
		$new_response['requires']     = '6.0';
		$new_response['tested']       = '6.1.1';
		$new_response['requires_php'] = '7.0';

		return $new_response;
	}

	public static function check_version_name( $version ): array|string {
		$arr = [ 'v', 'v.', 'ver', 'ver.' ];

		return str_replace( $arr, '', $version );
	}

	private static function get_transient( $cache_key ) {
		$cache = self::$transient_data[ $cache_key ] ?? get_option( $cache_key );

		if ( empty( $cache['timeout'] ) ) {
			return false;
		}

		if ( current_time( 'timestamp' ) > $cache['timeout'] && is_user_logged_in() ) {
			return false;
		}

		return json_decode( $cache['value'], true );
	}
}
