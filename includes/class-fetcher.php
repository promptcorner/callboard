<?php
/**
 * Builds a set folder from a YouTube URL with yt-dlp (and ffmpeg when present).
 * Runs wherever the binaries exist: a laptop, wp-env, a VPS. Managed hosts use the folder importer instead.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps yt-dlp to produce manifest.json, audio files, levels.json, cover.png and share.png.
 *
 * The rule for the audio itself: the truest copy available, not the most processed one.
 * YouTube already serves lossy Opus or AAC, so re-encoding either of them again can only lose
 * fidelity, and a re-encoder set to "best quality" will often produce a *bigger* file than the
 * lossy source it came from — a bitrate that looks better while sounding worse. The native
 * stream is kept whenever it is already something every browser can play (m4a/AAC); ffmpeg is
 * only asked to convert when there is no such stream to keep.
 */
final class Fetcher {

	/**
	 * Absolute paths of the tools we can use, or null.
	 *
	 * @return array{ytdlp: ?string, ffmpeg: ?string}
	 */
	public static function tools(): array {
		return array(
			'ytdlp'  => self::which( (string) apply_filters( 'callboard_ytdlp_path', 'yt-dlp' ) ),
			'ffmpeg' => self::which( (string) apply_filters( 'callboard_ffmpeg_path', 'ffmpeg' ) ),
		);
	}

	/**
	 * Whether this environment can fetch at all.
	 */
	public static function available(): bool {
		return function_exists( 'proc_open' ) && null !== self::tools()['ytdlp'];
	}

	/**
	 * Format selector for yt-dlp's `-f`: an already-AAC track first — by codec, not container,
	 * since a container extension is not proof of what is inside it — so the common case
	 * downloads exactly what YouTube serves with nothing to re-encode. `bestaudio` is yt-dlp's own
	 * shorthand for bestaudio; this is the same selector yt-dlp ships as its built-in `-t aac`
	 * alias, not a homemade one.
	 */
	private const AAC_FIRST = 'bestaudio[acodec^=aac]/bestaudio[acodec^=mp4a.40.]/bestaudio/best';

