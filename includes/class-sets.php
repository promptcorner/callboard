<?php
/**
 * Read model: sets, their tracks, and the data blob the front end renders from.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for sets.
 */
final class Sets {

	private const CACHE_KEY           = 'callboard_sets_v1';
	private const LAST_CHANGED_OPTION = 'callboard_sets_last_changed';

	/**
	 * Hook registration: keep the cache honest.
	 */
	public static function register_hooks(): void {
		foreach ( array( 'save_post', 'deleted_post', 'add_attachment', 'edit_attachment', 'delete_attachment', 'callboard_imported' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
		add_action( 'before_delete_post', array( self::class, 'delete_children' ) );
	}

	/**
	 * A set's tracks and artwork go with it when it is permanently deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function delete_children( int $post_id ): void {
		if ( get_post_type( $post_id ) !== Post_Types::SET ) {
			return;
		}
		$children = get_children(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
			)
		);
		foreach ( $children as $child ) {
			wp_delete_attachment( $child->ID, true );
		}
	}

	/**
	 * Drop the cached data.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
		$last_changed = max( 1, (int) get_option( self::LAST_CHANGED_OPTION, 1 ) );
		update_option( self::LAST_CHANGED_OPTION, $last_changed + 1, false );
	}

	/**
	 * Every published set, in menu order, as render-ready arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		// Keyed on the active data-shaping extensions, and on a generation counter flush() bumps.
		// That keeps one variant per fingerprint between flushes rather than rebuilding every request.
		$fingerprint = Extensions::data_fingerprint();
		$key         = self::CACHE_KEY . '_' . self::last_changed() . '_' . $fingerprint;
		$cached      = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::SET,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		$sets  = array_map( array( self::class, 'build' ), $posts );
		set_transient( $key, $sets, DAY_IN_SECONDS );
		return $sets;
	}

	/**
	 * Generation of the set cache key, bumped by flush() to bound fingerprint variants.
	 */
	private static function last_changed(): int {
		$last_changed = (int) get_option( self::LAST_CHANGED_OPTION, 0 );
		if ( $last_changed > 0 ) {
			return $last_changed;
		}
		add_option( self::LAST_CHANGED_OPTION, 1, '', 'no' );
		return 1;
	}

	/**
	 * One set by slug.
	 *
	 * @param string $slug Post name.
	 * @return array<string, mixed>|null
	 */
	public static function by_slug( string $slug ): ?array {
		foreach ( self::all() as $set ) {
			if ( $set['slug'] === $slug ) {
				return $set;
			}
		}
		return null;
	}

	/**
	 * The set post for a slug, published or not.
	 *
	 * @param string $slug Post name.
	 */
	public static function post_by_slug( string $slug ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::SET,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Audio attachments of a set, in menu order.
	 *
	 * @param int $set_id Set post ID.
	 * @return WP_Post[]
	 */
	public static function track_posts( int $set_id ): array {
		return get_children(
			array(
				'post_parent'    => $set_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio',
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Build a set's render-ready array.
	 *
	 * @param WP_Post $post Set post.
	 * @return array<string, mixed>
	 */
	public static function build( WP_Post $post ): array {
		$approved  = (bool) get_post_meta( $post->ID, '_callboard_lyrics_approved', true );
		$credits   = (array) get_post_meta( $post->ID, '_callboard_credits', true );
		$tracks    = array();
		$lyrics    = array();
		$uploaders = array();

		$index = 0;
		foreach ( self::track_posts( $post->ID ) as $track ) {
			$file = get_attached_file( $track->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$meta     = (array) wp_get_attachment_metadata( $track->ID );
			$duration = get_post_meta( $track->ID, '_callboard_duration', true );
			$notes    = get_post_meta( $track->ID, '_callboard_notes', true );
			$levels   = (string) get_post_meta( $track->ID, '_callboard_levels', true );
			$by       = (string) get_post_meta( $track->ID, '_callboard_uploader', true );
			$tracks[] = array(
				'id'       => $track->ID,
				'index'    => ++$index,
				'title'    => $track->post_title,
				'url'      => esc_url_raw( wp_get_attachment_url( $track->ID ) ),
				'duration' => '' !== $duration ? (float) $duration : (float) ( $meta['length'] ?? 0 ),
				'bytes'    => (int) ( $meta['filesize'] ?? filesize( $file ) ),
				'levels'   => '' !== $levels ? $levels : null,
				'notes'    => is_array( $notes ) ? array_values( $notes ) : array(),
				'artist'   => '' !== $by ? $by : null,
				// Tempo and quality belong to callboard/count-in and callboard/quality now, under `ext`.
				// Extensions::filter_set_data() still writes `bpm` and `quality` here for API v1.
			);
			$uploader = get_post_meta( $track->ID, '_callboard_uploader', true );
			if ( $uploader ) {
				$uploader_url           = get_post_meta( $track->ID, '_callboard_uploader_url', true );
				$uploaders[ $uploader ] = $uploader_url ? $uploader_url : null;
			}
			$cues = $approved ? get_post_meta( $track->ID, '_callboard_lyrics', true ) : null;
			if ( is_array( $cues ) && $cues ) {
				$lyrics[ $track->ID ] = $cues;
			}
		}

		// A set from one playlist has one uploader, and it belongs in the footer said once. A set
		// gathered from everywhere has a different artist per track, and it belongs on the row. Doing
		// both gives you eighteen names in the footer, or the same name on ten rows.
		if ( count( $uploaders ) < 2 ) {
			foreach ( $tracks as $k => $callboard_unused ) {
				$tracks[ $k ]['artist'] = null;
			}
		} else {
			$uploaders = array();
		}

		$thumb = (int) get_post_thumbnail_id( $post->ID );
		$set   = array(
			'id'      => $post->ID,
			'slug'    => $post->post_name,
			'name'    => $post->post_title,
			'tracks'  => $tracks,
			'meta'    => callboard_meta( $tracks ),
			'lyrics'  => $lyrics ? $lyrics : (object) array(),
			'art'     => self::art( $post->ID ),
			'cover'   => $thumb ? wp_get_attachment_image_url( $thumb, 'callboard-cover-512' ) : null,
			// Native responsive images: the sizes WordPress already made for the featured image, so a
			// full-screen Now Playing on a laptop is not handed the 512 a phone wants.
			'srcset'  => $thumb ? ( wp_get_attachment_image_srcset( $thumb, 'full' ) ? wp_get_attachment_image_srcset( $thumb, 'full' ) : null ) : null,
			'tint'    => self::tint( $post->ID ),
			'share'   => self::share_image( $post->ID ),
			'credits' => array(
				'uploaders'    => $uploaders ? $uploaders : (object) array(),
				'playlist_url' => $credits['playlist_url'] ?? null,
				'curator'      => $credits['curator'] ?? null,
				'curator_url'  => $credits['curator_url'] ?? null,
			),
		);
		/**
		 * One set as the app sees it. Filter to add fields per set or per track.
		 *
		 * @param array<string, mixed> $set  Set data.
		 * @param WP_Post              $post Set post.
		 */
		return apply_filters( 'callboard_set_data', $set, $post );
	}

	/**
	 * One colour taken from the set's cover, cached on the set as post meta.
	 *
	 * Computed once and stored rather than on every render: GD reading a PNG is not something to do
	 * inside a page load. The meta is cleared alongside the thumbnail in Importer::import_image(),
	 * so replacing a cover replaces the colour.
	 *
	 * @param int $set_id Set post ID.
	 */
	private static function tint( int $set_id ): ?string {
		$stored = get_post_meta( $set_id, '_callboard_tint', true );
		if ( is_string( $stored ) && '' !== $stored ) {
			return 'none' === $stored ? null : $stored;
		}

		$thumb = (int) get_post_thumbnail_id( $set_id );
		$file  = $thumb ? get_attached_file( $thumb ) : '';
		$tint  = $file ? Art::tint( $file ) : null;
		// 'none' rather than '' so a cover GD cannot read is remembered as answered, not as unasked.
		update_post_meta( $set_id, '_callboard_tint', $tint ? $tint : 'none' );

		return $tint;
	}

	/**
	 * Media Session artwork candidates: the featured image, else the app icon.
	 *
	 * @param int $set_id Set post ID.
	 * @return array<int, array<string, string>>
	 */
	private static function art( int $set_id ): array {
		$thumb = get_post_thumbnail_id( $set_id );
		$out   = array();
		if ( $thumb ) {
			foreach ( array(
				'full'                => '1024x1024',
				'callboard-cover-512' => '512x512',
			) as $size => $dims ) {
				$src = wp_get_attachment_image_src( $thumb, $size );
				if ( $src ) {
					$out[] = array(
						'src'   => $src[0],
						'sizes' => $dims,
						'type'  => 'image/png',
					);
				}
			}
		}
		return $out ? $out : array(
			array(
				'src'   => callboard_asset( 'assets/icon-512.png' ),
				'sizes' => '512x512',
				'type'  => 'image/png',
			),
		);
	}

	/**
	 * Link-preview image URL for a set.
	 *
	 * @param int $set_id Set post ID.
	 */
	private static function share_image( int $set_id ): string {
		$id = (int) get_post_meta( $set_id, '_callboard_share_image', true );
		return $id ? (string) wp_get_attachment_url( $id ) : callboard_asset( 'assets/share.png' );
	}

	/**
	 * Everything the client needs to render any view, shipped once.
	 *
	 * @param string|null $view '' for home, a slug, or null for an unknown path.
	 * @return array<string, mixed>
	 */
	public static function app_data( ?string $view ): array {
		return array(
			'site' => callboard_site_name(),
			'home' => home_url( '/' ),
			'slug' => $view ? $view : '',
			'sets' => self::all(),
		);
	}
}
