<?php
/**
 * Shared cue: the director opens a track and every phone with Follow on opens the same one.
 *
 * The cue is stored in one option: the playlist slug, the track's position in the playlist, a
 * playhead position in seconds, a sequence number, the time it was set and, when the director asked
 * for it, the moment the track starts. Anyone who can view the site reads it from
 * `GET callboard/v1/cue/`. Users who can edit calls change it with a POST. `GET
 * callboard/v1/cue/time` answers with the server's clock, which every phone samples so it can turn
 * that moment into its own. The page side is the callboard/cue section of assets/app.js.
 *
 * @package Callboard
 * @since 2.4.0
 */

namespace Callboard\Extension;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The shared cue extension.
 */
final class Cue {

	/**
	 * Option holding the cue.
	 */
	public const OPTION = 'callboard_cue';

	/**
	 * Seconds after the last change that the cue counts as empty.
	 */
	public const LIFETIME = 2 * HOUR_IN_SECONDS;

	/**
	 * Seconds ahead of now that a scheduled start may be set. Long enough for the slowest phone to
	 * read the cue and decode the track, short enough that a stale clock cannot book tomorrow.
	 */
	public const LEAD_IN = 60;

	/**
	 * Register with the extension registry.
	 *
	 * @since 2.4.0
	 */
	public static function register(): void {
		/**
		 * The shared cue extension. Unregister it by this id to remove the Lead and Follow buttons.
		 */
		callboard_register_extension(
			'callboard/cue',
			array(
				'title'       => __( 'Shared cue', 'callboard' ),
				'version'     => '1.0.0',
				'api_version' => 1,
				'app_data'    => array( self::class, 'app_data' ),
				'slots'       => array(
					'transport' => array( self::class, 'controls' ),
				),
				// Two entries for one route, so the registry wraps each method's permission check, and
				// the clock alongside them.
				'rest'        => array(
					array(
						'/',
						array(
							'methods'  => 'GET',
							'callback' => array( self::class, 'read' ),
							'args'     => array(
								'since' => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
							),
						),
					),
					array(
						'/',
						array(
							'methods'             => 'POST',
							'callback'            => array( self::class, 'write' ),
							'permission_callback' => array( self::class, 'can_lead' ),
							'args'                => array(
								'set'      => array(
									'type'     => 'string',
									'required' => true,
								),
								'track'    => array(
									'type'     => 'integer',
									'required' => true,
									'minimum'  => 0,
								),
								'position' => array(
									'type'    => 'number',
									'minimum' => 0,
									'default' => 0,
								),
								'start'    => array(
									'type'    => 'number',
									'minimum' => 0,
									'default' => 0,
								),
							),
						),
					),
					array(
						'/time',
						array(
							'methods'  => 'GET',
							'callback' => array( self::class, 'time' ),
						),
					),
				),
			)
		);

		add_filter( 'rest_authentication_errors', array( self::class, 'allow_signed_out' ), 9 );
	}

