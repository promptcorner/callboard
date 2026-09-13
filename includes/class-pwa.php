<?php
/**
 * Home-screen app files. On a single site, sw.js and manifest.json are written to ABSPATH (WP Engine
 * and most hosts allow it). On a multisite network every site shares ABSPATH, so each site keeps its
 * copies in options and WordPress serves them from that site's home URL.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Web app manifest and service worker.
 */
final class Pwa {

	/**
	 * Option holding a network site's service worker.
	 */
	public const SW_OPTION = 'callboard_sw';

	/**
	 * Option holding a network site's manifest.
	 */
	public const MANIFEST_OPTION = 'callboard_manifest';

	/**
	 * Query variable a network site's app files are served under: `?callboard_file=sw.js`.
	 */
	public const QUERY_VAR = 'callboard_file';

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'parse_request', array( self::class, 'serve' ) );
		add_action( 'update_option_blogname', array( self::class, 'write_files' ) );
		add_action( 'callboard_imported', array( self::class, 'write_files' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'write_files' ) );
		// The Home Screen shortcuts name the first four sets, so a set renamed or deleted in the
		// admin has to reach the manifest — but not the service worker, which would drop every
		// saved copy over a typo fixed in a title.
		//
		// The generic save_post, not save_post_{$type}: the typed hook fires first, and Sets::flush()
		// is on the generic one, so the manifest would be written from the cache about to be cleared.
		add_action( 'save_post', array( self::class, 'write_manifest_for_set' ), 20, 2 );
		add_action( 'deleted_post', array( self::class, 'write_manifest_for_set' ), 20, 2 );
	}

	/**
	 * Whether this site's app files are kept in options and served by WordPress instead of written to ABSPATH.
	 *
	 * @since 2.3.0
	 */
	public static function serves_files(): bool {
		return is_multisite();
	}

	/**
	 * The service worker's URL for this site.
	 *
	 * @since 2.3.0
	 */
	public static function sw_url(): string {
		return self::serves_files() ? add_query_arg( self::QUERY_VAR, 'sw.js', home_url( '/' ) ) : home_url( '/sw.js' );
	}

	/**
	 * The manifest's URL for this site.
	 *
	 * @since 2.3.0
	 */
	public static function manifest_url(): string {
		return self::serves_files() ? add_query_arg( self::QUERY_VAR, 'manifest.json', home_url( '/' ) ) : home_url( '/manifest.json' );
	}

	/**
	 * The path the service worker controls: the site's home path, such as `/` or `/choir/`.
	 *
	 * @since 2.3.0
	 */
	public static function scope(): string {
		$root = wp_make_link_relative( home_url( '/' ) );
		return '' !== $root ? $root : '/';
	}

	/**
	 * The service worker or manifest as this site last wrote it, or an empty string.
	 *
	 * @since 2.3.0
	 *
	 * @param string $file `sw.js` or `manifest.json`.
	 */
	public static function contents( string $file ): string {
		if ( 'sw.js' !== $file && 'manifest.json' !== $file ) {
			return '';
		}
		if ( self::serves_files() ) {
			return (string) get_option( 'sw.js' === $file ? self::SW_OPTION : self::MANIFEST_OPTION, '' );
		}
		return is_readable( ABSPATH . $file ) ? (string) file_get_contents( ABSPATH . $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Add the query variable a network site's app files are requested with.
	 *
	 * @since 2.3.0
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * The response for `?callboard_file=sw.js` or `?callboard_file=manifest.json` on a network site.
	 *
	 * Writes the file first when the site has no copy yet.
	 *
	 * @since 2.3.0
	 *
	 * @param string $file `sw.js` or `manifest.json`.
	 * @return array{headers: array<string, string>, body: string}|null Null when this site does not serve the file.
	 */
	public static function response( string $file ): ?array {
		if ( ! self::serves_files() || ( 'sw.js' !== $file && 'manifest.json' !== $file ) ) {
			return null;
		}
		$body = self::contents( $file );
		if ( '' === $body ) {
			if ( 'sw.js' === $file ) {
				self::write_sw();
			} else {
				self::write_manifest();
			}
			$body = self::contents( $file );
		}
		if ( '' === $body ) {
			return null;
		}
		$headers = array(
			'Content-Type'           => 'sw.js' === $file ? 'text/javascript; charset=utf-8' : 'application/manifest+json; charset=utf-8',
			'Cache-Control'          => 'no-cache',
			'X-Content-Type-Options' => 'nosniff',
		);
		if ( 'sw.js' === $file ) {
			$headers['Service-Worker-Allowed'] = self::scope();
		}
		return array(
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * Answer a request for a network site's service worker or manifest, then stop.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP $wp Current request.
	 */
	public static function serve( \WP $wp ): void {
		$response = self::response( (string) ( $wp->query_vars[ self::QUERY_VAR ] ?? '' ) );
		if ( ! $response ) {
			return;
		}
		status_header( 200 );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a script and a JSON document this site wrote.
		exit;
	}

	/**
	 * The iPhone screens we draw launch images for: [width, height, device-pixel-ratio] in portrait.
	 *
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	public static function splash_sizes(): array {
		return array(
			array( 1320, 2868, 3 ),
			array( 1206, 2622, 3 ),
			array( 1290, 2796, 3 ),
			array( 1179, 2556, 3 ),
			array( 1170, 2532, 3 ),
			array( 1080, 2340, 3 ),
			array( 1242, 2688, 3 ),
			array( 1125, 2436, 3 ),
			array( 828, 1792, 2 ),
			array( 750, 1334, 2 ),
		);
	}

	/**
	 * Where launch images live and their URL base.
	 *
	 * @return array{dir: string, url: string}
	 */
	public static function splash_location(): array {
		$u = wp_upload_dir( null, false );
		return array(
			'dir' => $u['basedir'] . '/callboard/splash',
			'url' => $u['baseurl'] . '/callboard/splash',
		);
	}

	/**
	 * URL of the app icon at a size: the site icon when one is set, otherwise the icon bundled with the plugin.
	 *
	 * @since 2.4.0
	 *
	 * @param int $size 180, 192 or 512, the sizes the plugin bundles.
	 * @return string
	 */
	public static function icon_url( int $size ): string {
		$url = has_site_icon() ? get_site_icon_url( $size ) : '';
		return '' !== $url ? $url : callboard_asset( 'assets/icon-' . $size . '.png' );
	}

	/**
	 * URL of the maskable app icon.
	 *
	 * With a site icon, this is the padded copy write_maskable_icon() drew from it, or '' when there
	 * is no copy (no GD, or an image GD cannot read). The bundled maskable icon is never used with a
	 * site icon, because Android would show it instead of the site icon.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	public static function maskable_icon_url(): string {
		if ( ! has_site_icon() ) {
			return callboard_asset( 'assets/icon-512-maskable.png' );
		}
		$loc = self::maskable_icon_location();
		return file_exists( $loc['file'] ) ? $loc['url'] . '?v=' . filemtime( $loc['file'] ) : '';
	}

	/**
	 * Where the maskable copy of the current site's icon lives.
	 *
	 * The file name carries the site ID and the attachment ID. Each network site normally has its
	 * own uploads folder, but a filter on upload_dir can point every site at the same one.
	 *
	 * @since 2.4.0
	 *
	 * @return array{file: string, url: string}
	 */
	public static function maskable_icon_location(): array {
		$u    = wp_upload_dir( null, false );
		$name = sprintf( '/callboard/icons/maskable-%d-%d.png', get_current_blog_id(), (int) get_option( 'site_icon' ) );
		return array(
			'file' => $u['basedir'] . $name,
			'url'  => $u['baseurl'] . $name,
		);
	}

	/**
	 * Draw the maskable copy of the site icon, and delete this site's copies of earlier icons.
	 *
	 * @since 2.4.0
	 */
	public static function write_maskable_icon(): void {
		$loc   = self::maskable_icon_location();
		$drawn = glob( sprintf( '%s/maskable-%d-*.png', dirname( $loc['file'] ), get_current_blog_id() ) );
		foreach ( is_array( $drawn ) ? $drawn : array() as $old ) {
			if ( $old !== $loc['file'] ) {
				wp_delete_file( $old );
			}
		}
		$src = self::site_icon_file();
		if ( '' === $src ) {
			return;
		}
		wp_mkdir_p( dirname( $loc['file'] ) );
		Art::maskable_icon( $src, $loc['file'] );
	}

	/**
	 * Path to the site icon's image file, or '' when there is no site icon.
	 */
	private static function site_icon_file(): string {
		$file = has_site_icon() ? get_attached_file( (int) get_option( 'site_icon' ) ) : false;
		return $file && is_readable( $file ) ? $file : '';
	}

	/**
	 * Draw launch images (light and dark) so the installed app doesn't flash white. Skipped without GD.
	 */
	public static function write_splash_screens(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			return;
		}
		$loc  = self::splash_location();
		$name = callboard_site_name();
		$key  = md5( $name . CALLBOARD_VERSION . '2' );
		if ( get_option( 'callboard_splash_key' ) === $key && is_dir( $loc['dir'] ) ) {
			return;
		}
		wp_mkdir_p( $loc['dir'] );
		$font    = CALLBOARD_DIR . 'assets/fonts/Poppins-SemiBold.ttf';
		$schemes = array(
			'light' => array( array( 236, 234, 229 ), array( 30, 28, 26 ) ),
			'dark'  => array( array( 22, 22, 22 ), array( 242, 241, 238 ) ),
		);
		foreach ( $schemes as $scheme => $colors ) {
			foreach ( self::splash_sizes() as $dims ) {
				list( $w, $h, $dpr ) = $dims;
				$im                  = imagecreatetruecolor( $w, $h );
				$ink                 = imagecolorallocate( $im, ...$colors[1] );
				$bg                  = imagecolorallocate( $im, ...$colors[0] );
				imagefill( $im, 0, 0, $bg );
				// The mark, drawn rather than pasted: a disc, a play triangle, the accent dot.
				$r  = (int) round( $w * 0.09 );
				$cx = (int) ( $w / 2 );
				$cy = (int) ( $h / 2 - $r * 0.6 );
				imagefilledellipse( $im, $cx, $cy, $r * 2, $r * 2, $ink );
				$t = $r * 0.42;
				imagefilledpolygon( $im, array( (int) ( $cx - $t * 0.65 ), (int) ( $cy - $t ), (int) ( $cx - $t * 0.65 ), (int) ( $cy + $t ), (int) ( $cx + $t * 1.05 ), $cy ), $bg );
				$d = (int) round( $r * 0.16 );
				imagefilledellipse( $im, (int) ( $cx + $r * 0.95 ), (int) ( $cy - $r * 0.95 ), $d * 2, $d * 2, imagecolorallocate( $im, 232, 84, 30 ) );
				$pt  = (int) round( 13 * $dpr );
				$box = imagettfbbox( $pt, 0, $font, $name );
				$tw  = $box ? $box[2] - $box[0] : 0;
				imagettftext( $im, $pt, 0, (int) ( ( $w - $tw ) / 2 ), (int) ( $cy + $r + $pt * 2.2 ), $ink, $font, $name );
				imagepng( $im, sprintf( '%s/%s-%dx%d.png', $loc['dir'], $scheme, $w, $h ), 6 );
			}
		}
		update_option( 'callboard_splash_key', $key, false );
	}

	/**
	 * Write manifest.json and sw.js, and draw the splash screens.
	 *
	 * @return bool Whether both files were written.
	 */
	public static function write_files(): bool {
		$manifest = self::write_manifest();
		self::write_splash_screens();
		return $manifest && self::write_sw();
	}

	/**
	 * Rewrite the manifest when the post that changed was a set.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object, where the hook passes one.
	 */
	public static function write_manifest_for_set( int $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( Post_Types::SET === $type ) {
			self::write_manifest();
		}
	}

	/**
	 * Write manifest.json alone.
	 *
	 * Separate from the service worker on purpose. sw.js carries a cache version stamped with
	 * time(), so rewriting it evicts every saved set — which is the right thing when the plugin
	 * updates and exactly the wrong thing when somebody renames a set in the admin. The manifest has
	 * no such cost, so it can follow the sets as closely as it likes.
	 *
	 * @return bool Whether the file was written.
	 */
	public static function write_manifest(): bool {
		$root  = self::scope();
		$name  = callboard_site_name();
		$short = $name;
		if ( mb_strlen( $name ) > 12 ) { // the Home Screen label: cut at a word, never mid-word.
			$cut   = mb_substr( $name, 0, 13 );
			$space = mb_strrpos( $cut, ' ' );
			$short = $space ? mb_substr( $cut, 0, $space ) : mb_substr( $name, 0, 12 );
		}
		$manifest = array(
			'name'                        => $name,
			'short_name'                  => $short,
			'description'                 => Settings::get( 'tagline' ),
			'start_url'                   => $root,
			'scope'                       => $root,
			'id'                          => $root,
			'lang'                        => str_replace( '_', '-', get_locale() ),
			'display'                     => 'standalone',
			'display_override'            => array( 'standalone', 'minimal-ui' ),
			'launch_handler'              => array( 'client_mode' => 'navigate-existing' ), // A link opens in the running app, never a second window.
			'handle_links'                => 'preferred',
			'prefer_related_applications' => false,
			'categories'                  => array( 'music', 'education' ),
			'shortcuts'                   => array_map(
				static fn( array $set ) => array(
					'name'  => $set['name'],
					'url'   => wp_make_link_relative( home_url( '/' . $set['slug'] . '/' ) ),
					'icons' => array(
						array(
							'src'   => wp_make_link_relative( CALLBOARD_URL . 'assets/icon-192.png' ),
							'sizes' => '192x192',
						),
					),
				),
				array_slice( Sets::all(), 0, 4 )
			),
			'orientation'                 => 'portrait',
			'background_color'            => '#eceae5',
			'theme_color'                 => '#eceae5',
			'icons'                       => array(
				array(
					'src'   => wp_make_link_relative( CALLBOARD_URL . 'assets/icon-192.png' ),
					'sizes' => '192x192',
					'type'  => 'image/png',
				),
				array(
					'src'   => wp_make_link_relative( CALLBOARD_URL . 'assets/icon-512.png' ),
					'sizes' => '512x512',
					'type'  => 'image/png',
				),
				array(
					'src'     => wp_make_link_relative( CALLBOARD_URL . 'assets/icon-512-maskable.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);
		return self::put( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Write sw.js alone. Stamps a fresh cache version, so every saved set is re-fetched after it.
	 *
	 * @return bool Whether the file was written.
	 */
	public static function write_sw(): bool {
		$assets = array_map(
			'wp_make_link_relative',
			array(
				home_url( '/' ),
				home_url( '/?fragment=1' ), // Home as the script swaps it in; a saved set's fragment is warmed by the page.
				self::manifest_url(),
				callboard_asset( 'assets/app.js' ),
				callboard_asset( 'assets/icon-192.png' ),
				callboard_asset( 'assets/icon-512.png' ),
				callboard_asset( 'assets/icon-180.png' ),
			)
		);
		// Core's hooks script and every active extension's assets, at the exact URLs the page asks for,
		// so an installed app opens with its extensions when there is no network.
		$assets = array_values( array_unique( array_merge( $assets, Extensions::precache_urls() ) ) );
		$sw     = str_replace(
			array( '__VERSION__', '__PLUGIN_PATH__', '__ASSETS__', '__APP_VERSION__', '__PUSH_API__', '__HOME__', '__MANIFEST__', '__SITE__' ),
			array(
				(string) time(),
				wp_make_link_relative( CALLBOARD_URL ),
				wp_json_encode( $assets, JSON_UNESCAPED_SLASHES ),
				CALLBOARD_VERSION,
				Settings::get( 'push' ) && Push::available() ? wp_make_link_relative( rest_url( 'callboard/v1/push/' ) ) : '',
				self::scope(),
				wp_make_link_relative( self::manifest_url() ),
				self::serves_files() ? (string) get_current_blog_id() : '',
			),
			(string) file_get_contents( CALLBOARD_DIR . 'pwa/sw.js' ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin.
		);
		if ( ! self::put( 'sw.js', $sw ) ) {
			return false;
		}
		// Whatever wrote the worker (an update, an import, the admin noticing a new extension), this is
		// what went into it. Extensions::maybe_refresh_worker() compares against it.
		update_option( 'callboard_extension_assets', Extensions::assets_fingerprint(), false );
		return true;
	}

	/**
	 * Save an app file: into ABSPATH on a single site, into an option on a network site.
	 *
	 * @param string $file `sw.js` or `manifest.json`.
	 * @param string $body File contents.
	 * @return bool Whether it was saved.
	 */
	private static function put( string $file, string $body ): bool {
		if ( self::serves_files() ) {
			update_option( 'sw.js' === $file ? self::SW_OPTION : self::MANIFEST_OPTION, $body, false );
			return true;
		}
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return false;
		}
		return (bool) $wp_filesystem->put_contents( ABSPATH . $file, $body, FS_CHMOD_FILE );
	}
}