	/**
	 * Fetch a playlist, video, or search into <import dir>/<slug>/ and return the manifest.
	 *
	 * A search (yt-dlp's own `ytsearch:` / `ytsearchN:` syntax, e.g. `ytsearch1:queen bohemian
	 * rhapsody`) resolves to several candidates for what is really one desired track; only the
	 * best of them is kept. See best_of_search() for how "best" is decided.
	 *
	 * @param string   $url      YouTube URL, playlist URL, or `ytsearch:` query.
	 * @param string   $slug     Folder / set slug.
	 * @param string   $name     Display name for the set.
	 * @param callable $progress Receives a line of progress text.
	 * @return array<string, mixed>|WP_Error Manifest.
	 */
	public static function fetch( string $url, string $slug, string $name, callable $progress ) {
		$tools = self::tools();
		if ( ! function_exists( 'proc_open' ) || ! $tools['ytdlp'] ) {
			return new WP_Error( 'callboard_no_ytdlp', __( 'yt-dlp is not available here.', 'callboard' ) );
		}
		$dir = Importer::source_dir() . '/' . sanitize_title( $slug );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'callboard_mkdir', __( 'Could not create the set folder.', 'callboard' ) );
		}

		$progress( __( 'Reading the playlist…', 'callboard' ) );
		$info = self::run( array( $tools['ytdlp'], '--flat-playlist', '-J', $url ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$source = json_decode( $info, true );
		if ( ! is_array( $source ) ) {
			return new WP_Error( 'callboard_bad_json', __( 'yt-dlp returned something unexpected.', 'callboard' ) );
		}
		$entries = $source['entries'] ?? array( $source );

		// A real playlist's entries are separate tracks and all of them are wanted. A search's
		// entries are rival copies of the one track that was actually asked for; keep only the
		// best. `youtube:search` is yt-dlp's own extractor name for this, not a guess.
		if ( 'youtube:search' === strtolower( (string) ( $source['extractor'] ?? '' ) ) ) {
			$picked  = self::best_of_search( $entries );
			$entries = array( $picked );
			$url     = (string) ( $picked['url'] ?? ( 'https://www.youtube.com/watch?v=' . ( $picked['id'] ?? '' ) ) );
			// A search's own webpage_url is the literal query string, not a link to anything real.
			$source['webpage_url']  = $url;
			$source['uploader']     = (string) ( $picked['channel'] ?? $picked['uploader'] ?? '' );
			$source['uploader_url'] = (string) ( $picked['channel_url'] ?? $picked['uploader_url'] ?? '' );
		}

		$progress( sprintf( /* translators: %d: number of videos. */ __( 'Downloading audio for %d videos…', 'callboard' ), count( $entries ) ) );
		$cmd = array( $tools['ytdlp'], '-x', '--no-playlist-reverse', '--download-archive', $dir . '/.archive', '-f', self::AAC_FIRST, '-o', $dir . '/%(playlist_index|1)02d - %(title)s [%(id)s].%(ext)s' );
		if ( $tools['ffmpeg'] ) {
			// Only reached when the source truly has no AAC stream to keep. m4a/AAC is preferred
			// even here since it is the format the app already plays everywhere, including
			// Safari, where a bare .webm/Opus file is not safe. Re-encoding is already a lossy
			// pass over a lossy source, so the target bitrate is fixed rather than left at
			// ffmpeg's "quality 0" — for its native aac encoder that setting is poorly bounded
			// and can quietly triple the size for no audible gain, the same mistake this class
			// used to make encoding to mp3. mp3 itself is the last resort of all, for the rare
			// ffmpeg build with no working aac encoder.
			$audio_format = self::has_aac_encoder( $tools['ffmpeg'] ) ? 'm4a' : 'mp3';
			array_push( $cmd, '--audio-format', $audio_format, '--audio-quality', '160K', '--ffmpeg-location', dirname( $tools['ffmpeg'] ) );
		}
		$cmd[]  = $url;
		$stderr = '';
		$out    = self::run( $cmd, $progress, $stderr );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$progress( __( 'Writing the manifest…', 'callboard' ) );
		$overrides = file_exists( $dir . '/titles.json' ) ? (array) json_decode( (string) file_get_contents( $dir . '/titles.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$files     = array_map( static fn( string $f ) => $dir . '/' . $f, array_filter( (array) scandir( $dir ), static fn( $f ) => is_string( $f ) && preg_match( '/\.(mp3|m4a|opus|webm|ogg)$/i', $f ) ) ); // no GLOB_BRACE: not on musl.
		$tracks    = array();
		foreach ( array_values( $entries ) as $i => $e ) {
			$vid  = (string) ( $e['id'] ?? '' );
			$file = null;
			foreach ( is_array( $files ) ? $files : array() as $f ) {
				if ( str_contains( basename( $f ), '[' . $vid . ']' ) ) {
					$file = $f;
					break;
				}
			}
			$tracks[] = array(
				'index'        => $i + 1,
				'id'           => $vid,
				'title'        => (string) ( $overrides[ $vid ] ?? $e['title'] ?? $vid ),
				'file'         => $file ? basename( $file ) : null,
				'duration'     => $file ? self::duration( $file, $tools['ffmpeg'] ) : ( isset( $e['duration'] ) ? (float) $e['duration'] : null ),
				'url'          => (string) ( $e['url'] ?? 'https://www.youtube.com/watch?v=' . $vid ),
				'uploader'     => (string) ( $e['uploader'] ?? $e['channel'] ?? '' ),
				'uploader_url' => (string) ( $e['uploader_url'] ?? $e['channel_url'] ?? '' ),
				// What was actually obtained, so the app can one day say "this is a re-encode".
				// codec is read from the file itself, not assumed from its extension.
				'codec'        => $file ? self::codec( $file, $tools['ffmpeg'] ) : null,
				'reencoded'    => $file ? self::was_reencoded( $stderr, $vid ) : null,
			);
		}
		$manifest = array(
			'version'      => Exporter::VERSION,
			'generator'    => 'callboard/' . CALLBOARD_VERSION,
			'name'         => $name,
			'slug'         => sanitize_title( $slug ),
			'order'        => 0,
			'playlist_url' => (string) ( $source['webpage_url'] ?? $url ),
			'curator'      => (string) ( $source['uploader'] ?? $source['channel'] ?? '' ),
			'curator_url'  => (string) ( $source['uploader_url'] ?? $source['channel_url'] ?? '' ),
			'tracks'       => $tracks,
		);
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$levels = self::levels_for_dir( $dir, $tools['ffmpeg'], $progress );
		if ( $levels ) {
			file_put_contents( $dir . '/levels.json', json_encode( $levels ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain digits, no WP needed.
		}

		$progress( __( 'Drawing the cover and share card…', 'callboard' ) );
		Art::cover( $manifest, $dir . '/cover.png' );
		Art::share( $manifest, $dir . '/share.png' );

		return $manifest;
	}

	/**
	 * Duration in seconds via ffprobe when available, else from the audio's metadata reader.
	 *
	 * @param string      $file   Audio file.
	 * @param string|null $ffmpeg ffmpeg path (ffprobe sits beside it).
	 */
	private static function duration( string $file, ?string $ffmpeg ): ?float {
		$ffprobe = $ffmpeg ? dirname( $ffmpeg ) . '/ffprobe' : self::which( 'ffprobe' );
		if ( $ffprobe && is_executable( $ffprobe ) ) {
			$out = self::run( array( $ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file ) );
			if ( ! is_wp_error( $out ) && is_numeric( trim( $out ) ) ) {
				return round( (float) trim( $out ), 1 );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$meta = wp_read_audio_metadata( $file );
		return isset( $meta['length'] ) ? (float) $meta['length'] : null;
	}

	/**
	 * The codec actually inside a fetched file, e.g. "aac", "mp3", "opus". Read from the file
	 * itself via ffprobe when there is one, since a container's extension is not proof of what
	 * is inside it; falls back to the extension's conventional codec when there is no ffprobe.
	 *
	 * @param string      $file   Audio file.
	 * @param string|null $ffmpeg ffmpeg path (ffprobe sits beside it).
	 */
	private static function codec( string $file, ?string $ffmpeg ): string {
		$ffprobe = $ffmpeg ? dirname( $ffmpeg ) . '/ffprobe' : self::which( 'ffprobe' );
		if ( $ffprobe && is_executable( $ffprobe ) ) {
			$out = self::run( array( $ffprobe, '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', $file ) );
			if ( ! is_wp_error( $out ) && trim( $out ) ) {
				return trim( $out );
			}
		}
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		return array(
			'm4a'  => 'aac',
			'opus' => 'opus',
			'ogg'  => 'vorbis',
		)[ $ext ] ?? $ext;
	}

	/**
	 * Whether yt-dlp actually re-encoded this track's audio rather than keeping (or losslessly
	 * remuxing into another container) what YouTube served. yt-dlp says so itself in its own log
	 * line — "Not converting audio …; file is already …" versus "[ExtractAudio] Destination: …"
	 * when a real conversion runs — so this only reads that, the same way the file names in $dir
	 * are matched to entries elsewhere in this class: by the "[id]" yt-dlp's own output carries.
	 *
	 * @param string $stderr yt-dlp's stderr from the fetch, one run covering every track.
	 * @param string $vid    This track's video id.
	 */
	private static function was_reencoded( string $stderr, string $vid ): bool {
		foreach ( preg_split( '/\r\n|\r|\n/', $stderr ) as $line ) {
			$line = trim( (string) $line );
			if ( str_starts_with( $line, '[ExtractAudio]' ) && str_contains( $line, '[' . $vid . ']' ) && str_contains( $line, 'Destination:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this ffmpeg build can actually encode AAC. Virtually every build can — libavcodec's
	 * native "aac" encoder needs no external library and ships by default — but a stripped build
	 * is possible, and mp3 is only worth reaching for when it truly is the only way out.
	 *
	 * @param string $ffmpeg ffmpeg binary.
	 */
	private static function has_aac_encoder( string $ffmpeg ): bool {
		$out = self::run( array( $ffmpeg, '-hide_banner', '-encoders' ) );
		return ! is_wp_error( $out ) && (bool) preg_match( '/\baac\b/', $out );
	}

	/**
	 * Reduce a YouTube search's results to the single best candidate for the one track that was
	 * actually asked for. In order: an auto-generated "Topic" channel — "Artist - Topic" is a
	 * literal YouTube naming convention for the label's own catalogue upload, not a guess, and
	 * usually the cleanest master — then a channel YouTube itself has verified (the closest
	 * structural signal yt-dlp exposes to "official"; it is not scoped to this one song, so it is
	 * a second choice, not a certainty), then anything left that does not look like a live
	 * version, cover or remix, else give up and take yt-dlp's own top result.
	 *
	 * @param array<int, array<string, mixed>> $entries Flat search results, in yt-dlp's own order.
	 * @return array<string, mixed>
	 */
	private static function best_of_search( array $entries ): array {
		$entries = array_values( $entries );
		if ( count( $entries ) < 2 ) {
			return $entries[0] ?? array();
		}

		$not_a_cover = static fn( array $e ): bool => ! preg_match( '/\b(live|cover|remix|karaoke|reaction)\b/i', (string) ( $e['title'] ?? '' ) );
		$clean       = array_values( array_filter( $entries, $not_a_cover ) );
		$pool        = $clean ? $clean : $entries; // Nothing clean survives: a live version beats nothing.

		foreach ( $pool as $e ) {
			if ( str_ends_with( (string) ( $e['channel'] ?? $e['uploader'] ?? '' ), ' - Topic' ) ) {
				return $e;
			}
		}
		foreach ( $pool as $e ) {
			if ( ! empty( $e['channel_is_verified'] ) ) {
				return $e;
			}
		}
		return $pool[0];
	}

	/**
	 * Loudness envelopes for every audio file in a folder, keyed by video id: a string of digits 0-9, ten per second.
	 * The app drives its light and level meters from this, so iPhones (which cannot analyse audio live) see real levels.
	 *
	 * @param string        $dir      Set folder.
	 * @param string|null   $ffmpeg   ffmpeg binary.
	 * @param callable|null $progress Line callback.
	 * @return array<string, string>
	 */
	public static function levels_for_dir( string $dir, ?string $ffmpeg, ?callable $progress = null ): array {
		if ( ! $ffmpeg ) {
			return array();
		}
		$out = array();
		foreach ( glob( $dir . '/*.{mp3,m4a,opus,ogg,wav,flac}', GLOB_BRACE ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			if ( ! preg_match( '/\[([^\]]+)\]\.[a-z0-9]+$/i', basename( $file ), $m ) ) {
				continue;
			}
			$env = self::levels( $file, $ffmpeg );
			if ( $env ) {
				$out[ $m[1] ] = $env;
			}
		}
		if ( $progress && $out ) {
			/* translators: %d: number of tracks analysed. */
			$progress( sprintf( __( 'Measured levels for %d tracks.', 'callboard' ), count( $out ) ) );
		}
		return $out;
	}

	/**
	 * One track's envelope: mono 8-bit at 1 kHz from ffmpeg, mean deviation per 100 samples, scaled to 0-9.
	 *
	 * @param string $file   Audio file.
	 * @param string $ffmpeg ffmpeg binary.
	 */
	public static function levels( string $file, string $ffmpeg ): string {
		$pcm = self::run( array( $ffmpeg, '-v', 'error', '-i', $file, '-ac', '1', '-ar', '1000', '-f', 'u8', '-' ) );
		if ( ! is_string( $pcm ) || strlen( $pcm ) < 200 ) {
			return '';
		}
		$n      = intdiv( strlen( $pcm ), 100 );
		$levels = array();
		$peak   = 1;
		for ( $k = 0; $k < $n; $k++ ) {
			$sum = 0;
			for ( $j = 0; $j < 100; $j++ ) {
				$sum += abs( ord( $pcm[ $k * 100 + $j ] ) - 128 );
			}
			$levels[ $k ] = $sum / 100;
			$peak         = max( $peak, $levels[ $k ] );
		}
		$digits = '';
		foreach ( $levels as $v ) {
			$digits .= (string) min( 9, (int) round( 9 * sqrt( $v / $peak ) ) ); // sqrt: quiet passages still move.
		}
		return $digits;
	}

	/**
	 * Run a command, streaming stderr lines to $progress. Returns stdout.
	 *
	 * @param string[]      $cmd      Argv.
	 * @param callable|null $progress Line callback.
	 * @param string|null   $stderr   Set to the command's full stderr, for callers that need more
	 *                                than the lines $progress was shown (e.g. was_reencoded()).
	 * @return string|WP_Error
	 */
	private static function run( array $cmd, ?callable $progress = null, ?string &$stderr = null ) {
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = proc_open( $cmd, $spec, $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		if ( ! is_resource( $proc ) ) {
			return new WP_Error( 'callboard_spawn', __( 'Could not start the fetch tool.', 'callboard' ) );
		}
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		stream_set_blocking( $pipes[2], false );
		$out = '';
		$err = '';
		while ( ! feof( $pipes[1] ) ) {
			$out .= (string) fread( $pipes[1], 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$line = fgets( $pipes[2] );
			if ( false !== $line ) {
				$err .= $line;
				if ( $progress && preg_match( '/\[(download|ExtractAudio)\] (Destination|Downloading item|\d+\.\d+% of)/', $line ) ) {
					$progress( trim( $line ) );
				}
			}
		}
		$err .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$code   = proc_close( $proc );
		$stderr = $err;
		if ( 0 !== $code ) {
			return new WP_Error( 'callboard_tool_failed', trim( $err ) ? trim( $err ) : sprintf( 'exit %d', $code ) );
		}
		return $out;
	}

	/**
	 * Resolve a binary on PATH (or accept an absolute path).
	 *
	 * @param string $bin Name or path.
	 */
	private static function which( string $bin ): ?string {
		if ( str_contains( $bin, '/' ) ) {
			return is_executable( $bin ) ? $bin : null;
		}
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $p ) {
			if ( $p && is_executable( $p . '/' . $bin ) ) {
				return $p . '/' . $bin;
			}
		}
		foreach ( array( '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin' ) as $p ) {
			if ( is_executable( $p . '/' . $bin ) ) {
				return $p . '/' . $bin;
			}
		}
		return null;
	}
}
