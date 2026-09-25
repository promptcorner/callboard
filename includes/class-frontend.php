<?php
/**
 * Front-end assets and <head>: only ours, whatever theme is active.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Assets and head output.
 */
final class Frontend {

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_everything_else' ), PHP_INT_MAX );
		add_action( 'wp_head', array( self::class, 'link_previews' ), 2 );
		add_action( 'wp_head', array( self::class, 'inline_css' ), 3 );
		add_action( 'wp_head', array( self::class, 'app_meta' ), 4 );
		// Block themes register a second viewport tag while locating templates (after init), so remove it just before wp_head runs.
		add_action( 'wp_head', static fn() => remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 ), -1 );
		add_action( 'init', array( self::class, 'trim_core_output' ) );
		add_filter( 'should_load_separate_core_block_assets', '__return_false' );
		add_filter( 'wp_img_tag_add_auto_sizes', '__return_false' );
		add_filter( 'wp_speculation_rules_configuration', '__return_null' );
	}

	/**
	 * Our script and its data.
	 */
	public static function enqueue(): void {
		if ( ! Gate::allowed() ) {
			return; // The gate needs no player, and app data is every set and every track URL.
		}
		// Core's hooks script is the lower layer of the extension API: window.callboard's events and
		// filters are wp.hooks actions and filters. It ships with WordPress, so there is still no build.
		wp_script_add_data( 'wp-hooks', 'strategy', 'defer' );
		// In the footer as well as deferred. WordPress drops the defer when an extension adds an inline
		// script after its own, and a blocking script in the head would run before the player's markup exists.
		wp_enqueue_script(
			'callboard',
			callboard_asset( 'assets/app.js' ),
			array( 'wp-hooks' ),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		Extensions::enqueue();
		$data             = Sets::app_data( Router::view() );
		$data['settings'] = Settings::for_client();
		$data['icon']     = callboard_asset( 'assets/icon-512.png' );
		$data['version']  = CALLBOARD_VERSION;
		$data['worker']   = array(
			'url'   => wp_make_link_relative( Pwa::sw_url() ),
			'scope' => Pwa::scope(),
		);
		$data['text']     = array(
			'play_all'       => __( 'Play all', 'callboard' ),
			'copied'         => __( 'Link copied', 'callboard' ),
			'pause'          => __( 'Pause', 'callboard' ),
			'resume'         => __( 'Play', 'callboard' ),
			'install'        => __( 'Install this as an app to keep it a tap away.', 'callboard' ),
			'install_go'     => __( 'Add', 'callboard' ),
			'open_safari'    => __( 'Open this in Safari to add it to your Home Screen.', 'callboard' ),
			'open_safari_go' => __( 'Open in Safari', 'callboard' ),
			'save'           => __( 'Save offline', 'callboard' ),
			'saved'          => __( 'Saved offline', 'callboard' ),
			/* translators: 1: tracks saved so far, 2: total tracks. */
			'saving_set'     => __( 'Saving offline, %1$s of %2$s', 'callboard' ),
			/* translators: 1: tracks saved so far, 2: total tracks. */
			'save_rest'      => __( 'Save the rest, %1$s of %2$s saved', 'callboard' ),
			'saved_hover'    => __( 'Hold to remove', 'callboard' ),
			'saved_hint'     => __( 'Saved offline. Press and hold, or press Delete, to remove the copies.', 'callboard' ),
			/* translators: 1: tracks saved so far, 2: total tracks. */
			'saving'         => __( 'Saving %1$s/%2$s · Cancel', 'callboard' ),
			/* translators: %s: track title. */
			'lyrics_label'   => __( 'Lyrics', 'callboard' ),
			'lyrics_sheet'   => __( 'Lyrics · auto-captions, may be rough', 'callboard' ),
			'notes'          => __( 'Notes', 'callboard' ),
			'notes_sheet'    => __( 'Director notes', 'callboard' ),
			'show_notes'     => __( 'Show director notes', 'callboard' ),
			'hide_notes'     => __( 'Hide director notes', 'callboard' ),
			/* translators: %d: beats per minute. */
			'show_lyrics'    => __( 'Show lyrics', 'callboard' ),
			// The pill's own label: a noun, because the control is the way to a thing, not an instruction.
			'lyrics'         => __( 'Lyrics', 'callboard' ),
			'hide_lyrics'    => __( 'Hide lyrics', 'callboard' ),
			'show_track'     => __( 'Show current track', 'callboard' ),
			'remote'         => __( 'Play on another device', 'callboard' ),
			'remote_on'      => __( 'Playing on another device, tap to change', 'callboard' ),
			/* translators: %s: track title. */
			'save_track'     => __( 'Save %s offline', 'callboard' ),
			/* translators: %s: track title. */
			'saved_track'    => __( '%s is saved offline', 'callboard' ),
			'remove_confirm' => __( 'Remove offline copies? Tap again', 'callboard' ),
			/* translators: %s: free space such as 40 MB. */
			'no_space'       => __( 'Not enough space, %s free', 'callboard' ),
			/* translators: %s: track title. */
			'saving_track'   => __( 'Saving %s, tap to cancel', 'callboard' ),
			'load_none'      => __( 'Nothing matched a track in this set', 'callboard' ),
			/* translators: 1: files loaded, 2: files chosen. */
			'loaded'         => __( 'Loaded %1$s of %2$s', 'callboard' ),
			/* translators: 1: tracks loaded, 2: tracks in the file, 3: set name. */
			'loaded_set'     => __( 'Loaded %1$s of %2$s tracks into %3$s', 'callboard' ),
			'set_file'       => __( 'Save set file', 'callboard' ),
			'set_file_busy'  => __( 'Making the set file', 'callboard' ),
			'set_file_send'  => __( 'Send set file', 'callboard' ),
			'set_file_fail'  => __( 'Could not make the set file', 'callboard' ),
			'not_a_set'      => __( 'That file is not a Callboard set', 'callboard' ),
			'set_newer'      => __( 'That set file needs a newer version of Callboard', 'callboard' ),
			/* translators: %s: set name. */
			'set_elsewhere'  => __( '%s is not on this site', 'callboard' ),
			/* translators: %s: track title. */
			'left_off'       => __( 'Left off at %s', 'callboard' ),
		);
		/**
		 * Everything the front end knows: site, sets, settings, text. Add a field here and it is on window.CALLBOARD.
		 *
		 * @param array<string, mixed> $data App data.
		 */
		wp_localize_script( 'callboard', 'CALLBOARD', apply_filters( 'callboard_app_data', $data ) );
	}

	/**
	 * Drop any style or script the active theme or other plugins added. What stays is Callboard's own
	 * script, core's hooks underneath it, and the assets of registered extensions: a plugin that
	 * wants to be on the page registers as an extension rather than enqueuing around the app.
	 */
	public static function dequeue_everything_else(): void {
		$handles = Gate::allowed() ? Extensions::handles() : array(
			'scripts' => array(),
			'styles'  => array(),
		);
		if ( is_admin_bar_showing() ) {
			$handles['styles']  = array_merge( $handles['styles'], array( 'admin-bar', 'dashicons' ) );
			$handles['scripts'] = array_merge( $handles['scripts'], array( 'admin-bar' ) );
		}
		foreach ( wp_styles()->queue as $handle ) {
			if ( ! in_array( $handle, $handles['styles'], true ) ) {
				wp_dequeue_style( $handle );
			}
		}
		foreach ( wp_scripts()->queue as $handle ) {
			if ( ! in_array( $handle, array_merge( array( 'callboard', 'wp-hooks' ), $handles['scripts'] ), true ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}

	/**
	 * Inline the stylesheet: one fewer render-blocking request.
	 */
	public static function inline_css(): void {
		$css = file_get_contents( CALLBOARD_DIR . 'assets/app.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		echo '<style id="callboard-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static plugin file.
		$accent = sanitize_hex_color( (string) Settings::get( 'accent' ) );
		if ( $accent ) {
			// One token drives the played wave, the filament, the marks, and the pins. Text on light backgrounds
			// takes a darker shade of it so it stays readable.
			printf( '<style id="callboard-accent">:root{--accent:%1$s;--accent-text:%2$s}@media (prefers-color-scheme:dark){:root{--accent-text:%1$s}}</style>' . "\n", esc_html( $accent ), esc_html( self::shade( $accent, 0.78 ) ) );
		}
	}

	/**
	 * A hex colour scaled toward black (factor below 1) or white (above 1).
	 *
	 * @param string $hex    #rrggbb.
	 * @param float  $factor Multiplier for each channel.
	 */
	private static function shade( string $hex, float $factor ): string {
		$out = '#';
		foreach ( str_split( ltrim( $hex, '#' ), 2 ) as $ch ) {
			$out .= str_pad( dechex( (int) max( 0, min( 255, round( hexdec( $ch ) * $factor ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * Theme color, manifest, icons, iOS web-app meta.
	 */
	public static function app_meta(): void {
		?>
		<meta name="theme-color" content="#eceae5" media="(prefers-color-scheme: light)">
		<meta name="theme-color" content="#161616" media="(prefers-color-scheme: dark)">
		<meta name="color-scheme" content="light dark">
		<link rel="manifest" href="<?php echo esc_url( Pwa::manifest_url() ); ?>">
		<link rel="icon" sizes="192x192" href="<?php echo esc_url( Pwa::icon_url( 192 ) ); ?>">
		<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( Pwa::icon_url( 180 ) ); ?>">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
		<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( callboard_site_name() ); ?>">
		<?php
		$loc = Pwa::splash_location();
		if ( is_dir( $loc['dir'] ) ) {
			foreach ( Pwa::splash_sizes() as $dims ) {
				list( $w, $h, $dpr ) = $dims;
				foreach ( array( 'light', 'dark' ) as $scheme ) {
					$file = sprintf( '%s/%s-%dx%d.png', $loc['dir'], $scheme, $w, $h );
					if ( file_exists( $file ) ) {
						printf(
							'<link rel="apple-touch-startup-image" media="(device-width: %1$dpx) and (device-height: %2$dpx) and (-webkit-device-pixel-ratio: %3$d) and (orientation: portrait)%4$s" href="%5$s">' . "\n",
							(int) ( $w / $dpr ),
							(int) ( $h / $dpr ),
							(int) $dpr,
							'dark' === $scheme ? ' and (prefers-color-scheme: dark)' : '',
							esc_url( sprintf( '%s/%s-%dx%d.png', $loc['url'], $scheme, $w, $h ) )
						);
					}
				}
			}
		}
		/**
		 * Print into the head of every front-end page: a stylesheet, a font, extra meta. The theme's own
		 * head is not used, so this is the place.
		 */
		do_action( 'callboard_head' );
	}

	/**
	 * Open Graph and Twitter tags, per view.
	 */
	public static function link_previews(): void {
		if ( ! Gate::allowed() ) {
			return; // A gated site does not describe its sets to a crawler or a chat unfurl.
		}
		$view  = Router::view();
		$set   = $view ? Sets::by_slug( $view ) : null;
		$title = Router::page_title();
		$url   = $set ? home_url( '/' . $set['slug'] . '/' ) : home_url( '/' );
		if ( $set ) {
			$desc = $set['meta'] . '. ' . Settings::get( 'tagline' ) . '.';
			$img  = $set['share'];
		} else {
			$count = count( Sets::all() );
			/* translators: %d: number of sets. */
			$desc = Settings::get( 'tagline' ) . '. ' . sprintf( _n( '%d set.', '%d sets.', $count, 'callboard' ), $count );
			$img  = file_exists( CALLBOARD_DIR . 'assets/share.png' ) ? callboard_asset( 'assets/share.png' ) : callboard_asset( 'assets/icon-512.png' );
		}
		$tags = array(
			'og:type'             => 'website',
			'og:site_name'        => callboard_site_name(),
			'og:title'            => $title,
			'og:description'      => $desc,
			'og:url'              => $url,
			'og:image'            => $img,
			'og:image:secure_url' => $img,
			'og:image:type'       => 'image/png',
			'og:image:alt'        => $title,
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $title,
			'twitter:description' => $desc,
			'twitter:image'       => $img,
		);
		foreach ( $tags as $key => $value ) {
			printf( '<meta %s="%s" content="%s">' . "\n", str_starts_with( $key, 'og:' ) ? 'property' : 'name', esc_attr( $key ), esc_attr( $value ) );
		}
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		printf( '<link rel="image_src" href="%s">' . "\n", esc_url( $img ) );
	}

	/**
	 * Remove core head/footer output we never want.
	 */
	public static function trim_core_output(): void {
		foreach ( array( 'wp_generator', 'wp_shortlink_wp_head', 'rsd_link', 'wlwmanifest_link', 'wp_oembed_add_discovery_links', 'wp_resource_hints', 'wp_print_auto_sizes_contain_css_fix' ) as $hook ) {
			remove_action( 'wp_head', $hook );
		}
		if ( 'production' === wp_get_environment_type() ) { // REST discovery stays available to local tooling and tests.
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		remove_action( 'wp_head', 'wp_site_icon', 99 ); // app_meta() prints the site icon, so the page has one apple-touch-icon.
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_common_block_scripts_and_styles' );
		remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		remove_action( 'wp_footer', 'the_block_template_skip_link' );
	}
}