	/**
	 * Let signed-out visitors past the REST sign-in rule for the cue route.
	 *
	 * WordPress strips the trailing slash from `rest_route`, so `/callboard/v1/cue` does not match the
	 * `/callboard/v1/cue/` prefix that Privacy checks for extension routes. The route's permission
	 * check still applies the front-end gate.
	 *
	 * @todo Remove once #145 changes how extension routes are matched.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Error|null|true $result Current authentication result.
	 * @return WP_Error|null|true
	 */
	public static function allow_signed_out( $result ) {
		if ( ! empty( $result ) || is_user_logged_in() || ! callboard_get_extension( 'callboard/cue' ) ) {
			return $result;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? untrailingslashit( (string) $GLOBALS['wp']->query_vars['rest_route'] ) : '';
		return '/callboard/v1/cue' === $route ? true : $result;
	}

	/**
	 * Whether the current user can set the cue.
	 *
	 * @since 2.4.0
	 */
	public static function can_lead(): bool {
		// @todo Switch to the call editing capability once #158 changes roles.
		return current_user_can( 'edit_posts' );
	}

	/**
	 * What the page needs: whether this user can lead, the routes, and a REST nonce for signed-in users.
	 *
	 * @since 2.4.0
	 *
	 * @return array{canLead: bool, api: string, time: string, nonce?: string}
	 */
	public static function app_data(): array {
		$data = array(
			'canLead' => self::can_lead(),
			'api'     => rest_url( 'callboard/v1/cue/' ),
			'time'    => rest_url( 'callboard/v1/cue/time' ),
		);
		// @todo Use the core REST nonce from app data once #145 adds it.
		if ( is_user_logged_in() ) {
			$data['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $data;
	}

	/**
	 * The Lead, Together and Follow buttons in the player's transport controls.
	 *
	 * Lead and Together are only rendered for users who can set the cue. Follow starts hidden; the
	 * script shows it while a cue is set.
	 *
	 * @since 2.4.0
	 */
	public static function controls(): string {
		$html = '<div class="cue">';
		if ( self::can_lead() ) {
			$html .= '<button type="button" class="cue-toggle" id="cue-lead" aria-pressed="false" title="' . esc_attr__( 'Send the track you open to everyone following', 'callboard' ) . '">' . esc_html__( 'Lead', 'callboard' ) . '</button>';
			$html .= '<button type="button" class="cue-toggle" id="cue-start" title="' . esc_attr__( 'Start this track on every phone following, at the same moment', 'callboard' ) . '">' . esc_html__( 'Together', 'callboard' ) . '</button>';
		}
		$html .= '<button type="button" class="cue-toggle" id="cue-follow" aria-pressed="false" hidden title="' . esc_attr__( 'Open the track the director opens', 'callboard' ) . '">' . esc_html__( 'Follow', 'callboard' ) . '</button>';
		return $html . '</div>';
	}

	/**
	 * The current cue. Once LIFETIME has passed since it was set, `set`, `track`, `at` and `start`
	 * are null.
	 *
	 * @since 2.4.0
	 *
	 * @param int|null $now Current Unix time in milliseconds, for tests.
	 * @return array{seq: int, set: string|null, track: int|null, position: float, at: int|null, start: int|null}
	 */
	public static function current( ?int $now = null ): array {
		$now    = $now ?? self::now();
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$cue    = array(
			'seq'      => (int) ( $stored['seq'] ?? 0 ),
			'set'      => null,
			'track'    => null,
			'position' => 0.0,
			'at'       => null,
			'start'    => null,
		);
		$at     = (int) ( $stored['at'] ?? 0 );
		if ( ! empty( $stored['set'] ) && $at > 0 && $now - $at < self::LIFETIME * 1000 ) {
			$cue['set']      = (string) $stored['set'];
			$cue['track']    = (int) ( $stored['track'] ?? 0 );
			$cue['position'] = (float) ( $stored['position'] ?? 0 );
			$cue['at']       = $at;
			$cue['start']    = empty( $stored['start'] ) ? null : (int) $stored['start'];
		}
		return $cue;
	}

	/**
	 * REST callback for GET time: the server's clock, in milliseconds.
	 *
	 * A phone asks several times, keeps the round trip that came back quickest and takes its own
	 * distance from this clock from that one, the way NTP does. Nothing here is cached or stored.
	 *
	 * @since 2.4.0
	 */
	public static function time(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'now' => self::now() ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * REST callback for GET: the cue, or 204 when `since` matches a cue that is still set.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function read( WP_REST_Request $request ): WP_REST_Response {
		$cue   = self::current();
		$since = $request->get_param( 'since' );
		if ( null !== $since && (int) $since === $cue['seq'] && null !== $cue['set'] ) {
			$response = new WP_REST_Response( null, 204 );
		} else {
			$response = new WP_REST_Response( $cue, 200 );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * REST callback for POST: set the cue to a track in a published playlist.
	 *
	 * `start` is the moment the track begins, in milliseconds on this server's clock. It has to land
	 * in the next LEAD_IN seconds: a start already gone by, or further off than the director could
	 * mean, is a clock that has drifted rather than a cue.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request with `set`, `track`, `position` and `start`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function write( WP_REST_Request $request ) {
		$slug     = sanitize_title( (string) $request->get_param( 'set' ) );
		$track    = (int) $request->get_param( 'track' );
		$playlist = '' !== $slug ? get_page_by_path( $slug, OBJECT, 'callboard_set' ) : null;
		if ( ! $playlist instanceof WP_Post || 'publish' !== $playlist->post_status || $track >= self::track_count( $playlist->ID ) ) {
			return new WP_Error( 'callboard_cue_invalid', __( 'That track is not in a published playlist.', 'callboard' ), array( 'status' => 400 ) );
		}

		$now   = self::now();
		$start = (int) round( (float) $request->get_param( 'start' ) );
		if ( $start && ( $start <= $now || $start > $now + self::LEAD_IN * 1000 ) ) {
			return new WP_Error(
				'callboard_cue_start_invalid',
				sprintf(
					/* translators: %d: number of seconds. */
					__( 'A start has to be within the next %d seconds.', 'callboard' ),
					self::LEAD_IN
				),
				array( 'status' => 400 )
			);
		}

		$previous = get_option( self::OPTION );
		$cue      = array(
			'seq'      => (int) ( is_array( $previous ) ? ( $previous['seq'] ?? 0 ) : 0 ) + 1,
			'set'      => $slug,
			'track'    => $track,
			'position' => round( max( 0.0, (float) $request->get_param( 'position' ) ), 1 ),
			'at'       => $now,
			'start'    => $start ? $start : null,
		);
		update_option( self::OPTION, $cue, false );
		return new WP_REST_Response( $cue, 200 );
	}

	/**
	 * Number of audio tracks in a playlist.
	 *
	 * @since 2.4.0
	 *
	 * @param int $playlist_id Playlist post ID.
	 */
	private static function track_count( int $playlist_id ): int {
		return count(
			get_children(
				array(
					'post_parent'    => $playlist_id,
					'post_type'      => 'attachment',
					'post_mime_type' => 'audio',
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * Current Unix time in milliseconds.
	 *
	 * @since 2.4.0
	 */
	private static function now(): int {
		return (int) round( microtime( true ) * 1000 );
	}
}
