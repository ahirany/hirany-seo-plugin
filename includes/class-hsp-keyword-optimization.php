<?php
/**
 * Unlimited keyword optimization: focus keywords and meta tags.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Keyword_Optimization {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title' ), 20 );
		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 1 );
	}

	/**
	 * Get supported post types.
	 *
	 * @return string[]
	 */
	protected function get_supported_post_types() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		/**
		 * Filter supported post types for keyword optimization.
		 *
		 * @param string[] $post_types Post types.
		 */
		return apply_filters( 'hsp_keyword_post_types', array_values( $post_types ) );
	}

	/**
	 * Register meta box.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		foreach ( $this->get_supported_post_types() as $post_type ) {
			add_meta_box(
				'hsp_keywords',
				__( 'Hirany SEO – Keywords & Meta', 'hirany-seo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render meta box content.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'hsp_keywords_meta', 'hsp_keywords_meta_nonce' );

		$keywords      = get_post_meta( $post->ID, '_hsp_focus_keywords', true );
		$keywords      = is_array( $keywords ) ? $keywords : array();
		$keywords_text = implode( "\n", $keywords );

		$meta_title       = get_post_meta( $post->ID, '_hsp_meta_title', true );
		$meta_description = get_post_meta( $post->ID, '_hsp_meta_description', true );
		?>
		<p>
			<label for="hsp_focus_keywords"><strong><?php esc_html_e( 'Focus keywords (one per line)', 'hirany-seo' ); ?></strong></label>
			<textarea id="hsp_focus_keywords" name="hsp_focus_keywords" rows="4" style="width:100%;"><?php echo esc_textarea( $keywords_text ); ?></textarea>
			<span class="description">
				<?php esc_html_e( 'You can add as many keywords as you like for this content. The first keyword is treated as the primary focus keyword.', 'hirany-seo' ); ?>
			</span>
		</p>

		<hr />

		<p>
			<label for="hsp_meta_title"><strong><?php esc_html_e( 'SEO title', 'hirany-seo' ); ?></strong></label>
			<input type="text" id="hsp_meta_title" name="hsp_meta_title" class="widefat" value="<?php echo esc_attr( $meta_title ); ?>" />
			<span class="description">
				<?php esc_html_e( 'Optional custom SEO title. If left empty, WordPress will use the default post title.', 'hirany-seo' ); ?>
			</span>
		</p>

		<p>
			<label for="hsp_meta_description"><strong><?php esc_html_e( 'Meta description', 'hirany-seo' ); ?></strong></label>
			<textarea id="hsp_meta_description" name="hsp_meta_description" rows="3" style="width:100%;"><?php echo esc_textarea( $meta_description ); ?></textarea>
			<span class="description">
				<?php esc_html_e( 'Write a compelling summary for search results. Aim for 120–160 characters including your primary focus keyword.', 'hirany-seo' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['hsp_keywords_meta_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['hsp_keywords_meta_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'hsp_keywords_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['post_type'] ) ) {
			return;
		}

		$post_type = sanitize_text_field( wp_unslash( $_POST['post_type'] ) );

		if ( ! in_array( $post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		if ( 'page' === $post_type ) {
			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return;
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
		}

		$keywords      = isset( $_POST['hsp_focus_keywords'] ) ? wp_unslash( $_POST['hsp_focus_keywords'] ) : '';
		$meta_title    = isset( $_POST['hsp_meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_meta_title'] ) ) : '';
		$meta_desc_raw = isset( $_POST['hsp_meta_description'] ) ? wp_unslash( $_POST['hsp_meta_description'] ) : '';

		$list = array();

		if ( is_string( $keywords ) && '' !== trim( $keywords ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $keywords );

			foreach ( $lines as $line ) {
				$line = trim( wp_strip_all_tags( $line ) );

				if ( '' === $line ) {
					continue;
				}

				$list[] = $line;
			}
		}

		update_post_meta( $post_id, '_hsp_focus_keywords', $list );

		// Maintain backward-compatible single keyword meta for other modules (Content AI, scoring, etc.).
		if ( ! empty( $list ) ) {
			update_post_meta( $post_id, '_hsp_focus_keyword', $list[0] );
		} else {
			delete_post_meta( $post_id, '_hsp_focus_keyword' );
		}

		update_post_meta( $post_id, '_hsp_meta_title', $meta_title );

		$meta_description = sanitize_textarea_field( $meta_desc_raw );

		update_post_meta( $post_id, '_hsp_meta_description', $meta_description );
	}

	/**
	 * Override the document title if a custom SEO title is set.
	 *
	 * @param array<string,string> $title_parts Title parts.
	 * @return array<string,string>
	 */
	public function filter_document_title( $title_parts ) {
		if ( is_admin() || ! is_singular() ) {
			return $title_parts;
		}

		$post = get_queried_object();

		if ( ! $post || empty( $post->ID ) ) {
			return $title_parts;
		}

		$meta_title = get_post_meta( $post->ID, '_hsp_meta_title', true );

		if ( ! $meta_title ) {
			return $title_parts;
		}

		$title_parts['title'] = wp_strip_all_tags( $meta_title );

		return $title_parts;
	}

	/**
	 * Output meta description tag for singular content.
	 *
	 * @return void
	 */
	public function output_meta_tags() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post || empty( $post->ID ) ) {
			return;
		}

		$meta_description = get_post_meta( $post->ID, '_hsp_meta_description', true );

		if ( ! $meta_description ) {
			return;
		}

		$meta_description = trim( wp_strip_all_tags( $meta_description ) );

		if ( '' === $meta_description ) {
			return;
		}

		printf(
			"<meta name=\"description\" content=\"%s\" />\n",
			esc_attr( $meta_description )
		);
	}
}

