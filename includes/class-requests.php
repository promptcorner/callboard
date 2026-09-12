<?php
/**
 * Fetch requests: "make a set from this YouTube URL". `wp callboard run` drains the queue wherever yt-dlp exists.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Request queue, stored as a private post type.
 */
final class Requests {

	public const TYPE = 'callboard_request';

	public const STATUSES = array( 'queued', 'running', 'done', 'failed' );

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'admin_post_callboard_request', array( self::class, 'handle_admin_request' ) );
		add_action( 'admin_post_callboard_request_delete', array( self::class, 'handle_admin_delete' ) );
	}

	/**
	 * Register the post type.
	 */
	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => Roles::CAPABILITY_TYPES[ self::TYPE ],
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Queue a request.
	 *
	 * @param string $url  YouTube video or playlist URL.
	 * @param string $name Set name.
	 * @return int|\WP_Error Request ID.
	 */
	public static function create( string $url, string $name ) {
		$url  = esc_url_raw( $url );
		$name = sanitize_text_field( $name );
		if ( ! $url || ! preg_match( '#^https?://(www\.|m\.|music\.)?(youtube\.com|youtu\.be)/#i', $url ) ) {
			return new \WP_Error( 'callboard_bad_url', __( 'That is not a YouTube URL.', 'callboard' ) );
		}
		if ( ! $name ) {
			return new \WP_Error( 'callboard_no_name', __( 'Give the set a name.', 'callboard' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				'post_title'  => $name,
				'meta_input'  => array(
					'_callboard_url'    => $url,
					'_callboard_slug'   => wp_unique_post_slug( sanitize_title( $name ), 0, 'publish', Post_Types::SET, 0 ),
					'_callboard_status' => 'queued',
					'_callboard_log'    => '',
				),
			),
			true
		);
		return $id;
	}

	/**
	 * Requests, newest first.
	 *
	 * @param string|null $status Filter by status.
	 * @return WP_Post[]
	 */
	public static function all( ?string $status = null ): array {
		$args = array(
			'post_type'      => self::TYPE,
			'post_status'    => 'private',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $status ) {
			$args['meta_key']   = '_callboard_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		return get_posts( $args );
	}

	/**
	 * A request as a plain array for REST and the admin table.
	 *
	 * @param WP_Post $post Request post.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Post $post ): array {
		return array(
			'id'      => $post->ID,
			'name'    => $post->post_title,
			'slug'    => (string) get_post_meta( $post->ID, '_callboard_slug', true ),
			'url'     => (string) get_post_meta( $post->ID, '_callboard_url', true ),
			'status'  => (string) get_post_meta( $post->ID, '_callboard_status', true ),
			'log'     => (string) get_post_meta( $post->ID, '_callboard_log', true ),
			'created' => $post->post_date_gmt,
		);
	}

	/**
	 * Update status and append to the log.
	 *
	 * @param int    $id     Request ID.
	 * @param string $status One of STATUSES.
	 * @param string $log    Line to append.
	 */
	public static function set_status( int $id, string $status, string $log = '' ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) || get_post_type( $id ) !== self::TYPE ) {
			return false;
		}
		update_post_meta( $id, '_callboard_status', $status );
		if ( '' !== $log ) {
			$existing = (string) get_post_meta( $id, '_callboard_log', true );
			update_post_meta( $id, '_callboard_log', trim( $existing . "\n" . sanitize_textarea_field( $log ) ) );
		}
		return true;
	}

	/**
	 * Admin form: queue a request.
	 */
	public static function handle_admin_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'callboard' ) );
		}
		check_admin_referer( 'callboard_request' );
		$url  = isset( $_POST['callboard_url'] ) ? esc_url_raw( wp_unslash( $_POST['callboard_url'] ) ) : '';
		$name = isset( $_POST['callboard_name'] ) ? sanitize_text_field( wp_unslash( $_POST['callboard_name'] ) ) : '';
		$made = self::create( $url, $name );
		set_transient( 'callboard_import_notice', array( is_wp_error( $made ) ? $made->get_error_message() : __( 'Queued. It will appear as a set once a runner has fetched it.', 'callboard' ) ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-import' ) );
		exit;
	}

	/**
	 * Admin: delete a request.
	 */
	public static function handle_admin_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'callboard' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'callboard_request_delete_' . $id );
		if ( get_post_type( $id ) === self::TYPE ) {
			wp_delete_post( $id, true );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-import' ) );
		exit;
	}
}
