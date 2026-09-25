<?php
/**
 * The .callboard file, and what it refuses to unpack.
 *
 * A set file is something a stage manager is expected to AirDrop around, which means Callboard
 * will be handed archives it did not write. These are the tests for that boundary.
 *
 * @package Callboard
 */

use Callboard\Exporter;
use Callboard\Importer;

/**
 * @covers \Callboard\Exporter
 */
class Test_Callboard_Exporter extends WP_UnitTestCase {

	/**
	 * Absolute paths written during a test, cleaned up afterwards.
	 *
	 * @var string[]
	 */
	private array $trash = array();

	public function tear_down(): void {
		foreach ( $this->trash as $path ) {
			if ( is_dir( $path ) ) {
				array_map( 'unlink', glob( $path . '/*' ) ?: array() );
				rmdir( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->trash = array();
		parent::tear_down();
	}

	/**
	 * Build a .callboard file with the given entries. Entry names are written verbatim, which is
	 * the point: a hostile archive is one whose names were never sanitized.
	 *
	 * @param array<string, string> $entries Entry name => contents.
	 */
	private function zip( array $entries ): string {
		$path = get_temp_dir() . 'callboard-test-' . wp_generate_password( 8, false ) . '.callboard';
		$zip  = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE ), 'could not create the test archive' );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		$this->trash[] = $path;
		return $path;
	}

	private function manifest( array $extra = array() ): string {
		return (string) wp_json_encode(
			array_merge(
				array(
					'version' => Exporter::VERSION,
					'name'    => 'Unpacked Set',
					'slug'    => 'unpacked-set',
					'tracks'  => array(),
				),
				$extra
			)
		);
	}

	public function test_a_track_key_prefers_the_video_id_it_came_from(): void {
		$track = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		update_post_meta( $track, '_callboard_video_id', 'dQw4w9WgXcQ' );

		$this->assertSame( 'dQw4w9WgXcQ', Exporter::track_key( $track, '/anywhere/01 Whatever.mp3' ) );
	}

	public function test_a_track_with_no_video_id_gets_a_stable_key_from_its_file_name(): void {
		$track = self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		$first  = Exporter::track_key( $track, '/one/place/01 Sonnet.mp3' );
		$second = Exporter::track_key( $track, '/somewhere/else/01 Sonnet.mp3' );

		$this->assertSame( $first, $second, 'the key must not depend on where the file happens to live' );
		$this->assertMatchesRegularExpression( '/^cb-[0-9a-f]{12}$/', $first );
		$this->assertNotSame( $first, Exporter::track_key( $track, '/one/place/02 Sonnet.mp3' ) );
	}

	/**
	 * The zip-slip case: an entry whose name climbs out of the folder must not be written, and
	 * must not stop the rest of the archive from unpacking.
	 *
	 * @dataProvider hostile_names
	 */
	public function test_unpack_refuses_an_entry_that_leaves_the_set_folder( string $name ): void {
		if ( ! Exporter::available() ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip = $this->zip(
			array(
				'manifest.json' => $this->manifest(),
				$name           => '<?php echo "pwned";',
				'01 Real.mp3'   => 'ID3 not really',
			)
		);

		$dir = Exporter::unpack( $zip );
		$this->assertNotWPError( $dir );
		$this->trash[] = $dir;

		$this->assertFileExists( $dir . '/01 Real.mp3', 'a legitimate entry beside a hostile one still unpacks' );
		$this->assertFileDoesNotExist( $dir . '/' . basename( $name ) );

		// Nothing may appear anywhere above the set folder either.
		$escaped = realpath( dirname( $dir ) ) . '/' . basename( $name );
		$this->assertFileDoesNotExist( $escaped );
		$this->assertFileDoesNotExist( dirname( $dir, 2 ) . '/' . basename( $name ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function hostile_names(): array {
		return array(
			'parent traversal'      => array( '../evil.php' ),
			'deep traversal'        => array( '../../../evil.php' ),
			'absolute path'         => array( '/etc/evil.php' ),
			'windows separator'     => array( '..\\evil.php' ),
			'nested directory'      => array( 'sub/evil.php' ),
			'traversal in the tail' => array( 'audio/../../evil.php' ),
		);
	}

	public function test_unpack_refuses_a_file_type_a_set_folder_never_contains(): void {
		if ( ! Exporter::available() ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip = $this->zip(
			array(
				'manifest.json' => $this->manifest(),
				'shell.php'     => '<?php echo "pwned";',
				'notes.txt'     => 'not a sidecar',
				'.htaccess'     => 'nope',
				'01 Real.mp3'   => 'ID3 not really',
				'levels.json'   => '{}',
				'lyrics.json'   => '{}',
				'notes.json'    => '{}',
				'cover.png'     => 'PNG',
			)
		);

		$dir = Exporter::unpack( $zip );
		$this->assertNotWPError( $dir );
		$this->trash[] = $dir;

		$this->assertFileDoesNotExist( $dir . '/shell.php' );
		$this->assertFileDoesNotExist( $dir . '/notes.txt' );
		$this->assertFileDoesNotExist( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/01 Real.mp3' );
		$this->assertFileExists( $dir . '/levels.json', 'a JSON sidecar is part of a set' );
		$this->assertFileDoesNotExist( $dir . '/lyrics.json', 'lyrics were removed in 3.0.0; an older file keeps them, and they are dropped' );
		$this->assertFileDoesNotExist( $dir . '/notes.json', 'notes were removed in 3.0.0; an older file keeps them, and they are dropped' );
		$this->assertFileExists( $dir . '/cover.png' );
	}

	public function test_unpack_rejects_a_file_from_a_newer_format(): void {
		if ( ! Exporter::available() ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip = $this->zip( array( 'manifest.json' => $this->manifest( array( 'version' => Exporter::VERSION + 1 ) ) ) );

		$error = Exporter::unpack( $zip );
		$this->assertWPError( $error );
		$this->assertSame( 'callboard_new_format', $error->get_error_code() );
	}

	public function test_unpack_rejects_something_that_is_not_a_set(): void {
		if ( ! Exporter::available() ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$error = Exporter::unpack( $this->zip( array( 'readme.txt' => 'just a zip' ) ) );
		$this->assertWPError( $error );
		$this->assertSame( 'callboard_no_manifest', $error->get_error_code() );
	}

	public function test_unpack_writes_into_the_import_folder_and_nowhere_else(): void {
		if ( ! Exporter::available() ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip = $this->zip( array( 'manifest.json' => $this->manifest( array( 'slug' => 'Some Set/../../Escape' ) ) ) );
		$dir = Exporter::unpack( $zip );
		$this->assertNotWPError( $dir );
		$this->trash[] = $dir;

		$this->assertStringStartsWith( Importer::source_dir() . '/', $dir );
		$this->assertSame( Importer::source_dir(), dirname( $dir ), 'a slug may never add a path segment' );
	}
}
