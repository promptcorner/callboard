<?php
/**
 * What the importer does to the data a folder hands it.
 *
 * Everything here runs against titles and sidecars written by yt-dlp, by a phone, or by hand, so
 * the interesting cases are the malformed ones.
 *
 * @package Callboard
 */

use Callboard\Art;
use Callboard\Importer;
use Callboard\Sets;

/**
 * @covers \Callboard\Importer
 */
class Test_Callboard_Importer extends WP_UnitTestCase {

	/**
	 * @dataProvider raw_titles
	 */
	public function test_a_track_title_loses_the_things_a_download_added( string $raw, string $expected ): void {
		$this->assertSame( $expected, Importer::clean_title( $raw ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function raw_titles(): array {
		return array(
			'a leading track number'   => array( '3. Be Our Guest', 'Be Our Guest' ),
			'numbered with a paren'    => array( '12) Finale', 'Finale' ),
			'numbered with a dash'     => array( '07 - Spooky', 'Spooky' ),
			'a trailing parenthetical' => array( 'Black Velvet (Official Audio)', 'Black Velvet' ),
			'a trailing bracket'       => array( 'Season of the Witch [HD]', 'Season of the Witch' ),
			'both at once'             => array( '5. I Put a Spell on You (Official Audio)', 'I Put a Spell on You' ),
			'collapsing whitespace'    => array( "  Sonnet   18\t ", 'Sonnet 18' ),
			'nothing to do'            => array( 'House of the Rising Sun', 'House of the Rising Sun' ),
		);
	}

	/**
	 * A parenthetical in the middle is part of the name; only a trailing one is packaging.
	 */
	public function test_a_title_keeps_a_parenthetical_it_needs(): void {
		$this->assertSame( 'Sit Down (You’re Rocking the Boat) reprise', Importer::clean_title( 'Sit Down (You’re Rocking the Boat) reprise' ) );
	}

	/**
	 * @dataProvider tempos
	 */
	public function test_a_tempo_outside_what_a_body_can_count_is_no_tempo( int $bpm, int $expected ): void {
		$this->assertSame( $expected, Importer::clamp_bpm( $bpm ) );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public function tempos(): array {
		return array(
			'a ballad'          => array( 60, 60 ),
			'the low edge'      => array( 30, 30 ),
			'the high edge'     => array( 300, 300 ),
			'below the edge'    => array( 29, 0 ),
			'above the edge'    => array( 301, 0 ),
			'a detection error' => array( 0, 0 ),
			'nonsense'          => array( -120, 0 ),
		);
	}

	public function test_notes_come_back_in_the_order_they_happen(): void {
		$notes = Importer::sanitize_notes(
			array(
				array(
					't'    => 42.0,
					'text' => 'Watch the cut-off',
					'date' => '2026-01-02',
				),
				array(
					't'    => 3.4,
					'text' => 'Softer here',
					'date' => '2026-01-02',
				),
			)
		);

		$this->assertCount( 2, $notes );
		$this->assertSame( 3.4, $notes[0]['t'] );
		$this->assertSame( 42.0, $notes[1]['t'] );
	}

	public function test_a_note_with_no_text_is_not_a_note(): void {
		$notes = Importer::sanitize_notes(
			array(
				array(
					't'    => 1.0,
					'text' => '   ',
				),
				array( 't' => 2.0 ),
				'not even an array',
				array(
					't'    => 3.0,
					'text' => 'Real',
				),
			)
		);

		$this->assertCount( 1, $notes );
		$this->assertSame( 'Real', $notes[0]['text'] );
	}

	public function test_a_note_cannot_be_stamped_with_something_that_is_not_a_date(): void {
		$notes = Importer::sanitize_notes(
			array(
				array(
					't'    => 1.0,
					'text' => 'Softer',
					'date' => 'yesterday',
				),
			)
		);

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $notes[0]['date'] );
	}

	public function test_a_note_cannot_sit_before_the_start_of_the_track(): void {
		$notes = Importer::sanitize_notes(
			array(
				array(
					't'    => -30.0,
					'text' => 'Softer',
				),
			)
		);

		$this->assertSame( 0.0, $notes[0]['t'] );
	}

	public function test_a_note_carries_no_markup_into_the_page(): void {
		$notes = Importer::sanitize_notes(
			array(
				array(
					't'    => 1.0,
					'text' => 'Softer <script>alert(1)</script> here',
				),
			)
		);

		$this->assertStringNotContainsString( '<script>', $notes[0]['text'] );
	}

	public function test_importing_a_manifest_keeps_its_valid_art_palette(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/callboard/demo-set';

		$this->assertSame( 'Compositions: 10 new tracks.', Importer::import_folder( $fixture ) );

		$set = Sets::post_by_slug( 'demo-set' );
		$this->assertInstanceOf( WP_Post::class, $set );
		$this->assertSame( 'congo', get_post_meta( $set->ID, '_callboard_palette', true ) );
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', (string) Art::tint( (string) get_attached_file( get_post_thumbnail_id( $set ) ) ) );
	}

	/**
	 * #65. The cover's attachment points at the file in the set folder itself. Replacing that file and
	 * importing again deleted the old attachment, and deleting an attachment deletes its file, which was
	 * by then the new cover. The set came back with no artwork at all.
	 */
	public function test_importing_a_replaced_cover_keeps_the_new_one(): void {
		$dir = wp_upload_dir()['basedir'] . '/callboard-reimport-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/manifest.json', wp_json_encode( array( 'name' => 'Reimported' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$fixtures = dirname( __DIR__ ) . '/fixtures/callboard';
		copy( $fixtures . '/demo-set/cover.png', $dir . '/cover.png' );

		Importer::import_folder( $dir );
		$set = Sets::post_by_slug( sanitize_title( basename( $dir ) ) );
		$this->assertNotEmpty( get_post_thumbnail_id( $set ), 'The first import attaches the cover.' );

		// A new cover under the same name, a minute newer.
		copy( $fixtures . '/empty-set/cover.png', $dir . '/cover.png' );
		touch( $dir . '/cover.png', time() + 60 );
		clearstatcache();
		Importer::import_folder( $dir );

		$this->assertFileExists( $dir . '/cover.png', 'Importing again deleted the new cover.' );
		$this->assertSame( md5_file( $fixtures . '/empty-set/cover.png' ), md5_file( $dir . '/cover.png' ) );
		$this->assertFileExists( (string) get_attached_file( get_post_thumbnail_id( $set ) ) );
	}
}
