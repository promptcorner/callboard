<?php
/**
 * Abilities: what is registered, who may run each one, and what they return.
 *
 * @package Callboard
 */

use Callboard\Abilities;
use Callboard\Roles;

/**
 * @covers \Callboard\Abilities
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
	 * Whether a user with a role passes an ability's permission check.
	 *
	 * @param string $name Ability name.
	 * @param string $role Role.
	 */
	private function allowed( string $name, string $role ): bool {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		return true === wp_get_ability( $name )->check_permissions( array() );
	}

	public function test_the_ability_is_registered_in_the_callboard_category(): void {
		$this->assertTrue( wp_has_ability_category( Abilities::CATEGORY ) );
		foreach ( array( 'callboard/list-playlists' ) as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "{$name} is not registered" );
			$ability = wp_get_ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category() );
			$this->assertNotEmpty( $ability->get_input_schema(), "{$name} has no input schema" );
			$this->assertNotEmpty( $ability->get_output_schema(), "{$name} has no output schema" );
		}
	}

	public function test_listing_playlists_needs_the_capability_to_edit_playlists(): void {
		$this->assertFalse( $this->allowed( 'callboard/list-playlists', 'subscriber' ) );
		$this->assertFalse( $this->allowed( 'callboard/list-playlists', Roles::LISTENER ), 'opening the front end is not enough' );
		$this->assertTrue( $this->allowed( 'callboard/list-playlists', 'author' ) );
	}

	public function test_the_lists_match_their_output_schemas(): void {
		$this->track();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		foreach ( array(
			'callboard/list-playlists' => 'playlists',
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
	}
}
