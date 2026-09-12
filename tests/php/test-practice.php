<?php
/**
 * Practice counts: what the route accepts, how counts are stored, and which window a call shows.
 *
 * @package Callboard
 */

use Callboard\Extension\Practice;
use Callboard\Extensions;
use Callboard\Settings;

/**
 * @covers \Callboard\Extension\Practice
 */
class Test_Callboard_Practice extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'practice' => true ) ) );
		$this->register_practice();
		$GLOBALS['wp_rest_server'] = null; // A fresh server fires rest_api_init, which registers the route.
		rest_get_server();
	}

	public function tear_down(): void {
		if ( callboard_get_extension( 'callboard/practice' ) ) {
			callboard_unregister_extension( 'callboard/practice' );
		}
		remove_action( 'add_meta_boxes_callboard_call', array( Practice::class, 'meta_box' ) );
		delete_option( Settings::OPTION );
		delete_option( 'timezone_string' );
		remove_all_filters( 'callboard_can_view' );
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register callboard/practice the way the plugin does at boot, where the callboard namespace is allowed.
	 */
	private function register_practice(): void {
		$flag = new ReflectionProperty( Extensions::class, 'first_party' );
		$flag->setAccessible( true );
		$flag->setValue( null, true );
		Practice::register();
		$flag->setValue( null, false );
	}

	/**
	 * An audio track in a playlist with the given status.
	 *
	 * @param string $status  Playlist post status.
	 * @param float  $seconds Track length.
	 * @return int Attachment id.
	 */
	private function track( string $status = 'publish', float $seconds = 60 ): int {
		$playlist = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_set',
				'post_status' => $status,
				'post_title'  => 'Practice Playlist',
			)
		);
		$track    = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_parent'    => $playlist,
				'post_title'     => 'Practice Track',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $track, '_callboard_duration', $seconds );
		return $track;
	}

	/**
	 * A published call at a time in the site's time zone.
	 *
	 * @param string $when 'Y-m-d H:i'.
	 * @return int Call id.
	 */
	private function call( string $when ): int {
		$call = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_call',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $call, '_callboard_when', $when );
		return $call;
	}

	/**
	 * POST a body to the route.
	 *
	 * @param mixed $body Value to send as JSON.
	 */
	private function post( $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/callboard/v1/practice/counts' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Unix time for a local time in the site's time zone.
	 *
	 * @param string $local 'Y-m-d H:i'.
	 */
	private function local_time( string $local ): int {
		return date_create_immutable_from_format( 'Y-m-d H:i', $local, wp_timezone() )->getTimestamp();
	}

	public function test_nothing_is_registered_when_the_setting_is_off(): void {
		callboard_unregister_extension( 'callboard/practice' );
		update_option( Settings::OPTION, Settings::defaults() );
		$this->register_practice();
		$this->assertNull( callboard_get_extension( 'callboard/practice' ) );
		$this->assertFalse( Settings::defaults()['practice'], 'the setting is off by default' );
	}

	public function test_values_are_capped_per_request(): void {
		$track    = $this->track( 'publish', 60 );
		$response = $this->post(
			array(
				array(
					'track'   => $track,
					'opens'   => 40,
					'loops'   => 900,
					'seconds' => 99999,
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$bucket = current( Practice::buckets( $track ) );
		$this->assertSame(
			array(
				'opens'   => Practice::MAX_OPENS,
				'loops'   => Practice::MAX_LOOPS,
				'seconds' => 180, // Three times the track's 60 seconds.
			),
			$bucket
		);
	}

	public function test_entries_for_the_same_track_are_merged_before_capping(): void {
		$track = $this->track();
		$this->post(
			array(
				array(
					'track' => $track,
					'opens' => 4,
				),
				array(
					'track' => $track,
					'opens' => 4,
				),
			)
		);
		$this->assertSame( Practice::MAX_OPENS, current( Practice::buckets( $track ) )['opens'] );
	}

	public function test_tracks_outside_a_published_playlist_and_bad_values_are_dropped(): void {
		$draft = $this->track( 'draft' );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$good  = $this->track();
		$saved = $this->post(
			array(
				array(
					'track' => $draft,
					'opens' => 1,
				),
				array(
					'track' => $page,
					'opens' => 1,
				),
				array(
					'track' => 999999,
					'opens' => 1,
				),
				array(
					'track' => 'nope',
					'opens' => 1,
				),
				array(
					'track'   => $good,
					'opens'   => -3,
					'seconds' => 'x',
					'loops'   => 1,
				),
			)
		)->get_data();
		$this->assertSame( 1, $saved['saved'] );
		$this->assertSame( array(), Practice::buckets( $draft ) );
		$this->assertSame(
			array(
				'opens'   => 0,
				'loops'   => 1,
				'seconds' => 0,
			),
			current( Practice::buckets( $good ) )
		);
	}

	public function test_a_body_that_is_not_a_list_is_refused(): void {
		$request = new WP_REST_Request( 'POST', '/callboard/v1/practice/counts' );
		$request->set_body( 'not json' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_counts_are_grouped_by_hour_in_the_site_time_zone(): void {
		$track = $this->track();
		// 03:30 UTC on 12 September is 23:30 on 11 September in New York.
		Practice::add(
			$track,
			array(
				'opens'   => 1,
				'loops'   => 0,
				'seconds' => 10,
			),
			gmmktime( 3, 30, 0, 9, 12, 2026 )
		);
		$this->assertSame( array( '2026-09-11 23' ), array_keys( Practice::buckets( $track ) ) );
	}

	public function test_old_buckets_are_removed_when_counts_are_written(): void {
		$track = $this->track();
		$now   = $this->local_time( '2026-09-12 12:00' );
		$one   = array(
			'opens'   => 1,
			'loops'   => 0,
			'seconds' => 0,
		);
		Practice::add( $track, $one, $now - ( Practice::KEEP_DAYS + 1 ) * DAY_IN_SECONDS );
		Practice::add( $track, $one, $now );
		$this->assertSame( array( '2026-09-12 12' ), array_keys( Practice::buckets( $track ) ) );
	}

	public function test_a_call_counts_from_the_previous_call_to_its_own_time(): void {
		$track = $this->track();
		$one   = array(
			'opens'   => 1,
			'loops'   => 0,
			'seconds' => 0,
		);
		$this->call( '2026-09-08 19:00' );
		$earlier_call = $this->call( '2026-09-10 19:00' );
		$later_call   = $this->call( '2026-09-13 19:00' );

		Practice::add( $track, $one, $this->local_time( '2026-09-10 14:00' ) ); // Before the earlier call.
		Practice::add( $track, $one, $this->local_time( '2026-09-10 21:00' ) ); // After it.
		Practice::add( $track, $one, $this->local_time( '2026-09-12 09:00' ) );
		Practice::add( $track, $one, $this->local_time( '2026-09-14 09:00' ) ); // After the later call.

		$now    = $this->local_time( '2026-09-20 12:00' );
		$window = Practice::window( get_post( $later_call ), $now );
		$this->assertSame( $this->local_time( '2026-09-10 19:00' ), $window['from'] );
		$this->assertSame( $this->local_time( '2026-09-13 19:00' ), $window['to'] );
		$this->assertSame( 2, Practice::totals( $track, $window['from'], $window['to'] )['opens'] );

		$window = Practice::window( get_post( $earlier_call ), $now );
		$this->assertSame( $this->local_time( '2026-09-08 19:00' ), $window['from'] );
		$this->assertSame( 1, Practice::totals( $track, $window['from'], $window['to'] )['opens'] );
	}

	public function test_a_call_in_the_future_counts_up_to_now(): void {
		$this->call( '2026-09-10 19:00' );
		$next   = $this->call( '2026-09-30 19:00' );
		$now    = $this->local_time( '2026-09-12 12:00' );
		$window = Practice::window( get_post( $next ), $now );
		$this->assertSame( $this->local_time( '2026-09-10 19:00' ), $window['from'] );
		$this->assertSame( $now, $window['to'] );
	}

	public function test_a_site_that_requires_sign_in_refuses_a_signed_out_request(): void {
		$track = $this->track();
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'practice'       => true,
					'require_signin' => true,
				)
			)
		);
		$body = array(
			array(
				'track' => $track,
				'opens' => 1,
			),
		);

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->post( $body )->get_status() );
		$this->assertSame( array(), Practice::buckets( $track ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 200, $this->post( $body )->get_status() );
	}

	public function test_the_page_gets_a_nonce_only_for_signed_in_users(): void {
		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( 'nonce', Practice::app_data() );
		$this->assertStringEndsWith( '/callboard/v1/practice/counts', Practice::app_data()['url'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 1, wp_verify_nonce( Practice::app_data()['nonce'], 'wp_rest' ) );
	}

	public function test_nothing_that_identifies_a_person_is_stored(): void {
		$track = $this->track();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post(
			array(
				array(
					'track'   => $track,
					'opens'   => 1,
					'user'    => 42,
					'ip'      => '10.0.0.1',
					'seconds' => 5,
				),
			)
		);
		$this->assertSame( array( 'opens', 'loops', 'seconds' ), array_keys( current( Practice::buckets( $track ) ) ) );
	}
}
