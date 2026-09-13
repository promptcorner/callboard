<?php
/**
 * Saving a set sends a push notification for each director's note that is new.
 *
 * @package Callboard
 */

use Callboard\Admin;
use Callboard\Notes;
use Callboard\Post_Types;

/**
 * @covers \Callboard\Admin::save
 * @covers \Callboard\Notes::set
 */
class Test_Callboard_Note_Notifications extends WP_UnitTestCase {

	/**
	 * Messages passed to the `callboard_push_message` filter.
	 *
	 * @var array<int, array{title: string, body: string, url: string}>
	 */
	private array $sent = array();

	private int $set;

	/**
	 * Track attachment IDs, in playlist order.
	 *
	 * @var int[]
	 */
	private array $tracks;

	public function set_up(): void {
		parent::set_up();
		Notes::register_meta();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );

		$this->set    = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::SET,
				'post_status' => 'publish',
				'post_name'   => 'spring-show',
			)
		);
		$this->tracks = array();
		foreach ( array( 'Overture', 'Number 14' ) as $title ) {
			$this->tracks[] = self::factory()->post->create(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'audio/mpeg',
					'post_parent'    => $this->set,
					'post_title'     => $title,
				)
			);
		}

		// Capture each message and return an empty one, so nothing is sent.
		add_filter(
			'callboard_push_message',
			function ( array $message ): array {
				$this->sent[] = $message;
				return array();
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'callboard_push_message' );
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Submit the set's edit form with these notes for the second track.
	 *
	 * @param string $notes Textarea content, one "m:ss text" note per line.
	 */
	private function save_notes( string $notes ): void {
		$_POST = array(
			'callboard_nonce' => wp_create_nonce( 'callboard_save' ),
			'callboard_order' => $this->tracks,
			'callboard_bpm'   => array_fill_keys( $this->tracks, '' ),
			'callboard_notes' => array( $this->tracks[1] => $notes ),
		);
		Admin::save( $this->set );
	}

	public function test_a_new_note_notifies_the_cast_with_a_link_to_the_track_and_time(): void {
		$this->save_notes( '1:12 Breathe before the key change' );

		$this->assertTrue( Notes::has_comments( $this->tracks[1] ), 'the note is stored as a comment' );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( "Director's note: Number 14", $this->sent[0]['title'] );
		$this->assertSame( 'Breathe before the key change', $this->sent[0]['body'] );
		$this->assertSame( home_url( '/spring-show/' ) . '?track=1&at=72', $this->sent[0]['url'] );
	}

	public function test_saving_notes_that_are_already_comments_sends_nothing(): void {
		Notes::set(
			$this->tracks[1],
			array(
				array(
					't'    => 72,
					'text' => 'Breathe before the key change',
				),
			)
		);

		$this->save_notes( '1:12 Breathe before the key change' );

		$this->assertSame( array(), $this->sent );
	}

	public function test_only_the_added_line_is_sent(): void {
		$this->save_notes( '1:12 Breathe before the key change' );
		$this->sent = array();

		$this->save_notes( "0:30 Watch the cut-off\n1:12 Breathe before the key change" );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'Watch the cut-off', $this->sent[0]['body'] );
		$this->assertStringEndsWith( '?track=1&at=30', $this->sent[0]['url'] );
	}

	public function test_notes_moved_over_from_the_old_post_meta_are_not_new(): void {
		update_post_meta(
			$this->tracks[1],
			Notes::META_LEGACY,
			array(
				array(
					't'    => 72,
					'text' => 'Breathe before the key change',
					'date' => '2026-01-02',
				),
			)
		);

		$this->save_notes( "1:12 Breathe before the key change\n2:05 Hold the fermata" );

		$this->assertTrue( Notes::has_comments( $this->tracks[1] ), 'the save copies the notes into comments' );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'Hold the fermata', $this->sent[0]['body'] );
	}

	public function test_a_draft_set_sends_nothing(): void {
		wp_update_post(
			array(
				'ID'          => $this->set,
				'post_status' => 'draft',
			)
		);

		$this->save_notes( '1:12 Breathe before the key change' );

		$this->assertTrue( Notes::has_comments( $this->tracks[1] ), 'the note is still saved' );
		$this->assertSame( array(), $this->sent );
	}

	public function test_set_returns_only_the_notes_it_added(): void {
		Notes::set(
			$this->tracks[0],
			array(
				array(
					't'    => 5,
					'text' => 'Count in',
				),
			)
		);

		$added = Notes::set(
			$this->tracks[0],
			array(
				array(
					't'    => 5,
					'text' => 'Count in',
				),
				array(
					't'    => 9.5,
					'text' => 'Lights up',
				),
			)
		);

		$this->assertCount( 1, $added );
		$this->assertSame( 9.5, $added[0]['t'] );
		$this->assertSame( 'Lights up', $added[0]['text'] );
	}
}
