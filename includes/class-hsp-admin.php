<?php
/**
 * Admin bootstrap: menu, assets, high-level settings.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register top-level menu and trigger subpage registration.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Hirany SEO', 'hirany-seo' ),
			__( 'Hirany SEO', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-line',
			59
		);

		add_submenu_page(
			HSP_SLUG,
			__( 'Dashboard', 'hirany-seo' ),
			__( 'Dashboard', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			HSP_SLUG,
			__( 'Settings', 'hirany-seo' ),
			__( 'Settings', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG . '-settings',
			array( $this, 'render_settings' )
		);

		/**
		 * Let feature modules register their own admin pages.
		 *
		 * @param string $parent_slug Parent menu slug.
		 */
		do_action( 'hsp_register_admin_pages', HSP_SLUG );
	}

	/**
	 * Enqueue admin styles/scripts.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, HSP_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'hsp-admin',
			HSP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			HSP_VERSION
		);

		wp_enqueue_script(
			'hsp-admin',
			HSP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			HSP_VERSION,
			true
		);

		wp_localize_script(
			'hsp-admin',
			'HSP_Admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'hsp_admin_nonce' ),
			)
		);
	}

	/**
	 * Render main dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'Hirany SEO Dashboard', 'hirany-seo' ); ?></h1>
			<p><?php esc_html_e( 'Overview of keyword rankings, SEO score, AI traffic and site health.', 'hirany-seo' ); ?></p>
			<div class="hsp-grid">
				<div class="hsp-card">
					<h2><?php esc_html_e( 'SEO Score Snapshot', 'hirany-seo' ); ?></h2>
					<p><?php esc_html_e( 'View average SEO score across your content and identify optimization opportunities.', 'hirany-seo' ); ?></p>
				</div>
				<div class="hsp-card">
					<h2><?php esc_html_e( 'Keyword Rankings', 'hirany-seo' ); ?></h2>
					<p><?php esc_html_e( 'Track up to 50,000 keywords and review 12 months of position history.', 'hirany-seo' ); ?></p>
				</div>
				<div class="hsp-card">
					<h2><?php esc_html_e( 'AI Search Traffic', 'hirany-seo' ); ?></h2>
					<p><?php esc_html_e( 'Monitor traffic coming from ChatGPT, Perplexity, and other AI assistants.', 'hirany-seo' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render global settings.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['hsp_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsp_settings_nonce'] ) ), 'hsp_save_settings' ) ) {
			$content_ai_provider = isset( $_POST['hsp_content_ai_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_content_ai_provider'] ) ) : 'none';
			$content_ai_api_key  = isset( $_POST['hsp_content_ai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_content_ai_api_key'] ) ) : '';
			$content_ai_model    = isset( $_POST['hsp_content_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_content_ai_model'] ) ) : '';

			update_option(
				'hsp_content_ai_settings',
				array(
					'provider' => $content_ai_provider,
					'api_key'  => $content_ai_api_key,
					'model'    => $content_ai_model,
				)
			);

			echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'hirany-seo' ) . '</p></div>';
		}

		$ai_settings = get_option(
			'hsp_content_ai_settings',
			array(
				'provider' => 'none',
				'api_key'  => '',
				'model'    => '',
			)
		);
		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'Hirany SEO Settings', 'hirany-seo' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'hsp_save_settings', 'hsp_settings_nonce' ); ?>
				<h2><?php esc_html_e( 'Content AI', 'hirany-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hsp_content_ai_provider"><?php esc_html_e( 'Provider', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<select name="hsp_content_ai_provider" id="hsp_content_ai_provider">
								<option value="none" <?php selected( $ai_settings['provider'], 'none' ); ?>><?php esc_html_e( 'Disabled', 'hirany-seo' ); ?></option>
								<option value="openai" <?php selected( $ai_settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI compatible', 'hirany-seo' ); ?></option>
								<option value="custom" <?php selected( $ai_settings['provider'], 'custom' ); ?>><?php esc_html_e( 'Custom endpoint', 'hirany-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_content_ai_model"><?php esc_html_e( 'Model Name', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="hsp_content_ai_model" name="hsp_content_ai_model" value="<?php echo esc_attr( $ai_settings['model'] ); ?>" placeholder="gpt-4.1, gpt-4o, etc." />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_content_ai_api_key"><?php esc_html_e( 'API Key', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="password" class="regular-text" id="hsp_content_ai_api_key" name="hsp_content_ai_api_key" value="<?php echo esc_attr( $ai_settings['api_key'] ); ?>" autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Store an API key for an OpenAI-compatible or custom LLM provider. This will be used by the Content AI generator to create SEO-optimized content.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'hirany-seo' ) ); ?>
			</form>
		</div>
		<?php
	}
}

