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
