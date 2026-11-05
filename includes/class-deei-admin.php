<?php
/**
 * Private scan controls, paged report and CSV export.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render and protect administrator-only scan and report screens. */
final class DEEI_Admin {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reports query bounded plugin-owned tables.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are fixed plugin-owned names; all variable values are prepared.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET values only filter a private, read-only report; mutating POST actions require task-specific nonces.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV uses an anonymous private tmpfile stream, not a WordPress-managed filesystem path.
	private const SLUG      = 'digicyfr-external-embed-inventory';
	private const PAGE_SIZE = 20;

	/**
	 * Register administrator hooks.
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_deei_action', array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Load bundled translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( self::SLUG, false, dirname( plugin_basename( DEEI_PATH . self::SLUG . '.php' ) ) . '/languages' );
	}

	/**
	 * Add the private Tools screen.
	 */
	public static function menu(): void {
		add_management_page( __( 'External Embed Inventory', 'digicyfr-external-embed-inventory' ), __( 'External Embed Inventory', 'digicyfr-external-embed-inventory' ), 'manage_options', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	/**
	 * Load styles on the plugin screen.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'deei-admin', DEEI_URL . 'assets/admin.css', array(), DEEI_VERSION );
		}
	}

	/**
	 * Direct endpoint: capability, method and task-specific nonce all required.
	 */
	public static function handle(): void {
		$method = strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) );
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== $method ) {
			wp_die( esc_html__( 'You cannot perform this action.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 403 ) );
		}
		$task = isset( $_POST['deei_task'] ) && is_string( $_POST['deei_task'] ) ? sanitize_key( wp_unslash( $_POST['deei_task'] ) ) : '';
		if ( ! in_array( $task, array( 'start', 'continue', 'cancel', 'export' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'deei_' . $task );
		if ( 'export' === $task ) {
			self::export();
			return;
		}
		$result = match ( $task ) {
			'start'    => DEEI_Run::start(),
			'continue' => DEEI_Run::proceed(),
			'cancel'   => DEEI_Run::cancel(),
		};
		$notice = is_wp_error( $result ) ? $result->get_error_code() : 'saved';
		wp_safe_redirect( add_query_arg( 'deei_notice', rawurlencode( $notice ), admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Render current attempt, active report and bounded pages.
	 */
	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot view this report.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 403 ) );
		}
		$state = DEEI_Run::reconcile();
		echo '<div class="wrap deei-wrap"><h1>' . esc_html__( 'External Embed Inventory', 'digicyfr-external-embed-inventory' ) . '</h1>';
		echo '<p>' . esc_html__( 'Local saved-content references only. A found host is not proof of a browser request or a privacy verdict. Rescan after content changes.', 'digicyfr-external-embed-inventory' ) . '</p>';
		if ( is_wp_error( $state ) ) {
			self::notice( __( 'Storage error. The previous complete report remains unchanged.', 'digicyfr-external-embed-inventory' ) . ' (' . $state->get_error_code() . ')', true );
			echo '</div>';
			return;
		}
		// Hold the singleton through this bounded report page so promotion/prune cannot
		// replace its underlying run midway through a read.
		if ( ! DEEI_Storage::begin_locked() ) {
			self::notice( __( 'Could not lock the active report.', 'digicyfr-external-embed-inventory' ), true );
			echo '</div>';
			return;
		}
		global $wpdb;
		$t              = DEEI_Storage::tables();
		$fresh_current  = DEEI_Run::current();
		$fresh_complete = $wpdb->get_row( "SELECT * FROM {$t['runs']} WHERE status = 'complete' ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( is_wp_error( $fresh_current ) || $wpdb->last_error ) {
			DEEI_Storage::rollback();
			self::notice( __( 'Storage error. The previous complete report remains unchanged.', 'digicyfr-external-embed-inventory' ), true );
			echo '</div>';
			return;
		}
		$state = array(
			'current'  => $fresh_current,
			'complete' => $fresh_complete,
		);
		try {
			$notice = isset( $_GET['deei_notice'] ) && is_string( $_GET['deei_notice'] ) ? sanitize_key( wp_unslash( $_GET['deei_notice'] ) ) : '';
			if ( $notice ) {
				self::notice( 'saved' === $notice ? __( 'Scan action completed.', 'digicyfr-external-embed-inventory' ) : __( 'Scan action failed.', 'digicyfr-external-embed-inventory' ) . ' (' . $notice . ')', 'saved' !== $notice );
			}
			$current  = $state['current'];
			$complete = $state['complete'];
			echo '<section aria-labelledby="deei-scan-heading"><h2 id="deei-scan-heading">' . esc_html__( 'Scan', 'digicyfr-external-embed-inventory' ) . '</h2>';
			if ( $current ) {
				printf( '<p>%s <strong>%s</strong>. %s %d; %s %d; %s %d. %s %s.</p>', esc_html__( 'Current attempt:', 'digicyfr-external-embed-inventory' ), esc_html( self::status_label( $current['status'] ) ), esc_html__( 'Cursor:', 'digicyfr-external-embed-inventory' ), (int) $current['cursor_id'], esc_html__( 'Scanned:', 'digicyfr-external-embed-inventory' ), (int) $current['scanned_count'], esc_html__( 'Skipped:', 'digicyfr-external-embed-inventory' ), (int) $current['skipped_count'], esc_html__( 'Started (UTC):', 'digicyfr-external-embed-inventory' ), esc_html( $current['started_at_utc'] ) );
				if ( $current['error'] ) {
					self::notice( __( 'Coverage reason:', 'digicyfr-external-embed-inventory' ) . ' ' . self::reason_label( $current['error'] ), true );
				}
				printf( '<p>%s %d. %s %d. %s %d. %s %d. %s %d. %s %d.</p>', esc_html__( 'Candidates:', 'digicyfr-external-embed-inventory' ), (int) $current['candidate_count'], esc_html__( 'Unresolved relative:', 'digicyfr-external-embed-inventory' ), (int) $current['unresolved_relative_count'], esc_html__( 'Unsupported attributes:', 'digicyfr-external-embed-inventory' ), (int) $current['unsupported_count'], esc_html__( 'Ignored values:', 'digicyfr-external-embed-inventory' ), (int) $current['ignored_count'], esc_html__( 'Invalid URLs:', 'digicyfr-external-embed-inventory' ), (int) $current['invalid_count'], esc_html__( 'Unclassified or ambiguous:', 'digicyfr-external-embed-inventory' ), (int) $current['unclassified_count'] + (int) $current['ambiguous_count'] );
				if ( $current['finished_at_utc'] ) {
					printf( '<p>%s %s</p>', esc_html__( 'Finished (UTC):', 'digicyfr-external-embed-inventory' ), esc_html( $current['finished_at_utc'] ) );
				}
			}
			self::action_form( 'start', $current ? __( 'Start a new scan', 'digicyfr-external-embed-inventory' ) : __( 'Start scan', 'digicyfr-external-embed-inventory' ) );
			if ( $current && ( 'in_progress' === $current['status'] || ( 'incomplete' === $current['status'] && str_starts_with( $current['error'], 'storage_' ) ) ) ) {
				self::action_form( 'continue', __( 'Continue (up to 10 posts)', 'digicyfr-external-embed-inventory' ) );
				self::action_form( 'cancel', __( 'Cancel attempt', 'digicyfr-external-embed-inventory' ) );
			}
			echo '</section>';
			if ( ! $complete ) {
				echo '<p>' . esc_html__( 'No complete report yet. CSV is unavailable until a scan completes without skips.', 'digicyfr-external-embed-inventory' ) . '</p></div>';
				return;
			}
			printf( '<section aria-labelledby="deei-report-heading"><h2 id="deei-report-heading">%s</h2><p>%s %s. %s %d.</p>', esc_html__( 'Active complete report', 'digicyfr-external-embed-inventory' ), esc_html__( 'Finished (UTC):', 'digicyfr-external-embed-inventory' ), esc_html( $complete['finished_at_utc'] ), esc_html__( 'Covered posts:', 'digicyfr-external-embed-inventory' ), (int) $complete['scanned_count'] );
			self::action_form( 'export', __( 'Download CSV', 'digicyfr-external-embed-inventory' ) );
			$filters = self::filters();
			self::filter_form( $filters );
			$view = isset( $_GET['deei_view'] ) && is_string( $_GET['deei_view'] ) ? sanitize_key( wp_unslash( $_GET['deei_view'] ) ) : 'hosts';
			if ( 'rows' === $view ) {
				self::rows_page( $complete['run_id'], $filters );
			} elseif ( 'coverage' === $view ) {
				self::coverage_page( $complete['run_id'] );
			} else {
				self::hosts_page( $complete['run_id'] );
			}
			echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG . '&deei_view=coverage' ) ) . '">' . esc_html__( 'View coverage and stale posts', 'digicyfr-external-embed-inventory' ) . '</a></p>';
			echo '</section></div>';
		} finally {
			DEEI_Storage::rollback();
		}
	}

