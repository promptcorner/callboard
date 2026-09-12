<?php
/**
 * Shared cue: who can read and set it, the sequence number, `since`, and when it expires.
 *
 * @package Callboard
 */

use Callboard\Extension\Cue;
use Callboard\Privacy;
use Callboard\Settings;

/**
 * @covers \Callboard\Extension\Cue
 */
class Test_Callboard_Cue extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null; // A fresh server fires rest_api_init, which registers the route.
		rest_get_server();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published playlist with three audio tracks.
	 *
	 * @param string $status Playlist post status.
	 * @return string Playlist slug.
	 */
	private function playlist( string $status = 'publish' ): string {
		$slug     = 'cue-playlist-' . $status;
		$playlist = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_set',
				'post_status' => $status,
				'post_name'   => $slug,
				'post_title'  => 'Cue Playlist',
			)
		);
		for ( $n = 0; $n < 3; $n++ ) {
			self::factory()->post->create(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'audio/mpeg',
					'post_parent'    => $playlist,
					'post_status'    => 'inherit',
					'menu_order'     => $n,
				)
			);
		}
		return $slug;
	}

	/**
	 * A user with a role, signed in.
	 *
	 * @param string $role Role.
	 */
	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * GET the cue.
	 *
	 * @param int|null $since Sequence number the caller already has.
	 */
	private function get( ?int $since = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/callboard/v1/cue' );
		if ( null !== $since ) {
			$request->set_query_params( array( 'since' => $since ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * POST a cue.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function post( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/callboard/v1/cue' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_cue_is_registered_as_a_first_party_extension(): void {
		$this->assertNotNull( callboard_get_extension( 'callboard/cue' ) );
		$this->assertArrayHasKey( '/callboard/v1/cue', rest_get_server()->get_routes() );
		$this->assertArrayHasKey( '/callboard/v1/cue/time', rest_get_server()->get_routes() );
	}

	public function test_the_clock_is_the_servers_own_and_anyone_who_can_view_the_site_reads_it(): void {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/callboard/v1/cue/time' );
		$before   = (int) round( microtime( true ) * 1000 );
		$response = rest_get_server()->dispatch( $request );
		$after    = (int) round( microtime( true ) * 1000 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$now = $response->get_data()['now'];
		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( $after, $now );
	}

	public function test_setting_the_cue_needs_edit_posts(): void {
		$slug = $this->playlist();
		$body = array(
			'set'   => $slug,
			'track' => 1,
		);

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->post( $body )->get_status() );

		$this->sign_in( 'subscriber' );
		$this->assertSame( 403, $this->post( $body )->get_status() );
		$this->assertNull( Cue::current()['set'], 'a refused request leaves the cue alone' );

		$this->sign_in( 'contributor' );
		$response = $this->post( $body );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $slug, $response->get_data()['set'] );
		$this->assertSame( 1, $response->get_data()['track'] );
	}

	public function test_anyone_who_can_view_the_site_reads_the_cue(): void {
		$slug = $this->playlist();
		$this->sign_in( 'editor' );
		$this->post(
			array(
				'set'      => $slug,
				'track'    => 2,
				'position' => 12.34,
			)
		);

		wp_set_current_user( 0 );
		$response = $this->get();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$cue = $response->get_data();
		$this->assertSame( array( 'seq', 'set', 'track', 'position', 'at', 'start' ), array_keys( $cue ) );
		$this->assertSame( $slug, $cue['set'] );
		$this->assertSame( 2, $cue['track'] );
		$this->assertSame( 12.3, $cue['position'] );
		$this->assertNull( $cue['start'], 'a cue nobody asked to start has no start' );
	}

	public function test_a_start_is_kept_and_has_to_land_in_the_next_minute(): void {
		$slug = $this->playlist();
		$this->sign_in( 'editor' );
		$now   = (int) round( microtime( true ) * 1000 );
		$start = $now + 3000;

		$cue = $this->post(
			array(
				'set'      => $slug,
				'track'    => 0,
				'position' => 30,
				'start'    => $start,
			)
		);
		$this->assertSame( 200, $cue->get_status() );
		$this->assertSame( $start, $cue->get_data()['start'] );

		wp_set_current_user( 0 );
		$this->assertSame( $start, $this->get()->get_data()['start'], 'every phone reads the same moment' );

		$this->sign_in( 'editor' );
		foreach ( array( $now - 1000, $now + ( Cue::LEAD_IN + 1 ) * 1000 ) as $drifted ) {
			$refused = $this->post(
				array(
					'set'   => $slug,
					'track' => 0,
					'start' => $drifted,
				)
			);
			$this->assertSame( 400, $refused->get_status() );
		}
		$this->assertSame( $start, Cue::current()['start'], 'a refused start leaves the cue alone' );

		// The next cue without one clears it: opening another track is not a start.
		$this->post(
			array(
				'set'   => $slug,
				'track' => 1,
			)
		);
		$this->assertNull( Cue::current()['start'] );
	}

	public function test_each_change_increments_seq(): void {
		$slug = $this->playlist();
		$this->sign_in( 'editor' );
		$first  = $this->post(
			array(
				'set'   => $slug,
				'track' => 0,
			)
		)->get_data()['seq'];
		$second = $this->post(
			array(
				'set'   => $slug,
				'track' => 1,
			)
		)->get_data()['seq'];
		$this->assertSame( $first + 1, $second );
		$this->assertSame( $second, $this->get()->get_data()['seq'] );
	}

	public function test_since_gets_204_while_the_cue_has_not_changed(): void {
		$slug = $this->playlist();
		$this->sign_in( 'editor' );
		$seq = $this->post(
			array(
				'set'   => $slug,
				'track' => 0,
			)
		)->get_data()['seq'];

		$this->assertSame( 204, $this->get( $seq )->get_status() );
		$this->assertNull( $this->get( $seq )->get_data() );
		$this->assertSame( 200, $this->get( $seq - 1 )->get_status() );
	}

	public function test_the_cue_is_empty_two_hours_after_the_last_change(): void {
		$slug = $this->playlist();
		$this->sign_in( 'editor' );
		$cue = $this->post(
			array(
				'set'   => $slug,
				'track' => 1,
			)
		)->get_data();

		$almost = $cue['at'] + Cue::LIFETIME * 1000 - 1;
		$this->assertSame( $slug, Cue::current( $almost )['set'] );

		$expired = Cue::current( $cue['at'] + Cue::LIFETIME * 1000 );
		$this->assertSame(
			array(
				'seq'      => $cue['seq'],
				'set'      => null,
				'track'    => null,
				'position' => 0.0,
				'at'       => null,
				'start'    => null,
			),
			$expired
		);

		// Through the route: an expired cue answers `since` with the empty cue, not a 204.
		update_option( Cue::OPTION, array_merge( $cue, array( 'at' => $cue['at'] - Cue::LIFETIME * 1000 ) ) );
		$response = $this->get( $cue['seq'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['set'] );
	}

	public function test_a_track_outside_a_published_playlist_is_refused(): void {
		$published = $this->playlist();
		$draft     = $this->playlist( 'draft' );
		$this->sign_in( 'editor' );

		$this->assertSame(
			400,
			$this->post(
				array(
					'set'   => $draft,
					'track' => 0,
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->post(
				array(
					'set'   => 'no-such-playlist',
					'track' => 0,
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->post(
				array(
					'set'   => $published,
					'track' => 3,
				)
			)->get_status(),
			'the playlist has three tracks, 0 to 2'
		);
		$this->assertSame( 0, Cue::current()['seq'] );
	}

	public function test_a_site_that_requires_sign_in_refuses_a_signed_out_read(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'require_signin' => true ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get()->get_status() );

		$this->sign_in( 'subscriber' );
		$this->assertSame( 200, $this->get()->get_status() );
	}

	public function test_signed_out_visitors_get_past_the_rest_sign_in_rule_for_the_cue_only(): void {
		wp_set_current_user( 0 );
		// WordPress strips the trailing slash from rest_route.
		$GLOBALS['wp']->query_vars['rest_route'] = '/callboard/v1/cue';
		$this->assertNotWPError( Privacy::require_auth_for_rest( Cue::allow_signed_out( null ) ) );

		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$this->assertWPError( Privacy::require_auth_for_rest( Cue::allow_signed_out( null ) ) );
	}

	public function test_only_users_who_can_lead_get_the_lead_button_and_a_nonce_needs_a_sign_in(): void {
		wp_set_current_user( 0 );
		$controls = callboard_get_slot( 'transport' );
		$this->assertStringNotContainsString( 'id="cue-lead"', $controls );
		$this->assertStringNotContainsString( 'id="cue-start"', $controls );
		$this->assertStringContainsString( 'id="cue-follow"', $controls );
		$data = Cue::app_data();
		$this->assertFalse( $data['canLead'] );
		$this->assertArrayNotHasKey( 'nonce', $data );
		$this->assertStringEndsWith( '/callboard/v1/cue/', $data['api'] );
		$this->assertStringEndsWith( '/callboard/v1/cue/time', $data['time'] );

		$this->sign_in( 'subscriber' );
		$this->assertStringNotContainsString( 'id="cue-lead"', callboard_get_slot( 'transport' ) );
		$this->assertSame( 1, wp_verify_nonce( Cue::app_data()['nonce'], 'wp_rest' ) );

		$this->sign_in( 'editor' );
		$this->assertStringContainsString( 'id="cue-lead"', callboard_get_slot( 'transport' ) );
		$this->assertStringContainsString( 'id="cue-start"', callboard_get_slot( 'transport' ) );
		$this->assertTrue( Cue::app_data()['canLead'] );
	}
}
