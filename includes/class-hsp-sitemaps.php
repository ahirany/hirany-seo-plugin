<?php
/**
 * Automated XML sitemaps.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Sitemaps {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_sitemap_request' ) );
		add_action( 'hsp_register_admin_pages', array( $this, 'register_admin_page' ) );
	}

	/**
	 * Install hook.
	 *
	 * @return void
	 */
	public function install() {
		$this->register_rewrites();
		flush_rewrite_rules();
	}

	/**
	 * Register custom sitemap rewrite.
	 *
	 * @return void
	 */
	public function register_rewrites() {
		add_rewrite_rule( '^hsp-sitemap\.xml$', 'index.php?hsp_sitemap=1', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'hsp_sitemap';
		return $vars;
	}

	/**
	 * Get settings.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings() {
		$defaults = array(
			'enabled'      => 1,
			'post_types'   => array( 'post', 'page' ),
			'include_home' => 1,
		);

		$settings = get_option( 'hsp_sitemaps_settings', array() );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Handle sitemap request.
	 *
	 * @return void
	 */
	public function handle_sitemap_request() {
		if ( ! get_query_var( 'hsp_sitemap' ) ) {
			return;
		}

		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ), true );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		// Homepage.
		if ( ! empty( $settings['include_home'] ) ) {
			$this->output_url_entry(
				home_url( '/' ),
				get_lastpostmodified( 'GMT' ),
				'daily',
				1.0
			);
		}

		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		/**
		 * Filter which post types appear in the sitemap.
		 *
		 * @param string[] $post_types Post types.
		 */
		$post_types = apply_filters( 'hsp_sitemaps_post_types', $post_types );

		$args = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => 1000,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$url     = get_permalink();
				$lastmod = get_the_modified_date( DATE_W3C );

				$this->output_url_entry( $url, $lastmod, 'weekly', 0.6 );
			}

			wp_reset_postdata();
		}

		echo '</urlset>';
		exit;
	}

	/**
	 * Output a single URL entry.
	 *
	 * @param string      $loc     URL.
	 * @param string|null $lastmod Last modified date (W3C).
	 * @param string      $changefreq Change frequency.
	 * @param float       $priority Priority.
	 * @return void
	 */
	protected function output_url_entry( $loc, $lastmod = null, $changefreq = 'weekly', $priority = 0.5 ) {
		echo '<url>' . "\n";
		echo '<loc>' . esc_url( $loc ) . '</loc>' . "\n";

		if ( $lastmod ) {
			echo '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' . "\n";
		}

		echo '<changefreq>' . esc_html( $changefreq ) . '</changefreq>' . "\n";
		echo '<priority>' . esc_html( number_format( (float) $priority, 1 ) ) . '</priority>' . "\n";
		echo '</url>' . "\n";
	}

	/**
	 * Register Sitemaps admin page.
	 *
	 * @param string $parent_slug Parent slug.
	 * @return void
	 */
	public function register_admin_page( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'SEO Sitemaps', 'hirany-seo' ),
			__( 'Sitemaps', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG . '-sitemaps',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render Sitemaps settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['hsp_sitemaps_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsp_sitemaps_settings_nonce'] ) ), 'hsp_save_sitemaps_settings' ) ) {
			$enabled      = isset( $_POST['hsp_sitemaps_enabled'] ) ? 1 : 0;
			$include_home = isset( $_POST['hsp_sitemaps_include_home'] ) ? 1 : 0;
			$post_types   = isset( $_POST['hsp_sitemaps_post_types'] ) ? (array) wp_unslash( $_POST['hsp_sitemaps_post_types'] ) : array( 'post', 'page' );
			$post_types   = array_map( 'sanitize_text_field', $post_types );

			update_option(
				'hsp_sitemaps_settings',
				array(
					'enabled'      => $enabled,
					'post_types'   => $post_types,
					'include_home' => $include_home,
				)
			);

			echo '<div class="updated"><p>' . esc_html__( 'Sitemap settings saved.', 'hirany-seo' ) . '</p></div>';
		}

		$settings    = $this->get_settings();
		$public_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $public_types['attachment'] );
		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'SEO Sitemaps', 'hirany-seo' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: sitemap URL. */
					esc_html__( 'Your XML sitemap URL is: %s', 'hirany-seo' ),
					'<code>' . esc_html( home_url( '/hsp-sitemap.xml' ) ) . '</code>'
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'hsp_save_sitemaps_settings', 'hsp_sitemaps_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hsp_sitemaps_enabled"><?php esc_html_e( 'Enable sitemap', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="hsp_sitemaps_enabled" name="hsp_sitemaps_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<span class="description">
								<?php esc_html_e( 'Generate an XML sitemap dedicated to Hirany SEO.', 'hirany-seo' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'hirany-seo' ); ?></th>
						<td>
							<?php foreach ( $public_types as $type ) : ?>
								<label>
									<input type="checkbox" name="hsp_sitemaps_post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $type ); ?>
								</label><br />
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Choose which post types should be included.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_sitemaps_include_home"><?php esc_html_e( 'Include homepage', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="checkbox" id="hsp_sitemaps_include_home" name="hsp_sitemaps_include_home" value="1" <?php checked( ! empty( $settings['include_home'] ) ); ?> />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'hirany-seo' ) ); ?>
			</form>
		</div>
		<?php
	}
}

