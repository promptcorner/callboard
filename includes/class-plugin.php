<?php
/**
 * Wires the plugin together.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Register every component.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
		Extensions::register_hooks();
		Post_Types::register_hooks();
		Meta::register_hooks();
		Calls::register_hooks();
		Sets::register_hooks();
		Importer::register_hooks();
		Exporter::register_hooks();
		Requests::register_hooks();
		Push::register_hooks();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
		Router::register_hooks();
		Frontend::register_hooks();
		Privacy::register_hooks();
		Pwa::register_hooks();
		if ( is_admin() ) {
			Admin::register_hooks();
		}
	}

	/**
	 * After an update: add any missing roles, drop cached data shaped by the old version and refresh the app files.
	 */
	public static function maybe_upgrade(): void {
		// Checked apart from the plugin version, so a site that already has this version still gets the roles.
		if ( (int) get_option( Roles::OPTION ) < Roles::VERSION ) {
			Roles::install();
		}
		if ( get_option( 'callboard_version' ) === CALLBOARD_VERSION ) {
			return;
		}
		update_option( 'callboard_version', CALLBOARD_VERSION, false );
		Sets::flush();
		Importer::import_all(); // sidecar files (levels, notes, tempo) added by a deploy land here.
		Pwa::write_files();
	}

	/**
	 * Activation: add the roles, register types, write PWA files, import anything waiting.
	 */
	public static function activate(): void {
		Roles::install();
		Post_Types::register();
		if ( ! get_option( 'permalink_structure' ) ) { // /<set>/ routes need pretty permalinks.
			update_option( 'permalink_structure', '/%postname%/' );
		}
		Importer::import_all(); // Before the manifest, so shortcuts and artwork reflect the sets.
		Pwa::write_files();
		flush_rewrite_rules();
	}
}
