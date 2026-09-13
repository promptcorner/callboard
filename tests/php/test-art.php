<?php
/**
 * The artwork Callboard draws for a set, and the colour it reads back out of a cover.
 *
 * @package Callboard
 */

use Callboard\Art;

/**
 * @covers \Callboard\Art
 */
class Test_Callboard_Art extends WP_UnitTestCase {

	/**
	 * Where temporary files for these tests go.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Files written by a test, removed after it.
	 *
	 * @var string[]
	 */
	private array $files = array();

	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/callboard-art-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
	}

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( isset( $this->dir ) && file_exists( $this->dir ) ) {
			rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a temp folder this test made.
		}
		parent::tear_down();
	}

	private function require_freetype(): void {
		if ( ! function_exists( 'imagettftext' ) ) {
			$this->markTestSkipped( 'GD without FreeType cannot draw the artwork.' );
		}
	}

	private function temp( string $ext ): string {
		$file          = $this->dir . '/callboard-art-' . wp_generate_password( 8, false ) . '.' . $ext;
		$this->files[] = $file;
		return $file;
	}

	/**
	 * A flat PNG, optionally with a square of another colour in the middle.
	 *
	 * @param int[]      $ground RGB of the background.
	 * @param int[]|null $mark   RGB of the square, if any.
	 */
	private function png( array $ground, ?array $mark = null ): string {
		$im = imagecreatetruecolor( 64, 64 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, ...$ground ) );
		if ( $mark ) {
			imagefilledrectangle( $im, 24, 24, 39, 39, imagecolorallocate( $im, ...$mark ) );
		}
		$file = $this->temp( 'png' );
		imagepng( $im, $file );
		return $file;
	}

	/**
	 * #93. "Hadestown" is one word, and at the size the cover starts from it is wider than the column.
	 * Wrapping happens between words, so a count of lines said it fit and the name ran off the right
	 * edge. Nothing but the background may sit in the margin beside the title.
	 *
	 * @dataProvider cards
	 */
	public function test_a_long_one_word_name_stays_inside_the_artwork( string $kind, array $margin ): void {
		$this->require_freetype();
		$file = $this->temp( 'png' );
		$this->assertTrue( Art::$kind( $this->manifest( 'Hadestown' ), $file ) );

		// The left margin is always bare, so it says what the background is.
		$im    = imagecreatefrompng( $file );
		$bg    = imagecolorat( $im, 4, (int) ( imagesy( $im ) / 2 ) );
		$inked = 0;
		for ( $x = $margin[0]; $x <= $margin[1]; $x++ ) {
			for ( $y = $margin[2]; $y <= $margin[3]; $y++ ) {
				$inked += imagecolorat( $im, $x, $y ) === $bg ? 0 : 1;
			}
		}

		$this->assertSame( 0, $inked, "The name reaches the {$kind}'s right margin." );
	}

	/**
	 * The right margin beside each card's title: clear of the accent dot above and the level bars below.
	 *
	 * @return array<string, array{0: string, 1: array{0: int, 1: int, 2: int, 3: int}}>
	 */
	public function cards(): array {
		return array(
			'the square cover'      => array( 'cover', array( 950, 1023, 200, 880 ) ),
			'the link-preview card' => array( 'share', array( 920, 1199, 150, 490 ) ),
		);
	}

	/**
	 * A manifest with nothing but a name, the way a folder of dropped-in audio produces one.
	 *
	 * @param string $name Set name.
	 * @return array<string, mixed>
	 */
	private function manifest( string $name ): array {
		return array(
			'name'   => $name,
			'slug'   => sanitize_title( $name ),
			'tracks' => array(),
		);
	}

	public function test_a_cover_comes_back_as_a_square_png(): void {
		$this->require_freetype();
		$out      = $this->temp( 'png' );
		$manifest = array(
			'name'   => 'Shakespeare’s Sonnets',
			'slug'   => 'demo-set',
			'tracks' => array(),
		);

		$this->assertTrue( Art::cover( $manifest, $out ) );

		$size = getimagesize( $out );
		$this->assertSame( array( 1024, 1024, IMAGETYPE_PNG ), array( $size[0], $size[1], $size[2] ) );
	}

	/**
	 * Mostly cream paper with one red mark on it reads as the mark.
	 */
	public function test_a_cover_is_tinted_by_the_colour_it_carries_not_the_paper(): void {
		$tint = Art::tint( $this->png( array( 245, 240, 225 ), array( 200, 30, 30 ) ) );

		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', (string) $tint );
		[ $r, $g, $b ] = sscanf( (string) $tint, '#%02x%02x%02x' );
		$this->assertGreaterThan( $g + 40, $r );
		$this->assertGreaterThan( $b + 40, $r );
	}

	public function test_a_grey_cover_is_its_own_grey(): void {
		$this->assertSame( '#808080', Art::tint( $this->png( array( 128, 128, 128 ) ) ) );
	}

	public function test_a_file_that_is_not_an_image_has_no_tint(): void {
		$file = $this->temp( 'png' );
		file_put_contents( $file, 'not a picture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file for the test.

		$this->assertNull( Art::tint( $file ) );
	}

	/**
	 * A launcher may crop a maskable icon to any shape that holds the middle circle, 80% of the width.
	 * A red band running edge to edge across the source must land inside that circle.
	 */
	public function test_a_maskable_icon_keeps_the_whole_image_inside_the_safe_circle(): void {
		$src = imagecreatetruecolor( 64, 64 );
		imagefill( $src, 0, 0, imagecolorallocate( $src, 30, 60, 200 ) );
		imagefilledrectangle( $src, 0, 24, 63, 39, imagecolorallocate( $src, 220, 20, 20 ) );
		$in = $this->temp( 'png' );
		imagepng( $src, $in );
		$out = $this->temp( 'png' );

		$this->assertTrue( Art::maskable_icon( $in, $out ) );

		$im = imagecreatefrompng( $out );
		$this->assertSame( array( 512, 512 ), array( imagesx( $im ), imagesy( $im ) ) );
		$this->assertSame( array( 30, 60, 200 ), array_values( array_slice( imagecolorsforindex( $im, imagecolorat( $im, 0, 0 ) ), 0, 3 ) ), 'the padding is the icon’s own background' );
		$red     = 0;
		$outside = 0;
		for ( $x = 0; $x < 512; $x += 2 ) {
			for ( $y = 0; $y < 512; $y += 2 ) {
				$c = imagecolorsforindex( $im, imagecolorat( $im, $x, $y ) );
				if ( $c['red'] > 150 && $c['blue'] < 100 ) {
					++$red;
					$outside += hypot( $x - 256, $y - 256 ) > 512 * 0.4 ? 1 : 0;
				}
			}
		}
		$this->assertGreaterThan( 0, $red, 'the image is drawn' );
		$this->assertSame( 0, $outside, 'no part of it is outside the safe circle' );
	}
}
