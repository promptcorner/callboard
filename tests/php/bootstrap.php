<?php
/**
 * Boots the WordPress test suite with Callboard loaded.
 *
 * WP_TESTS_DIR is where wp-env mounts core's PHPUnit library inside the tests container
 * (/wordpress-phpunit). Nothing here is specific to wp-env beyond that default, so the same
 * bootstrap works against a suite installed by install-wp-tests.sh.
 *
 * @package Callboard
 */

$callboard_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $callboard_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test suite at {$callboard_tests_dir}.\nRun `npm run test:php`, which runs this inside the wp-env tests container.\n" );
	exit( 1 );
}

// Our own database configuration, so the suite gets its own table prefix rather than emptying the
// site on :8893. Core reads this as a constant, not an environment variable, and falls back to
// wp-env's own config — which shares the site's prefix — the moment it is not defined.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's own constant name, read by the test suite's bootstrap.
}

// `WP_TESTS_MULTISITE=1 ./vendor/bin/phpunit` runs the suite as a multisite network (npm run test:php:multisite).
if ( getenv( 'WP_TESTS_MULTISITE' ) && ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's own constant name, read by the test suite's bootstrap.
}

require_once $callboard_tests_dir . '/includes/functions.php';

/**
 * Somewhere disposable for anything the tests attach.
 *
 * .wp-env.json maps wp-content/uploads/callboard onto tests/fixtures/callboard on the host, and the
 * WordPress test suite hard-deletes every attachment it created between tests — which deletes the
 * file behind it. Without this, a green test run quietly deletes the fixture audio off the disk of
 * whoever ran it. Uploads go somewhere disposable instead, and the guard at the bottom of this file
 * refuses to start if they ever point back into the repository.
 */
$callboard_uploads = sys_get_temp_dir() . '/callboard-phpunit-uploads';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $callboard_uploads ) {
		if ( ! is_dir( $callboard_uploads ) ) {
			mkdir( $callboard_uploads, 0777, true );
		}

		add_filter(
			'upload_dir',
			static function ( array $dirs ) use ( $callboard_uploads ): array {
				$dirs['basedir'] = $callboard_uploads;
				$dirs['baseurl'] = 'http://example.org/wp-content/uploads';
				$dirs['path']    = $callboard_uploads . ( $dirs['subdir'] ?? '' );
				$dirs['url']     = $dirs['baseurl'] . ( $dirs['subdir'] ?? '' );
				return $dirs;
			}
		);

		require dirname( __DIR__, 2 ) . '/callboard.php';
	}
);

require $callboard_tests_dir . '/includes/bootstrap.php';

/*
 * The guard. An attachment deleted by the test suite takes its file with it, and .wp-env.json maps
 * wp-content/uploads/callboard onto tests/fixtures/callboard on the host — so anything the suite
 * writes or deletes under wp-content may be landing in somebody's working copy. That is the
 * invariant worth asserting, rather than a path this file already controls: test uploads live
 * outside wp-content, or the run stops here instead of halfway through deleting the fixtures.
 */
$callboard_basedir = (string) realpath( wp_upload_dir()['basedir'] );
$callboard_content = (string) realpath( WP_CONTENT_DIR );

if ( '' === $callboard_basedir || str_starts_with( $callboard_basedir, $callboard_content ) ) {
	fwrite(
		STDERR,
		"Refusing to run: uploads resolve to {$callboard_basedir}, inside {$callboard_content}.\n"
		. "wp-content/uploads/callboard is mapped to tests/fixtures/callboard, and the test suite deletes\n"
		. "attachments along with the files behind them, so this run would delete the fixture audio.\n"
	);
	exit( 1 );
}
