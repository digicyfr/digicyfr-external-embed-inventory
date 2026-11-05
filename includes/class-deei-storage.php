<?php
/**
 * Plugin-owned transactional tables and bounded report queries.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manage plugin-owned InnoDB schema, row writes and retention. */
final class DEEI_Storage {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Private, versioned plugin-owned tables require transactional SQL.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table identifiers are fixed plugin-owned names; all variable values are prepared.
	/**
	 * Return this site’s plugin table names.
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			'runs'     => $wpdb->prefix . 'deei_runs',
			'rows'     => $wpdb->prefix . 'deei_rows',
			'coverage' => $wpdb->prefix . 'deei_coverage',
			'lock'     => $wpdb->prefix . 'deei_lock',
		);
	}

	/**
	 * Create four InnoDB tables; fail activation if transactional storage is unavailable.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::tables();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			array(
				"CREATE TABLE {$t['runs']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					run_id char(36) NOT NULL,
					status varchar(24) NOT NULL,
					high_water_id bigint(20) unsigned NOT NULL DEFAULT 0,
					cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
					started_at_utc datetime NOT NULL,
					finished_at_utc datetime NULL,
					scanned_count bigint(20) unsigned NOT NULL DEFAULT 0,
					skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
					candidate_count bigint(20) unsigned NOT NULL DEFAULT 0,
					unresolved_relative_count bigint(20) unsigned NOT NULL DEFAULT 0,
					unsupported_count bigint(20) unsigned NOT NULL DEFAULT 0,
					first_party_count bigint(20) unsigned NOT NULL DEFAULT 0,
					invalid_count bigint(20) unsigned NOT NULL DEFAULT 0,
					ignored_count bigint(20) unsigned NOT NULL DEFAULT 0,
					unclassified_count bigint(20) unsigned NOT NULL DEFAULT 0,
					ambiguous_count bigint(20) unsigned NOT NULL DEFAULT 0,
					error varchar(80) NOT NULL DEFAULT '',
					PRIMARY KEY  (id),
					UNIQUE KEY run_id (run_id),
					KEY status (status)
				) ENGINE=InnoDB $c",
				"CREATE TABLE {$t['rows']} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					run_id char(36) NOT NULL,
					post_id bigint(20) unsigned NOT NULL,
					host_key varchar(260) NOT NULL,
					host_digest char(64) NOT NULL,
					scheme varchar(8) NOT NULL,
					source_kind varchar(20) NOT NULL,
					attribute_name varchar(16) NOT NULL,
					evidence varchar(24) NOT NULL,
					occurrence_count smallint(5) unsigned NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY logical_row (run_id,post_id,host_digest,scheme,source_kind,attribute_name,evidence),
					KEY report_host (run_id,host_digest,id),
					KEY report_post (run_id,post_id,id)
				) ENGINE=InnoDB $c",
				"CREATE TABLE {$t['coverage']} (
					run_id char(36) NOT NULL,
					post_id bigint(20) unsigned NOT NULL,
					content_hash char(64) NOT NULL,
					post_modified_gmt_at_scan datetime NOT NULL,
					reason varchar(48) NOT NULL DEFAULT '',
					PRIMARY KEY  (run_id,post_id),
					KEY report_post (post_id)
				) ENGINE=InnoDB $c",
				"CREATE TABLE {$t['lock']} (
					id tinyint(3) unsigned NOT NULL,
					PRIMARY KEY  (id)
				) ENGINE=InnoDB $c",
			)
		);
		if ( ! self::transactional() ) {
			wp_die( esc_html__( 'External Embed Inventory requires InnoDB for safe resumable scans.', 'digicyfr-external-embed-inventory' ) );
		}
		$wpdb->query( "INSERT IGNORE INTO {$t['lock']} (id) VALUES (1)" );
		if ( $wpdb->last_error ) {
			wp_die( esc_html__( 'External Embed Inventory could not initialize its scan lock.', 'digicyfr-external-embed-inventory' ) );
		}
		add_option( 'deei_schema_version', '1', '', false );
		add_option( 'deei_current_run', array(), '', false );
		add_option( 'deei_active_complete_run_id', '', '', false );
	}

	/**
	 * Check every plugin table's actual engine before relying on rollback.
	 */
	public static function transactional(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( $wpdb->last_error || 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Start a transaction and acquire the singleton before reading current state.
	 */
	public static function begin_locked(): bool {
		global $wpdb;
		$t = self::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$id = $wpdb->get_var( "SELECT id FROM {$t['lock']} WHERE id = 1 FOR UPDATE" );
		if ( $wpdb->last_error || 1 !== (int) $id ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
	}

	/**
	 * Commit a plugin transaction.
	 */
	public static function commit(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' ) && ! $wpdb->last_error;
	}

	/**
	 * Roll back a plugin transaction.
	 */
	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Replace rows and coverage for one post in the caller's transaction.
	 *
	 * @param string  $run_id Run id.
	 * @param WP_Post $post Post.
	 * @param array   $result Result.
	 * @param string  $reason Reason.
	 */
	public static function replace_post( string $run_id, WP_Post $post, array $result, string $reason = '' ): bool|WP_Error {
		global $wpdb;
		$t = self::tables();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['rows']} WHERE run_id = %s AND post_id = %d", $run_id, $post->ID ) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'storage_write_failed' );
		}
		foreach ( $result['rows'] as $row ) {
			$host = (string) $row['host_key'];
			if ( strlen( $host ) > 260 || ! preg_match( '/^[\x21-\x7e]+$/', $host ) ) {
				return new WP_Error( 'invalid_host_key' );
			}
			$digest = apply_filters( 'deei_host_digest', hash( 'sha256', $host ), $host );
			if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
				return new WP_Error( 'invalid_digest' );
			}
			$other = $wpdb->get_var( $wpdb->prepare( "SELECT host_key FROM {$t['rows']} WHERE run_id = %s AND post_id = %d AND host_digest = %s LIMIT 1", $run_id, $post->ID, $digest ) );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'storage_read_failed' );
			}
			if ( null !== $other && $host !== $other ) {
				return new WP_Error( 'host_digest_collision' );
			}
			$ok = $wpdb->insert(
				$t['rows'],
				array(
					'run_id'           => $run_id,
					'post_id'          => $post->ID,
					'host_key'         => $host,
					'host_digest'      => $digest,
					'scheme'           => $row['scheme'],
					'source_kind'      => $row['source_kind'],
					'attribute_name'   => $row['attribute_name'],
					'evidence'         => $row['evidence'],
					'occurrence_count' => $row['occurrence_count'],
				)
			);
			if ( false === $ok || $wpdb->last_error ) {
				return new WP_Error( 'storage_write_failed' );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['coverage']} WHERE run_id = %s AND post_id = %d", $run_id, $post->ID ) );
		if ( $wpdb->last_error ) {
			return new WP_Error( 'storage_write_failed' );
		}
		$ok = $wpdb->insert(
			$t['coverage'],
			array(
				'run_id'                    => $run_id,
				'post_id'                   => $post->ID,
				'content_hash'              => hash( 'sha256', $post->post_content ),
				'post_modified_gmt_at_scan' => $post->post_modified_gmt,
				'reason'                    => $reason,
			)
		);
		return false !== $ok && ! $wpdb->last_error;
	}

	/**
	 * Record a post skipped before its content is loaded.
	 *
	 * @param string $run_id Run id.
	 * @param int    $post_id Post id.
	 * @param string $modified_gmt Modified gmt.
	 * @param string $reason Reason.
	 */
	public static function replace_skipped( string $run_id, int $post_id, string $modified_gmt, string $reason ): bool {
		global $wpdb;
		$t = self::tables();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['rows']} WHERE run_id = %s AND post_id = %d", $run_id, $post_id ) );
		if ( $wpdb->last_error ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['coverage']} WHERE run_id = %s AND post_id = %d", $run_id, $post_id ) );
		if ( $wpdb->last_error ) {
			return false;
		}
		return false !== $wpdb->insert(
			$t['coverage'],
			array(
				'run_id'                    => $run_id,
				'post_id'                   => $post_id,
				'content_hash'              => '',
				'post_modified_gmt_at_scan' => $modified_gmt,
				'reason'                    => $reason,
			)
		) && ! $wpdb->last_error;
	}

	/**
	 * Remove all reports except a checked current attempt and active complete run.
	 *
	 * @param array $keep Keep.
	 */
	public static function prune( array $keep ): bool {
		global $wpdb;
		$t    = self::tables();
		$keep = array_values( array_unique( array_filter( $keep, static fn ( $v ) => is_string( $v ) && preg_match( '/^[a-f0-9-]{36}$/', $v ) ) ) );
		if ( ! $keep ) {
			return false;
		}
		$p = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
		foreach ( array( 'rows', 'coverage', 'runs' ) as $name ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t[$name]} WHERE run_id NOT IN ($p)", ...$keep ) );
			if ( $wpdb->last_error ) {
				return false;
			}
		}
		return true;
	}
}
