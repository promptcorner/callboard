<?php
/**
 * Abilities: what is registered, who may run each one, and what they return.
 *
 * @package Callboard
 */

use Callboard\Abilities;
use Callboard\Calls;
use Callboard\Roles;

/**
 * @covers \Callboard\Abilities
 * @covers \Callboard\Calls
 */
class Test_Callboard_Abilities extends WP_UnitTestCase {

	/**
	 * Files to delete after the test.
	 *
	 * @var string[]
	 */
	private array $trash = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9 or later.' );
		}
		update_option( 'timezone_string', 'America/New_York' );
		add_filter( 'callboard_push_message', '__return_empty_array' ); // Publishing a call sends nothing.
	}

	public function tear_down(): void {
		foreach ( $this->trash as $file ) {
			wp_delete_file( $file );
		}
		delete_option( 'timezone_string' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published playlist with one audio track whose file exists, which is what Sets::build() needs.
	 *
	 * @return int Track id.
	 */
	private function track(): int {
		$playlist = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_set',
				'post_status' => 'publish',
				'post_name'   => 'ability-playlist',
				'post_title'  => 'Ability Playlist',
			)
		);
		$file     = wp_upload_dir()['basedir'] . '/ability-track.mp3';
		file_put_contents( $file, str_repeat( "\0", 128 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->trash[] = $file;
		$track         = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_parent'    => $playlist,
				'post_title'     => 'Ability Track',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $track, '_wp_attached_file', 'ability-track.mp3' );
		return $track;
	}

	/**
	 * Tomorrow at 19:00 in the site's time zone, as the editor's When field sends it.
	 */
	private function tomorrow(): string {
		return wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) . 'T19:00';
	}

	/**
	 * Whether a user with a role passes an ability's permission check.
	 *
	 * @param string $name Ability name.
	 * @param string $role Role.
	 */
	private function allowed( string $name, string $role ): bool {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		return true === wp_get_ability( $name )->check_permissions( array() );
	}

	public function test_the_three_abilities_are_registered_in_the_callboard_category(): void {
		$this->assertTrue( wp_has_ability_category( Abilities::CATEGORY ) );
		foreach ( array( 'callboard/list-playlists', 'callboard/list-upcoming-calls', 'callboard/post-call' ) as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "{$name} is not registered" );
			$ability = wp_get_ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category() );
			$this->assertNotEmpty( $ability->get_input_schema(), "{$name} has no input schema" );
			$this->assertNotEmpty( $ability->get_output_schema(), "{$name} has no output schema" );
		}
	}

	public function test_listing_playlists_needs_the_capability_to_edit_playlists(): void {
		$this->assertFalse( $this->allowed( 'callboard/list-playlists', 'subscriber' ) );
		$this->assertFalse( $this->allowed( 'callboard/list-playlists', Roles::CAST_MEMBER ), 'opening the front end is not enough' );
		$this->assertTrue( $this->allowed( 'callboard/list-playlists', Roles::DIRECTOR ) );
	}

	public function test_listing_upcoming_calls_needs_the_capability_to_edit_calls(): void {
		$this->assertFalse( $this->allowed( 'callboard/list-upcoming-calls', 'subscriber' ) );
		$this->assertFalse( $this->allowed( 'callboard/list-upcoming-calls', Roles::CAST_MEMBER ), 'opening the front end is not enough' );
		$this->assertTrue( $this->allowed( 'callboard/list-upcoming-calls', Roles::DIRECTOR ) );
	}

	public function test_posting_a_call_needs_the_capability_to_publish_calls(): void {
		$this->assertFalse( $this->allowed( 'callboard/post-call', 'subscriber' ) );
		$this->assertFalse( $this->allowed( 'callboard/post-call', 'contributor' ), 'a contributor can write a call but not publish it' );
		$this->assertTrue( $this->allowed( 'callboard/post-call', 'author' ) );
		$this->assertTrue( $this->allowed( 'callboard/post-call', Roles::DIRECTOR ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertWPError( wp_get_ability( 'callboard/post-call' )->execute( array( 'title' => 'Not allowed' ) ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => Calls::TYPE ) ) );
	}

	public function test_a_posted_call_shows_in_the_upcoming_calls(): void {
		$track    = $this->track();
		$director = self::factory()->user->create( array( 'role' => Roles::DIRECTOR ) );
		wp_set_current_user( $director );
		$when = $this->tomorrow();

		$call = wp_get_ability( 'callboard/post-call' )->execute(
			array(
				'title'   => 'Act I run',
				'note'    => 'Bring your script.',
				'when'    => $when,
				'where'   => 'Studio B',
				'numbers' => array( $track ),
			)
		);
		$this->assertNotWPError( $call );
		$this->assertSame( 'publish', get_post_status( $call['id'] ) );
		$this->assertSame( $director, (int) get_post_field( 'post_author', $call['id'] ) );

		$calls = wp_get_ability( 'callboard/list-upcoming-calls' )->execute();
		$this->assertNotWPError( $calls );
		$listed = array_column( $calls['calls'], null, 'id' )[ $call['id'] ] ?? null;
		$this->assertNotNull( $listed, 'the posted call is not on the board' );
		$this->assertSame( 'Act I run', $listed['title'] );
		$this->assertSame( 'Studio B', $listed['where'] );
		$this->assertSame( str_replace( 'T', ' ', $when ), wp_date( 'Y-m-d H:i', $listed['when'] ), 'the time is read in the site time zone' );
		$this->assertSame( array( 'Ability Track' ), array_column( $listed['numbers'], 'title' ) );
		$this->assertStringContainsString( 'Bring your script.', $listed['body'] );
		$this->assertTrue( $listed['is_next'] );
	}

	public function test_the_lists_match_their_output_schemas(): void {
		$this->track();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::DIRECTOR ) ) );
		$this->assertNotWPError( Calls::post( 'Act II run', '', $this->tomorrow(), 'Main stage' ) );
		$this->assertNotWPError( Calls::post( 'Costume fittings are posted' ) );

		foreach ( array(
			'callboard/list-playlists'      => 'playlists',
			'callboard/list-upcoming-calls' => 'calls',
		) as $name => $key ) {
			$ability = wp_get_ability( $name );
			$output  = $ability->execute();
			$this->assertNotWPError( $output );
			$this->assertNotEmpty( $output[ $key ], "{$name} returned nothing to check" );
			$valid = rest_validate_value_from_schema( $output, $ability->get_output_schema(), $name );
			$this->assertNotWPError( $valid );
			$this->assertTrue( $valid );
		}

		$playlists = wp_get_ability( 'callboard/list-playlists' )->execute()['playlists'];
		$playlist  = array_column( $playlists, null, 'slug' )['ability-playlist'];
		$this->assertSame( array( 'Ability Track' ), array_column( $playlist['tracks'], 'title' ) );
		$this->assertSame( home_url( '/ability-playlist/' ), $playlist['url'] );
		$this->assertCount( 2, wp_get_ability( 'callboard/list-upcoming-calls' )->execute()['calls'] );
	}
}
