<?php
/**
 * Serialized bounded scan with an authoritative run ledger.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Run and reconcile serialized saved-content scans. */
final class DEEI_Run {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Scan state and plugin-owned rows must share one InnoDB transaction.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are fixed plugin-owned names; all variable values are prepared.
	/**
	 * Return the newest ledger run, distinguishing an empty ledger from SQL failure.
	 */
	public static function current(): array|WP_Error|null {
		global $wpdb;
		$t   = DEEI_Storage::tables();
		$row = $wpdb->get_row( "SELECT * FROM {$t['runs']} ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'storage_read_failed' );
		}
		return $row ? $row : null;
	}

	/**
	 * Get an exact run.
	 *
	 * @param string $run_id Run id.
	 */
	public static function get( string $run_id ): array|WP_Error|null {
		global $wpdb;
		$t   = DEEI_Storage::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['runs']} WHERE run_id = %s LIMIT 1", $run_id ), ARRAY_A );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'storage_read_failed' );
		}
		return $row ? $row : null;
	}

	/**
	 * Repair non-autoloaded pointers after a committed run and prune only after repair.
	 */
	public static function reconcile(): array|WP_Error {
		global $wpdb;
		if ( ! DEEI_Storage::transactional() ) {
			return new WP_Error( 'innodb_required' );
		}
		if ( ! DEEI_Storage::begin_locked() ) {
			return new WP_Error( 'scan_lock_failed' );
		}
		$t       = DEEI_Storage::tables();
		$current = self::current();
		if ( is_wp_error( $current ) ) {
			DEEI_Storage::rollback();
			return $current;
		}
		$complete = $wpdb->get_row( "SELECT * FROM {$t['runs']} WHERE status = 'complete' ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( $wpdb->last_error ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'storage_read_failed' );
		}
		if ( $complete ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['coverage']} WHERE run_id = %s", $complete['run_id'] ) );
			if ( $wpdb->last_error || null === $count ) {
				DEEI_Storage::rollback();
				return new WP_Error( 'coverage_verification_failed' );
			}
			$skip_coverage = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['coverage']} WHERE run_id = %s AND reason <> ''", $complete['run_id'] ) );
			if ( $wpdb->last_error || null === $skip_coverage || (int) $skip_coverage > 0 || (int) $count !== (int) $complete['scanned_count'] || (int) $complete['skipped_count'] > 0 || '' !== $complete['error'] || ! $complete['finished_at_utc'] ) {
				DEEI_Storage::rollback();
				return new WP_Error( 'coverage_verification_failed' );
			}
		}
		$active_id = $complete['run_id'] ?? '';
		if ( ! self::write_pointer( 'deei_active_complete_run_id', $active_id ) || ! self::write_pointer( 'deei_current_run', self::option_state( $current ) ) ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'pointer_write_failed' );
		}
		$keep = array_filter( array( $active_id, $current['run_id'] ?? '' ) );
		if ( $keep && ! DEEI_Storage::prune( $keep ) ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'retention_failed' );
		}
		if ( ! DEEI_Storage::commit() ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'storage_write_failed' );
		}
		wp_cache_delete( 'deei_active_complete_run_id', 'options' );
		wp_cache_delete( 'deei_current_run', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return array(
			'current'  => $current,
			'complete' => $complete,
		);
	}

	/**
	 * Start or restart a fresh high-water pass; old active complete remains visible.
	 */
	public static function start(): array|WP_Error {
		global $wpdb;
		if ( ! DEEI_Storage::transactional() ) {
			return new WP_Error( 'innodb_required' );
		}
		$repaired = self::reconcile();
		if ( is_wp_error( $repaired ) ) {
			return $repaired;
		}
		if ( ! DEEI_Storage::begin_locked() ) {
			return new WP_Error( 'scan_lock_failed' );
		}
		$t          = DEEI_Storage::tables();
		$high_water = $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" );
		if ( $wpdb->last_error ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'high_water_query_failed' );
		}
		$run_id = wp_generate_uuid4();
		$state  = array(
			'run_id'                    => $run_id,
			'status'                    => 'in_progress',
			'high_water_id'             => (int) $high_water,
			'cursor_id'                 => 0,
			'started_at_utc'            => current_time( 'mysql', true ),
			'finished_at_utc'           => null,
			'scanned_count'             => 0,
			'skipped_count'             => 0,
			'candidate_count'           => 0,
			'unresolved_relative_count' => 0,
			'unsupported_count'         => 0,
			'first_party_count'         => 0,
			'invalid_count'             => 0,
			'ignored_count'             => 0,
			'unclassified_count'        => 0,
			'ambiguous_count'           => 0,
			'error'                     => '',
		);
		if ( false === $wpdb->insert( $t['runs'], $state ) || $wpdb->last_error || ! DEEI_Storage::commit() ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'storage_write_failed' );
		}
		$repaired = self::reconcile();
		return is_wp_error( $repaired ) ? $repaired : $state;
	}

	/**
	 * Process at most ten posts under the singleton row lock and one transaction.
	 */
	public static function proceed(): array|WP_Error {
		global $wpdb;
		if ( ! DEEI_Storage::transactional() ) {
			return new WP_Error( 'innodb_required' );
		}
		if ( ! DEEI_Storage::begin_locked() ) {
			return new WP_Error( 'scan_lock_failed' );
		}
		$t   = DEEI_Storage::tables();
		$run = self::current();
		if ( is_wp_error( $run ) || ! $run ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'run_unavailable' );
		}
		$run_id = $run['run_id'];
		$cursor = (int) $run['cursor_id'];
		if ( 'in_progress' !== $run['status'] && ! ( 'incomplete' === $run['status'] && str_starts_with( $run['error'], 'storage_' ) ) ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'run_not_retryable' );
		}
		$run['status'] = 'in_progress';
		if ( str_starts_with( $run['error'], 'storage_' ) ) {
			$run['error'] = '';
		}
		if ( (int) $run['skipped_count'] > 0 && '' === $run['error'] ) {
			$prior_skip = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$t['coverage']} WHERE run_id = %s AND reason <> '' ORDER BY post_id ASC LIMIT 1", $run_id ) );
			if ( $wpdb->last_error || ! is_string( $prior_skip ) || '' === $prior_skip ) {
				return self::fail_batch( $run_id, $cursor, 'storage_coverage_query_failed' );
			}
			$run['error'] = $prior_skip;
		}
		$batch = $wpdb->get_results( $wpdb->prepare( "SELECT ID, OCTET_LENGTH(post_content) AS content_bytes, post_modified_gmt FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' ORDER BY ID ASC LIMIT 10", $cursor, (int) $run['high_water_id'] ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $batch ) ) {
			return self::fail_batch( $run_id, $cursor, 'storage_batch_query_failed' );
		}
		foreach ( $batch as $item ) {
			$id = (int) $item['ID'];
			if ( null === $item['content_bytes'] ) {
				return self::fail_batch( $run_id, $cursor, 'storage_length_query_failed' );
			}
			if ( (int) $item['content_bytes'] > DEEI_Scanner::MAX_BYTES ) {
				if ( ! DEEI_Storage::replace_skipped( $run_id, $id, (string) $item['post_modified_gmt'], 'post_too_large' ) ) {
					return self::fail_batch( $run_id, $cursor, 'storage_write_failed' );
				}
				++$run['skipped_count'];
				$run['error'] = 'post_too_large';
			} else {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
					return self::fail_batch( $run_id, $cursor, 'storage_post_read_failed' );
				}
				$scan = DEEI_Scanner::scan( $post->post_content );
				if ( is_wp_error( $scan ) ) {
					if ( ! DEEI_Storage::replace_skipped( $run_id, $id, $post->post_modified_gmt, $scan->get_error_code() ) ) {
						return self::fail_batch( $run_id, $cursor, 'storage_write_failed' );
					}
					++$run['skipped_count'];
					$run['error'] = $scan->get_error_code();
				} else {
					$stored = DEEI_Storage::replace_post( $run_id, $post, $scan );
					if ( is_wp_error( $stored ) || ! $stored ) {
						return self::fail_batch( $run_id, $cursor, is_wp_error( $stored ) ? $stored->get_error_code() : 'storage_write_failed' );
					}
					$run['candidate_count']           += $scan['candidate_count'];
					$run['unresolved_relative_count'] += $scan['unresolved_relative'];
					$run['unsupported_count']         += $scan['unsupported_count'];
					$run['first_party_count']         += $scan['first_party_count'];
					$run['invalid_count']             += $scan['invalid_count'];
					$run['ignored_count']             += $scan['ignored_count'];
					$run['unclassified_count']        += $scan['unclassified_count'];
					$run['ambiguous_count']           += $scan['ambiguous_count'];
				}
			}
			++$run['scanned_count'];
			$run['cursor_id'] = $id;
		}
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' ORDER BY ID ASC LIMIT 1", (int) $run['cursor_id'], (int) $run['high_water_id'] ) );
		if ( $wpdb->last_error ) {
			return self::fail_batch( $run_id, $cursor, 'storage_remaining_query_failed' );
		}
		if ( null === $remaining ) {
			$run['finished_at_utc'] = current_time( 'mysql', true );
			$run['status']          = (int) $run['skipped_count'] ? 'incomplete' : 'complete';
			if ( 'complete' === $run['status'] ) {
				$coverage = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['coverage']} WHERE run_id = %s", $run_id ) );
				if ( $wpdb->last_error || null === $coverage || (int) $coverage !== (int) $run['scanned_count'] ) {
					return self::fail_batch( $run_id, $cursor, 'storage_coverage_query_failed' );
				}
			}
		}
		$updated = $wpdb->update( $t['runs'], self::writable_state( $run ), array( 'run_id' => $run_id ) );
		if ( false === $updated || $wpdb->last_error || ! DEEI_Storage::commit() ) {
			return self::fail_batch( $run_id, $cursor, 'storage_write_failed' );
		}
		$repaired = self::reconcile();
		return is_wp_error( $repaired ) ? $repaired : $run;
	}

	/**
	 * Cancel only the locked current run.
	 */
	public static function cancel(): array|WP_Error {
		global $wpdb;
		if ( ! DEEI_Storage::transactional() ) {
			return new WP_Error( 'innodb_required' );
		}
		if ( ! DEEI_Storage::begin_locked() ) {
			return new WP_Error( 'scan_lock_failed' );
		}
		$t   = DEEI_Storage::tables();
		$run = self::current();
		if ( is_wp_error( $run ) || ! $run || ! in_array( $run['status'], array( 'in_progress', 'incomplete' ), true ) ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'run_not_cancellable' );
		}
		$run['status']          = 'cancelled';
		$run['finished_at_utc'] = current_time( 'mysql', true );
		$ok                     = $wpdb->update( $t['runs'], self::writable_state( $run ), array( 'run_id' => $run['run_id'] ) );
		if ( false === $ok || $wpdb->last_error || ! DEEI_Storage::commit() ) {
			DEEI_Storage::rollback();
			return new WP_Error( 'storage_write_failed' );
		}
		$repaired = self::reconcile();
		return is_wp_error( $repaired ) ? $repaired : $run;
	}

	/**
	 * Roll back a whole batch, then persist a retryable error without moving its cursor.
	 *
	 * @param string $run_id Run id.
	 * @param int    $cursor Cursor.
	 * @param string $reason Reason.
	 */
	private static function fail_batch( string $run_id, int $cursor, string $reason ): WP_Error {
		global $wpdb;
		DEEI_Storage::rollback();
		if ( DEEI_Storage::begin_locked() ) {
			$t   = DEEI_Storage::tables();
			$run = self::current();
			if ( is_array( $run ) && $run_id === $run['run_id'] && $cursor === (int) $run['cursor_id'] ) {
				$run['status'] = 'incomplete';
				$run['error']  = $reason;
				$updated       = $wpdb->update( $t['runs'], self::writable_state( $run ), array( 'run_id' => $run_id ) );
				if ( false === $updated || $wpdb->last_error || ! DEEI_Storage::commit() ) {
					DEEI_Storage::rollback();
				}
			} else {
				DEEI_Storage::rollback();
			}
		}
		self::reconcile();
		return new WP_Error( $reason );
	}

	/**
	 * Remove immutable columns before a ledger update.
	 *
	 * @param array $run Run.
	 */
	private static function writable_state( array $run ): array {
		unset( $run['id'], $run['run_id'] );
		return $run;
	}

	/**
	 * Project a ledger row into the current-run option.
	 *
	 * @param ?array $run Run.
	 */
	private static function option_state( ?array $run ): array {
		if ( ! $run ) {
			return array();
		}
		$fields = array( 'run_id', 'status', 'high_water_id', 'cursor_id', 'started_at_utc', 'finished_at_utc', 'scanned_count', 'skipped_count', 'candidate_count', 'unresolved_relative_count', 'unsupported_count', 'first_party_count', 'invalid_count', 'ignored_count', 'unclassified_count', 'ambiguous_count', 'error' );
		$state  = array( 'schema_version' => 1 );
		foreach ( $fields as $field ) {
			$state[ $field ] = $run[ $field ];
		}
		return $state;
	}

	/**
	 * Write non-autoloaded pointers in the same locked InnoDB transaction as pruning.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 */
	private static function write_pointer( string $key, mixed $value ): bool {
		global $wpdb;
		$sql = $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'", $key, maybe_serialize( $value ) );
		return false !== $wpdb->query( $sql ) && ! $wpdb->last_error;
	}
}
