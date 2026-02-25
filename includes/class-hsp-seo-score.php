<?php
/**
 * On-page SEO scoring based on focus keywords and content analysis.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_SEO_Score {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'update_score_on_save' ), 20, 2 );
	}

	/**
	 * Register SEO score meta box.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'hsp_seo_score',
				__( 'Hirany SEO Score', 'hirany-seo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render SEO score meta box.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$score_data = $this->calculate_score( $post );
		$score      = $score_data['score'];
		$grade      = $this->grade_from_score( $score );

		$badge_class = 'hsp-score-badge--bad';

		if ( $score >= 80 ) {
			$badge_class = 'hsp-score-badge--good';
		} elseif ( $score >= 50 ) {
			$badge_class = 'hsp-score-badge--ok';
		}
		?>
		<p>
			<span class="hsp-score-badge <?php echo esc_attr( $badge_class ); ?>">
				<?php echo esc_html( (string) $score ); ?>/100
			</span>
			<strong><?php echo esc_html( $grade ); ?></strong>
		</p>

		<ul class="ul-disc">
			<?php foreach ( $score_data['checks'] as $check ) : ?>
				<li>
					<?php if ( ! empty( $check['passed'] ) ) : ?>
						<strong><?php echo esc_html( $check['label'] ); ?></strong> – <?php esc_html_e( 'OK', 'hirany-seo' ); ?>
					<?php else : ?>
						<strong><?php echo esc_html( $check['label'] ); ?></strong> – <?php esc_html_e( 'Needs improvement', 'hirany-seo' ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="description">
			<?php esc_html_e( 'This score is based on your primary focus keyword and common on-page SEO best practices.', 'hirany-seo' ); ?>
		</p>
		<?php
	}

	/**
	 * Update SEO score when a post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function update_score_on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$score_data = $this->calculate_score( $post );

		update_post_meta( $post_id, '_hsp_seo_score', (int) $score_data['score'] );
		update_post_meta( $post_id, '_hsp_seo_score_breakdown', $score_data['checks'] );
	}

	/**
	 * Calculate a score and breakdown for a given post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array{score:int,checks:array<int,array<string,mixed>>}
	 */
	protected function calculate_score( $post ) {
		$primary_keyword = get_post_meta( $post->ID, '_hsp_focus_keyword', true );

		if ( ! $primary_keyword ) {
			$keywords = get_post_meta( $post->ID, '_hsp_focus_keywords', true );
			if ( is_array( $keywords ) && ! empty( $keywords ) ) {
				$primary_keyword = $keywords[0];
			}
		}

		$primary_keyword = trim( (string) $primary_keyword );

		$checks = array();
		$score  = 0;

		if ( '' === $primary_keyword ) {
			$checks[] = array(
				'key'    => 'focus_keyword',
				'label'  => __( 'Primary focus keyword set', 'hirany-seo' ),
				'passed' => false,
				'points' => 0,
			);

			return array(
				'score'  => 0,
				'checks' => $checks,
			);
		}

		$kw_lower = mb_strtolower( $primary_keyword );
		$title    = get_the_title( $post );
		$slug     = $post->post_name;

		$content_raw  = $post->post_content;
		$content_text = wp_strip_all_tags( $content_raw );
		$content_lc   = mb_strtolower( $content_text );

		$excerpt = get_the_excerpt( $post );
		$excerpt = $excerpt ? $excerpt : $content_text;

		$meta_description = get_post_meta( $post->ID, '_hsp_meta_description', true );
		$meta_description = $meta_description ? $meta_description : $excerpt;

		// Title contains keyword (20 points).
		$title_ok = ( false !== mb_stripos( $title, $primary_keyword ) );
		$checks[] = array(
			'key'    => 'title_keyword',
			'label'  => __( 'Focus keyword in SEO title', 'hirany-seo' ),
			'passed' => $title_ok,
			'points' => $title_ok ? 20 : 0,
		);
		$score   += $title_ok ? 20 : 0;

		// Slug contains keyword (10 points).
		$slug_ok = ( false !== mb_stripos( str_replace( '-', ' ', $slug ), $primary_keyword ) );
		$checks[] = array(
			'key'    => 'slug_keyword',
			'label'  => __( 'Focus keyword in slug (URL)', 'hirany-seo' ),
			'passed' => $slug_ok,
			'points' => $slug_ok ? 10 : 0,
		);
		$score   += $slug_ok ? 10 : 0;

		// Meta description contains keyword (15 points).
		$desc_ok = ( false !== mb_stripos( $meta_description, $primary_keyword ) );
		$checks[] = array(
			'key'    => 'description_keyword',
			'label'  => __( 'Focus keyword in meta description', 'hirany-seo' ),
			'passed' => $desc_ok,
			'points' => $desc_ok ? 15 : 0,
		);
		$score   += $desc_ok ? 15 : 0;

		// First paragraph (approx. first 200 words) contains keyword (15 points).
		$words         = preg_split( '/\s+/', $content_text );
		$first_segment = implode( ' ', array_slice( $words, 0, 200 ) );
		$first_ok      = ( false !== mb_stripos( $first_segment, $primary_keyword ) );

		$checks[] = array(
			'key'    => 'intro_keyword',
			'label'  => __( 'Focus keyword early in the content', 'hirany-seo' ),
			'passed' => $first_ok,
			'points' => $first_ok ? 15 : 0,
		);
		$score   += $first_ok ? 15 : 0;

		// Content length (20 points).
		$word_count = is_array( $words ) ? count( $words ) : 0;
		$length_ok  = ( $word_count >= 300 );
		$length_great = ( $word_count >= 800 );

		$length_points = 0;

		if ( $length_great ) {
			$length_points = 20;
		} elseif ( $length_ok ) {
			$length_points = 15;
		} elseif ( $word_count >= 150 ) {
			$length_points = 10;
		} else {
			$length_points = 0;
		}

		$checks[] = array(
			'key'    => 'content_length',
			'label'  => __( 'Content length (300+ words)', 'hirany-seo' ),
			'passed' => $length_ok,
			'points' => $length_points,
		);
		$score   += $length_points;

		// Headings contain keyword (10 points).
		$headings_ok = false;
		if ( preg_match_all( '/<(h1|h2)[^>]*>(.*?)<\/\1>/is', $content_raw, $matches ) ) {
			foreach ( $matches[2] as $heading_text ) {
				if ( false !== mb_stripos( wp_strip_all_tags( $heading_text ), $primary_keyword ) ) {
					$headings_ok = true;
					break;
				}
			}
		}

		$checks[] = array(
			'key'    => 'heading_keyword',
			'label'  => __( 'Focus keyword in headings (H1/H2)', 'hirany-seo' ),
			'passed' => $headings_ok,
			'points' => $headings_ok ? 10 : 0,
		);
		$score   += $headings_ok ? 10 : 0;

		// Images with alt text containing keyword (10 points).
		$image_ok = false;
		if ( preg_match_all( '/<img[^>]+alt=("|\')(.*?)\1[^>]*>/is', $content_raw, $img_matches ) ) {
			foreach ( $img_matches[2] as $alt_text ) {
				if ( false !== mb_stripos( $alt_text, $primary_keyword ) ) {
					$image_ok = true;
					break;
				}
			}
		}

		$checks[] = array(
			'key'    => 'image_alt',
			'label'  => __( 'Keyword in at least one image ALT text', 'hirany-seo' ),
			'passed' => $image_ok,
			'points' => $image_ok ? 10 : 0,
		);
		$score   += $image_ok ? 10 : 0;

		// Overall keyword density too high (penalty).
		$occurrences = 0;
		if ( $content_lc && $kw_lower ) {
			$occurrences = substr_count( $content_lc, $kw_lower );
		}

		$density_penalty = 0;
		if ( $word_count > 0 ) {
			$density = ( $occurrences / $word_count ) * 100;
			if ( $density > 5 ) {
				$density_penalty = -10;
			}
		}

		$checks[] = array(
			'key'    => 'density',
			'label'  => __( 'Keyword density not excessive', 'hirany-seo' ),
			'passed' => ( 0 === $density_penalty ),
			'points' => 10 + $density_penalty,
		);

		$score += 10 + $density_penalty;

		// Normalise final score.
		$score = max( 0, min( 100, (int) $score ) );

		return array(
			'score'  => $score,
			'checks' => $checks,
		);
	}

	/**
	 * Human-friendly grade from numeric score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	protected function grade_from_score( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'hirany-seo' );
		}
		if ( $score >= 70 ) {
			return __( 'Good', 'hirany-seo' );
		}
		if ( $score >= 50 ) {
			return __( 'Fair', 'hirany-seo' );
		}
		if ( $score >= 30 ) {
			return __( 'Poor', 'hirany-seo' );
		}

		return __( 'Very poor', 'hirany-seo' );
	}
}

