<?php
/**
 * Plugin Name: Callboard fixture guard (development only)
 * Description: Refuses to unlink anything inside the mapped fixture folder.
 *
 * .wp-env.json maps wp-content/uploads/callboard onto tests/fixtures/callboard in the working copy,
 * so every WordPress code path that deletes an attachment's file is pointed straight at a
 * developer's audio. wp_delete_attachment( $id, true ) does it, wp_delete_post() on an attachment
 * quietly routes to the same place, WP_UnitTestCase does it between tests, and any stray
 * `wp eval` doing housekeeping does it too. All of them go through wp_delete_file().
 *
 * Core skips the unlink when the filtered path is empty, so returning '' is a refusal rather than an
 * error: the attachment row still goes, the bytes stay. The worst case is a stale intermediate PNG
 * that the next import overwrites, which is a much better worst case than the other one.
 *
 * Development only. It is mapped in by .wp-env.json and lives under tests/, which package.json's
 * `files` list excludes, so it cannot reach the plugin zip.
 *
 * @package Callboard
 */

defined( 'ABSPATH' ) || exit;

// End-to-end runs exercise stable admin destinations, not the one-time activation redirect.
if ( ! wp_installing() ) {
	delete_option( 'callboard_onboarding_pending' );
}

add_filter(
	'wp_delete_file',
	static function ( $file ) {
		$guard = realpath( WP_CONTENT_DIR . '/uploads/callboard' );
		$path  = realpath( (string) $file );

		if ( $guard && $path && str_starts_with( $path, $guard . DIRECTORY_SEPARATOR ) ) {
			error_log( '[callboard] fixture guard kept ' . $path ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the whole point of this file is to say so.
			return '';
		}

		return $file;
	}
);
