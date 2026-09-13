<?php
/**
 * Cover and share-card artwork, drawn with GD. Quiet: warm ground, the set name, a label, one accent.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Artwork generator.
 */
final class Art {

	private const BG     = array( 241, 238, 231 );
	private const INK    = array( 30, 28, 26 );
	private const MUTED  = array( 120, 116, 108 );
	private const ACCENT = array( 232, 84, 30 );

	/**
	 * The palettes a cover can be drawn in, borrowed from the theatre rather than invented.
	 *
	 * Playbill's masthead yellow, the deep red of a house curtain with its gold, the bone white of a
	 * ghost light, and two lighting gels — Congo blue and bastard amber — that anybody who has stood
	 * in a wing has seen. Each is a ground, an ink dark enough to read on it, a muted ink for the
	 * label, and one accent for the dot and the level bars.
	 *
	 * The set picks its own unless somebody chooses for it, so a board of six sets is six colours
	 * instead of six identical cream squares.
	 *
	 * @var array<string, array<string, array<int, int>>>
	 */
	private const PALETTES = array(
		'ghost'    => array(
			'bg'     => array( 241, 238, 231 ),
			'ink'    => array( 30, 28, 26 ),
			'muted'  => array( 120, 116, 108 ),
			'accent' => array( 232, 84, 30 ),
		),
		'playbill' => array(
			'bg'     => array( 249, 200, 44 ),
			'ink'    => array( 22, 20, 18 ),
			'muted'  => array( 104, 86, 30 ),
			'accent' => array( 22, 20, 18 ),
		),
		'velvet'   => array(
			'bg'     => array( 108, 26, 30 ),
			'ink'    => array( 244, 236, 224 ),
			'muted'  => array( 198, 160, 138 ),
			'accent' => array( 201, 162, 39 ),
		),
		'congo'    => array(
			'bg'     => array( 22, 32, 84 ),
			'ink'    => array( 236, 238, 246 ),
			'muted'  => array( 150, 160, 196 ),
			'accent' => array( 245, 200, 154 ),
		),
		'amber'    => array(
			'bg'     => array( 245, 200, 154 ),
			'ink'    => array( 46, 30, 20 ),
			'muted'  => array( 132, 98, 68 ),
			'accent' => array( 150, 32, 28 ),
		),
		'blackout' => array(
			'bg'     => array( 24, 23, 22 ),
			'ink'    => array( 238, 234, 226 ),
			'muted'  => array( 138, 132, 124 ),
			'accent' => array( 232, 84, 30 ),
		),
	);

	/**
	 * The palette in force while a cover is being drawn.
	 *
	 * @var array<string, array<int, int>>|null
	 */
	private static ?array $palette = null;

	/**
	 * Every palette's key, for a settings field.
	 *
	 * @return array<int, string>
	 */
	public static function palettes(): array {
		return array_keys( self::PALETTES );
	}

	/**
	 * A palette by name, or one chosen from a string so a set keeps the same colours forever.
	 *
	 * @param string $name Palette key, '' to choose from $seed.
	 * @param string $seed Anything stable about the set; its slug does nicely.
	 * @return array<string, array<int, int>>
	 */
	public static function palette( string $name = '', string $seed = '' ): array {
		if ( isset( self::PALETTES[ $name ] ) ) {
			return self::PALETTES[ $name ];
		}
		$keys = array_keys( self::PALETTES );
		return self::PALETTES[ $keys[ hexdec( substr( md5( $seed ), 0, 8 ) ) % count( $keys ) ] ];
	}

	/**
	 * One colour of the palette in force, falling back to the constants this class started with.
	 *
	 * @param string          $role     bg, ink, muted or accent.
	 * @param array<int, int> $fallback Colour to use when nothing is set.
	 * @return array<int, int>
	 */
	private static function ink( string $role, array $fallback ): array {
		return self::$palette[ $role ] ?? $fallback;
	}

	/**
	 * 1024×1024 Now Playing cover.
	 *
	 * @param array<string, mixed> $manifest Set manifest.
	 * @param string               $out      Output path.
	 */
	public static function cover( array $manifest, string $out ): bool {
		if ( ! self::ready() ) {
			return false;
		}
		self::$palette = self::palette( (string) ( $manifest['palette'] ?? '' ), (string) ( $manifest['slug'] ?? $manifest['name'] ?? '' ) );
		$s             = 1024;
		$pad           = 90;
		$im            = self::canvas( $s, $s );
		self::dot( $im, $s - $pad - 22, $pad + 22, 22 );
		self::rule( $im, $pad, $pad + 20, 160 );
		$y = self::title( $im, (string) $manifest['name'], $pad - 4, $s - $pad - 66, $s - 2 * $pad, 140, 3 );
		self::text( $im, 32, $pad, $s - $pad - 8, self::ink( 'muted', self::MUTED ), self::label( $manifest ), 'SemiBold' );
		return imagepng( $im, $out, 6 );
	}

