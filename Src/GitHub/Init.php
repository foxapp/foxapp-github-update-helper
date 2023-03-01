<?php

namespace FoxApp\GitHub;

use FoxApp\GitHub\Helper\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

//Define constant for current plugin
define( 'FOX_APP_GITHUB_UPDATE_HELPER', __return_true() );
define( 'FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN', 'foxapp-github-update-helper' );

class Init {
	public string $processed_plugin_file;
	public string $parent_page_slug;
	public string $parent_page_real_slug;
	public string $plugin_identifier;
	public string $transient_key_prefix;

	/*

	private static $instance;

	static function GetInstance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
	*/
	public string $github_updater_username_option_key;
	public string $github_updater_authorize_token_option_key;
	public string $github_updater_repository_option_key;
	public string $github_updater_enabled_option_key;

	public function __construct( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		if(!defined('FOX_APP_GITHUB_UPDATE_HELPER_VERSION'))
			define( 'FOX_APP_GITHUB_UPDATE_HELPER_VERSION', get_plugin_data( $file )["Version"] );

		//define( 'FOX_APP_GITHUB_UPDATE_HELPER_PLUGIN_FILE', $file );
		//define( 'FOX_APP_GITHUB_UPDATE_HELPER_PLUGIN_SLUG', basename( $file, ".php" ) );
		//define( 'FOX_APP_GITHUB_UPDATE_HELPER_PLUGIN_REAL_SLUG', plugin_basename( $file ) );

		//Define variables for current plugin


		$this->processed_plugin_file = $file;
		$this->parent_page_slug      = basename( $file, ".php" );
		$this->parent_page_real_slug = plugin_basename( $file );


		//Notify users to use only private repositories
		if ( isset( $_GET['page'] ) && $_GET['page'] === $this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) {
			add_action( 'admin_notices', [ $this, 'admin_notice_use_private_repositories_only' ] );
		}

		//Notify users when options was saved
		if ( isset( $_GET['page'] ) && $_GET['page'] === $this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) {
			add_action( 'admin_notices_saved_options', [ $this, 'admin_notice_github_update_helper_settings_saved' ] );
		}

		add_action( 'admin_menu', [ $this, 'plugin_settings' ] );

		//include_once plugin_dir_path( $this->processed_plugin_file ) . 'fox-app-github-update-helper/GitHub/Helper/Updater.php';

		$this->plugin_identifier    = md5( $this->parent_page_slug );
		$this->transient_key_prefix = 'fox_app_github_update_helper_remote_info_api_data_' . $this->plugin_identifier;

		$this->github_updater_username_option_key        = 'github_updater_username_' . $this->plugin_identifier;
		$this->github_updater_authorize_token_option_key = 'github_updater_authorize_token_' . $this->plugin_identifier;
		$this->github_updater_repository_option_key      = 'github_updater_repository_' . $this->plugin_identifier;
		$this->github_updater_enabled_option_key         = 'github_updater_enabled_' . $this->plugin_identifier;

		if ( get_option( $this->github_updater_enabled_option_key ) ) {
			$github_helper_plugin = array(
				'plugin_file'            => $this->processed_plugin_file,
				'plugin_slug'            => $this->parent_page_slug,
				'plugin_real_slug'       => $this->parent_page_real_slug,
				'transient_key_prefix'   => $this->transient_key_prefix,
				'github_host'            => 'https://api.github.com',
				'github_username'        => get_option( $this->github_updater_username_option_key ),
				'github_authorize_token' => get_option( $this->github_updater_authorize_token_option_key ),
				'github_repository'      => get_option( $this->github_updater_repository_option_key )
			);

			new Updater( $github_helper_plugin );
		}
	}