	/**
	 * Render a nonce-protected action form.
	 *
	 * @param string $task Task.
	 * @param string $label Label.
	 */
	private static function action_form( string $task, string $label ): void {
		echo '<form class="deei-action" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="deei_action"><input type="hidden" name="deei_task" value="' . esc_attr( $task ) . '">';
		wp_nonce_field( 'deei_' . $task );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render an accessible status notice.
	 *
	 * @param string $message Message.
	 * @param bool   $error Error.
	 */
	private static function notice( string $message, bool $error ): void {
		echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . ' deei-notice" role="' . ( $error ? 'alert' : 'status' ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Translate a run status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			'in_progress' => __( 'In progress', 'digicyfr-external-embed-inventory' ),
			'complete'    => __( 'Complete', 'digicyfr-external-embed-inventory' ),
			'incomplete'  => __( 'Incomplete', 'digicyfr-external-embed-inventory' ),
			'cancelled'   => __( 'Cancelled', 'digicyfr-external-embed-inventory' ),
			default       => __( 'Unknown', 'digicyfr-external-embed-inventory' ),
		};
	}

	/**
	 * Translate a bounded coverage reason.
	 *
	 * @param string $code Code.
	 */
	private static function reason_label( string $code ): string {
		$labels = array(
			'post_too_large'           => __( 'Saved content exceeded 512 KiB.', 'digicyfr-external-embed-inventory' ),
			'candidate_count_exceeded' => __( 'More than 300 URL candidates in a post.', 'digicyfr-external-embed-inventory' ),
			'block_depth_exceeded'     => __( 'Block nesting limit exceeded.', 'digicyfr-external-embed-inventory' ),
			'block_count_exceeded'     => __( 'Block count limit exceeded.', 'digicyfr-external-embed-inventory' ),
			'tag_count_exceeded'       => __( 'HTML tag count limit exceeded.', 'digicyfr-external-embed-inventory' ),
			'incomplete_html_token'    => __( 'Saved HTML ended in an incomplete tag.', 'digicyfr-external-embed-inventory' ),
			'parser_failed'            => __( 'Saved content parsing failed.', 'digicyfr-external-embed-inventory' ),
		);
		return $labels[ $code ] ?? __( 'Storage or coverage failed; retry Continue if it is available.', 'digicyfr-external-embed-inventory' );
	}

	/**
	 * Read allowlisted report filters.
	 */
	private static function filters(): array {
		$host     = isset( $_GET['deei_host'] ) && is_string( $_GET['deei_host'] ) ? sanitize_text_field( wp_unslash( $_GET['deei_host'] ) ) : '';
		$kind     = isset( $_GET['deei_kind'] ) && is_string( $_GET['deei_kind'] ) ? sanitize_text_field( wp_unslash( $_GET['deei_kind'] ) ) : '';
		$evidence = isset( $_GET['deei_evidence'] ) && is_string( $_GET['deei_evidence'] ) ? sanitize_key( wp_unslash( $_GET['deei_evidence'] ) ) : '';
		if ( strlen( $host ) > 260 || preg_match( '/[^\x21-\x7e]/', $host ) ) {
			$host = '';
		}
		$kinds = array( 'core/embed', 'iframe', 'script', 'embed', 'object', 'video', 'audio', 'source', 'img' );
		return array(
			'host'     => $host,
			'kind'     => in_array( $kind, $kinds, true ) ? $kind : '',
			'evidence' => in_array( $evidence, array( 'direct', 'hint' ), true ) ? $evidence : '',
		);
	}

	/**
	 * Render source filters.
	 *
	 * @param array $filters Filters.
	 */
	private static function filter_form( array $filters ): void {
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="deei_view" value="rows">';
		echo '<label for="deei-host">' . esc_html__( 'Exact host', 'digicyfr-external-embed-inventory' ) . '</label> <input id="deei-host" name="deei_host" maxlength="260" value="' . esc_attr( $filters['host'] ) . '"> ';
		echo '<label for="deei-kind">' . esc_html__( 'Source kind', 'digicyfr-external-embed-inventory' ) . '</label> <select id="deei-kind" name="deei_kind"><option value="">' . esc_html__( 'All kinds', 'digicyfr-external-embed-inventory' ) . '</option>';
		foreach ( array( 'core/embed', 'iframe', 'script', 'embed', 'object', 'video', 'audio', 'source', 'img' ) as $kind ) {
			echo '<option value="' . esc_attr( $kind ) . '" ' . selected( $filters['kind'], $kind, false ) . '>' . esc_html( $kind ) . '</option>';
		}
		echo '</select> <label for="deei-evidence">' . esc_html__( 'Evidence', 'digicyfr-external-embed-inventory' ) . '</label> <select id="deei-evidence" name="deei_evidence"><option value="">' . esc_html__( 'All evidence', 'digicyfr-external-embed-inventory' ) . '</option><option value="direct" ' . selected( $filters['evidence'], 'direct', false ) . '>' . esc_html__( 'Saved resource URL', 'digicyfr-external-embed-inventory' ) . '</option><option value="hint" ' . selected( $filters['evidence'], 'hint', false ) . '>' . esc_html__( 'Hints and lazy URLs', 'digicyfr-external-embed-inventory' ) . '</option></select> ';
		submit_button( __( 'Filter', 'digicyfr-external-embed-inventory' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Bound the requested page number.
	 */
	private static function page_number(): int {
		return max( 1, min( 100000, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) );
	}

	/**
	 * Render one host-summary page.
	 *
	 * @param string $run_id Run id.
	 */
	private static function hosts_page( string $run_id ): void {
		global $wpdb;
		$t      = DEEI_Storage::tables();
		$page   = self::page_number();
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT host_key, scheme, COUNT(DISTINCT post_id) AS page_count, SUM(occurrence_count) AS occurrences FROM {$t['rows']} WHERE run_id = %s GROUP BY host_key, scheme ORDER BY occurrences DESC, host_key ASC, scheme ASC LIMIT %d OFFSET %d", $run_id, self::PAGE_SIZE, $offset ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			self::notice( __( 'Host report query failed.', 'digicyfr-external-embed-inventory' ), true );
			return;
		}
		echo '<h3>' . esc_html__( 'Hosts found in saved content', 'digicyfr-external-embed-inventory' ) . '</h3>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No supported saved external references found in this report. Unsupported and dynamic sources remain outside scope.', 'digicyfr-external-embed-inventory' ) . '</p>';
			return;
		}
		echo '<div class="deei-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Host', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Scheme', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Pages', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Occurrences', 'digicyfr-external-embed-inventory' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$link = add_query_arg(
				array(
					'page'      => self::SLUG,
					'deei_view' => 'rows',
					'deei_host' => $row['host_key'],
				),
				admin_url( 'tools.php' )
			);
			printf( '<tr><th scope="row"><a href="%s">%s</a></th><td>%s</td><td>%d</td><td>%d</td></tr>', esc_url( $link ), esc_html( $row['host_key'] ), esc_html( $row['scheme'] ), (int) $row['page_count'], (int) $row['occurrences'] );
		}
		echo '</tbody></table></div>';
		self::next_page( count( $rows ), $page );
	}

	/**
	 * Bounded report row query, also used by CSV in keyset order.
	 *
	 * @param string $run_id Run id.
	 * @param array  $filters Filters.
	 * @param int    $after_id After id.
	 * @param int    $limit Limit.
	 * @param int    $offset Offset.
	 */
	private static function row_query( string $run_id, array $filters, int $after_id, int $limit, int $offset = 0 ): array|WP_Error {
		global $wpdb;
		$t    = DEEI_Storage::tables();
		$sql  = "SELECT r.*, c.content_hash, c.post_modified_gmt_at_scan, c.reason FROM {$t['rows']} r LEFT JOIN {$t['coverage']} c ON c.run_id = r.run_id AND c.post_id = r.post_id WHERE r.run_id = %s AND r.id > %d";
		$args = array( $run_id, $after_id );
		if ( $filters['host'] ) {
			$sql   .= ' AND r.host_key = %s';
			$args[] = $filters['host'];
		}
		if ( $filters['kind'] ) {
			$sql   .= ' AND r.source_kind = %s';
			$args[] = $filters['kind'];
		}
		if ( $filters['evidence'] ) {
			$sql .= ' AND r.evidence ' . ( 'direct' === $filters['evidence'] ? '= %s' : 'IN (%s,%s)' );
			if ( 'direct' === $filters['evidence'] ) {
				$args[] = 'saved_resource_url';
			} else {
				$args[] = 'provider_url_hint';
				$args[] = 'potential_lazy_url';
			}
		}
		$sql   .= ' ORDER BY r.id ASC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'report_query_failed' );
		}
		foreach ( $rows as $row ) {
			if ( null === $row['content_hash'] || '' !== $row['reason'] ) {
				return new WP_Error( 'coverage_verification_failed' );
			}
		}
		return $rows;
	}

	/**
	 * Render one source-row page.
	 *
	 * @param string $run_id Run id.
	 * @param array  $filters Filters.
	 */
	private static function rows_page( string $run_id, array $filters ): void {
		$page = self::page_number();
		$rows = self::row_query( $run_id, $filters, 0, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		if ( is_wp_error( $rows ) ) {
			self::notice( __( 'Source report query failed.', 'digicyfr-external-embed-inventory' ), true );
			return;
		}
		echo '<h3>' . esc_html__( 'Saved source references', 'digicyfr-external-embed-inventory' ) . '</h3>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No matching saved references.', 'digicyfr-external-embed-inventory' ) . '</p>';
			return;
		}
		$stale = array();
		foreach ( $rows as $row ) {
			$stale[ (int) $row['id'] ] = self::coverage_stale( $row );
		}
		echo '<div class="deei-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Page', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Host', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Source', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Count', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Stale', 'digicyfr-external-embed-inventory' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$post      = get_post( (int) $row['post_id'] );
			$title     = $post ? get_the_title( $post ) : '#' . $row['post_id'];
			$url       = current_user_can( 'edit_post', (int) $row['post_id'] ) ? get_edit_post_link( (int) $row['post_id'] ) : '';
			$page_cell = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
			printf( '<tr><th scope="row">%s</th><td>%s<br>%s</td><td>%s / %s</td><td>%s</td><td>%d</td><td>%s</td></tr>', wp_kses_post( $page_cell ), esc_html( $row['host_key'] ), esc_html( $row['scheme'] ), esc_html( $row['source_kind'] ), esc_html( $row['attribute_name'] ), esc_html( $row['evidence'] ), (int) $row['occurrence_count'], esc_html( $stale[ (int) $row['id'] ] ? __( 'Yes', 'digicyfr-external-embed-inventory' ) : __( 'No', 'digicyfr-external-embed-inventory' ) ) );
		}
		echo '</tbody></table></div>';
		self::next_page( count( $rows ), $page );
	}

	/**
	 * Render one coverage page.
	 *
	 * @param string $run_id Run id.
	 */
	private static function coverage_page( string $run_id ): void {
		global $wpdb;
		$t    = DEEI_Storage::tables();
		$page = self::page_number();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['coverage']} WHERE run_id = %s ORDER BY post_id ASC LIMIT %d OFFSET %d", $run_id, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			self::notice( __( 'Coverage query failed.', 'digicyfr-external-embed-inventory' ), true );
			return;
		}
		echo '<h3>' . esc_html__( 'Coverage, including pages without matches', 'digicyfr-external-embed-inventory' ) . '</h3>';
		echo '<div class="deei-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Page ID', 'digicyfr-external-embed-inventory' ) . '</th><th scope="col">' . esc_html__( 'Status', 'digicyfr-external-embed-inventory' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><th scope="row">%d</th><td>%s</td></tr>', (int) $row['post_id'], esc_html( self::coverage_stale( $row ) ? __( 'Stale or unavailable', 'digicyfr-external-embed-inventory' ) : __( 'Current saved content', 'digicyfr-external-embed-inventory' ) ) );
		}
		echo '</tbody></table></div>';
		self::next_page( count( $rows ), $page );
	}

	/**
	 * Render the next-page link.
	 *
	 * @param int $count Count.
	 * @param int $page Page.
	 */
	private static function next_page( int $count, int $page ): void {
		if ( self::PAGE_SIZE === $count ) {
			$url = add_query_arg( 'paged', $page + 1 );
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Next page', 'digicyfr-external-embed-inventory' ) . '</a></p>';
		}
	}

	/**
	 * Compare one coverage record with saved content.
	 *
	 * @param array $coverage Coverage.
	 */
	private static function coverage_stale( array $coverage ): bool {
		$post = get_post( (int) $coverage['post_id'] );
		return ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status || '' === $coverage['content_hash'] || ! hash_equals( $coverage['content_hash'], hash( 'sha256', $post->post_content ) );
	}

	/**
	 * Complete private spool before sending any CSV headers.
	 */
	private static function export(): void {
		$state = DEEI_Run::reconcile();
		if ( is_wp_error( $state ) || ! $state['complete'] ) {
			wp_die( esc_html__( 'No complete report is available for export.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 409 ) );
		}
		$stream = tmpfile();
		if ( false === $stream ) {
			wp_die( esc_html__( 'Could not prepare a private CSV file.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 500 ) );
		}
		if ( ! DEEI_Storage::transactional() || ! DEEI_Storage::begin_locked() ) {
			fclose( $stream );
			wp_die( esc_html__( 'Could not lock the active report for export.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 500 ) );
		}
		global $wpdb;
		$t        = DEEI_Storage::tables();
		$complete = $wpdb->get_row( "SELECT * FROM {$t['runs']} WHERE status = 'complete' ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( $wpdb->last_error || ! $complete ) {
			DEEI_Storage::rollback();
			fclose( $stream );
			wp_die( esc_html__( 'No complete report is available for export.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 409 ) );
		}
		$ok = self::stream_csv( $stream, $complete );
		if ( $ok ) {
			$ok = DEEI_Storage::commit();
		} else {
			DEEI_Storage::rollback();
		}
		if ( ! $ok ) {
			DEEI_Storage::rollback();
			fclose( $stream );
			wp_die( esc_html__( 'CSV could not be completed. No file was downloaded.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 500 ) );
		}
		if ( ! rewind( $stream ) ) {
			fclose( $stream );
			wp_die( esc_html__( 'CSV could not be completed. No file was downloaded.', 'digicyfr-external-embed-inventory' ), '', array( 'response' => 500 ) );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="digicyfr-external-embed-inventory.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		fpassthru( $stream );
		fclose( $stream );
		exit;
	}

	/**
	 * Exposed to integration tests to verify late-page failure without HTTP headers.
	 *
	 * @param mixed $stream Stream.
	 * @param array $complete Complete.
	 */
	public static function stream_csv( mixed $stream, array $complete ): bool {
		if ( ! is_resource( $stream ) ) {
			return false;
		}
		$head = array( 'scan_finished_at_utc', 'post_id', 'post_title', 'post_permalink', 'host_key', 'scheme', 'source_kind', 'attribute_name', 'evidence', 'occurrence_count', 'stale' );
		if ( false === fputcsv( $stream, $head ) ) {
			return false;
		}
		$after   = 0;
		$filters = array(
			'host'     => '',
			'kind'     => '',
			'evidence' => '',
		);
		do {
			$rows = self::row_query( $complete['run_id'], $filters, $after, 200 );
			if ( is_wp_error( $rows ) ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$post   = get_post( (int) $row['post_id'] );
				$stale  = self::coverage_stale( $row );
				$values = array(
					$complete['finished_at_utc'],
					$row['post_id'],
					$post ? get_the_title( $post ) : '',
					$post ? get_permalink( $post ) : '',
					$row['host_key'],
					$row['scheme'],
					$row['source_kind'],
					$row['attribute_name'],
					$row['evidence'],
					$row['occurrence_count'],
					$stale ? 'yes' : 'no',
				);
				$values = array_map( array( __CLASS__, 'csv_cell' ), $values );
				if ( false === fputcsv( $stream, $values ) ) {
					return false;
				}
				$after = (int) $row['id'];
			}
			$chunk_count = count( $rows );
		} while ( 200 === $chunk_count );
		return true;
	}

	/**
	 * Neutralize spreadsheet formula prefixes.
	 *
	 * @param mixed $value Value.
	 */
	private static function csv_cell( mixed $value ): string {
		$text = (string) $value;
		return preg_match( '/^[\x00-\x20]*[=+\-@]/', $text ) ? "'" . $text : $text;
	}
}
