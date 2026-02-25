<?php
/**
 * Keyword rank tracker: store keywords and 12-month position history.
 *
 * @package Hirany_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HSP_Rank_Tracker {

	/**
	 * Keywords table name.
	 *
	 * @var string
	 */
	protected $table_keywords;

	/**
	 * Rank history table name.
	 *
	 * @var string
	 */
	protected $table_ranks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->table_keywords = $wpdb->prefix . 'hsp_keywords';
		$this->table_ranks    = $wpdb->prefix . 'hsp_keyword_ranks';

		add_action( 'hsp_register_admin_pages', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'hsp_rank_tracker_run', array( $this, 'cron_run' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}

	/**
	 * Create database tables and schedule cron on activation.
	 *
	 * @return void
	 */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql_keywords = "CREATE TABLE {$this->table_keywords} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL,
			target_url TEXT NULL,
			search_engine VARCHAR(50) NOT NULL DEFAULT 'google.com',
			location VARCHAR(191) DEFAULT '',
			device VARCHAR(20) DEFAULT 'desktop',
			active TINYINT(1) NOT NULL DEFAULT 1,
			last_position INT DEFAULT NULL,
			best_position INT DEFAULT NULL,
			last_checked_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY keyword_idx (keyword(191)),
			KEY active_idx (active),
			KEY last_checked_idx (last_checked_at)
		) $charset_collate;";

		$sql_ranks = "CREATE TABLE {$this->table_ranks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword_id BIGINT UNSIGNED NOT NULL,
			checked_date DATE NOT NULL,
			position INT DEFAULT NULL,
			url_found TEXT NULL,
			PRIMARY KEY (id),
			KEY keyword_date_idx (keyword_id, checked_date),
			KEY position_idx (position)
		) $charset_collate;";

		dbDelta( $sql_keywords );
		dbDelta( $sql_ranks );

		// Schedule cron if not already scheduled.
		if ( ! wp_next_scheduled( 'hsp_rank_tracker_run' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hsp_hourly_light', 'hsp_rank_tracker_run' );
		}
	}

	/**
	 * Remove scheduled cron on plugin deactivation (optional; caller responsibility).
	 *
	 * @return void
	 */
	public function clear_scheduled_events() {
		wp_clear_scheduled_hook( 'hsp_rank_tracker_run' );
	}

	/**
	 * Add a light hourly schedule so we can spread out keyword checks.
	 *
	 * @param array<string,array<string,int|string>> $schedules Existing schedules.
	 * @return array<string,array<string,int|string>>
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['hsp_hourly_light'] ) ) {
			$schedules['hsp_hourly_light'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Hirany SEO hourly (light)', 'hirany-seo' ),
			);
		}

		return $schedules;
	}

	/**
	 * Register Rank Tracker admin page.
	 *
	 * @param string $parent_slug Parent slug.
	 * @return void
	 */
	public function register_admin_page( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			__( 'Rank Tracker', 'hirany-seo' ),
			__( 'Rank Tracker', 'hirany-seo' ),
			'manage_options',
			HSP_SLUG . '-rank-tracker',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue any admin assets.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, HSP_SLUG . '_page_' . HSP_SLUG . '-rank-tracker' ) ) {
			return;
		}

		wp_enqueue_style(
			'hsp-rank-tracker',
			HSP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			HSP_VERSION
		);
	}

	/**
	 * Get settings for the rank tracker.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings() {
		$defaults = array(
			'provider'    => 'none', // none|serpapi|custom.
			'api_key'     => '',
			'custom_url'  => '',
			'location'    => '',
			'language'    => 'en',
			'daily_limit' => 1000,
			'batch_size'  => 100,
		);

		$settings = get_option( 'hsp_rank_tracker_settings', array() );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Render admin UI for managing keywords and settings.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		// Handle settings save.
		if ( isset( $_POST['hsp_rank_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsp_rank_settings_nonce'] ) ), 'hsp_save_rank_settings' ) ) {
			$provider    = isset( $_POST['hsp_rank_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_rank_provider'] ) ) : 'none';
			$api_key     = isset( $_POST['hsp_rank_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_rank_api_key'] ) ) : '';
			$custom_url  = isset( $_POST['hsp_rank_custom_url'] ) ? esc_url_raw( wp_unslash( $_POST['hsp_rank_custom_url'] ) ) : '';
			$location    = isset( $_POST['hsp_rank_location'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_rank_location'] ) ) : '';
			$language    = isset( $_POST['hsp_rank_language'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_rank_language'] ) ) : 'en';
			$daily_limit = isset( $_POST['hsp_rank_daily_limit'] ) ? absint( $_POST['hsp_rank_daily_limit'] ) : 1000;
			$batch_size  = isset( $_POST['hsp_rank_batch_size'] ) ? absint( $_POST['hsp_rank_batch_size'] ) : 100;

			update_option(
				'hsp_rank_tracker_settings',
				array(
					'provider'    => $provider,
					'api_key'     => $api_key,
					'custom_url'  => $custom_url,
					'location'    => $location,
					'language'    => $language,
					'daily_limit' => $daily_limit,
					'batch_size'  => $batch_size,
				)
			);

			echo '<div class="updated"><p>' . esc_html__( 'Rank Tracker settings saved.', 'hirany-seo' ) . '</p></div>';
		}

		// Handle keyword add.
		if ( isset( $_POST['hsp_rank_add_keywords_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hsp_rank_add_keywords_nonce'] ) ), 'hsp_rank_add_keywords' ) ) {
			$keywords_raw = isset( $_POST['hsp_rank_keywords'] ) ? wp_unslash( $_POST['hsp_rank_keywords'] ) : '';
			$target_url   = isset( $_POST['hsp_rank_target_url'] ) ? esc_url_raw( wp_unslash( $_POST['hsp_rank_target_url'] ) ) : '';
			$engine       = isset( $_POST['hsp_rank_engine'] ) ? sanitize_text_field( wp_unslash( $_POST['hsp_rank_engine'] ) ) : 'google.com';

			$keywords     = preg_split( '/\r\n|\r|\n/', (string) $keywords_raw );
			$now          = current_time( 'mysql' );
			$inserted     = 0;

			foreach ( $keywords as $keyword ) {
				$keyword = trim( wp_strip_all_tags( $keyword ) );

				if ( '' === $keyword ) {
					continue;
				}

				$wpdb->insert(
					$this->table_keywords,
					array(
						'keyword'       => $keyword,
						'target_url'    => $target_url,
						'search_engine' => $engine,
						'created_at'    => $now,
						'active'        => 1,
					),
					array(
						'%s',
						'%s',
						'%s',
						'%s',
						'%d',
					)
				);

				if ( ! $wpdb->insert_id ) {
					continue;
				}

				$inserted++;
			}

			if ( $inserted > 0 ) {
				/* translators: %d: number of keywords added. */
				printf( '<div class="updated"><p>%s</p></div>', esc_html( sprintf( _n( 'Added %d keyword.', 'Added %d keywords.', $inserted, 'hirany-seo' ), $inserted ) ) );
			}
		}

		$settings = $this->get_settings();

		// Fetch a page of keywords for display.
		$limit  = 100;
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = ( $paged - 1 ) * $limit;

		$total_keywords = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_keywords}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_keywords} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$total_pages = $total_keywords > 0 ? ceil( $total_keywords / $limit ) : 1;
		?>
		<div class="wrap hsp-wrap">
			<h1><?php esc_html_e( 'Keyword Rank Tracker', 'hirany-seo' ); ?></h1>
			<p><?php esc_html_e( 'Track up to 50,000 keywords and monitor position history for the trailing 12 months.', 'hirany-seo' ); ?></p>

			<h2><?php esc_html_e( 'Rank Tracker Settings', 'hirany-seo' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'hsp_save_rank_settings', 'hsp_rank_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hsp_rank_provider"><?php esc_html_e( 'Provider', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<select name="hsp_rank_provider" id="hsp_rank_provider">
								<option value="none" <?php selected( $settings['provider'], 'none' ); ?>><?php esc_html_e( 'Disabled', 'hirany-seo' ); ?></option>
								<option value="serpapi" <?php selected( $settings['provider'], 'serpapi' ); ?>><?php esc_html_e( 'SerpAPI (Google)', 'hirany-seo' ); ?></option>
								<option value="custom" <?php selected( $settings['provider'], 'custom' ); ?>><?php esc_html_e( 'Custom JSON endpoint', 'hirany-seo' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Use a SERP provider to automatically fetch positions. When disabled, you can still manage keywords and import data manually via custom code.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_api_key"><?php esc_html_e( 'API Key', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="password" class="regular-text" id="hsp_rank_api_key" name="hsp_rank_api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_custom_url"><?php esc_html_e( 'Custom endpoint URL', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="url" class="regular-text" id="hsp_rank_custom_url" name="hsp_rank_custom_url" value="<?php echo esc_attr( $settings['custom_url'] ); ?>" placeholder="https://example.com/rank-endpoint" />
							<p class="description">
								<?php esc_html_e( 'For the "Custom JSON endpoint" provider, this URL should accept POST requests and return {"position": <int>, "url_found": "<url>"} for a given keyword.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_location"><?php esc_html_e( 'Location', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="hsp_rank_location" name="hsp_rank_location" value="<?php echo esc_attr( $settings['location'] ); ?>" placeholder="United States" />
							<p class="description">
								<?php esc_html_e( 'Optional default location (e.g. "United States", "London,England,United Kingdom"). Used by some providers like SerpAPI.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_language"><?php esc_html_e( 'Language', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="hsp_rank_language" name="hsp_rank_language" value="<?php echo esc_attr( $settings['language'] ); ?>" placeholder="en" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_daily_limit"><?php esc_html_e( 'Daily API limit', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" id="hsp_rank_daily_limit" name="hsp_rank_daily_limit" value="<?php echo esc_attr( (string) $settings['daily_limit'] ); ?>" min="1" />
							<p class="description">
								<?php esc_html_e( 'Maximum number of rank checks per day. The scheduler will respect this limit to avoid hitting provider quotas.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_batch_size"><?php esc_html_e( 'Batch size per run', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" id="hsp_rank_batch_size" name="hsp_rank_batch_size" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" min="1" max="500" />
							<p class="description">
								<?php esc_html_e( 'Number of keywords to check on each cron run. Lower values reduce API bursts; higher values help cover large keyword sets faster.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'hirany-seo' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Add Keywords', 'hirany-seo' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'hsp_rank_add_keywords', 'hsp_rank_add_keywords_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hsp_rank_keywords"><?php esc_html_e( 'Keywords (one per line)', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<textarea id="hsp_rank_keywords" name="hsp_rank_keywords" rows="6" cols="60" class="large-text" placeholder="<?php esc_attr_e( "best seo plugin\nseo plugin for wordpress\nrank tracker tool", 'hirany-seo' ); ?>"></textarea>
							<p class="description">
								<?php esc_html_e( 'You can paste hundreds or thousands of keywords here; the scheduler will process them in batches.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_target_url"><?php esc_html_e( 'Target URL (optional)', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<input type="url" id="hsp_rank_target_url" name="hsp_rank_target_url" class="regular-text" value="" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'If provided, the tracker will try to locate this URL (or its domain) in search results when determining ranking.', 'hirany-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hsp_rank_engine"><?php esc_html_e( 'Search engine', 'hirany-seo' ); ?></label>
						</th>
						<td>
							<select name="hsp_rank_engine" id="hsp_rank_engine">
								<option value="google.com">Google.com</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Add Keywords', 'hirany-seo' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Tracked Keywords', 'hirany-seo' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Keyword', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Target URL', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Engine', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Last position', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Best position', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Last checked', 'hirany-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'hirany-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No keywords tracked yet. Add some above to get started.', 'hirany-seo' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->keyword ); ?></td>
							<td>
								<?php
								if ( ! empty( $row->target_url ) ) {
									printf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', esc_url( $row->target_url ) );
								}
								?>
							</td>
							<td><?php echo esc_html( $row->search_engine ); ?></td>
							<td>
								<?php
								if ( null === $row->last_position ) {
									echo '&mdash;';
								} else {
									echo esc_html( (string) $row->last_position );
								}
								?>
							</td>
							<td>
								<?php
								if ( null === $row->best_position ) {
									echo '&mdash;';
								} else {
									echo esc_html( (string) $row->best_position );
								}
								?>
							</td>
							<td>
								<?php
								if ( $row->last_checked_at ) {
									echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->last_checked_at ) );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td>
								<?php echo $row->active ? esc_html__( 'Active', 'hirany-seo' ) : esc_html__( 'Paused', 'hirany-seo' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<?php
				$page_links = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => __( '&laquo;', 'hirany-seo' ),
						'next_text' => __( '&raquo;', 'hirany-seo' ),
						'total'     => $total_pages,
						'current'   => $paged,
					)
				);
				?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php echo wp_kses_post( $page_links ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Cron callback: process a batch of keywords and store rank history.
	 *
	 * @return void
	 */
	public function cron_run() {
		$settings = $this->get_settings();

		if ( 'none' === $settings['provider'] || empty( $settings['api_key'] ) ) {
			return;
		}

		global $wpdb;

		$batch_size = max( 1, (int) $settings['batch_size'] );
		$batch_size = min( 500, $batch_size );

		// Simple daily quota tracking using a transient keyed by date.
		$today_key = gmdate( 'Ymd' );
		$used      = (int) get_transient( 'hsp_rank_daily_used_' . $today_key );

		if ( $used >= (int) $settings['daily_limit'] ) {
			return;
		}

		$remaining = (int) $settings['daily_limit'] - $used;
		$limit     = min( $batch_size, $remaining );

		$keywords = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_keywords}
				WHERE active = 1
				ORDER BY COALESCE(last_checked_at, '0000-00-00 00:00:00') ASC
				LIMIT %d",
				$limit
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $keywords ) ) {
			return;
		}

		$today_date = gmdate( 'Y-m-d' );
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $keywords as $keyword ) {
			$result = $this->fetch_keyword_position(
				$settings,
				$keyword->keyword,
				$keyword->target_url,
				$keyword->search_engine,
				$site_host
			);

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$position  = isset( $result['position'] ) ? (int) $result['position'] : null;
			$url_found = isset( $result['url_found'] ) ? (string) $result['url_found'] : '';

			$wpdb->insert(
				$this->table_ranks,
				array(
					'keyword_id'   => (int) $keyword->id,
					'checked_date' => $today_date,
					'position'     => $position,
					'url_found'    => $url_found,
				),
				array(
					'%d',
					'%s',
					'%d',
					'%s',
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			// Update summary stats on the keyword row.
			$best_position = $keyword->best_position;
			if ( null === $best_position || ( null !== $position && $position > 0 && $position < $best_position ) ) {
				$best_position = $position;
			}

			$wpdb->update(
				$this->table_keywords,
				array(
					'last_position'   => $position,
					'best_position'   => $best_position,
					'last_checked_at' => current_time( 'mysql', 1 ),
				),
				array(
					'id' => (int) $keyword->id,
				),
				array(
					'%d',
					'%d',
					'%s',
				),
				array(
					'%d',
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			$used++;

			if ( $used >= (int) $settings['daily_limit'] ) {
				break;
			}
		}

		// Keep stats for 24 hours.
		set_transient( 'hsp_rank_daily_used_' . $today_key, $used, DAY_IN_SECONDS );

		// Optional cleanup: drop rank history older than 12 months to keep the table compact.
		$cutoff = gmdate( 'Y-m-d', strtotime( '-12 months' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_ranks} WHERE checked_date < %s",
				$cutoff
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Fetch a keyword position via configured provider.
	 *
	 * @param array<string,mixed> $settings     Tracker settings.
	 * @param string              $keyword      Keyword.
	 * @param string              $target_url   Optional target URL.
	 * @param string              $search_engine Search engine (e.g. google.com).
	 * @param string|null         $fallback_host Fallback host to search for if no target URL.
	 * @return array{position:int|null,url_found:string}|WP_Error
	 */
	protected function fetch_keyword_position( $settings, $keyword, $target_url, $search_engine, $fallback_host ) {
		/**
		 * Let custom code completely override rank fetching logic.
		 *
		 * Returning an array with 'position' and 'url_found' keys skips the default providers.
		 *
		 * @param null|array{position:int|null,url_found:string} $result      Current result.
		 * @param array<string,mixed>                            $settings    Tracker settings.
		 * @param string                                         $keyword     Keyword.
		 * @param string                                         $target_url  Target URL.
		 * @param string                                         $search_engine Search engine.
		 * @param string|null                                    $fallback_host Fallback host.
		 */
		$override = apply_filters( 'hsp_rank_tracker_fetch_keyword_position', null, $settings, $keyword, $target_url, $search_engine, $fallback_host );

		if ( is_array( $override ) ) {
			return $override;
		}

		if ( 'serpapi' === $settings['provider'] ) {
			return $this->fetch_via_serpapi( $settings, $keyword, $target_url, $search_engine, $fallback_host );
		}

		if ( 'custom' === $settings['provider'] ) {
			return $this->fetch_via_custom_endpoint( $settings, $keyword, $target_url, $search_engine, $fallback_host );
		}

		return new WP_Error(
			'hsp_rank_provider_not_supported',
			__( 'Rank provider not configured or not supported.', 'hirany-seo' )
		);
	}

	/**
	 * Fetch position using SerpAPI.
	 *
	 * @param array<string,mixed> $settings     Settings.
	 * @param string              $keyword      Keyword.
	 * @param string              $target_url   Target URL.
	 * @param string              $search_engine Search engine (unused; reserved).
	 * @param string|null         $fallback_host Fallback host.
	 * @return array{position:int|null,url_found:string}|WP_Error
	 */
	protected function fetch_via_serpapi( $settings, $keyword, $target_url, $search_engine, $fallback_host ) {
		$api_key = $settings['api_key'];

		if ( ! $api_key ) {
			return new WP_Error( 'hsp_serpapi_no_key', __( 'SerpAPI API key is missing.', 'hirany-seo' ) );
		}

		$args = array(
			'engine'        => 'google',
			'q'             => $keyword,
			'num'           => 100,
			'api_key'       => $api_key,
			'google_domain' => $search_engine ? $search_engine : 'google.com',
		);

		if ( ! empty( $settings['location'] ) ) {
			$args['location'] = $settings['location'];
		}

		if ( ! empty( $settings['language'] ) ) {
			$args['hl'] = $settings['language'];
		}

		$url = add_query_arg( $args, 'https://serpapi.com/search.json' );

		/**
		 * Filter SerpAPI request args.
		 *
		 * @param array  $request_args Arguments for wp_remote_get.
		 * @param string $url          Request URL.
		 */
		$request_args = apply_filters(
			'hsp_rank_tracker_serpapi_request_args',
			array(
				'timeout' => 30,
			),
			$url
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error(
				'hsp_serpapi_bad_response',
				__( 'SerpAPI returned an unexpected response.', 'hirany-seo' )
			);
		}

		if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			return new WP_Error( 'hsp_serpapi_error', $body['error'] );
		}

		$results = isset( $body['organic_results'] ) && is_array( $body['organic_results'] ) ? $body['organic_results'] : array();

		if ( empty( $results ) ) {
			return array(
				'position'  => null,
				'url_found' => '',
			);
		}

		$target_host = '';

		if ( $target_url ) {
			$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
		}

		if ( ! $target_host && $fallback_host ) {
			$target_host = $fallback_host;
		}

		$best_position = null;
		$url_found     = '';

		foreach ( $results as $result ) {
			if ( empty( $result['position'] ) || empty( $result['link'] ) ) {
				continue;
			}

			$position = (int) $result['position'];
			$link     = (string) $result['link'];

			if ( ! $target_host ) {
				// Fallback: first organic result.
				$best_position = $position;
				$url_found     = $link;
				break;
			}

			$link_host = wp_parse_url( $link, PHP_URL_HOST );

			if ( $link_host && false !== strpos( $link_host, $target_host ) ) {
				$best_position = $position;
				$url_found     = $link;
				break;
			}
		}

		return array(
			'position'  => $best_position,
			'url_found' => $url_found,
		);
	}

	/**
	 * Fetch position via a custom JSON endpoint.
	 *
	 * @param array<string,mixed> $settings     Settings.
	 * @param string              $keyword      Keyword.
	 * @param string              $target_url   Target URL.
	 * @param string              $search_engine Search engine identifier.
	 * @param string|null         $fallback_host Fallback host.
	 * @return array{position:int|null,url_found:string}|WP_Error
	 */
	protected function fetch_via_custom_endpoint( $settings, $keyword, $target_url, $search_engine, $fallback_host ) {
		if ( empty( $settings['custom_url'] ) ) {
			return new WP_Error(
				'hsp_custom_endpoint_missing',
				__( 'Custom rank endpoint URL is not configured.', 'hirany-seo' )
			);
		}

		$body = array(
			'keyword'       => $keyword,
			'target_url'    => $target_url,
			'search_engine' => $search_engine,
			'host'          => $fallback_host,
		);

		/**
		 * Filter custom endpoint request arguments.
		 *
		 * @param array  $request_args Arguments for wp_remote_post.
		 * @param string $url          Endpoint URL.
		 * @param array  $body         JSON body.
		 */
		$request_args = apply_filters(
			'hsp_rank_tracker_custom_request_args',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-HSP-Token'  => $settings['api_key'],
				),
				'timeout' => 30,
				'body'    => wp_json_encode( $body ),
			),
			$settings['custom_url'],
			$body
		);

		$response = wp_remote_post( $settings['custom_url'], $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error(
				'hsp_custom_endpoint_bad_response',
				__( 'Custom rank endpoint returned an unexpected response.', 'hirany-seo' )
			);
		}

		$position  = isset( $data['position'] ) ? (int) $data['position'] : null;
		$url_found = isset( $data['url_found'] ) ? (string) $data['url_found'] : '';

		return array(
			'position'  => $position,
			'url_found' => $url_found,
		);
	}
}

