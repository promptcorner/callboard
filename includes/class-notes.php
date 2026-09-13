<?php
/**
 * Director's notes on a track, stored as comments.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Comment;
use WP_Comment_Query;

defined( 'ABSPATH' ) || exit;

/**
 * A note is a comment of type `callboard_note` on the track attachment.
 *
 * The text is the comment body. The time into the track is comment meta, because that is the only
 * part of a note the comments table does not already understand. Authors, dates, threading and
 * moderation come free.
 *
 * Until every site has migrated, {@see self::get()} still falls back to the old `_callboard_notes`
 * post-meta array so a set that has not been rewritten keeps its notes. New writes go to comments.
 * {@see self::migrate()} turns the arrays into comments when a site is ready.
 */
final class Notes {

	/**
	 * Comment type. Not `note`: that is spoken for by core's editor Notes.
	 */
	public const TYPE = 'callboard_note';

	/**
	 * Comment meta: seconds into the track.
	 */
	public const META_AT = '_callboard_at';

	/**
	 * Post meta the notes used to live in. Still read as a fallback; left alone on write so a
	 * downgrade can still see what was there before comments took over.
	 */
	public const META_LEGACY = '_callboard_notes';

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 11 );
		add_action( 'pre_get_comments', array( self::class, 'exclude_from_queries' ) );
		add_filter( 'comments_clauses', array( self::class, 'exclude_from_clauses' ), 10, 2 );
		add_filter( 'comment_feed_where', array( self::class, 'exclude_from_feed' ) );
		add_filter( 'wp_count_comments', array( self::class, 'filter_count' ), 10, 2 );
		// Core's counter does not know about comment_type. After it writes, put the public count back.
		add_action( 'wp_update_comment_count', array( self::class, 'recount' ), 20, 1 );
	}

	/**
	 * Register the timecode meta on comments.
	 */
	public static function register_meta(): void {
		register_meta(
			'comment',
			self::META_AT,
			array(
				'type'              => 'number',
				'description'       => 'Seconds into the track where this director note sits.',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ) => max( 0.0, round( (float) $value, 1 ) ),
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $comment_id ): bool {
					$comment = get_comment( $comment_id );
					return $comment && current_user_can( 'edit_post', (int) $comment->comment_post_ID );
				},
			)
		);
	}

	/**
	 * Notes for a track, oldest time first. Comments win; the legacy meta array is the fallback.
	 *
	 * @param int $track_id Attachment ID.
	 * @return array<int, array{t: float, text: string, date: string, author?: string}>
	 */
	public static function get( int $track_id ): array {
		if ( $track_id <= 0 ) {
			return array();
		}

		$comments = self::comments_for( $track_id );
		if ( $comments ) {
			$notes = array_map( array( self::class, 'from_comment' ), $comments );
			usort( $notes, static fn( array $a, array $b ) => $a['t'] <=> $b['t'] );
			return $notes;
		}

		$legacy = get_post_meta( $track_id, self::META_LEGACY, true );
		return is_array( $legacy ) ? Importer::sanitize_notes( $legacy ) : array();
	}

	/**
	 * Replace the notes on a track with this list. Matched lines keep their author and date.
	 *
	 * @param int               $track_id Attachment ID.
	 * @param array<int, mixed> $notes    Raw notes: t, text, optional date.
	 */
	public static function set( int $track_id, array $notes ): void {
		if ( $track_id <= 0 ) {
			return;
		}

		$notes    = Importer::sanitize_notes( $notes );
		$existing = self::comments_for( $track_id );
		$by_key   = array();
		foreach ( $existing as $comment ) {
			$note = self::from_comment( $comment );
			$by_key[ self::key( $note['t'], $note['text'] ) ] = $comment;
		}

		$kept = array();
		foreach ( $notes as $note ) {
			$key = self::key( $note['t'], $note['text'] );
			if ( isset( $by_key[ $key ] ) ) {
				$comment = $by_key[ $key ];
				$kept[]  = (int) $comment->comment_ID;
				// Timecode meta can drift if an older write missed it.
				if ( (float) get_comment_meta( (int) $comment->comment_ID, self::META_AT, true ) !== $note['t'] ) {
					update_comment_meta( (int) $comment->comment_ID, self::META_AT, $note['t'] );
				}
				continue;
			}
			$kept[] = self::insert( $track_id, $note );
		}

		foreach ( $existing as $comment ) {
			if ( ! in_array( (int) $comment->comment_ID, $kept, true ) ) {
				wp_delete_comment( (int) $comment->comment_ID, true );
			}
		}
	}

	/**
	 * Whether this track already has notes as comments (ignoring legacy meta).
	 *
	 * @param int $track_id Attachment ID.
	 */
	public static function has_comments( int $track_id ): bool {
		return (bool) self::comments_for( $track_id );
	}

	/**
	 * Turn legacy `_callboard_notes` arrays into comments. Leaves the meta in place.
	 *
	 * @param bool $dry_run Count without writing.
	 * @return array{tracks: int, notes: int, skipped: int}
	 */
	public static function migrate( bool $dry_run = false ): array {
		global $wpdb;

		$track_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot migration inventory.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_LEGACY
			)
		);

		$result = array(
			'tracks'  => 0,
			'notes'   => 0,
			'skipped' => 0,
		);

		foreach ( array_map( 'intval', $track_ids ? $track_ids : array() ) as $track_id ) {
			if ( $track_id <= 0 ) {
				continue;
			}
			if ( self::has_comments( $track_id ) ) {
				++$result['skipped'];
				continue;
			}
			$legacy = get_post_meta( $track_id, self::META_LEGACY, true );
			if ( ! is_array( $legacy ) || ! $legacy ) {
				continue;
			}
			$notes = Importer::sanitize_notes( $legacy );
			if ( ! $notes ) {
				continue;
			}
			++$result['tracks'];
			$result['notes'] += count( $notes );
			if ( ! $dry_run ) {
				foreach ( $notes as $note ) {
					self::insert( $track_id, $note, false );
				}
			}
		}

		return $result;
	}

	/**
	 * Keep director notes out of ordinary comment queries unless the type was asked for.
	 *
	 * @param WP_Comment_Query $query Query.
	 */
	public static function exclude_from_queries( WP_Comment_Query $query ): void {
		if ( self::query_asks_for_notes( $query ) ) {
			return;
		}

		$not_in = isset( $query->query_vars['type__not_in'] ) ? (array) $query->query_vars['type__not_in'] : array();
		if ( ! in_array( self::TYPE, $not_in, true ) ) {
			$not_in[] = self::TYPE;
		}
		$query->query_vars['type__not_in'] = $not_in;
	}

	/**
	 * Belt and braces when type__not_in did not make it into the SQL.
	 *
	 * @param array<string, string> $clauses Clauses.
	 * @param WP_Comment_Query      $query   Query.
	 * @return array<string, string>
	 */
	public static function exclude_from_clauses( array $clauses, WP_Comment_Query $query ): array {
		if ( self::query_asks_for_notes( $query ) ) {
			return $clauses;
		}

		global $wpdb;
		// type__not_in already injects this; only append when the type is nowhere in the WHERE.
		if ( ! str_contains( $clauses['where'] ?? '', self::TYPE ) ) {
			$clauses['where'] .= $wpdb->prepare( ' AND comment_type != %s ', self::TYPE );
		}

		return $clauses;
	}

	/**
	 * Comment feeds never carry director notes.
	 *
	 * @param string $where WHERE clause.
	 */
	public static function exclude_from_feed( string $where ): string {
		global $wpdb;
		return $where . $wpdb->prepare( ' AND comment_type != %s ', self::TYPE );
	}

	/**
	 * Drop our type out of the moderated/approved tallies a site shows for a post.
	 *
	 * @param array<string, mixed>|object $counts Counts.
	 * @param int                         $post_id Post ID, or 0 for the site.
	 * @return array<string, mixed>|object
	 */
	public static function filter_count( $counts, int $post_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- WP filter signature.
		if ( ! is_object( $counts ) && ! is_array( $counts ) ) {
			return $counts;
		}

		$notes = self::status_counts( $post_id );
		if ( ! array_filter( $notes ) ) {
			return $counts;
		}

		$as_array = (array) $counts;
		foreach ( $notes as $status => $n ) {
			if ( isset( $as_array[ $status ] ) ) {
				$as_array[ $status ] = max( 0, (int) $as_array[ $status ] - $n );
			}
		}
		// `total_comments` and `all` are derived fields some callers read.
		foreach ( array( 'total_comments', 'all' ) as $key ) {
			if ( isset( $as_array[ $key ] ) ) {
				$as_array[ $key ] = max( 0, (int) $as_array[ $key ] - array_sum( $notes ) );
			}
		}

		return is_object( $counts ) ? (object) $as_array : $as_array;
	}

	/**
	 * Approved comments on a post, excluding director notes.
	 *
	 * Runs after core's own counter so a track with two hundred notes still reports zero public
	 * comments. Writes through $wpdb rather than wp_update_comment_count() so this does not loop.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function recount( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- mirrors wp_update_comment_count_now.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type != %s",
				$post_id,
				self::TYPE
			)
		);

		$current = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT comment_count FROM {$wpdb->posts} WHERE ID = %d", $post_id )
		);
		if ( $current === $count ) {
			return;
		}

		$wpdb->update( $wpdb->posts, array( 'comment_count' => $count ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $post_id );
	}

	/**
	 * Approved note comments on a track.
	 *
	 * @param int $track_id Attachment ID.
	 * @return WP_Comment[]
	 */
	private static function comments_for( int $track_id ): array {
		$comments = get_comments(
			array(
				'post_id' => $track_id,
				'type'    => self::TYPE,
				'status'  => 'approve',
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
				'number'  => 0,
			)
		);

		return is_array( $comments ) ? $comments : array();
	}

	/**
	 * Shape a comment into the array the player and export already understand.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return array{t: float, text: string, date: string, author?: string}
	 */
	private static function from_comment( WP_Comment $comment ): array {
		$t    = max( 0.0, round( (float) get_comment_meta( (int) $comment->comment_ID, self::META_AT, true ), 1 ) );
		$note = array(
			't'    => $t,
			'text' => $comment->comment_content,
			'date' => substr( (string) $comment->comment_date, 0, 10 ),
		);

		$author = self::author_name( $comment );
		if ( '' !== $author ) {
			$note['author'] = $author;
		}

		return $note;
	}

	/**
	 * Display name for the person who left the note.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	private static function author_name( WP_Comment $comment ): string {
		if ( (int) $comment->user_id > 0 ) {
			$user = get_userdata( (int) $comment->user_id );
			if ( $user && $user->display_name ) {
				return $user->display_name;
			}
		}
		return sanitize_text_field( (string) $comment->comment_author );
	}

	/**
	 * Insert one note comment and its timecode meta.
	 *
	 * @param int                                         $track_id  Attachment ID.
	 * @param array{t: float, text: string, date: string} $note      Note.
	 * @param bool                                        $with_user Attribute to the current user when possible.
	 * @return int Comment ID.
	 */
	private static function insert( int $track_id, array $note, bool $with_user = true ): int {
		$user_id = 0;
		$author  = '';
		$email   = '';
		if ( $with_user ) {
			$user = wp_get_current_user();
			if ( $user && $user->exists() ) {
				$user_id = (int) $user->ID;
				$author  = $user->display_name;
				$email   = $user->user_email;
			}
		}

		$date = $note['date'] ?? '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		// Noon avoids a note flipping day under a timezone offset when only a date was known.
		$datetime = $date . ' 12:00:00';

		$id = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $track_id,
				'comment_author'       => $author,
				'comment_author_email' => $email,
				'comment_author_url'   => '',
				'comment_content'      => $note['text'],
				'comment_type'         => self::TYPE,
				'comment_parent'       => 0,
				'user_id'              => $user_id,
				'comment_date'         => $datetime,
				'comment_date_gmt'     => get_gmt_from_date( $datetime ),
				'comment_approved'     => 1,
			)
		);

		if ( $id > 0 ) {
			update_comment_meta( $id, self::META_AT, $note['t'] );
		}

		return $id;
	}

	/**
	 * Match key so a re-save of the same line keeps its author.
	 *
	 * @param float  $t    Seconds.
	 * @param string $text Note text.
	 */
	private static function key( float $t, string $text ): string {
		return (string) $t . '|' . $text;
	}

	/**
	 * Whether this query asked for director notes by type.
	 *
	 * @param WP_Comment_Query $query Query.
	 */
	private static function query_asks_for_notes( WP_Comment_Query $query ): bool {
		$type = $query->query_vars['type'] ?? '';
		if ( self::TYPE === $type || ( is_array( $type ) && in_array( self::TYPE, $type, true ) ) ) {
			return true;
		}

		$type_in = isset( $query->query_vars['type__in'] ) ? (array) $query->query_vars['type__in'] : array();
		return in_array( self::TYPE, $type_in, true );
	}

	/**
	 * How many notes sit in each comment_approved bucket for a post (or the whole site).
	 *
	 * @param int $post_id Post ID, or 0.
	 * @return array<string, int>
	 */
	private static function status_counts( int $post_id ): array {
		global $wpdb;

		$sql  = "SELECT comment_approved AS status, COUNT(*) AS n FROM {$wpdb->comments} WHERE comment_type = %s";
		$args = array( self::TYPE );
		if ( $post_id > 0 ) {
			$sql   .= ' AND comment_post_ID = %d';
			$args[] = $post_id;
		}
		$sql .= ' GROUP BY comment_approved';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		$out  = array(
			'approved'     => 0,
			'moderated'    => 0,
			'spam'         => 0,
			'trash'        => 0,
			'post-trashed' => 0,
		);
		foreach ( $rows ? $rows : array() as $row ) {
			$status = (string) $row->status;
			$n      = (int) $row->n;
			if ( '1' === $status ) {
				$out['approved'] += $n;
			} elseif ( '0' === $status ) {
				$out['moderated'] += $n;
			} elseif ( isset( $out[ $status ] ) ) {
				$out[ $status ] += $n;
			}
		}
		return $out;
	}
}
