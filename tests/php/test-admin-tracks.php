<?php
/**
 * Adding, ordering, and removing tracks in the set editor.
 *
 * @package Callboard
 */

use Callboard\Admin;
use Callboard\Post_Types;

/**
 * @covers \Callboard\Admin::save
 */
class Test_Callboard_Admin_Tracks extends WP_UnitTestCase {

	private int $set;

	private int $other_set;

	public function set_up(): void {
		parent::set_up();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );

		$this->set       = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::SET,
				'post_status' => 'draft',
			)
		);
		$this->other_set = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::SET,
				'post_status' => 'draft',
			)
		);
	}

	public function tear_down(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Submit the Tracks meta box.
	 *
	 * @param int[]              $order   Track order.
	 * @param int[]              $removed Removed track IDs.
	 * @param array<int, string> $titles  Edited titles.
	 */
	private function save_tracks( array $order, array $removed = array(), array $titles = array() ): void {
		$_POST = array(
			'callboard_nonce'   => wp_create_nonce( 'callboard_save' ),
			'callboard_order'   => $order,
			'callboard_removed' => $removed,
			'callboard_title'   => $titles,
		);
		Admin::save( $this->set );
	}

	/**
	 * Make an audio attachment without needing a file on disk.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $title     Attachment title.
	 */
	private function audio( int $parent_id, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_parent'    => $parent_id,
				'post_title'     => $title,
			)
		);
	}

	public function test_an_unattached_audio_file_can_be_added_and_ordered(): void {
		$first  = $this->audio( 0, 'First' );
		$second = $this->audio( $this->set, 'Second' );

		$this->save_tracks(
			array( $second, $first ),
			array(),
			array( $first => 'New title' )
		);

		$this->assertSame( $this->set, (int) get_post_field( 'post_parent', $first ) );
		$this->assertSame( 2, (int) get_post_field( 'menu_order', $first ) );
		$this->assertSame( 'New title', get_post_field( 'post_title', $first ) );
		$this->assertSame( 1, (int) get_post_field( 'menu_order', $second ) );
	}

	public function test_removing_a_track_keeps_it_in_the_media_library(): void {
		$track = $this->audio( $this->set, 'Keep the file' );

		$this->save_tracks( array(), array( $track ) );

		$this->assertSame( 'attachment', get_post_type( $track ) );
		$this->assertSame( 0, (int) get_post_field( 'post_parent', $track ) );
	}

	public function test_a_track_from_another_set_cannot_be_taken(): void {
		$track = $this->audio( $this->other_set, 'Already used' );

		$this->save_tracks( array( $track ) );

		$this->assertSame( $this->other_set, (int) get_post_field( 'post_parent', $track ) );
	}
}
