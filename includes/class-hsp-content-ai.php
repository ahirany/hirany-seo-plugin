<?php
/**
 * Content AI generator: create SEO-optimized content with an LLM.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Content_AI {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_hsp_generate_content_ai', array( $this, 'handle_generate_request' ) );
	}

	/**
	 * Register Content AI meta box on supported post types.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		/**
		 * Filter which post types expose the Content AI meta box.
		 *
		 * @param string[] $post_types Post types.
		 */
		$post_types = apply_filters( 'hsp_content_ai_post_types', $post_types );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'hsp_content_ai',
				__( 'Hirany Content AI', 'hirany-seo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the Content AI meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'hsp_content_ai_meta', 'hsp_content_ai_meta_nonce' );

		$focus_keyword = get_post_meta( $post->ID, '_hsp_focus_keyword', true );
		?>
		<p>
			<label for="hsp_content_ai_focus_keyword"><strong><?php esc_html_e( 'Focus keyword', 'hirany-seo' ); ?></strong></label><br />
			<input type="text" id="hsp_content_ai_focus_keyword" name="hsp_content_ai_focus_keyword" class="widefat" value="<?php echo esc_attr( $focus_keyword ); ?>" />
		</p>
		<p>
			<label for="hsp_content_ai_prompt"><strong><?php esc_html_e( 'Prompt for AI', 'hirany-seo' ); ?></strong></label>
			<textarea id="hsp_content_ai_prompt" class="widefat" rows="4" placeholder="<?php esc_attr_e( 'e.g. Write an SEO-optimized introduction for this post.', 'hirany-seo' ); ?>"></textarea>
		</p>
		<p>
			<button type="button" class="button button-primary" id="hsp_content_ai_generate">
				<?php esc_html_e( 'Generate content', 'hirany-seo' ); ?>
			</button>
		</p>
		<p>
			<label for="hsp_content_ai_output"><strong><?php esc_html_e( 'AI output (copy into your content)', 'hirany-seo' ); ?></strong></label>
			<textarea id="hsp_content_ai_output" class="widefat" rows="6" readonly="readonly"></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Use Content AI to draft titles, meta descriptions, or paragraphs optimized for your focus keyword. Review and edit before publishing.', 'hirany-seo' ); ?>
		</p>
		<?php
	}

	/**
	 * Enqueue editor scripts for Content AI.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'hsp-content-ai',
			HSP_PLUGIN_URL . 'assets/js/content-ai.js',
			array( 'jquery' ),
			HSP_VERSION,
			true
		);

		wp_localize_script(
			'hsp-content-ai',
			'HSP_Content_AI',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'hsp_content_ai_nonce' ),
			)
		);
	}

	/**
	 * Handle AJAX request to generate content.
	 *
	 * @return void
	 */
	public function handle_generate_request() {
		check_ajax_referer( 'hsp_content_ai_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to use Content AI.', 'hirany-seo' ),
				),
				403
			);
		}

		$prompt        = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$focus_keyword = isset( $_POST['focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) : '';
		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $prompt ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a prompt for Content AI.', 'hirany-seo' ),
				),
				400
			);
		}

		if ( $post_id && $focus_keyword ) {
			update_post_meta( $post_id, '_hsp_focus_keyword', $focus_keyword );
		}

		$response = $this->generate_text( $prompt, $focus_keyword );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'text' => $response,
			)
		);
	}

	/**
	 * Perform an LLM API call to generate text.
	 *
	 * @param string $prompt        User prompt.
	 * @param string $focus_keyword Optional focus keyword.
	 * @return string|\WP_Error
	 */
	protected function generate_text( $prompt, $focus_keyword = '' ) {
		$settings = get_option(
			'hsp_content_ai_settings',
			array(
				'provider' => 'none',
				'api_key'  => '',
				'model'    => '',
				'api_url'  => '',
			)
		);

		if ( empty( $settings['api_key'] ) || empty( $settings['model'] ) || 'none' === $settings['provider'] ) {
			return new WP_Error(
				'hsp_content_ai_not_configured',
				__( 'Content AI is not yet configured. Please add an API key and model under Hirany SEO → Settings.', 'hirany-seo' )
			);
		}

		$system_message = __(
			'You are an SEO assistant. Generate concise, high-quality content optimized for the given focus keyword. Write in a natural, human tone and avoid keyword stuffing.',
			'hirany-seo'
		);

		if ( $focus_keyword ) {
			$prompt = sprintf(
				/* translators: 1: focus keyword, 2: original prompt */
				__( 'Focus keyword: %1$s. %2$s', 'hirany-seo' ),
				$focus_keyword,
				$prompt
			);
		}

		$body = array(
			'model'    => $settings['model'],
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $system_message,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$api_url = 'https://api.openai.com/v1/chat/completions';
		if ( ! empty( $settings['api_url'] ) ) {
			$api_url = rtrim( $settings['api_url'], '/' );
		}
		if ( 'custom' === $settings['provider'] && empty( $api_url ) ) {
			return new WP_Error(
				'hsp_content_ai_no_url',
				__( 'Content AI is set to "Custom endpoint" but no API URL was provided. Add it under Hirany SEO → Settings.', 'hirany-seo' )
			);
		}

		/**
		 * Filter the request arguments before the Content AI LLM call.
		 *
		 * This is especially useful if you want to route requests through a custom
		 * OpenAI-compatible gateway or different provider.
		 *
		 * @param array  $request_args Arguments passed to wp_remote_post.
		 * @param array  $body         Request JSON body.
		 * @param string $api_url      API URL.
		 */
		$request_args = apply_filters(
			'hsp_content_ai_request_args',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
				),
				'timeout' => 30,
				'body'    => wp_json_encode( $body ),
			),
			$body,
			$api_url
		);

		$response = wp_remote_post( $api_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->build_api_error_message( $code, $body, $data );
			return new WP_Error( 'hsp_content_ai_bad_response', $message );
		}

		if ( ! is_array( $data ) ) {
			$message = $this->build_api_error_message( $code, $body, null );
			return new WP_Error( 'hsp_content_ai_bad_response', $message );
		}

		if ( isset( $data['error']['message'] ) ) {
			return new WP_Error( 'hsp_content_ai_api_error', $data['error']['message'] );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'hsp_content_ai_empty',
				__( 'The Content AI response was empty. Try a more specific prompt.', 'hirany-seo' )
			);
		}

		return trim( wp_kses_post( $data['choices'][0]['message']['content'] ) );
	}

	/**
	 * Build a user-friendly error message from API response.
	 *
	 * @param int         $code HTTP status code.
	 * @param string      $body Raw response body.
	 * @param array|null  $data Parsed JSON (if any).
	 * @return string
	 */
	protected function build_api_error_message( $code, $body, $data ) {
		$msg = sprintf(
			/* translators: 1: HTTP status code. */
			__( 'Content AI returned HTTP %1$d.', 'hirany-seo' ),
			$code
		);

		if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
			$msg .= ' ' . trim( wp_strip_all_tags( (string) $data['error']['message'] ) );
			return $msg;
		}

		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			$msg .= ' ' . trim( wp_strip_all_tags( (string) $data['message'] ) );
			return $msg;
		}

		$body_trim = trim( wp_strip_all_tags( $body ) );
		if ( $body_trim !== '' ) {
			$snippet = wp_html_excerpt( $body_trim, 120 );
			$msg   .= ' ' . sprintf(
				/* translators: %s: short snippet of response. */
				__( 'Response: %s', 'hirany-seo' ),
				$snippet
			);
		} else {
			$msg .= ' ' . __( 'Please check your API key and that the endpoint URL is correct (Hirany SEO → Settings).', 'hirany-seo' );
		}

		return $msg;
	}
}

