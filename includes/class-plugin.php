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
	 * Set on activation until an administrator reaches the setup screen.
	 */
	public const ONBOARDING_OPTION = 'callboard_onboarding_pending';

	/**
	 * Register every component.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		Extensions::register_hooks();
		Post_Types::register_hooks();
		Meta::register_hooks();
		Sets::register_hooks();
		Importer::register_hooks();
		Exporter::register_hooks();
		Requests::register_hooks();
		Abilities::register_hooks();
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
		delete_option( 'callboard_cue' ); // Left by the shared cue, which shipped in 2.4.0 and was then removed.
		self::remove_notifications();
		self::trash_notes();
		Sets::flush();
		Importer::import_all(); // sidecar files (levels) added by a deploy land here.
		Pwa::write_files();
	}

	/**
	 * Notifications were removed in 3.0.0. Their subscriptions are live push credentials with nothing
	 * left to send to them, so they go, with the server's signing keys. Calls stay in the database,
	 * like any other content a removed feature leaves behind.
	 */
	private static function remove_notifications(): void {
		$subscriptions = get_posts(
			array(
				'post_type'      => 'callboard_subscriber',
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $subscriptions as $id ) {
			wp_delete_post( (int) $id, true );
		}
		delete_option( 'callboard_vapid' );
	}

	/**
	 * Director's notes were removed in 3.0.0. They are comments on the track, which only Callboard's
	 * own filters kept out of the Comments screen and the comment counts, so they go to the trash:
	 * out of sight, and still there to restore until WordPress empties it.
	 */
	private static function trash_notes(): void {
		$notes = get_comments(
			array(
				'type'   => 'callboard_note',
				'status' => 'all',
				'fields' => 'ids',
			)
		);
		foreach ( $notes as $id ) {
			wp_trash_comment( (int) $id );
		}
	}

	/**
	 * Activation: add roles, register types, write PWA files, import anything waiting.
	 *
	 * @since 2.3.0 Accepts `$network_wide`, and sets up every site of a network.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		Roles::install();
		Post_Types::register();
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::prepare_site();
				restore_current_blog();
			}
			return;
		}
		if ( ! get_option( 'permalink_structure' ) ) { // /<set>/ routes need pretty permalinks.
			update_option( 'permalink_structure', '/%postname%/' );
		}
		Importer::import_all(); // Before the manifest, so shortcuts and artwork reflect the sets.
		Pwa::write_files();
		flush_rewrite_rules();
		update_option( self::ONBOARDING_OPTION, '1', false );
	}

	/**
	 * Set up a site added to a network where Callboard is network-activated.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active_for_network( plugin_basename( CALLBOARD_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::prepare_site();
		restore_current_blog();
	}

	/**
	 * Get one network site ready, from a request that has switched to it.
	 *
	 * The import and the app files wait for the site's own next request, through maybe_upgrade(): while
	 * switched, plugin and script URLs still belong to the site the request started on.
	 */
	private static function prepare_site(): void {
		if ( ! get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		delete_option( 'rewrite_rules' ); // flush_rewrite_rules() would build them from the wrong site's settings.
		delete_option( 'callboard_version' );
	}

	/**
	 * Uninstall: remove what Callboard stored for each site. Playlists, tracks and uploaded files stay.
	 *
	 * @since 2.3.0
	 */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Remove the current site's options, cached data and generated files.
	 */
	private static function uninstall_site(): void {
		Roles::uninstall();
		if ( ! Pwa::serves_files() ) {
			foreach ( array( 'sw.js', 'manifest.json' ) as $file ) {
				// Only the copies Callboard wrote: another plugin may own a file with the same name.
				if ( str_contains( Pwa::contents( $file ), 'callboard' ) ) {
					wp_delete_file( ABSPATH . $file );
				}
			}
		}
		$splash = Pwa::splash_location()['dir'];
		foreach ( Pwa::splash_sizes() as $dims ) {
			foreach ( array( 'light', 'dark' ) as $scheme ) {
				wp_delete_file( sprintf( '%s/%s-%dx%d.png', $splash, $scheme, $dims[0], $dims[1] ) );
			}
		}
		foreach ( array( 'callboard_version', 'callboard_settings', 'callboard_vapid', 'callboard_import_stamp', 'callboard_splash_key', 'callboard_extension_assets', self::ONBOARDING_OPTION, Pwa::SW_OPTION, Pwa::MANIFEST_OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'callboard_sets_v1' );
		delete_transient( 'callboard_import_notice' );
	}
}
