<?php
/**
 * Director's notes as comments on the track.
 *
 * @package Callboard
 */

use Callboard\Notes;

/**
 * @covers \Callboard\Notes
 */
class Test_Callboard_Notes extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private int $track;

	public function set_up(): void {
		parent::set_up();
		Notes::register_meta();
		$this->track = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
	}

	public function test_a_track_with_no_notes_has_none(): void {
		$this->assertSame( array(), Notes::get( $this->track ) );
	}

	public function test_legacy_meta_is_still_read_until_comments_exist(): void {
		update_post_meta(
			$this->track,
			Notes::META_LEGACY,
			array(
				array(
					't'    => 3.4,
					'text' => 'Softer here',
					'date' => '2026-01-02',
				),
			)
		);

		$notes = Notes::get( $this->track );

		$this->assertCount( 1, $notes );
		$this->assertSame( 3.4, $notes[0]['t'] );
		$this->assertSame( 'Softer here', $notes[0]['text'] );
		$this->assertSame( '2026-01-02', $notes[0]['date'] );
	}

	public function test_set_writes_comments_not_post_meta(): void {
		$user = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Stage Manager',
			)
		);
		wp_set_current_user( $user );

		Notes::set(
			$this->track,
			array(
				array(
					't'    => 12.0,
					'text' => 'Watch the cut-off',
					'date' => '2026-03-01',
				),
			)
		);

		$this->assertSame( '', get_post_meta( $this->track, Notes::META_LEGACY, true ), 'new writes leave the legacy key alone' );

		$notes = Notes::get( $this->track );
		$this->assertCount( 1, $notes );
		$this->assertSame( 12.0, $notes[0]['t'] );
		$this->assertSame( 'Watch the cut-off', $notes[0]['text'] );
		$this->assertSame( '2026-03-01', $notes[0]['date'] );
		$this->assertSame( 'Stage Manager', $notes[0]['author'] );

		$comments = get_comments(
			array(
				'post_id' => $this->track,
				'type'    => Notes::TYPE,
				'status'  => 'approve',
			)
		);
		$this->assertCount( 1, $comments );
		$this->assertSame( 12.0, (float) get_comment_meta( (int) $comments[0]->comment_ID, Notes::META_AT, true ) );
	}

	public function test_comments_win_over_legacy_meta(): void {
		update_post_meta(
			$this->track,
			Notes::META_LEGACY,
			array(
				array(
					't'    => 1.0,
					'text' => 'From meta',
					'date' => '2026-01-01',
				),
			)
		);
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 2.0,
					'text' => 'From comments',
					'date' => '2026-02-02',
				),
			)
		);

		$notes = Notes::get( $this->track );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'From comments', $notes[0]['text'] );
	}

	public function test_rewriting_the_same_line_keeps_the_original_author(): void {
		$director = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Director',
			)
		);
		$md       = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'MD',
			)
		);

		wp_set_current_user( $director );
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 5.0,
					'text' => 'Softer here',
					'date' => '2026-01-02',
				),
			)
		);

		wp_set_current_user( $md );
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 5.0,
					'text' => 'Softer here',
					'date' => '2026-01-02',
				),
				array(
					't'    => 20.0,
					'text' => 'Hold the fermata',
					'date' => '2026-04-01',
				),
			)
		);

		$notes = Notes::get( $this->track );
		$this->assertCount( 2, $notes );
		$this->assertSame( 'Director', $notes[0]['author'] );
		$this->assertSame( 'MD', $notes[1]['author'] );
	}

	public function test_notes_do_not_appear_in_ordinary_comment_queries(): void {
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 1.0,
					'text' => 'Hidden from the list',
					'date' => '2026-01-01',
				),
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID'  => $this->track,
				'comment_content'  => 'A real comment',
				'comment_approved' => 1,
				'comment_type'     => '',
			)
		);

		$ordinary = get_comments(
			array(
				'post_id' => $this->track,
				'status'  => 'approve',
			)
		);
		$this->assertCount( 1, $ordinary );
		$this->assertSame( 'A real comment', $ordinary[0]->comment_content );

		$notes = get_comments(
			array(
				'post_id' => $this->track,
				'type'    => Notes::TYPE,
				'status'  => 'approve',
			)
		);
		$this->assertCount( 1, $notes );
	}

	public function test_notes_do_not_inflate_the_attachment_comment_count(): void {
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 1.0,
					'text' => 'Not a public comment',
					'date' => '2026-01-01',
				),
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID'  => $this->track,
				'comment_content'  => 'Counted',
				'comment_approved' => 1,
				'comment_type'     => '',
			)
		);

		$post = get_post( $this->track );
		$this->assertSame( '1', $post->comment_count );
		$this->assertSame( 1, (int) get_comments_number( $this->track ) );
	}

	public function test_migrate_turns_legacy_meta_into_comments_and_leaves_meta(): void {
		update_post_meta(
			$this->track,
			Notes::META_LEGACY,
			array(
				array(
					't'    => 8.5,
					'text' => 'Breathe',
					'date' => '2025-12-25',
				),
			)
		);

		$dry = Notes::migrate( true );
		$this->assertSame( 1, $dry['tracks'] );
		$this->assertSame( 1, $dry['notes'] );
		$this->assertFalse( Notes::has_comments( $this->track ), 'dry-run writes nothing' );

		$result = Notes::migrate( false );
		$this->assertSame( 1, $result['tracks'] );
		$this->assertSame( 1, $result['notes'] );

		$notes = Notes::get( $this->track );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Breathe', $notes[0]['text'] );
		$this->assertSame( 8.5, $notes[0]['t'] );
		$this->assertSame( '2025-12-25', $notes[0]['date'] );
		$this->assertTrue( Notes::has_comments( $this->track ) );

		$legacy = get_post_meta( $this->track, Notes::META_LEGACY, true );
		$this->assertIsArray( $legacy, 'legacy meta stays so a downgrade survives' );

		$again = Notes::migrate( false );
		$this->assertSame( 0, $again['tracks'] );
		$this->assertSame( 1, $again['skipped'] );
	}

	public function test_set_removes_notes_that_left_the_list(): void {
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 1.0,
					'text' => 'Keep',
					'date' => '2026-01-01',
				),
				array(
					't'    => 2.0,
					'text' => 'Drop',
					'date' => '2026-01-01',
				),
			)
		);
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 1.0,
					'text' => 'Keep',
					'date' => '2026-01-01',
				),
			)
		);

		$notes = Notes::get( $this->track );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Keep', $notes[0]['text'] );
	}

	public function test_notes_come_back_sorted_by_time(): void {
		Notes::set(
			$this->track,
			array(
				array(
					't'    => 40.0,
					'text' => 'Later',
					'date' => '2026-01-01',
				),
				array(
					't'    => 3.0,
					'text' => 'Earlier',
					'date' => '2026-01-01',
				),
			)
		);

		$notes = Notes::get( $this->track );
		$this->assertSame( 'Earlier', $notes[0]['text'] );
		$this->assertSame( 'Later', $notes[1]['text'] );
	}
}
