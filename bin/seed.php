<?php
/**
 * Seed script: generate ~3,000 test posts with random Swipe Statements
 * and verdicts, for load-testing the deck/swipe REST endpoints.
 *
 * Usage (from the site root, with WP-CLI installed):
 *   wp eval-file wp-content/plugins/wellactually/bin/seed.php
 *
 * Safe to re-run; it only adds new posts, it never touches existing ones.
 *
 * @package WellActually
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run this with WP-CLI: wp eval-file bin/seed.php\n";
	exit;
}

$count      = isset( $args[0] ) ? absint( $args[0] ) : 3000;
$statements = array(
	'Temp tables are faster than CTEs',
	'You should always rebuild your indexes weekly',
	'NOLOCK is safe to use in production reports',
	'RAID 5 is fine for database storage',
	'Auto-growth settings don\'t matter if you have SSDs',
	'Stored procedures always outperform ORM-generated queries',
	'You should shrink your database files regularly',
	'More indexes are always better for performance',
	'Backup compression always speeds up backups',
	'Restarting SQL Server fixes most performance problems',
);

$verdicts = array( 'true', 'false', 'debatable' );

WP_CLI::log( "Seeding {$count} test posts…" );

for ( $i = 0; $i < $count; $i++ ) {
	$statement = $statements[ array_rand( $statements ) ] . ' (test #' . ( $i + 1 ) . ')';
	$verdict   = $verdicts[ array_rand( $verdicts ) ];

	$seeded_id = wp_insert_post(
		array(
			'post_title'   => 'WellActually seed post ' . ( $i + 1 ),
			'post_content' => '<p>This is seeded test content for load-testing WellActually swipe mode.</p>',
			'post_excerpt' => 'Seeded test post for swipe mode load testing.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);

	if ( is_wp_error( $seeded_id ) || ! $seeded_id ) {
		continue;
	}

	update_post_meta( $seeded_id, '_wellactually_statement', $statement );
	update_post_meta( $seeded_id, '_wellactually_verdict', $verdict );

	if ( 0 === $i % 500 ) {
		WP_CLI::log( "…{$i} posts created" );
	}
}

WP_CLI::success( "Done. Created up to {$count} seeded posts." );
