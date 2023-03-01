<?php
/*
@link https://plugins.foxapp.net/
Plugin Name: FoxApp GitHub Update Helper
Plugin URI: https://plugins.foxapp.net/foxapp-github-update-helper
Description: With this plugin, developers can keep information about their projects privately on the GitHub up to date.
Version: 1.0.4
Author: FoxApp
Author URI: https://plugins.foxapp.net/
Requires at least: 6.0
Requires PHP: >= 7.4
Text Domain: foxapp-github-update-helper
Domain Path: /languages
*/

//Autoload Plugin Files
require_once(plugin_dir_path(__FILE__) . 'Lib/autoload.php');

new FoxApp\GitHub\Init(__FILE__);