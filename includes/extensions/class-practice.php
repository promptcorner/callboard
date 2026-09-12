<?php
/**
 * Practice counts: how often each track was opened, looped and played, shown on each call.
 *
 * Off by default. When the "Count practice" setting is off, the extension is not registered, so no
 * script listens, no route exists and nothing is stored. When it is on, the page sends anonymous
 * totals per track and this class adds them to the track's `_callboard_practice` meta, grouped by
 * hour in the site's time zone. No user id, IP address or cookie is stored.
 *
 * @package Callboard
 * @since 2.4.0
 */

namespace Callboard\Extension;

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The practice counts extension.
 */
final class Practice {

	/**
	 * Attachment meta: hour buckets of counts.
	 */
	public const META = '_callboard_practice';

	/**
	 * Most a single request may add to one track.
	 */
	public const MAX_OPENS = 5;
	public const MAX_LOOPS = 50;

	/**
	 * Seconds a single request may add, as a multiple of the track's length.
	 */
	public const MAX_PLAYS = 3;

	/**
	 * Most tracks one request may report.
	 */
	public const MAX_TRACKS = 100;

	/**
	 * Buckets older than this many days are removed when a track's counts are written.
	 */
	public const KEEP_DAYS = 180;

	/**
	 * Register with the extension registry when the setting is on.
	 *
	 * @since 2.4.0
	 */
	public static function register(): void {
		if ( ! callboard_get_setting( 'practice' ) ) {
			return;
		}

		/**
		 * The practice counts extension. Unregister it by this id to stop counting.
		 */
		callboard_register_extension(
			'callboard/practice',
			array(
				'title'       => __( 'Practice counts', 'callboard' ),
				'version'     => '1.0.0',
				'api_version' => 1,
				'app_data'    => array( self::class, 'app_data' ),
				'rest'        => array(
					array(
						'/counts',
						array(
							'methods'  => 'POST',
							'callback' => array( self::class, 'receive' ),
						),
					),
				),
			)
		);

		add_action( 'add_meta_boxes_callboard_call', array( self::class, 'meta_box' ) );
	}

	/**
	 * Where the page sends counts, and a REST nonce for signed-in users.
	 *
	 * Without the nonce, WordPress treats a signed-in user's request as signed out, and a site that
	 * requires sign-in refuses it.
	 *
	 * @since 2.4.0
	 *
	 * @return array{url: string, nonce?: string}
	 */
	public static function app_data(): array {
		$data = array( 'url' => rest_url( 'callboard/v1/practice/counts' ) );
		// @todo Use the core REST nonce from app data once #145 adds it.
		if ( is_user_logged_in() ) {
			$data['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $data;
	}

	/**
	 * REST callback: add a batch of counts.
	 *
	 * The body is a JSON array of { track, opens, loops, seconds }. It is read from the raw body so
	 * the page can send it as text/plain, which `navigator.sendBeacon` sends without a preflight.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$entries = json_decode( $request->get_body(), true );
		if ( ! is_array( $entries ) ) {
			return new WP_REST_Response( array( 'saved' => 0 ), 400 );
		}
		$saved = 0;
		foreach ( self::clamp( $entries ) as $track_id => $counts ) {
			self::add( $track_id, $counts );
			++$saved;
		}
		return new WP_REST_Response( array( 'saved' => $saved ), 200 );
	}

	/**
	 * Merge entries per track, drop tracks that are not in a published playlist, and cap each value.
	 *
	 * @since 2.4.0
	 *
	 * @param array<int|string, mixed> $entries Decoded request body.
	 * @return array<int, array{opens: int, loops: int, seconds: int}> Counts by attachment id.
	 */
	public static function clamp( array $entries ): array {
		$merged = array();
		foreach ( array_slice( $entries, 0, self::MAX_TRACKS ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['track'] ) || ! is_numeric( $entry['track'] ) ) {
				continue;
			}
			$id = (int) $entry['track'];
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = array(
					'opens'   => 0,
					'loops'   => 0,
					'seconds' => 0,
				);
			}
			foreach ( array( 'opens', 'loops', 'seconds' ) as $key ) {
				$merged[ $id ][ $key ] += max( 0, (int) ( is_numeric( $entry[ $key ] ?? null ) ? $entry[ $key ] : 0 ) );
			}
		}

		$out = array();
		foreach ( $merged as $id => $counts ) {
			if ( ! self::is_published_track( $id ) ) {
				continue;
			}
			$length  = (float) get_post_meta( $id, '_callboard_duration', true );
			$seconds = $length > 0 ? min( $counts['seconds'], (int) ceil( $length * self::MAX_PLAYS ) ) : 0;
			$counts  = array(
				'opens'   => min( $counts['opens'], self::MAX_OPENS ),
				'loops'   => min( $counts['loops'], self::MAX_LOOPS ),
				'seconds' => $seconds,
			);
			if ( array_sum( $counts ) > 0 ) {
				$out[ $id ] = $counts;
			}
		}
		return $out;
	}