	/**
	 * 1200×630 link-preview card.
	 *
	 * @param array<string, mixed> $manifest Set manifest.
	 * @param string               $out      Output path.
	 */
	public static function share( array $manifest, string $out ): bool {
		if ( ! self::ready() ) {
			return false;
		}
		self::$palette = self::palette( (string) ( $manifest['palette'] ?? '' ), (string) ( $manifest['slug'] ?? $manifest['name'] ?? '' ) );
		$w             = 1200;
		$h             = 630;
		$pad           = 80;
		$im            = self::canvas( $w, $h );
		self::dot( $im, $w - $pad - 20, $pad + 20, 20 );
		self::rule( $im, $pad, $pad + 16, 140 );
		self::title( $im, (string) $manifest['name'], $pad - 4, $h - $pad - 70, $w - 2 * $pad - 220, 124, 2 );
		self::text( $im, 28, $pad, $h - $pad - 6, self::ink( 'muted', self::MUTED ), self::label( $manifest ), 'SemiBold' );
		// A quiet row of level bars, bottom right.
		$bx = $w - $pad - 4 * 18;
		foreach ( array( 18, 34, 26, 42 ) as $k => $bar ) {
			$c = imagecolorallocate( $im, ...( 1 === $k ? self::ink( 'accent', self::ACCENT ) : self::ink( 'ink', self::INK ) ) );
			imagefilledrectangle( $im, $bx + $k * 18, $h - $pad - $bar, $bx + $k * 18 + 8, $h - $pad, $c );
		}
		return imagepng( $im, $out, 6 );
	}

	/**
	 * "Rehearsal tracks · 20 tracks · 42 min".
	 *
	 * @param array<string, mixed> $manifest Set manifest.
	 */
	private static function label( array $manifest ): string {
		return Settings::get( 'tagline' ) . ' · ' . callboard_meta( (array) ( $manifest['tracks'] ?? array() ) );
	}

	/**
	 * One colour that stands for a cover, as "#rrggbb", or null when GD cannot read the file.
	 *
	 * Tidal and Spotify wash the now-playing screen with a colour taken from the artwork. This does
	 * the same thing with a hex value rather than a blurred copy of the image: it costs one meta
	 * field instead of a second download, it survives offline because a colour is not a request, and
	 * it composes with the accent setting rather than fighting it.
	 *
	 * The picking is deliberately blunt. The image is scaled to 32x32 — GD does the averaging, and
	 * the result is the same regardless of the original's size — then every pixel is weighted by how
	 * much colour it actually carries, so a cover that is mostly cream paper still reports the one
	 * saturated mark on it rather than the paper. Grey covers fall through to the mid grey they are.
	 *
	 * @param string $file Absolute path to an image.
	 */
	public static function tint( string $file ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! is_readable( $file ) ) {
			return null;
		}

		$src = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that is not an image is a null, not a warning.
		if ( ! $src ) {
			return null;
		}

		$im = match ( $src[2] ) {
			IMAGETYPE_PNG  => @imagecreatefrompng( $file ),  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			IMAGETYPE_JPEG => @imagecreatefromjpeg( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			IMAGETYPE_WEBP => @imagecreatefromwebp( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
			default        => false,
		};
		if ( ! $im ) {
			return null;
		}

		$small = imagescale( $im, 32, 32 );
		if ( ! $small ) {
			return null;
		}

		$r_sum  = 0.0;
		$g_sum  = 0.0;
		$b_sum  = 0.0;
		$weight = 0.0;
		for ( $y = 0; $y < 32; $y++ ) {
			for ( $x = 0; $x < 32; $x++ ) {
				$rgb = imagecolorat( $small, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;
				$max = max( $r, $g, $b );
				$min = min( $r, $g, $b );
				// Chroma, plus a floor so a wholly grey cover still averages to its own grey rather
				// than dividing by zero and reporting black.
				$w       = ( $max - $min ) + 1;
				$r_sum  += $r * $w;
				$g_sum  += $g * $w;
				$b_sum  += $b * $w;
				$weight += $w;
			}
		}
		if ( $weight <= 0 ) {
			return null;
		}

		return sprintf(
			'#%02x%02x%02x',
			(int) round( $r_sum / $weight ),
			(int) round( $g_sum / $weight ),
			(int) round( $b_sum / $weight )
		);
	}

	/**
	 * GD with FreeType and our font present?
	 */
	private static function ready(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && file_exists( self::font( 'Bold' ) );
	}

	/**
	 * Font path.
	 *
	 * @param string $weight 'Bold' or 'SemiBold'.
	 */
	private static function font( string $weight ): string {
		return CALLBOARD_DIR . 'assets/fonts/Poppins-' . $weight . '.ttf';
	}

	/**
	 * Blank canvas in the ground color.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return \GdImage
	 */
	private static function canvas( int $w, int $h ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, ...self::ink( 'bg', self::BG ) ) );
		return $im;
	}

