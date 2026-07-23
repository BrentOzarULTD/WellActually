<?php
/**
 * Work queue for AI drafting.
 *
 * Drafting runs several requests at once, which makes handing out work a
 * genuine concurrency problem: two workers must never draft the same post
 * (it's billable twice and the results race), a sweep that reclaims an
 * abandoned item must not clobber a result that landed a moment earlier,
 * and the configured ceiling has to hold no matter how many browser tabs
 * are open.
 *
 * Post meta can't express any of that safely. There's no uniqueness
 * constraint available on it, so "check then write" always leaves a window
 * open no matter how the check is arranged. A table can: `post_id` is
 * UNIQUE, so admission is a single INSERT IGNORE that either wins or
 * doesn't, and every state change is one conditional UPDATE whose
 * affected-row count *is* the answer.
 *
 * Rows exist only while work is outstanding. Finishing an item deletes its
 * row; the drafted suggestion (or error) lives on in post meta as before, so
 * this table stays small and needs no pruning.
 *
 * @package WellActually
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic queue for AI drafting work items.
 */
class WA_AI_Queue {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'wa_ai_queue_db_version';

	const STATUS_QUEUED     = 'queued';
	const STATUS_PROCESSING = 'processing';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wa_ai_queue';
	}

	/**
	 * Create or update the table.
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// UNIQUE on post_id is the point of this table: it makes "this post
		// is already in the queue" a constraint the database enforces, not a
		// race for application code to lose.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			batch_id VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			claim_token VARCHAR(32) NOT NULL DEFAULT '',
			claimed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY batch_status (batch_id, status),
			KEY status_claimed (status, claimed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Create the table if it's missing or out of date.
	 */
	public static function maybe_upgrade_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * A new batch identifier.
	 *
	 * @return string
	 */
	public static function new_batch_id() {
		return wp_generate_password( 24, false, false );
	}

	/**
	 * Admit posts into a batch, skipping any that are already outstanding.
	 *
	 * INSERT IGNORE against the UNIQUE post_id means a post that another
	 * batch (or another request racing this one) already holds is silently
	 * skipped rather than stolen — the row simply isn't created, and the
	 * existing owner's claim is left completely untouched.
	 *
	 * @param int[]  $post_ids Candidate post IDs.
	 * @param string $batch_id Batch to admit them into.
	 * @return int[] The post IDs actually admitted by this call.
	 */
	public static function admit( array $post_ids, $batch_id ) {
		global $wpdb;

		$table    = self::table_name();
		$now      = time();
		$admitted = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} ( post_id, batch_id, status, claim_token, claimed_at, created_at )
					 VALUES ( %d, %s, %s, '', 0, %d )",
					$post_id,
					$batch_id,
					self::STATUS_QUEUED,
					$now
				)
			);

			if ( $inserted ) {
				$admitted[] = $post_id;
			}
		}

		return $admitted;
	}

	/**
	 * Claim the next item in a batch, respecting the concurrency ceiling.
	 *
	 * The capacity check and the claim have to happen together — counting
	 * active work and then claiming would let several simultaneous requests
	 * all see spare capacity and all proceed. A named database lock makes the
	 * pair atomic across every PHP process on the site, which is the only
	 * scope that actually matters here (the ceiling exists to protect the
	 * provider account and the site's PHP workers, both shared site-wide).
	 *
	 * @param string $batch_id Batch to claim from.
	 * @param int    $limit    Maximum simultaneous in-flight items.
	 * @return array|string {post_id, token} on success, 'at_capacity' when the
	 *                      ceiling is reached, 'busy' when the claim lock could
	 *                      not be acquired (retry), or 'empty' when the batch
	 *                      has no queued work left. 'at_capacity' and 'busy' are
	 *                      both retryable; 'empty' is terminal for the batch.
	 */
	public static function claim( $batch_id, $limit ) {
		global $wpdb;

		$table = self::table_name();
		$lock  = 'wa_ai_claim_' . substr( md5( $table ), 0, 16 );

		// A short wait: if another claim is mid-flight we'd rather queue
		// briefly than give up and report the batch finished.
		$got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, 3 ) );

		// MySQL returns 1 when the lock is ours, 0 on timeout, and NULL on a
		// server/connection error. The count-and-claim below is only atomic
		// while we hold the lock: without it, two callers that both failed to
		// acquire it would each count the same in-flight rows, each see spare
		// capacity, and each claim another — which is exactly the concurrency
		// ceiling this lock exists to enforce, defeated. So if the lock isn't
		// ours, change no queue state and report 'busy'; the caller retries,
		// the same way it does for the ceiling itself.
		if ( 1 !== (int) $got_lock ) {
			if ( WA_Settings::debug_logging_enabled() ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'[wellactually] AI queue claim could not acquire lock %s (GET_LOCK returned %s); reporting busy.',
						$lock,
						null === $got_lock ? 'NULL (error)' : "'" . (string) $got_lock . "'"
					)
				);
			}
			return 'busy';
		}

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			$active = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PROCESSING )
			);

			if ( $active >= max( 1, (int) $limit ) ) {
				return 'at_capacity';
			}

			$token = wp_generate_password( 24, false, false );

			// One statement picks the row and marks it ours. Nothing else can
			// select the same row, because the UPDATE takes the row lock.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = %s, claim_token = %s, claimed_at = %d
					 WHERE batch_id = %s AND status = %s
					 ORDER BY id ASC
					 LIMIT 1",
					self::STATUS_PROCESSING,
					$token,
					time(),
					$batch_id,
					self::STATUS_QUEUED
				)
			);

			if ( ! $claimed ) {
				return 'empty';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			$post_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT post_id FROM {$table} WHERE claim_token = %s", $token )
			);

			if ( ! $post_id ) {
				return 'empty';
			}

			return array(
				'post_id' => $post_id,
				'token'   => $token,
			);
		} finally {
			// We only reach here holding the lock (a failed acquire returned
			// above), so always release it — on success, on an empty batch,
			// and if the critical section threw.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Finish an item, but only if this caller still owns it.
	 *
	 * This is the fence that makes late results safe to discard. If a stale
	 * sweep reclaimed the item while the provider call was still running, the
	 * token no longer matches, nothing is deleted, and the caller knows not to
	 * store a result over whoever owns it now.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   The token handed out by claim().
	 * @return bool True when this caller still owned the item.
	 */
	public static function complete( $post_id, $token ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND claim_token = %s",
				absint( $post_id ),
				$token
			)
		);

		return (bool) $deleted;
	}

	/**
	 * Hand an item back to the queue without consuming it.
	 *
	 * Used when the work couldn't be done for a reason that isn't the post's
	 * fault — a provider rate limit — so it stays available for a later run
	 * instead of being recorded as a failed draft. Fenced by the claim token,
	 * like complete(), so a caller that already lost ownership can't disturb
	 * whoever holds it now.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   The token handed out by claim().
	 * @return bool True if this caller still owned the item and released it.
	 */
	public static function release( $post_id, $token ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$released = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, claim_token = '', claimed_at = 0
				 WHERE post_id = %d AND claim_token = %s",
				self::STATUS_QUEUED,
				absint( $post_id ),
				$token
			)
		);

		return (bool) $released;
	}

	/**
	 * Return abandoned claims to the queue.
	 *
	 * A single conditional statement, so there's no window between deciding a
	 * claim is stale and acting on it — an item that finished in the meantime
	 * has already had its row deleted and simply isn't matched.
	 *
	 * @param int $timeout Seconds after which a claim counts as abandoned.
	 * @return int Number of items released.
	 */
	public static function release_stale( $timeout ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = time() - max( 1, (int) $timeout );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$released = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, claim_token = '', claimed_at = 0
				 WHERE status = %s AND claimed_at > 0 AND claimed_at < %d",
				self::STATUS_QUEUED,
				self::STATUS_PROCESSING,
				$cutoff
			)
		);

		return (int) $released;
	}

	/**
	 * Every post_id currently in the queue, across all batches and statuses.
	 *
	 * Rows exist only while work is outstanding, so this list is small (a few
	 * hundred at most). Candidate selection uses it to skip posts another run
	 * already holds, which would otherwise be selected, refused admission, and
	 * wasted — the cause of a request for N drafts filling fewer. (Issue #28.)
	 *
	 * @return int[]
	 */
	public static function queued_post_ids() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$ids = $wpdb->get_col( "SELECT post_id FROM {$table}" );

		return array_map( 'intval', $ids );
	}

	/**
	 * Outstanding work in a batch.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array { queued: int, processing: int, remaining: int }
	 */
	public static function batch_counts( $batch_id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE batch_id = %s GROUP BY status",
				$batch_id
			)
		);

		$counts = array(
			'queued'     => 0,
			'processing' => 0,
			'remaining'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->total;
			}
		}

		$counts['remaining'] = $counts['queued'] + $counts['processing'];

		return $counts;
	}

	/**
	 * Delete queued items that were never started and are older than a
	 * cutoff, so a batch whose browser never came back doesn't strand its
	 * posts forever (post_id is UNIQUE, so a stranded row would block that
	 * post from ever being drafted again).
	 *
	 * Only untouched `queued` rows are collected — anything in flight is left
	 * to release_stale(), which understands claims.
	 *
	 * @param int $older_than Seconds since the item was admitted.
	 * @return int Rows removed.
	 */
	public static function collect_abandoned( $older_than ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = time() - max( 60, (int) $older_than );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = %s AND created_at < %d",
				self::STATUS_QUEUED,
				$cutoff
			)
		);
	}

	/**
	 * Drop every outstanding item in a batch (used when a run is abandoned).
	 *
	 * @param string $batch_id Batch identifier.
	 * @return int Rows removed.
	 */
	public static function clear_batch( $batch_id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE batch_id = %s", $batch_id )
		);
	}
}