	public function plugin_settings(): void {

		if ( $this->parent_page_slug === FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) {
			add_menu_page(
				__( 'GitHub Update Helper Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ),
				__( 'GitHub Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ),
				'manage_options',
				$this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN,
				array( $this, 'render_plugin_settings_administration' ),
			);
		}

		add_submenu_page(
			$this->parent_page_slug,
			__( 'GitHub Update Helper Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ),
			__( 'GitHub Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ),
			'manage_options',
			$this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN,
			array( $this, 'render_plugin_settings_administration' ),
			100
		);


		add_filter( 'plugin_action_links_' . $this->parent_page_real_slug, [ $this, 'github_helper_settings_link' ] );
		add_filter( 'plugin_row_meta', [ $this, 'github_helper_additional_link' ], 10, 4 );

		//include_once plugin_dir_path( $this->processed_plugin_file ) . 'fox-app-github-update-helper/GitHub/Helper/Updater.php';
	}

	function github_helper_settings_link( $links_array ) {

		array_unshift( $links_array,'<a href="https://plugins.local/wp-admin/admin.php?page=' . $this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN . '">' . __( 'GitHub Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) . '</a>');
        array_unshift( $links_array,'<a href="https://plugins.local/wp-admin/admin.php?page=' . $this->parent_page_slug .'">' . __( 'Settings', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) . '</a>');

        $links_array[] = '<a href="https://plugins.foxapp.net/' . $this->parent_page_slug . '/faq">' . __( 'FAQ', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) . '</a>';

		return $links_array;
	}

	function github_helper_additional_link( $plugin_meta, $plugin_file_name, $plugin_data, $status ) {
		if ( $this->parent_page_real_slug === $plugin_file_name ) {
			$row_meta    = [
				'changelog' => '<a href="" title="' . esc_attr( esc_html__( 'GitHub Update Helper Changelog', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) ) . '" target="_blank">' . esc_html__( 'Changelog', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) . '</a>',
			];
			$plugin_meta = array_merge( $plugin_meta, $row_meta );
		}

		return $plugin_meta;
	}

	public function admin_notice_use_private_repositories_only(): void {
		$key_transient = 'use-private-repository-only-' . $this->plugin_identifier;
		$notice        = get_transient( $key_transient );

		if ( ! $notice ) {
			$class   = 'notice notice-warning';
			$notify  = __( 'Attention!', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN );
			$message = __( 'Please be informed this plugin works with <strong>private</strong> repository only!', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN );

			$notice = sprintf( '<div class="%1$s"><p><strong>%2$s</strong> %3$s</p></div>', esc_attr( $class ), esc_html( $notify ), $message );

			set_transient( $key_transient, $notice, 60 * 60 );
			print $notice;
		}
	}

	public function admin_notice_github_update_helper_settings_saved(): void {
		$class   = 'notice notice-success';
		$notify  = __( 'Options saved!', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN );
		$message = __( 'Settings are stored with success.', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN );

		printf( '<div class="%1$s"><p><strong>%2$s</strong> %3$s</p></div>', esc_attr( $class ), esc_html( $notify ), esc_html( $message ) );
	}

	public function render_plugin_settings_administration(): void {
		//FIXME: Next Release: Add theme domain

		//load_theme_textdomain( FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN, __DIR__ . '/languages' );
		if ( isset( $_GET['page'] ) && $_GET['page'] === $this->parent_page_slug . '/' . FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) {
			?>
            <div class="wrap">
                <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            </div>
			<?php
			$this->save_plugin_settings_administration();
		}
	}

	public function save_plugin_settings_administration(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['action'] ) && $_POST['action'] === 'github_update_helper_settings' ) {
			update_option(
				$this->github_updater_username_option_key,
				sanitize_text_field( $_POST['github_updater_username'] ?? '' ),
				'yes'
			);
			update_option(
				$this->github_updater_authorize_token_option_key,
				sanitize_text_field( $_POST['github_updater_authorize_token'] ?? '' ),
				'yes'
			);
			update_option(
				$this->github_updater_repository_option_key,
				sanitize_text_field( $_POST['github_updater_repository'] ?? '' ),
				'yes'
			);
			update_option(
				$this->github_updater_enabled_option_key,
				sanitize_key( $_POST['github_updater_enabled'] ?? 0 ),
				'yes'
			);

			do_action( 'admin_notices_saved_options' );
		}

		//Get plugin settings from database
		$github_updater_username        = get_option( $this->github_updater_username_option_key ) ?? '';
		$github_updater_authorize_token = get_option( $this->github_updater_authorize_token_option_key ) ?? '';
		$github_updater_repository      = get_option( $this->github_updater_repository_option_key ) ?? '';
		$github_updater_enabled         = get_option( $this->github_updater_enabled_option_key ) ?? 0;
		?>

        <form method="post" action="">
            <input type="hidden" name="action" value="github_update_helper_settings">
            <div style="display:flex">
                <table class="form-table settings" style="float:left;width:50%">
                    <tbody>
                    <tr class="github_updater_enabled">
                        <td scope="row"><label
                                    for="github_updater_enabled"><?php echo __( 'Enable', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ); ?></label>
                        </td>
                        <td><input type="checkbox"
                                   id="github_updater_enabled"
                                   name="github_updater_enabled"
                                   value="1" <?php checked( $github_updater_enabled, 1 ) ?>
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr class="github_updater_username">
                        <td scope="row">
                            <label for="github_updater_username"><?php echo __( 'GitHub Username', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ); ?></label>
                        </td>
                        <td><input type="text"
                                   id="github_updater_username"
                                   name="github_updater_username"
                                   value="<?php echo $github_updater_username ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr class="github_updater_repository">
                        <td scope="row">
                            <label for="github_updater_repository"><?php echo __( 'GitHub Repository Name', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ); ?></label>
                        </td>
                        <td><input type="text"
                                   id="github_updater_repository"
                                   name="github_updater_repository"
                                   value="<?php echo $github_updater_repository ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr class="github_updater_authorize_token">
                        <td scope="row">
                            <label for="github_updater_authorize_token"><?php echo __( 'GitHub Authorize Token', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ); ?></label>
                        </td>
                        <td><input type="text"
                                   id="github_updater_authorize_token"
                                   name="github_updater_authorize_token"
                                   value="<?php echo $github_updater_authorize_token ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    </tbody>
                </table>
            </div>
            <div style="clear:both"></div>
            <input type="submit"
                   class="button-primary"
                   style="margin-top:40px"
                   value="<?php echo __( 'Save options', FOX_APP_GITHUB_UPDATE_HELPER_DOMAIN ) ?>"/>
        </form>
		<?php
	}
}