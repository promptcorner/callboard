<?php
/**
 * Imports sets from folders in uploads/callboard/<slug>/ (manifest.json + audio files).
 *
 * The folder is an import format; once imported, posts are the source of truth.
 * Re-imports refresh order, credits and lyrics but never overwrite titles edited in the admin.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Folder importer.
 */
final class Importer {

	private const STAMP_OPTION = 'callboard_import_stamp';

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'maybe_import' ), 20 );
		add_action( 'admin_post_callboard_import', array( self::class, 'handle_admin_import' ) );
		add_action( 'admin_post_callboard_import_file', array( self::class, 'handle_admin_import_file' ) );
	}

	/**
	 * Where import folders live.
	 */
	public static function source_dir(): string {
		$dir = wp_upload_dir( null, false )['basedir'] . '/callboard';
		/**
		 * Filter the import directory.
		 *
		 * @param string $dir Absolute path.
		 */
		return (string) apply_filters( 'callboard_import_dir', $dir );
	}

	/**
	 * Import when the deploy stamp file has changed since the last run.
	 */
	public static function maybe_import(): void {
		$stamp_file = self::source_dir() . '/.deploy';
		if ( ! file_exists( $stamp_file ) ) {
			return;
		}
		$stamp = trim( (string) file_get_contents( $stamp_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $stamp && get_option( self::STAMP_OPTION ) !== $stamp ) {
			update_option( self::STAMP_OPTION, $stamp, false );
			self::import_all();
		}
	}

	/**
	 * Import every folder that has a manifest.
	 *
	 * @return array<string, string> slug => result message.
	 */
	public static function import_all(): array {
		$results   = array();
		$manifests = glob( self::source_dir() . '/*/manifest.json' );
		foreach ( is_array( $manifests ) ? $manifests : array() as $manifest ) {
			$slug             = basename( dirname( $manifest ) );
			$results[ $slug ] = self::import_folder( dirname( $manifest ) );
		}
		if ( $results ) {
			/**
			 * Fires after an import pass.
			 *
			 * @param array<string, string> $results Per-slug messages.
			 */
			do_action( 'callboard_imported', $results );
		}
		return $results;
	}

	/**
	 * Import one set folder.
	 *
	 * @param string $dir Absolute folder path.
	 * @return string Human-readable result.
	 */
	public static function import_folder( string $dir ): string {
		$slug = sanitize_title( basename( $dir ) );
		$data = json_decode( (string) file_get_contents( $dir . '/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return __( 'No manifest.', 'callboard' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$set = Sets::post_by_slug( $slug );
		if ( ! $set ) {
			$set_id = wp_insert_post(
				array(
					'post_type'   => Post_Types::SET,
					'post_status' => 'publish',
					'post_name'   => $slug,
					'post_title'  => sanitize_text_field( $data['name'] ?? ucwords( str_replace( '-', ' ', $slug ) ) ),
					'menu_order'  => (int) ( $data['order'] ?? 0 ),
				),
				true
			);
			if ( is_wp_error( $set_id ) ) {
				return $set_id->get_error_message();
			}
			$set = get_post( $set_id );
		}
		if ( ! $set instanceof WP_Post ) {
			return __( 'Could not create the set.', 'callboard' );
		}

		update_post_meta(
			$set->ID,
			'_callboard_credits',
			array(
				'playlist_url' => esc_url_raw( (string) ( $data['playlist_url'] ?? '' ) ),
				'curator'      => sanitize_text_field( (string) ( $data['curator'] ?? '' ) ),
				'curator_url'  => esc_url_raw( (string) ( $data['curator_url'] ?? '' ) ),
			)
		);
		$palette = sanitize_key( (string) ( $data['palette'] ?? '' ) );
		if ( in_array( $palette, Art::palettes(), true ) ) {
			update_post_meta( $set->ID, '_callboard_palette', $palette );
		}
		if ( file_exists( $dir . '/lyrics.approved' ) ) {
			update_post_meta( $set->ID, '_callboard_lyrics_approved', 1 );
		}

		$lyrics   = self::sidecar( $dir, 'lyrics' );
		$levels   = self::sidecar( $dir, 'levels' );
		$notes    = self::sidecar( $dir, 'notes' );
		$tempo    = self::sidecar( $dir, 'tempo' );
		$existing = array();
		foreach ( Sets::track_posts( $set->ID ) as $track ) {
			$existing[ (string) get_post_meta( $track->ID, '_callboard_video_id', true ) ] = $track->ID;
		}

		$added = 0;
		foreach ( (array) ( $data['tracks'] ?? array() ) as $t ) {
			$file = isset( $t['file'] ) ? $dir . '/' . $t['file'] : null;
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$video_id = sanitize_text_field( (string) ( $t['id'] ?? '' ) );
			$order    = (int) ( $t['index'] ?? 0 );
			$track_id = $existing[ $video_id ] ?? 0;

			if ( ! $track_id ) {
				$track_id = self::attach_file( $file, $set->ID, self::clean_title( (string) ( $t['title'] ?? $video_id ) ), $order );
				if ( ! $track_id ) {
					continue;
				}
				update_post_meta( $track_id, '_callboard_video_id', $video_id );
				++$added;
			} else {
				wp_update_post(
					array(
						'ID'          => $track_id,
						'menu_order'  => $order,
						'post_parent' => $set->ID,
					)
				);
			}
			update_post_meta( $track_id, '_callboard_source_url', esc_url_raw( (string) ( $t['url'] ?? '' ) ) );
			update_post_meta( $track_id, '_callboard_uploader', sanitize_text_field( (string) ( $t['uploader'] ?? '' ) ) );
			update_post_meta( $track_id, '_callboard_uploader_url', esc_url_raw( (string) ( $t['uploader_url'] ?? '' ) ) );
			if ( ! empty( $t['codec'] ) ) {
				// What Fetcher actually got, and whether it had to re-encode to get it: provenance
				// for a "this is a re-encode" display later, not a measurement of the file itself.
				update_post_meta( $track_id, '_callboard_codec', sanitize_text_field( (string) $t['codec'] ) );
				update_post_meta( $track_id, '_callboard_reencoded', ! empty( $t['reencoded'] ) ? 1 : 0 );
			}
			if ( isset( $t['duration'] ) ) {
				update_post_meta( $track_id, '_callboard_duration', (float) $t['duration'] );
			}
			if ( ! empty( $lyrics[ $video_id ] ) && is_array( $lyrics[ $video_id ] ) ) {
				update_post_meta( $track_id, '_callboard_lyrics', self::sanitize_cues( $lyrics[ $video_id ] ) );
			}
			if ( ! empty( $levels[ $video_id ] ) && is_string( $levels[ $video_id ] ) ) {
				update_post_meta( $track_id, '_callboard_levels', preg_replace( '/[^0-9]/', '', $levels[ $video_id ] ) );
			}
			if ( isset( $tempo[ $video_id ] ) && is_numeric( $tempo[ $video_id ] ) ) {
				update_post_meta( $track_id, '_callboard_bpm', self::clamp_bpm( (int) $tempo[ $video_id ] ) );
			}
			if ( ! empty( $notes[ $video_id ] ) && is_array( $notes[ $video_id ] ) && ! Notes::get( $track_id ) ) {
				Notes::set( $track_id, $notes[ $video_id ] ); // Notes are edited in the admin; a folder only seeds them.
			}
		}

		self::draw_missing_art( $dir, $set->ID );
		self::import_image( $dir . '/cover.png', $set->ID, 'cover' );
		self::import_image( $dir . '/share.png', $set->ID, 'share' );

		Sets::flush();
		/* translators: 1: set name, 2: number of new tracks. */
		return sprintf( __( '%1$s: %2$d new tracks.', 'callboard' ), $set->post_title, $added );
	}

	/**
	 * Register a file already in the uploads directory as an attachment of a set.
	 *
	 * @param string $file   Absolute path.
	 * @param int    $set_id Set post ID.
	 * @param string $title  Attachment title.
	 * @param int    $order  Menu order.
	 * @return int Attachment ID or 0.
	 */
	private static function attach_file( string $file, int $set_id, string $title, int $order ): int {
		$type = wp_check_filetype( basename( $file ) );
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'] ? $type['type'] : 'application/octet-stream',
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_parent'    => $set_id,
				'menu_order'     => $order,
			),
			$file,
			$set_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$only_ours = static fn( array $sizes ) => array_intersect_key( $sizes, array( 'callboard-cover-512' => 1 ) );
		add_filter( 'intermediate_image_sizes_advanced', $only_ours ); // covers need one extra size, not the whole ladder.
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		remove_filter( 'intermediate_image_sizes_advanced', $only_ours );
		return (int) $id;
	}

	/**
	 * A folder that is only audio still deserves a cover. Fetcher draws one on the way past; a set
	 * assembled by dropping files in has never been near it, and would otherwise sit on the home
	 * screen as a bare letter tile next to sets that have art. Drawn from what actually landed in
	 * WordPress, so the label counts the tracks that imported rather than the ones the folder held.
	 *
	 * @param string $dir Set folder.
	 * @param int    $set Set post ID.
	 */
	private static function draw_missing_art( string $dir, int $set ): void {
		$cover = $dir . '/cover.png';
		$share = $dir . '/share.png';
		if ( ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- GD writes the PNG with a path, not through WP_Filesystem, so the question is whether that path is writable.
			return;
		}

		$tracks     = array();
		$performers = array();
		foreach ( Sets::track_posts( $set ) as $track ) {
			$tracks[]  = array( 'duration' => (float) get_post_meta( $track->ID, '_callboard_duration', true ) );
			$performer = trim( (string) get_post_meta( $track->ID, '_callboard_uploader', true ) );
			if ( '' !== $performer ) {
				$performers[ $performer ] = true;
			}
		}
		$palette  = (string) get_post_meta( $set, '_callboard_palette', true );
		$credits  = (array) get_post_meta( $set, '_callboard_credits', true );
		$manifest = array(
			'name'      => get_the_title( $set ),
			'slug'      => get_post_field( 'post_name', $set ),
			'palette'   => $palette,
			'curator'   => (string) ( $credits['curator'] ?? '' ),
			'performer' => 1 === count( $performers ) ? (string) array_key_first( $performers ) : '',
			'tracks'    => $tracks,
		);

		// The artwork carries the set's name and "18 tracks · 1 hr 15 min", so it goes stale the moment
		// the set gains a track — a cover drawn while a folder was empty says "No audio yet" forever
		// otherwise. Redrawing on a changed fingerprint fixes that, but only for artwork this code
		// drew: a cover somebody supplied themselves is theirs, and is never overwritten.
		$want  = md5( (string) wp_json_encode( array( $manifest['name'], count( $tracks ), (int) array_sum( array_column( $tracks, 'duration' ) ), Settings::get( 'tagline' ), $palette, $manifest['curator'], $manifest['performer'] ) ) );
		$drawn = (string) get_post_meta( $set, '_callboard_art_drawn', true );
		$ours  = '' !== $drawn;

		$redraw_cover = ! file_exists( $cover ) || ( $ours && $drawn !== $want );
		$redraw_share = ! file_exists( $share ) || ( $ours && $drawn !== $want );
		if ( ! $redraw_cover && ! $redraw_share ) {
			return;
		}

		if ( $redraw_cover && Art::cover( $manifest, $cover ) ) {
			update_post_meta( $set, '_callboard_art_drawn', $want );
		}
		if ( $redraw_share ) {
			Art::share( $manifest, $share );
		}
	}

	/**
	 * Import cover / share image for a set if the file is present and changed.
	 *
	 * @param string $file Absolute path.
	 * @param int    $set  Set post ID.
	 * @param string $role 'cover' or 'share'.
	 */
	private static function import_image( string $file, int $set, string $role ): void {
		if ( ! file_exists( $file ) ) {
			return;
		}
		$meta_key = '_callboard_' . $role . '_image';
		$current  = (int) get_post_meta( $set, $meta_key, true );
		$mtime    = (string) filemtime( $file );
		if ( $current && get_post_meta( $current, '_callboard_source_mtime', true ) === $mtime ) {
			return;
		}
		if ( $current && get_attached_file( $current ) === $file ) {
			// The attachment points at this very file, so the file has been replaced under it. Deleting the
			// attachment would delete the new file too. Refresh its sizes in place instead.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $current, wp_generate_attachment_metadata( $current, $file ) );
			update_post_meta( $current, '_callboard_source_mtime', $mtime );
			if ( 'cover' === $role ) {
				set_post_thumbnail( $set, $current );
				delete_post_meta( $set, '_callboard_tint' ); // A new cover is a new colour.
			}
			return;
		}
		if ( $current ) {
			wp_delete_attachment( $current, true );
		}
		$id = self::attach_file( $file, $set, get_the_title( $set ) . ' – ' . $role, 0 );
		if ( ! $id ) {
			return;
		}
		update_post_meta( $id, '_callboard_source_mtime', $mtime );
		update_post_meta( $set, $meta_key, $id );
		if ( 'cover' === $role ) {
			set_post_thumbnail( $set, $id );
			delete_post_meta( $set, '_callboard_tint' );
		}
	}

	/**
	 * "3. Be Our Guest (Some Show OBC)" → "Be Our Guest".
	 *
	 * @param string $title Raw title.
	 */
	public static function clean_title( string $title ): string {
		$title = preg_replace( '/^\s*\d+\s*[.)-]\s*/', '', $title );
		$title = preg_replace( '/\s*[\(\[][^)\]]*[\)\]]\s*$/', '', $title );
		$title = str_replace( array( ' / ', '/' ), ' / ', $title );
		return sanitize_text_field( trim( preg_replace( '/\s+/', ' ', $title ) ) );
	}

	/**
	 * Cues as [[start, end, text], ...] with sane types.
	 *
	 * @param array<int, mixed> $cues Raw cues.
	 * @return array<int, array{0: float, 1: float, 2: string}>
	 */
	private static function sanitize_cues( array $cues ): array {
		$out = array();
		foreach ( $cues as $cue ) {
			if ( is_array( $cue ) && count( $cue ) >= 3 ) {
				$out[] = array( (float) $cue[0], (float) $cue[1], sanitize_text_field( (string) $cue[2] ) );
			}
		}
		return $out;
	}

	/**
	 * A JSON sidecar file next to the audio (lyrics.json, levels.json, notes.json, tempo.json), keyed by video id.
	 *
	 * @param string $dir  Set folder.
	 * @param string $name File name without extension.
	 * @return array<string, mixed>
	 */
	private static function sidecar( string $dir, string $name ): array {
		$file = $dir . '/' . $name . '.json';
		return file_exists( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Director notes: [{t: seconds, text, date: Y-m-d}, ...], sorted by time.
	 *
	 * @param array<int, mixed> $notes Raw notes.
	 * @return array<int, array{t: float, text: string, date: string}>
	 */
	public static function sanitize_notes( array $notes ): array {
		$out = array();
		foreach ( $notes as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['text'] ) || '' === trim( (string) $n['text'] ) ) {
				continue;
			}
			$date  = (string) ( $n['date'] ?? '' );
			$out[] = array(
				't'    => max( 0.0, round( (float) ( $n['t'] ?? 0 ), 1 ) ), // 0.0, not 0: max() hands back the argument it picked, so an int zero here would make a clamped note the one note whose time is not a float.
				'text' => sanitize_text_field( (string) $n['text'] ),
				'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
			);
		}
		usort( $out, static fn( array $a, array $b ) => $a['t'] <=> $b['t'] );
		return $out;
	}

	/**
	 * A tempo the count-in can use, or 0 for none.
	 *
	 * @param int $bpm Beats per minute.
	 */
	public static function clamp_bpm( int $bpm ): int {
		return $bpm >= 30 && $bpm <= 300 ? $bpm : 0;
	}

	/**
	 * Admin "Import now" button.
	 */
	public static function handle_admin_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'callboard' ) );
		}
		check_admin_referer( 'callboard_import' );
		$results = self::import_all();
		set_transient( 'callboard_import_notice', $results ? $results : array( __( 'Nothing to import.', 'callboard' ) ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET ) );
		exit;
	}

	/**
	 * Admin: a .callboard file was uploaded. Unpack it into a set folder and import that.
	 */
	public static function handle_admin_import_file(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'callboard' ) );
		}
		check_admin_referer( 'callboard_import_file' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a tmp path from PHP, checked with is_uploaded_file() before it is read.
		$tmp = isset( $_FILES['callboard_file']['tmp_name'] ) ? (string) $_FILES['callboard_file']['tmp_name'] : '';

		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			$notice = array( __( 'No file arrived.', 'callboard' ) );
		} else {
			$dir    = Exporter::unpack( $tmp );
			$notice = is_wp_error( $dir ) ? array( $dir->get_error_message() ) : array( self::import_folder( $dir ) );
		}

		set_transient( 'callboard_import_notice', $notice, 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET ) );
		exit;
	}
}
