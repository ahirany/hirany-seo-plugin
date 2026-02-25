<?php
/**
 * Plugin Name: Hirany SEO Plugin
 * Plugin URI: https://example.com/hirany-seo-plugin
 * Description: All-in-one SEO suite similar to RankMath: keyword tracking, schema, content AI, SEO scoring, sitemaps, llms.txt, robots.txt, and AI traffic analytics.
 * Version: 0.1.0
 * Author: Hirany
 * Author URI: https://example.com
 * Text Domain: hirany-seo
 * Domain Path: /languages
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HSP_VERSION', '0.1.0' );
define( 'HSP_PLUGIN_FILE', __FILE__ );
define( 'HSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'HSP_Plugin' ) ) {
	require_once HSP_PLUGIN_DIR . 'includes/class-hsp-plugin.php';
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function hsp_init_plugin() {
	\HSP_Plugin::instance();
}

add_action( 'plugins_loaded', 'hsp_init_plugin' );

