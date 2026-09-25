<?php
/**
 * Writes a set out as a .callboard file, and reads one back.
 *
 * The file is a zip of the folder the fetcher already produces: manifest.json, the audio,
 * and the sidecars beside it. Unzipped it *is* an import folder, so `Importer` reads it
 * without knowing it was ever a file, and somebody with no Callboard at all still has
 * playable mp3s in the right order. Nothing in here is a new idea; it is the existing
 * shape, versioned and given an extension.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Error;
use WP_Post;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Set export and import as a single file.
 */
final class Exporter {

	/**
	 * Manifest format version. Bump when a reader has to behave differently, never for
	 * a field a reader can ignore.
	 */
	public const VERSION = 1;

	public const EXT  = 'callboard';
	public const TYPE = 'application/vnd.callboard+zip';

	/** Files carried beside the audio. Lyrics went in 3.0.0; a lyrics.json in an older file is skipped. */
	private const SIDECARS = array( 'levels' );

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_callboard_export', array( self::class, 'handle_admin_export' ) );
	}

	/**
	 * Whether this server can read and write zips at all.
	 */
	public static function available(): bool {
		return class_exists( ZipArchive::class );
	}

	/**
	 * A track's key in the manifest and in every sidecar.
	 *
	 * `_callboard_video_id` when the track came from a fetch, so a re-import matches what is
	 * already here. Otherwise one derived from the file name, which is stable across exports
	 * and, unlike the empty string, unique within the set.
	 *
	 * @param int    $track_id Attachment ID.
	 * @param string $file     Absolute path to the audio.
	 */
	public static function track_key( int $track_id, string $file ): string {
		$video = (string) get_post_meta( $track_id, '_callboard_video_id', true );

		return '' !== $video ? $video : 'cb-' . substr( sha1( basename( $file ) ), 0, 12 );
	}

	/**
	 * The manifest for a set, built from posts rather than from whatever folder it arrived in.
	 *
	 * @param WP_Post $set Set post.
	 * @return array<string, mixed>
	 */
	public static function manifest( WP_Post $set ): array {
		$credits = (array) get_post_meta( $set->ID, '_callboard_credits', true );
		$tracks  = array();
		$index   = 0;

		foreach ( Sets::track_posts( $set->ID ) as $track ) {
			$file = get_attached_file( $track->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$duration = get_post_meta( $track->ID, '_callboard_duration', true );
			$tracks[] = array(
				'index'        => ++$index,
				'id'           => self::track_key( $track->ID, $file ),
				'title'        => $track->post_title,
				'file'         => basename( $file ),
				'duration'     => '' !== $duration ? (float) $duration : null,
				'url'          => (string) get_post_meta( $track->ID, '_callboard_source_url', true ),
				'uploader'     => (string) get_post_meta( $track->ID, '_callboard_uploader', true ),
				'uploader_url' => (string) get_post_meta( $track->ID, '_callboard_uploader_url', true ),
			);
		}

		return array(
			'version'      => self::VERSION,
			'generator'    => 'callboard/' . CALLBOARD_VERSION,
			'name'         => $set->post_title,
			'slug'         => $set->post_name,
			'order'        => (int) $set->menu_order,
			'playlist_url' => (string) ( $credits['playlist_url'] ?? '' ),
			'curator'      => (string) ( $credits['curator'] ?? '' ),
			'curator_url'  => (string) ( $credits['curator_url'] ?? '' ),
			'tracks'       => $tracks,
		);
	}

	/**
	 * The sidecars for a set, each keyed the way the manifest keys its tracks.
	 *
	 * @param WP_Post $set Set post.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sidecars( WP_Post $set ): array {
		$out = array_fill_keys( self::SIDECARS, array() );

		foreach ( Sets::track_posts( $set->ID ) as $track ) {
			$file = get_attached_file( $track->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$key    = self::track_key( $track->ID, $file );
			$levels = (string) get_post_meta( $track->ID, '_callboard_levels', true );

			if ( '' !== $levels ) {
				$out['levels'][ $key ] = $levels;
			}
		}

		return $out;
	}

	/**
	 * Write a set to a .callboard file.
	 *
	 * @param WP_Post $set  Set post.
	 * @param string  $path Absolute destination path.
	 * @return string|WP_Error The path written.
	 */
	public static function write( WP_Post $set, string $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'callboard_no_zip', __( 'This server has no ZipArchive, so sets cannot be exported.', 'callboard' ) );
		}

		$manifest = self::manifest( $set );
		if ( ! $manifest['tracks'] ) {
			return new WP_Error( 'callboard_empty_set', __( 'That set has no audio to export.', 'callboard' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'callboard_zip_open', __( 'Could not create the file.', 'callboard' ) );
		}

		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		foreach ( Sets::track_posts( $set->ID ) as $track ) {
			$file = get_attached_file( $track->ID );
			if ( $file && file_exists( $file ) ) {
				$zip->addFile( $file, basename( $file ) );
			}
		}

		foreach ( self::sidecars( $set ) as $name => $data ) {
			if ( $data ) {
				$zip->addFromString( $name . '.json', (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
			}
		}

		foreach ( array( 'cover', 'share' ) as $role ) {
			$image = self::image_path( $set->ID, $role );
			if ( $image ) {
				$zip->addFile( $image, $role . '.png' );
			}
		}

		$zip->close();

		return $path;
	}

	/**
	 * Write a set as a plain folder of tagged mp3s, in order, with a cover.
	 *
	 * This is the copy that goes on a USB stick. A car stereo has no manifest and no
	 * sidecars; it sorts by file name and reads ID3, so the numbering carries the order and
	 * every track names itself and its set on the dash.
	 *
	 * @param WP_Post $set  Set post.
	 * @param string  $into Absolute path to write the set's folder inside.
	 * @return string|WP_Error Absolute path to the folder written.
	 */
	public static function write_folder( WP_Post $set, string $into ) {
		$tracks = self::manifest( $set )['tracks'];
		if ( ! $tracks ) {
			return new WP_Error( 'callboard_empty_set', __( 'That set has no audio to export.', 'callboard' ) );
		}

		$dir = trailingslashit( $into ) . self::folder_name( $set );
		if ( file_exists( $dir ) ) {
			if ( ! is_dir( $dir ) ) {
				return new WP_Error( 'callboard_mkdir', __( 'Could not create the folder.', 'callboard' ) );
			}
			self::rmdir( $dir );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'callboard_mkdir', __( 'Could not create the folder.', 'callboard' ) );
		}

		$cover   = self::jpeg_cover( self::image_path( $set->ID, 'cover' ), $dir );
		$total   = count( $tracks );
		$sources = array();
		foreach ( Sets::track_posts( $set->ID ) as $track ) {
			$file = get_attached_file( $track->ID );
			if ( $file && file_exists( $file ) ) {
				$sources[ basename( $file ) ] = $file;
			}
		}

		foreach ( $tracks as $t ) {
			$source = $sources[ $t['file'] ] ?? null;
			if ( ! $source ) {
				continue;
			}
			$name = sprintf( '%02d %s.%s', (int) $t['index'], self::plain_name( (string) $t['title'] ), pathinfo( $source, PATHINFO_EXTENSION ) );
			$dest = $dir . '/' . $name;
			if ( ! copy( $source, $dest ) ) {
				continue;
			}
			if ( 'mp3' === strtolower( (string) pathinfo( $dest, PATHINFO_EXTENSION ) ) ) {
				Id3::write(
					$dest,
					array(
						'TIT2' => (string) $t['title'],
						'TALB' => $set->post_title,
						'TPE1' => (string) ( $t['uploader'] ? $t['uploader'] : $set->post_title ),
						'TRCK' => (int) $t['index'] . '/' . $total,
					),
					$cover
				);
			}
		}

		return $dir;
	}

	/**
	 * The same folder, zipped, because a browser can only be handed one file.
	 *
	 * @param WP_Post $set  Set post.
	 * @param string  $path Absolute destination path for the zip.
	 * @return string|WP_Error The path written.
	 */
	public static function write_car_zip( WP_Post $set, string $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'callboard_no_zip', __( 'This server has no ZipArchive, so sets cannot be exported.', 'callboard' ) );
		}

		$staging = trailingslashit( get_temp_dir() ) . uniqid( 'callboard-car-', true );
		$folder  = self::write_folder( $set, $staging );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			self::rmdir( $staging );
			return new WP_Error( 'callboard_zip_open', __( 'Could not create the file.', 'callboard' ) );
		}
		$base = basename( $folder );
		foreach ( (array) glob( $folder . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				$zip->addFile( $file, $base . '/' . basename( $file ) );
			}
		}
		$zip->close();
		self::rmdir( $staging );

		return $path;
	}

	/**
	 * The cover as a JPEG beside the audio, named the way head units and desktop players both
	 * look for it. Embedded artwork is read far more widely as JPEG than as PNG, and the plugin
	 * draws them, so convert where GD can and fall back to copying where it cannot.
	 *
	 * @param string|null $cover Absolute path to the set's cover, or null.
	 * @param string      $dir   The folder being written.
	 * @return string|null Path to the image to embed, or null.
	 */
	private static function jpeg_cover( ?string $cover, string $dir ): ?string {
		if ( ! $cover ) {
			return null;
		}
		if ( function_exists( 'imagecreatefrompng' ) && function_exists( 'imagejpeg' ) && 'png' === strtolower( (string) pathinfo( $cover, PATHINFO_EXTENSION ) ) ) {
			$image = @imagecreatefrompng( $cover ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed cover is not worth failing the export over.
			if ( false !== $image ) {
				$dest = $dir . '/folder.jpg';
				imagejpeg( $image, $dest, 90 ); // GdImage frees itself; imagedestroy() is deprecated.

				return $dest;
			}
		}
		$dest = $dir . '/folder.' . pathinfo( $cover, PATHINFO_EXTENSION );

		return copy( $cover, $dest ) ? $dest : $cover;
	}

	/**
	 * A set's folder name on a stick: the title, with anything a FAT volume dislikes removed.
	 *
	 * @param WP_Post $set Set post.
	 */
	private static function folder_name( WP_Post $set ): string {
		$name = self::plain_name( $set->post_title );

		return '' !== $name ? $name : ( $set->post_name ? $set->post_name : 'set' );
	}

	/**
	 * A name a FAT volume and a head unit will both accept.
	 *
	 * @param string $name Raw name.
	 */
	private static function plain_name( string $name ): string {
		$name = (string) preg_replace( '#[\\/:*?"<>|]+#', '', $name );
		$name = (string) preg_replace( '/\s+/', ' ', $name );

		return trim( substr( trim( $name ), 0, 60 ) );
	}

	/**
	 * Remove a staging directory and everything in it.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function rmdir( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				self::rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a temp directory this export made.
		}
	}

	/**
	 * Unpack a .callboard file into an import folder.
	 *
	 * @param string $zip_path Absolute path to the uploaded file.
	 * @param string $slug     Optional slug override; the manifest's own is used otherwise.
	 * @return string|WP_Error Absolute path to the folder.
	 */
	public static function unpack( string $zip_path, string $slug = '' ) {
		if ( ! self::available() ) {
			return new WP_Error( 'callboard_no_zip', __( 'This server has no ZipArchive, so sets cannot be imported from a file.', 'callboard' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'callboard_zip_open', __( 'That file is not readable as a zip.', 'callboard' ) );
		}

		$raw = $zip->getFromName( 'manifest.json' );
		if ( false === $raw ) {
			$zip->close();
			return new WP_Error( 'callboard_no_manifest', __( 'That file has no manifest.json, so it is not a set.', 'callboard' ) );
		}

		$manifest = json_decode( (string) $raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['name'] ) ) {
			$zip->close();
			return new WP_Error( 'callboard_bad_manifest', __( 'That file\'s manifest is unreadable.', 'callboard' ) );
		}
		if ( (int) ( $manifest['version'] ?? 1 ) > self::VERSION ) {
			$zip->close();
			/* translators: %d: format version found in the file. */
			return new WP_Error( 'callboard_new_format', sprintf( __( 'That file is format version %d, which this version of Callboard cannot read. Update the plugin.', 'callboard' ), (int) $manifest['version'] ) );
		}

		$slug = sanitize_title( $slug ? $slug : (string) ( $manifest['slug'] ?? $manifest['name'] ) );
		if ( ! $slug ) {
			$zip->close();
			return new WP_Error( 'callboard_bad_slug', __( 'That set has no usable slug.', 'callboard' ) );
		}

		$dir = Importer::source_dir() . '/' . $slug;
		if ( ! wp_mkdir_p( $dir ) ) {
			$zip->close();
			return new WP_Error( 'callboard_mkdir', __( 'Could not create the set folder.', 'callboard' ) );
		}

		// Extract by name rather than with extractTo(): an archive from anywhere can carry
		// `../` or an absolute path, and a set is a flat folder, so anything with a directory
		// separator in it is not ours to write.
		$total = $zip->count();
		for ( $i = 0; $i < $total; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! self::safe_entry( $name ) ) {
				continue;
			}
			$contents = $zip->getFromIndex( $i );
			if ( false !== $contents ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing inside the uploads dir the importer owns.
			}
		}
		$zip->close();

		return $dir;
	}

	/**
	 * Whether a zip entry is one a set folder may contain.
	 *
	 * @param string $name Entry name as the archive records it.
	 */
	private static function safe_entry( string $name ): bool {
		if ( '' === $name || str_contains( $name, '/' ) || str_contains( $name, '\\' ) || str_starts_with( $name, '.' ) ) {
			return false;
		}
		if ( 'manifest.json' === $name ) {
			return true;
		}
		if ( in_array( pathinfo( $name, PATHINFO_FILENAME ), self::SIDECARS, true ) && 'json' === strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(mp3|m4a|opus|webm|ogg|png|jpe?g)$/i', $name );
	}

	/**
	 * The file behind a set's cover or share image, if there is one.
	 *
	 * @param int    $set_id Set post ID.
	 * @param string $role   'cover' or 'share'.
	 */
	private static function image_path( int $set_id, string $role ): ?string {
		$id = (int) get_post_meta( $set_id, '_callboard_' . $role . '_image', true );
		if ( ! $id && 'cover' === $role ) {
			$id = (int) get_post_thumbnail_id( $set_id );
		}
		$file = $id ? get_attached_file( $id ) : '';

		return $file && file_exists( $file ) ? $file : null;
	}

	/**
	 * The download's file name, without a path.
	 *
	 * @param WP_Post $set Set post.
	 */
	public static function filename( WP_Post $set ): string {
		return sanitize_file_name( ( $set->post_name ? $set->post_name : 'set' ) . '.' . self::EXT );
	}

	/**
	 * Admin: stream a set to the browser as a .callboard file.
	 */
	public static function handle_admin_export(): void {
		$set_id = isset( $_GET['set'] ) ? (int) $_GET['set'] : 0;
		check_admin_referer( 'callboard_export_' . $set_id );
		if ( ! current_user_can( 'edit_post', $set_id ) ) {
			wp_die( esc_html__( 'You cannot export that set.', 'callboard' ) );
		}

		$set = get_post( $set_id );
		if ( ! $set instanceof WP_Post || Post_Types::SET !== $set->post_type ) {
			wp_die( esc_html__( 'That is not a set.', 'callboard' ) );
		}

		$car = isset( $_GET['format'] ) && 'car' === $_GET['format'];

		$path   = trailingslashit( get_temp_dir() ) . uniqid( 'callboard-', true ) . ( $car ? '.zip' : '.' . self::EXT );
		$result = $car ? self::write_car_zip( $set, $path ) : self::write( $set, $path );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$name = $car ? sanitize_file_name( ( $set->post_name ? $set->post_name : 'set' ) . '.zip' ) : self::filename( $set );

		nocache_headers();
		header( 'Content-Type: ' . ( $car ? 'application/zip' : self::TYPE ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a temp file to the browser.
		wp_delete_file( $path );
		exit;
	}
}
