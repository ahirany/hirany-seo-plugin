<?php
/**
 * Core plugin loader.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class HSP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var HSP_Plugin
	 */
	protected static $instance;

	/**
	 * Loaded modules.
	 *
	 * @var array<string, object>
	 */
	protected $modules = array();

	/**
	 * Get singleton instance.
	 *
	 * @return HSP_Plugin
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->define_constants();
		$this->register_autoloader();
		$this->register_hooks();
	}

	/**
	 * Define additional constants.
	 *
	 * @return void
	 */
	protected function define_constants() {
		if ( ! defined( 'HSP_SLUG' ) ) {
			define( 'HSP_SLUG', 'hirany-seo' );
		}
	}

	/**
	 * Simple class autoloader for HSP_ prefixed classes.
	 *
	 * @return void
	 */
	protected function register_autoloader() {
		spl_autoload_register(
			function ( $class ) {
				if ( 0 !== strpos( $class, 'HSP_' ) ) {
					return;
				}

				$file = strtolower( str_replace( '_', '-', $class ) );
				$path = HSP_PLUGIN_DIR . 'includes/class-' . $file . '.php';

				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		);
	}

	/**
	 * Register core hooks.
	 *
	 * @return void
	 */
	protected function register_hooks() {
		register_activation_hook( HSP_PLUGIN_FILE, array( $this, 'on_activation' ) );
		register_deactivation_hook( HSP_PLUGIN_FILE, array( $this, 'on_deactivation' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_modules' ), 20 );
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public function on_activation() {
		$this->load_textdomain();
		$this->init_modules();

		if ( isset( $this->modules['rank_tracker'] ) && method_exists( $this->modules['rank_tracker'], 'install' ) ) {
			$this->modules['rank_tracker']->install();
		}

		if ( isset( $this->modules['ai_traffic'] ) && method_exists( $this->modules['ai_traffic'], 'install' ) ) {
			$this->modules['ai_traffic']->install();
		}

		if ( isset( $this->modules['sitemaps'] ) && method_exists( $this->modules['sitemaps'], 'install' ) ) {
			$this->modules['sitemaps']->install();
		}

		if ( isset( $this->modules['llms'] ) && method_exists( $this->modules['llms'], 'install' ) ) {
			$this->modules['llms']->install();
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public function on_deactivation() {
		flush_rewrite_rules();
	}

	/**
	 * Load textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'hirany-seo',
			false,
			dirname( plugin_basename( HSP_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Initialise all modules.
	 *
	 * @return void
	 */
	public function init_modules() {
		if ( is_admin() ) {
			$this->modules['admin'] = new HSP_Admin();
		}

		$this->modules['schema']             = new HSP_Schema();
		$this->modules['content_ai']        = new HSP_Content_AI();
		$this->modules['rank_tracker']      = new HSP_Rank_Tracker();
		$this->modules['seo_score']         = new HSP_SEO_Score();
		$this->modules['keyword_optimize']  = new HSP_Keyword_Optimization();
		$this->modules['sitemaps']          = new HSP_Sitemaps();
		$this->modules['llms']              = new HSP_Llms();
		$this->modules['robots']            = new HSP_Robots();
		$this->modules['ai_traffic']        = new HSP_AI_Traffic();
	}

	/**
	 * Get a module instance.
	 *
	 * @param string $key Module key.
	 * @return object|null
	 */
	public function get_module( $key ) {
		return isset( $this->modules[ $key ] ) ? $this->modules[ $key ] : null;
	}
}

