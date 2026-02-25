<?php
/**
 * Schema generator: post-level schema selection and JSON-LD output.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Schema {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_action( 'wp_head', array( $this, 'output_schema' ), 20 );
		add_action( 'hsp_register_admin_pages', array( $this, 'register_admin_page' ) );
	}

	/**
	 * Post types that should expose schema controls.
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
		 * Filter supported post types for schema controls.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return apply_filters( 'hsp_schema_post_types', array_values( $post_types ) );
	}

	/**
	 * Register the schema meta box.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$supported = $this->get_supported_post_types();

		foreach ( $supported as $post_type ) {
			add_meta_box(
				'hsp_schema',
				__( 'Hirany SEO Schema', 'hirany-seo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render schema settings meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'hsp_schema_meta', 'hsp_schema_meta_nonce' );

		$schema_type   = get_post_meta( $post->ID, '_hsp_schema_type', true );
		$schema_type   = $schema_type ? $schema_type : 'auto';
		$custom_json   = get_post_meta( $post->ID, '_hsp_schema_custom_json', true );
		$faq_raw       = get_post_meta( $post->ID, '_hsp_schema_faq_raw', true );
		$faq_raw       = $faq_raw ? $faq_raw : '';
		$available_map = $this->get_schema_type_options();
		?>
		<p>
			<label for="hsp_schema_type"><strong><?php esc_html_e( 'Schema Type', 'hirany-seo' ); ?></strong></label><br />
			<select name="hsp_schema_type" id="hsp_schema_type">
				<?php foreach ( $available_map as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $schema_type, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="description">
			<?php esc_html_e( 'Auto chooses the best type based on the post type (Article for posts and pages). Use Custom JSON-LD if you want full control.', 'hirany-seo' ); ?>
		</p>

		<hr />

		<h4><?php esc_html_e( 'FAQ (for FAQPage schema)', 'hirany-seo' ); ?></h4>
		<p>
			<textarea name="hsp_schema_faq_raw" id="hsp_schema_faq_raw" rows="6" style="width:100%;"><?php echo esc_textarea( $faq_raw ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'One question-answer pair per line using the format: Question ?| Answer', 'hirany-seo' ); ?>
		</p>

		<hr />

		<h4><?php esc_html_e( 'Custom JSON-LD', 'hirany-seo' ); ?></h4>
		<p>
			<textarea name="hsp_schema_custom_json" id="hsp_schema_custom_json" rows="8" style="width:100%; font-family:monospace;"><?php echo esc_textarea( $custom_json ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Paste your own JSON-LD here (without surrounding &lt;script&gt; tags). This is used when Schema Type is set to "Custom JSON-LD".', 'hirany-seo' ); ?>
		</p>
		<?php
	}

	/**
	 * Available schema type options.
	 *
	 * @return array<string,string>
	 */
	protected function get_schema_type_options() {
		$options = array(
			'auto'        => __( 'Auto (recommended)', 'hirany-seo' ),
			'article'     => __( 'Article / BlogPosting', 'hirany-seo' ),
			'product'     => __( 'Product', 'hirany-seo' ),
			'faq'         => __( 'FAQPage', 'hirany-seo' ),
			'custom_json' => __( 'Custom JSON-LD', 'hirany-seo' ),
			'none'        => __( 'Disable schema for this content', 'hirany-seo' ),
		);

		/**
		 * Filter available schema type options.
		 *
		 * @param array<string,string> $options Schema type map.
		 */
		return apply_filters( 'hsp_schema_type_options', $options );
	}

	/**
	 * Save schema meta box fields.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['hsp_schema_meta_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['hsp_schema_meta_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'hsp_schema_meta' ) ) {
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

		$schema_type = isset( $_POST['hsp_schema_type'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_schema_type'] ) ) : 'auto';
		$custom_json = isset( $_POST['hsp_schema_custom_json'] ) ? wp_kses_post( wp_unslash( $_POST['hsp_schema_custom_json'] ) ) : '';
		$faq_raw     = isset( $_POST['hsp_schema_faq_raw'] ) ? wp_kses_post( wp_unslash( $_POST['hsp_schema_faq_raw'] ) ) : '';

		update_post_meta( $post_id, '_hsp_schema_type', $schema_type );
		update_post_meta( $post_id, '_hsp_schema_custom_json', $custom_json );
		update_post_meta( $post_id, '_hsp_schema_faq_raw', $faq_raw );
	}

	/**
	 * Register Schema admin page.
	 *
	 * @param string $parent_slug Parent menu slug.
	 * @return void
	 */
	public function register_admin_page( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Schema Generator', 'hirany-seo' ),
			__( 'Schema Generator', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG . '-schema',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render Schema admin settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['hsp_schema_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsp_schema_settings_nonce'] ) ), 'hsp_save_schema_settings' ) ) {
			$enabled_post_types = isset( $_POST['hsp_schema_enabled_post_types'] ) ? (array) $_POST['hsp_schema_enabled_post_types'] : array();
			$enabled_post_types = array_map( 'sanitize_text_field', wp_unslash( $enabled_post_types ) );

			update_option(
				'hsp_schema_settings',
				array(
					'enabled_post_types' => $enabled_post_types,
				)
			);

			echo '<div class="updated"><p>' . esc_html__( 'Schema settings saved.', 'hirany-seo' ) . '</p></div>';
		}

		$settings      = get_option(
			'hsp_schema_settings',
			array(
				'enabled_post_types' => $this->get_supported_post_types(),
			)
		);
		$enabled_types = isset( $settings['enabled_post_types'] ) ? (array) $settings['enabled_post_types'] : array();
		$all_types     = $this->get_supported_post_types();
		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'Schema Generator Settings', 'hirany-seo' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'hsp_save_schema_settings', 'hsp_schema_settings_nonce' ); ?>
				<h2><?php esc_html_e( 'Enabled Content Types', 'hirany-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'hirany-seo' ); ?></th>
						<td>
							<?php foreach ( $all_types as $post_type ) : ?>
								<label>
									<input
										type="checkbox"
										name="hsp_schema_enabled_post_types[]"
										value="<?php echo esc_attr( $post_type ); ?>"
										<?php checked( in_array( $post_type, $enabled_types, true ) ); ?>
									/>
									<?php echo esc_html( $post_type ); ?>
								</label><br />
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Choose which post types should display the Hirany SEO schema meta box.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'hirany-seo' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Output JSON-LD schema for the current singular content.
	 *
	 * @return void
	 */
	public function output_schema() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post || empty( $post->ID ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		$settings      = get_option(
			'hsp_schema_settings',
			array(
				'enabled_post_types' => $this->get_supported_post_types(),
			)
		);
		$enabled_types = isset( $settings['enabled_post_types'] ) ? (array) $settings['enabled_post_types'] : $this->get_supported_post_types();

		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		$schema_type = get_post_meta( $post->ID, '_hsp_schema_type', true );
		$schema_type = $schema_type ? $schema_type : 'auto';

		if ( 'none' === $schema_type ) {
			return;
		}

		if ( 'custom_json' === $schema_type ) {
			$custom_json = get_post_meta( $post->ID, '_hsp_schema_custom_json', true );
			$custom_json = trim( $custom_json );

			if ( '' === $custom_json ) {
				return;
			}

			/**
			 * Filter raw custom JSON-LD before output.
			 *
			 * @param string  $custom_json Raw JSON-LD string.
			 * @param WP_Post $post        Current post object.
			 */
			$custom_json = apply_filters( 'hsp_schema_custom_json', $custom_json, $post );

			echo '<script type="application/ld+json">' . $custom_json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		if ( 'auto' === $schema_type ) {
			$schema_type = $this->guess_schema_type_for_post( $post );
		}

		$graph = $this->build_graph_for_post( $post, $schema_type );

		if ( empty( $graph ) ) {
			return;
		}

		/**
		 * Filter the generated schema graph.
		 *
		 * @param array   $graph       Schema graph array.
		 * @param WP_Post $post        Current post.
		 * @param string  $schema_type Final schema type.
		 */
		$graph = apply_filters( 'hsp_schema_graph', $graph, $post, $schema_type );

		if ( empty( $graph ) ) {
			return;
		}

		$json = wp_json_encode(
			$graph,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( ! $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Guess a reasonable schema type for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function guess_schema_type_for_post( $post ) {
		if ( 'post' === $post->post_type || 'page' === $post->post_type ) {
			return 'article';
		}

		return 'article';
	}

	/**
	 * Build schema graph array for a given post and type.
	 *
	 * @param WP_Post $post        Post.
	 * @param string  $schema_type Schema type key.
	 * @return array<string,mixed>|null
	 */
	protected function build_graph_for_post( $post, $schema_type ) {
		$schema_type = $schema_type ? $schema_type : 'article';

		switch ( $schema_type ) {
			case 'faq':
				return $this->build_faq_schema( $post );
			case 'product':
				return $this->build_product_schema( $post );
			case 'article':
			default:
				return $this->build_article_schema( $post );
		}
	}

	/**
	 * Article / BlogPosting schema.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	protected function build_article_schema( $post ) {
		$author_name = get_the_author_meta( 'display_name', $post->post_author );
		$image_id    = get_post_thumbnail_id( $post->ID );
		$image_url   = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'Article',
			'headline'        => get_the_title( $post ),
			'description'     => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'mainEntityOfPage'=> get_permalink( $post ),
			'datePublished'   => get_the_date( DATE_W3C, $post ),
			'dateModified'    => get_the_modified_date( DATE_W3C, $post ),
			'author'          => array(
				'@type' => 'Person',
				'name'  => $author_name,
			),
		);

		if ( $image_url ) {
			$schema['image'] = array( $image_url );
		}

		return $schema;
	}

	/**
	 * FAQPage schema based on stored Q&A pairs.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>|null
	 */
	protected function build_faq_schema( $post ) {
		$faq_raw = get_post_meta( $post->ID, '_hsp_schema_faq_raw', true );

		if ( ! $faq_raw ) {
			return null;
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $faq_raw );
		$items   = array();
		$counter = 0;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = explode( '?|', $line );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$question = trim( $parts[0] );
			$answer   = trim( $parts[1] );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$items[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);

			$counter++;

			if ( $counter >= 100 ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $items,
		);
	}

	/**
	 * Simple Product schema.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	protected function build_product_schema( $post ) {
		$image_id  = get_post_thumbnail_id( $post->ID );
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => get_the_title( $post ),
			'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'url'         => get_permalink( $post ),
		);

		if ( $image_url ) {
			$schema['image'] = array( $image_url );
		}

		return $schema;
	}
}

