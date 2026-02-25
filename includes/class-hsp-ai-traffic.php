<?php
/**
 * AI search traffic tracker.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_AI_Traffic {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Known AI referrer host patterns.
	 *
	 * @var string[]
	 */
	protected $ai_hosts = array(
		'chat.openai.com',
		'chatgpt.com',
		'poe.com',
		'perplexity.ai',
		'claude.ai',
		'bard.google.com',
		'gemini.google.com',
		'copilot.microsoft.com',
		'you.com',
		'duckduckgo.com', // DuckAssist / AI answers.
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->table = $wpdb->prefix . 'hsp_ai_traffic';

		add_action( 'template_redirect', array( $this, 'maybe_log_visit' ), 5 );
		add_action( 'hsp_register_admin_pages', array( $this, 'register_admin_page' ) );
	}

	/**
	 * Install DB table.
	 *
	 * @return void
	 */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visit_date DATE NOT NULL,
			referrer_host VARCHAR(191) NOT NULL,
			landing_path TEXT NOT NULL,
			user_agent TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY visit_date_idx (visit_date),
			KEY host_idx (referrer_host(100))
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Attempt to log a visit if referred from an AI assistant.
	 *
	 * @return void
	 */
	public function maybe_log_visit() {
		if ( is_admin() || is_feed() || is_trackback() ) {
			return;
		}

		if ( is_404() ) {
			return;
		}

		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return;
		}

		$referrer = wp_unslash( $_SERVER['HTTP_REFERER'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$host     = wp_parse_url( $referrer, PHP_URL_HOST );

		if ( ! $host ) {
			return;
		}

		$host = strtolower( (string) $host );

		if ( ! $this->is_ai_referrer( $host ) ) {
			return;
		}

		global $wpdb;

		$path       = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		$data = array(
			'visit_date'    => gmdate( 'Y-m-d' ),
			'referrer_host' => substr( $host, 0, 191 ),
			'landing_path'  => $path,
			'user_agent'    => $user_agent,
			'created_at'    => current_time( 'mysql', 1 ),
		);

		/**
		 * Filter AI traffic row before insert.
		 *
		 * @param array<string,mixed> $data Row data.
		 */
		$data = apply_filters( 'hsp_ai_traffic_row', $data );

		$wpdb->insert(
			$this->table,
			$data,
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Check if a host is considered AI traffic.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	protected function is_ai_referrer( $host ) {
		$ai_hosts = apply_filters( 'hsp_ai_traffic_hosts', $this->ai_hosts );

		foreach ( $ai_hosts as $ai_host ) {
			$ai_host = strtolower( trim( (string) $ai_host ) );

			if ( '' === $ai_host ) {
				continue;
			}

			if ( $host === $ai_host || str_ends_with( $host, '.' . $ai_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register admin page.
	 *
	 * @param string $parent_slug Parent slug.
	 * @return void
	 */
	public function register_admin_page( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'AI Search Traffic', 'hirany-seo' ),
			__( 'AI Traffic', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG . '-ai-traffic',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render AI traffic dashboard.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $days < 1 ) {
			$days = 30;
		}
		if ( $days > 365 ) {
			$days = 365;
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		$by_day = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visit_date, COUNT(*) AS visits
				 FROM {$this->table}
				 WHERE visit_date >= %s
				 GROUP BY visit_date
				 ORDER BY visit_date DESC",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$by_host = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_host, COUNT(*) AS visits
				 FROM {$this->table}
				 WHERE visit_date >= %s
				 GROUP BY referrer_host
				 ORDER BY visits DESC
				 LIMIT 50",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$recent = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visit_date, referrer_host, landing_path, created_at
				 FROM {$this->table}
				 WHERE visit_date >= %s
				 ORDER BY created_at DESC
				 LIMIT 50",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'AI Search Traffic', 'hirany-seo' ); ?></h1>
			<p>
				<?php esc_html_e( 'Track visits from AI search agents like ChatGPT, Perplexity, Claude, and others.', 'hirany-seo' ); ?>
			</p>

			<form method="get" style="margin-bottom: 16px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( HSP_SLUG . '-ai-traffic' ); ?>" />
				<label for="hsp_ai_days">
					<?php esc_html_e( 'Period (days):', 'hirany-seo' ); ?>
				</label>
				<input type="number" id="hsp_ai_days" name="days" value="<?php echo esc_attr( (string) $days ); ?>" min="7" max="365" />
				<?php submit_button( __( 'Apply', 'hirany-seo' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Visits over time', 'hirany-seo' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'AI visits', 'hirany-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $by_day ) ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( 'No AI-driven traffic recorded yet.', 'hirany-seo' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $by_day as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->visit_date ); ?></td>
								<td><?php echo esc_html( (string) $row->visits ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Top AI referrers', 'hirany-seo' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Referrer host', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Visits', 'hirany-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $by_host ) ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( 'No AI referrers detected yet.', 'hirany-seo' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $by_host as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->referrer_host ); ?></td>
								<td><?php echo esc_html( (string) $row->visits ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent AI visits', 'hirany-seo' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Landing URL', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Time', 'hirany-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No recent visits recorded.', 'hirany-seo' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->visit_date ); ?></td>
								<td><?php echo esc_html( $row->referrer_host ); ?></td>
								<td>
									<a href="<?php echo esc_url( home_url( $row->landing_path ) ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $row->landing_path ); ?>
									</a>
								</td>
								<td><?php echo esc_html( mysql2date( get_option( 'time_format' ), $row->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

