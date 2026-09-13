<?php
/**
 * WP-CLI: fetch sets from YouTube, drain the admin queue, import folders.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp callboard` commands.
 */
final class Cli {

	/**
	 * Register with WP-CLI.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'callboard', self::class );
	}

	/**
	 * Fetch a YouTube video or playlist as a new set.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : YouTube video or playlist URL. A search also works, using yt-dlp's own
	 * ytsearch:/ytsearchN: syntax — Callboard then keeps only the best of the results: an
	 * auto-generated "Topic" upload first, then a channel YouTube has verified, skipping
	 * obvious live versions, covers and remixes where another copy exists.
	 *
	 * --name=<name>
	 * : Set name shown to the cast.
	 *
	 * [--slug=<slug>]
	 * : Folder and URL slug. Defaults to the name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp callboard fetch 'https://www.youtube.com/playlist?list=…' --name="Spring Show"
	 *     wp callboard fetch 'ytsearch1:queen bohemian rhapsody' --name="Bohemian Rhapsody"
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function fetch( array $args, array $assoc_args ): void {
		$url  = (string) $args[0];
		$name = (string) ( $assoc_args['name'] ?? '' );
		$slug = sanitize_title( (string) ( $assoc_args['slug'] ?? $name ) );
		if ( '' === $name || '' === $slug ) {
			WP_CLI::error( 'Give the set a --name.' );
		}
		self::require_tools();
		$manifest = Fetcher::fetch( $url, $slug, $name, static fn( string $line ) => WP_CLI::log( '  ' . $line ) );
		if ( is_wp_error( $manifest ) ) {
			WP_CLI::error( $manifest->get_error_message() );
		}
		$result = Importer::import_folder( Importer::source_dir() . '/' . $slug );
		do_action( 'callboard_imported', array( $slug => $result ) );
		WP_CLI::success( $result . ' ' . home_url( '/' . $slug . '/' ) );
	}

	/**
	 * Fetch everything queued from Sets → Import → "From YouTube".
	 *
	 * ## OPTIONS
	 *
	 * [--watch]
	 * : Keep polling for new requests.
	 *
	 * [--interval=<seconds>]
	 * : Seconds between polls with --watch.
	 * ---
	 * default: 60
	 * ---
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function run( array $args, array $assoc_args ): void {
		self::require_tools();
		do {
			$queued = Requests::all( 'queued' );
			if ( ! $queued ) {
				WP_CLI::log( 'Nothing queued.' );
			}
			foreach ( $queued as $post ) {
				$r = Requests::to_array( $post );
				WP_CLI::log( sprintf( '→ %s (%s) %s', $r['name'], $r['slug'], $r['url'] ) );
				Requests::set_status( $r['id'], 'running', 'fetching' );
				$manifest = Fetcher::fetch( $r['url'], $r['slug'], $r['name'], static fn( string $line ) => WP_CLI::log( '  ' . $line ) );
				if ( is_wp_error( $manifest ) ) {
					Requests::set_status( $r['id'], 'failed', $manifest->get_error_message() );
					WP_CLI::warning( $manifest->get_error_message() );
					continue;
				}
				$result = Importer::import_folder( Importer::source_dir() . '/' . $r['slug'] );
				Requests::set_status( $r['id'], Sets::post_by_slug( $r['slug'] ) ? 'done' : 'failed', $result );
				do_action( 'callboard_imported', array( $r['slug'] => $result ) );
				WP_CLI::success( $result );
			}
			if ( isset( $assoc_args['watch'] ) ) {
				sleep( max( 5, (int) ( $assoc_args['interval'] ?? 60 ) ) );
			}
		} while ( isset( $assoc_args['watch'] ) );
	}

	/**
	 * Write a set out as a .callboard file.
	 *
	 * A whole set in one file: the audio, the order, the levels, the lyrics, the notes and the
	 * tempo. Unzip it and it is an import folder; leave it zipped and `wp callboard import
	 * --file=` reads it back on another site.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The set's slug.
	 *
	 * [--format=<format>]
	 * : `file` for a .callboard file, or `car` for a plain folder of tagged mp3s to copy
	 * onto a USB stick. Default: file.
	 * ---
	 * default: file
	 * options:
	 *   - file
	 *   - car
	 * ---
	 *
	 * [--out=<path>]
	 * : Where to write it. A file path for `file`, a directory to write the set's folder
	 * inside for `car`. Defaults to the working directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp callboard export spring-show
	 *     wp callboard export spring-show --out=/tmp/spring.callboard
	 *     wp callboard export spring-show --format=car --out=/Volumes/USB
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function export( array $args, array $assoc_args ): void {
		$slug = sanitize_title( (string) ( $args[0] ?? '' ) );
		$set  = $slug ? Sets::post_by_slug( $slug ) : null;
		if ( ! $set ) {
			WP_CLI::error( sprintf( 'No set with the slug "%s".', $slug ) );
		}

		if ( 'car' === ( $assoc_args['format'] ?? 'file' ) ) {
			$result = Exporter::write_folder( $set, (string) ( $assoc_args['out'] ?? getcwd() ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%s: %s', $set->post_title, $result ) );
			return;
		}

		$path   = (string) ( $assoc_args['out'] ?? getcwd() . '/' . Exporter::filename( $set ) );
		$result = Exporter::write( $set, $path );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( '%s: %s (%s)', $set->post_title, $result, size_format( (int) filesize( $result ) ) ) );
	}

	/**
	 * Import every set folder in the uploads/callboard directory, or one .callboard file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Import this .callboard file instead of scanning the import directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp callboard import
	 *     wp callboard import --file=spring-show.callboard
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function import( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		if ( isset( $assoc_args['file'] ) ) {
			$file = (string) $assoc_args['file'];
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( sprintf( 'Cannot read %s', $file ) );
			}
			$dir = Exporter::unpack( $file );
			if ( is_wp_error( $dir ) ) {
				WP_CLI::error( $dir->get_error_message() );
			}
			WP_CLI::success( Importer::import_folder( $dir ) );
			return;
		}

		$results = Importer::import_all();
		if ( ! $results ) {
			WP_CLI::log( 'Nothing to import in ' . Importer::source_dir() );
			return;
		}
		foreach ( $results as $slug => $message ) {
			WP_CLI::log( sprintf( '%s: %s', $slug, $message ) );
		}
		WP_CLI::success( 'Imported.' );
	}

	/**
	 * Measure loudness envelopes for a set folder that was fetched before levels existed, then re-import it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The set folder name under the import directory.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function levels( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$dir    = Importer::source_dir() . '/' . sanitize_title( (string) $args[0] );
		$ffmpeg = Fetcher::tools()['ffmpeg'];
		if ( ! is_dir( $dir ) ) {
			WP_CLI::error( 'No folder at ' . $dir );
		}
		if ( ! $ffmpeg ) {
			WP_CLI::error( 'ffmpeg is not on PATH.' );
		}
		$levels = Fetcher::levels_for_dir( $dir, $ffmpeg, static fn( string $line ) => WP_CLI::log( '  ' . $line ) );
		if ( ! $levels ) {
			WP_CLI::error( 'No audio files with [id] names in ' . $dir );
		}
		file_put_contents( $dir . '/levels.json', wp_json_encode( $levels ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		WP_CLI::log( Importer::import_folder( $dir ) );
		WP_CLI::success( 'Levels written for ' . count( $levels ) . ' tracks.' );
	}

	/**
	 * Send a notice to everyone subscribed to notifications.
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : The notice text.
	 *
	 * [--title=<title>]
	 * : Notification title. Defaults to the site name.
	 *
	 * [--url=<url>]
	 * : Where a tap goes. Defaults to the home page.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function notify( array $args, array $assoc_args ): void {
		$result = Push::send( (string) ( $assoc_args['title'] ?? callboard_site_name() ), (string) $args[0], (string) ( $assoc_args['url'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Sent to %d devices, %d failed, %d expired subscriptions removed.', $result['sent'], $result['failed'], $result['pruned'] ) );
	}

	/**
	 * Turn legacy `_callboard_notes` post meta into `callboard_note` comments.
	 *
	 * New notes are already saved as comments. This rewrites arrays that were stored before that,
	 * leaving the old meta in place so a downgrade still has something to read. Safe to run more
	 * than once: a track that already has note comments is skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count tracks and notes without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp callboard migrate-notes
	 *     wp callboard migrate-notes --dry-run
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function migrate_notes( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$dry    = isset( $assoc_args['dry-run'] );
		$result = Notes::migrate( $dry );
		$msg    = sprintf(
			/* translators: 1: number of tracks, 2: number of notes, 3: number of tracks skipped. */
			__( '%1$d tracks, %2$d notes, %3$d already migrated.', 'callboard' ),
			$result['tracks'],
			$result['notes'],
			$result['skipped']
		);
		if ( $dry ) {
			WP_CLI::success( 'Dry run: ' . $msg );
			return;
		}
		WP_CLI::success( $msg );
	}

	/**
	 * Show which fetch tools this environment has.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 */
	public function doctor( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$tools = Fetcher::tools();
		WP_CLI::log( 'proc_open: ' . ( function_exists( 'proc_open' ) ? 'available' : 'disabled' ) );
		WP_CLI::log( 'yt-dlp:    ' . ( $tools['ytdlp'] ?? 'not found' ) );
		WP_CLI::log( 'ffmpeg:    ' . ( $tools['ffmpeg'] ?? 'not found (audio will be kept as m4a)' ) );
		WP_CLI::log( 'GD:        ' . ( function_exists( 'imagettftext' ) ? 'available' : 'missing (no artwork will be drawn)' ) );
		WP_CLI::log( 'import dir: ' . Importer::source_dir() );
	}

	/**
	 * Stop early with a clear message when fetching is impossible here.
	 */
	private static function require_tools(): void {
		if ( ! Fetcher::available() ) {
			WP_CLI::error( 'Fetching needs proc_open and yt-dlp on this machine. Run `wp callboard doctor`, or build the set folder elsewhere and use `wp callboard import`.' );
		}
	}
}
