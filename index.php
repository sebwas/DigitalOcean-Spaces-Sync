<?php
/**
 * Plugin Name: DigitalOcean Spaces Sync
 * Plugin URI: https://github.com/sebwas/DO-Spaces-Wordpress-Sync
 * Description: This WordPress plugin syncs your media library with DigitalOcean Spaces Container.
 * Version: 0.0.1
 * Author: Sebastian Wasser
 * Author URI: https://github.com/sebwas
 * License: MIT
 * Text Domain: dos
 * Domain Path: /languages
 */
require __DIR__ . '/vendor/autoload.php';

load_plugin_textdomain('dos', false, dirname(plugin_basename(__FILE__)) . '/lang');

function dos_incompatible($msg): void {
	require_once ABSPATH . '/wp-admin/includes/plugin.php';

	deactivate_plugins(__FILE__);

	wp_die($msg);
}

if (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
	if (version_compare(PHP_VERSION, '7.0.0', '<')) {
		dos_incompatible(
			__(
				'Plugin DigitalOcean Spaces Sync requires PHP 7.0.0 or higher. The plugin has now disabled itself.',
				'dos'
			)
		);
	} elseif (!function_exists('curl_version')
		|| !($curl = curl_version())
		|| empty($curl['version'])
		|| version_compare($curl['version'], '7.16.2', '<')
	) {
		dos_incompatible(
			__('Plugin DigitalOcean Spaces Sync requires cURL 7.16.2+. The plugin has now disabled itself.', 'dos')
		);
	} elseif (empty($curl['features']) || !($curl['features'] & CURL_VERSION_SSL)) {
		dos_incompatible(
			__(
				'Plugin DigitalOcean Spaces Sync requires that cURL is compiled with OpenSSL. The plugin has now disabled itself.',
				'dos'
			)
		);
	}
}

$instance = Dos\Plugin::getInstance();
$instance->setup();