	/**
	 * Accent dot.
	 *
	 * @param \GdImage $im Image.
	 * @param int      $cx Center x.
	 * @param int      $cy Center y.
	 * @param int      $r  Radius.
	 */
	private static function dot( $im, int $cx, int $cy, int $r ): void {
		imagefilledellipse( $im, $cx, $cy, $r * 2, $r * 2, imagecolorallocate( $im, ...self::ink( 'accent', self::ACCENT ) ) );
	}

	/**
	 * Short hairline.
	 *
	 * @param \GdImage $im Image.
	 * @param int      $x  Left.
	 * @param int      $y  Top.
	 * @param int      $w  Width.
	 */
	private static function rule( $im, int $x, int $y, int $w ): void {
		imagefilledrectangle( $im, $x, $y, $x + $w, $y + 4, imagecolorallocate( $im, ...self::ink( 'ink', self::INK ) ) );
	}

	/**
	 * Draw one line of text at a baseline.
	 *
	 * @param \GdImage        $im     Image.
	 * @param int             $size   Point size.
	 * @param int             $x      Left.
	 * @param int             $y      Baseline.
	 * @param array<int, int> $rgb    Color.
	 * @param string          $text   Text.
	 * @param string          $weight Font weight.
	 */
	private static function text( $im, int $size, int $x, int $y, array $rgb, string $text, string $weight = 'Bold' ): void {
		imagettftext( $im, $size, 0, $x, $y, imagecolorallocate( $im, ...$rgb ), self::font( $weight ), $text );
	}

	/**
	 * Wrapped title, bottom-anchored: largest size that fits within $max_lines lines.
	 *
	 * @param \GdImage $im        Image.
	 * @param string   $title     Text.
	 * @param int      $x         Left.
	 * @param int      $bottom    Baseline of the last line.
	 * @param int      $max_w     Available width.
	 * @param int      $start     Starting point size.
	 * @param int      $max_lines Line cap.
	 * @return int Baseline of the first line.
	 */
	private static function title( $im, string $title, int $x, int $bottom, int $max_w, int $start, int $max_lines ): int {
		$size = $start;
		do {
			$lines = self::wrap( $title, $size, $max_w );
			// Line count is not enough: wrap() breaks between words, so a single word longer than the
			// column comes back as one over-wide line and would run off the edge. Shrink until every
			// line fits, not just until there are few enough of them.
			$fits = count( $lines ) <= $max_lines;
			foreach ( $lines as $line ) {
				$box = imagettfbbox( $size, 0, self::font( 'Bold' ), $line );
				if ( $box && ( $box[2] - $box[0] ) > $max_w ) {
					$fits = false;
					break;
				}
			}
			if ( $fits ) {
				break;
			}
			$size -= 8;
		} while ( $size > 40 );
		$lh = (int) round( $size * 1.35 );
		$y  = $bottom - $lh * ( count( $lines ) - 1 );
		foreach ( $lines as $line ) {
			self::text( $im, $size, $x, $y, self::ink( 'ink', self::INK ), $line );
			$y += $lh;
		}
		return $bottom - $lh * ( count( $lines ) - 1 );
	}

	/**
	 * Greedy word wrap using measured widths.
	 *
	 * @param string $text  Text.
	 * @param int    $size  Point size.
	 * @param int    $max_w Max width in pixels.
	 * @return string[]
	 */
	private static function wrap( string $text, int $size, int $max_w ): array {
		$lines = array();
		$cur   = '';
		$words = preg_split( '/\s+/', trim( $text ) );
		foreach ( is_array( $words ) ? $words : array() as $word ) {
			$try = trim( $cur . ' ' . $word );
			$box = imagettfbbox( $size, 0, self::font( 'Bold' ), $try );
			if ( $box && ( $box[2] - $box[0] ) <= $max_w ) {
				$cur = $try;
			} else {
				if ( '' !== $cur ) {
					$lines[] = $cur;
				}
				$cur = $word;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines;
	}
}