	/**
	 * Whether an attachment is an audio track in a published playlist.
	 *
	 * @since 2.4.0
	 *
	 * @param int $id Attachment id.
	 */
	public static function is_published_track( int $id ): bool {
		$track = get_post( $id );
		if ( ! $track instanceof WP_Post || 'attachment' !== $track->post_type || ! str_starts_with( (string) $track->post_mime_type, 'audio/' ) ) {
			return false;
		}
		$playlist = $track->post_parent ? get_post( $track->post_parent ) : null;
		return $playlist instanceof WP_Post && 'callboard_set' === $playlist->post_type && 'publish' === $playlist->post_status;
	}

	/**
	 * Add counts to the current hour's bucket on a track.
	 *
	 * @since 2.4.0
	 *
	 * @param int                                         $track_id Attachment id.
	 * @param array{opens: int, loops: int, seconds: int} $counts   Counts to add.
	 * @param int|null                                    $time     Unix time to file them under. Defaults to now.
	 */
	public static function add( int $track_id, array $counts, ?int $time = null ): void {
		$time    = $time ?? time();
		$buckets = self::buckets( $track_id );
		$key     = wp_date( 'Y-m-d H', $time );
		$bucket  = $buckets[ $key ] ?? array(
			'opens'   => 0,
			'loops'   => 0,
			'seconds' => 0,
		);
		foreach ( array( 'opens', 'loops', 'seconds' ) as $field ) {
			$bucket[ $field ] = (int) ( $bucket[ $field ] ?? 0 ) + (int) $counts[ $field ];
		}
		$buckets[ $key ] = $bucket;

		$oldest = wp_date( 'Y-m-d H', $time - self::KEEP_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $buckets ) as $hour ) {
			if ( (string) $hour < $oldest ) {
				unset( $buckets[ $hour ] );
			}
		}
		ksort( $buckets );
		update_post_meta( $track_id, self::META, $buckets );
	}

	/**
	 * A track's stored buckets.
	 *
	 * @since 2.4.0
	 *
	 * @param int $track_id Attachment id.
	 * @return array<string, array{opens: int, loops: int, seconds: int}>
	 */
	public static function buckets( int $track_id ): array {
		$buckets = get_post_meta( $track_id, self::META, true );
		return is_array( $buckets ) ? $buckets : array();
	}

	/**
	 * Totals for a track between two times, by hour bucket. The start hour is included only from
	 * the hour after the start time, so practice before a call does not count toward the next one.
	 *
	 * @since 2.4.0
	 *
	 * @param int      $track_id Attachment id.
	 * @param int|null $from     Unix time the window starts, or null for no start.
	 * @param int      $to       Unix time the window ends.
	 * @return array{opens: int, loops: int, seconds: int}
	 */
	public static function totals( int $track_id, ?int $from, int $to ): array {
		$total = array(
			'opens'   => 0,
			'loops'   => 0,
			'seconds' => 0,
		);
		$start = null === $from ? '' : wp_date( 'Y-m-d H', $from );
		$end   = wp_date( 'Y-m-d H', $to );
		foreach ( self::buckets( $track_id ) as $hour => $bucket ) {
			$hour = (string) $hour;
			if ( ( '' !== $start && $hour <= $start ) || $hour > $end ) {
				continue;
			}
			foreach ( array( 'opens', 'loops', 'seconds' ) as $field ) {
				$total[ $field ] += (int) ( $bucket[ $field ] ?? 0 );
			}
		}
		return $total;
	}

	/**
	 * The time window for a call: from the most recent published call before it, to its own time
	 * or to now, whichever is earlier.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Post  $call Call post.
	 * @param int|null $now  Current Unix time, for tests.
	 * @return array{from: int|null, to: int}
	 */
	public static function window( WP_Post $call, ?int $now = null ): array {
		$now  = $now ?? time();
		$time = self::call_time( $call );
		$to   = $time ? min( $time, $now ) : $now;

		$from = null;
		$ids  = get_posts(
			array(
				'post_type'      => 'callboard_call',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => array( $call->ID ),
				'meta_key'       => '_callboard_when', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- calls are few.
			)
		);
		foreach ( $ids as $id ) {
			$other = self::call_time( get_post( $id ) );
			if ( $other && $other < $to && ( null === $from || $other > $from ) ) {
				$from = $other;
			}
		}
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * A call's time as Unix time, or 0 for a call without one.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Post|null $call Call post.
	 */
	public static function call_time( ?WP_Post $call ): int {
		if ( ! $call ) {
			return 0;
		}
		$when = (string) get_post_meta( $call->ID, '_callboard_when', true );
		$date = $when ? date_create_immutable_from_format( 'Y-m-d H:i', $when, wp_timezone() ) : false;
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Add the meta box to the call editor.
	 *
	 * @since 2.4.0
	 */
	public static function meta_box(): void {
		add_meta_box( 'callboard-practice', __( 'Practiced since the last call', 'callboard' ), array( self::class, 'render_box' ), 'callboard_call', 'normal', 'default' );
	}

	/**
	 * The meta box: each track on the call with its opens, loops and minutes played.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_Post $call Call post.
	 */
	public static function render_box( WP_Post $call ): void {
		$tracks = array_filter( array_map( 'intval', (array) get_post_meta( $call->ID, '_callboard_numbers', true ) ) );
		if ( ! $tracks ) {
			echo '<p>' . esc_html__( 'Choose the tracks for this call and save it to see how much each one was practiced.', 'callboard' ) . '</p>';
			return;
		}

		$window = self::window( $call );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$range  = null === $window['from']
			/* translators: %s: date and time. */
			? sprintf( __( 'Counts up to %s.', 'callboard' ), wp_date( $format, $window['to'] ) )
			/* translators: 1: start date and time, 2: end date and time. */
			: sprintf( __( 'Counts from %1$s to %2$s.', 'callboard' ), wp_date( $format, $window['from'] ), wp_date( $format, $window['to'] ) );
		?>
		<p class="description"><?php echo esc_html( $range ); ?> <?php esc_html_e( 'Counts are anonymous and grouped by hour.', 'callboard' ); ?></p>
		<table class="widefat striped callboard-practice">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Track', 'callboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Opened', 'callboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Loops set', 'callboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Minutes played', 'callboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tracks as $track_id ) : ?>
					<?php
					$total = self::totals( $track_id, $window['from'], $window['to'] );
					$title = get_the_title( $track_id );
					?>
					<tr data-track="<?php echo (int) $track_id; ?>">
						<th scope="row"><?php echo esc_html( '' !== $title ? $title : '#' . $track_id ); ?></th>
						<?php if ( 0 === $total['opens'] && 0 === $total['seconds'] ) : ?>
							<td colspan="3" class="callboard-practice-none"><?php esc_html_e( 'Nobody opened this track.', 'callboard' ); ?></td>
						<?php else : ?>
							<td class="callboard-practice-opens"><?php echo esc_html( number_format_i18n( $total['opens'] ) ); ?></td>
							<td class="callboard-practice-loops"><?php echo esc_html( number_format_i18n( $total['loops'] ) ); ?></td>
							<td class="callboard-practice-minutes"><?php echo esc_html( number_format_i18n( $total['seconds'] / MINUTE_IN_SECONDS, 1 ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
