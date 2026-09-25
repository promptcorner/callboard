<?php
/**
 * Admin: track order, credits, lyrics approval, settings, import.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
final class Admin {

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'add_meta_boxes_' . Post_Types::SET, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . Post_Types::SET, array( self::class, 'save' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_init', array( self::class, 'maybe_redirect_to_setup' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'import_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CALLBOARD_FILE ), array( self::class, 'plugin_action_links' ) );
		add_filter( 'manage_' . Post_Types::SET . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Post_Types::SET . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		add_filter( 'post_updated_messages', array( self::class, 'updated_messages' ) );
	}

	/**
	 * Meta boxes on the Set screen.
	 */
	public static function meta_boxes(): void {
		add_meta_box( 'callboard-tracks', __( 'Tracks', 'callboard' ), array( self::class, 'box_tracks' ), Post_Types::SET, 'normal', 'high' );
		add_meta_box( 'callboard-credits', __( 'Source & credits', 'callboard' ), array( self::class, 'box_credits' ), Post_Types::SET, 'normal' );
		add_meta_box( 'callboard-lyrics', __( 'Lyrics', 'callboard' ), array( self::class, 'box_lyrics' ), Post_Types::SET, 'side' );
		add_meta_box( 'callboard-cover', __( 'Cover colours', 'callboard' ), array( self::class, 'box_palette' ), Post_Types::SET, 'side' );
	}

	/**
	 * Sortable track list with editable titles.
	 *
	 * @param WP_Post $post Set post.
	 */
	public static function box_tracks( WP_Post $post ): void {
		wp_nonce_field( 'callboard_save', 'callboard_nonce' );
		$tracks = Sets::track_posts( $post->ID );
		?>
		<!--
		THESIS: A set is assembled where WordPress already manages its title, cover, status, and media.
		OWN-WORLD: Core post boxes, Media Library, buttons, fields, notices, and sortable attachment rows.
		STORY: Name the set, choose audio, arrange it, publish it, then open the player.
		FIRST VIEWPORT: The title and Publish box remain native; Tracks is the first working panel and names its next action.
		FORM: A complete WordPress post editor with one media-first track list.
		-->
		<div class="callboard-track-toolbar">
			<div>
				<strong><?php esc_html_e( 'Music in this set', 'callboard' ); ?></strong>
				<p class="description"><?php esc_html_e( 'Choose audio from the Media Library, then drag or use the arrow buttons to set the playing order.', 'callboard' ); ?></p>
			</div>
			<?php if ( current_user_can( 'upload_files' ) ) : ?>
				<button type="button" class="button callboard-add-tracks"><?php esc_html_e( 'Add tracks', 'callboard' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="callboard-track-empty"<?php echo $tracks ? ' hidden' : ''; ?>>
			<?php esc_html_e( 'No tracks yet. Add audio from your computer or choose files already in WordPress.', 'callboard' ); ?>
		</p>
		<div class="notice notice-warning inline callboard-track-warning" hidden><p></p></div>
		<ol class="callboard-tracks" id="callboard-track-list">
			<?php
			foreach ( $tracks as $track ) {
				self::track_row( $track );
			}
			?>
		</ol>
		<div id="callboard-track-removals" hidden></div>
		<?php
	}

	/**
	 * One editable track row.
	 *
	 * @param WP_Post $track Audio attachment.
	 */
	private static function track_row( WP_Post $track ): void {
		$meta = (array) wp_get_attachment_metadata( $track->ID );
		printf(
			'<li data-id="%1$d"><div class="callboard-track-row"><span class="callboard-track-number" aria-hidden="true"></span><span class="dashicons dashicons-move callboard-drag" aria-hidden="true"></span><input type="hidden" name="callboard_order[]" value="%1$d"><label class="screen-reader-text" for="callboard-title-%1$d">%6$s</label><input type="text" class="regular-text callboard-track-title" id="callboard-title-%1$d" name="callboard_title[%1$d]" value="%2$s"><span class="callboard-len">%3$s</span><span class="callboard-track-reorder"><button type="button" class="button-link callboard-move" data-dir="-1" aria-label="%7$s"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button><button type="button" class="button-link callboard-move" data-dir="1" aria-label="%8$s"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button></span><span class="callboard-track-actions"><a href="%4$s">%5$s</a><span aria-hidden="true">&middot;</span><button type="button" class="button-link button-link-delete callboard-remove-track">%9$s</button></span></div></li>',
			(int) $track->ID,
			esc_attr( $track->post_title ),
			esc_html( $meta['length_formatted'] ?? '' ),
			esc_url( get_edit_post_link( $track->ID ) ),
			esc_html__( 'Edit file', 'callboard' ),
			/* translators: %s: track title. */
			esc_attr( sprintf( __( 'Title for %s', 'callboard' ), $track->post_title ) ),
			esc_attr__( 'Move up', 'callboard' ),
			esc_attr__( 'Move down', 'callboard' ),
			esc_html__( 'Remove', 'callboard' )
		);
	}

	/**
	 * Credits fields.
	 *
	 * @param WP_Post $post Set post.
	 */
	public static function box_credits( WP_Post $post ): void {
		$c      = (array) get_post_meta( $post->ID, '_callboard_credits', true );
		$fields = array(
			'playlist_url' => __( 'Source playlist URL', 'callboard' ),
			'curator'      => __( 'Playlist by', 'callboard' ),
			'curator_url'  => __( 'Playlist author URL', 'callboard' ),
		);
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="callboard-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="callboard-%1$s" name="callboard_credits[%1$s]" value="%3$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) ( $c[ $key ] ?? '' ) )
			);
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'Per-track uploader credits come from each audio file\'s metadata and are shown automatically.', 'callboard' ) . '</p>';
	}

	/**
	 * Lyrics approval.
	 *
	 * @param WP_Post $post Set post.
	 */
	public static function box_lyrics( WP_Post $post ): void {
		$on = (bool) get_post_meta( $post->ID, '_callboard_lyrics_approved', true );
		printf(
			'<label><input type="checkbox" name="callboard_lyrics_approved" value="1" %s> %s</label><p class="description">%s</p>',
			checked( $on, true, false ),
			esc_html__( 'Show lyrics for this set', 'callboard' ),
			esc_html__( 'Only turn this on after checking the imported captions for accuracy.', 'callboard' )
		);
	}

	/**
	 * Which colours the generated cover is drawn in.
	 *
	 * A set that has no artwork of its own gets one drawn for it, and left alone every set would be
	 * the same cream square. The palettes are taken from the theatre — Playbill's yellow, a house
	 * curtain's red and gold, the bone of a ghost light, two lighting gels — and a set picks one from
	 * its own slug so a board reads as a row of different things. This is where somebody overrides
	 * that. Uploading a featured image overrides all of it.
	 *
	 * @param WP_Post $post Set post.
	 */
	public static function box_palette( WP_Post $post ): void {
		$names   = array(
			'ghost'    => __( 'Ghost light — bone and ink', 'callboard' ),
			'playbill' => __( 'Playbill — yellow and black', 'callboard' ),
			'velvet'   => __( 'House curtain — red and gold', 'callboard' ),
			'congo'    => __( 'Congo blue', 'callboard' ),
			'amber'    => __( 'Bastard amber', 'callboard' ),
			'blackout' => __( 'Blackout', 'callboard' ),
		);
		$current = (string) get_post_meta( $post->ID, '_callboard_palette', true );

		echo '<label class="screen-reader-text" for="callboard-palette">' . esc_html__( 'Generated cover colour palette', 'callboard' ) . '</label>';
		echo '<select id="callboard-palette" name="callboard_palette" style="width:100%">';
		printf(
			'<option value="" %s>%s</option>',
			selected( $current, '', false ),
			esc_html__( 'Chosen from the set’s name', 'callboard' )
		);
		foreach ( Art::palettes() as $key ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $names[ $key ] ?? $key )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Used only for the cover Callboard draws. A featured image always wins.', 'callboard' )
		);
	}

	/**
	 * Save meta boxes.
	 *
	 * @param int $post_id Set ID.
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST['callboard_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['callboard_nonce'] ), 'callboard_save' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$order   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['callboard_order'] ?? array() ) ) ) ) );
		$removed = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['callboard_removed'] ?? array() ) ) ) ) );
		$titles  = isset( $_POST['callboard_title'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['callboard_title'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per element.
		foreach ( $removed as $track_id ) {
			if ( (int) get_post_field( 'post_parent', $track_id ) !== $post_id || ! current_user_can( 'edit_post', $track_id ) || ! str_starts_with( (string) get_post_mime_type( $track_id ), 'audio/' ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $track_id,
					'post_parent' => 0,
					'menu_order'  => 0,
				)
			);
		}
		foreach ( $order as $i => $track_id ) {
			if ( in_array( $track_id, $removed, true ) || 'attachment' !== get_post_type( $track_id ) || ! str_starts_with( (string) get_post_mime_type( $track_id ), 'audio/' ) || ! current_user_can( 'edit_post', $track_id ) ) {
				continue;
			}
			$parent = (int) get_post_field( 'post_parent', $track_id );
			if ( 0 !== $parent && $post_id !== $parent ) {
				continue;
			}
			$update = array(
				'ID'          => $track_id,
				'menu_order'  => $i + 1,
				'post_parent' => $post_id,
			);
			if ( ! empty( $titles[ $track_id ] ) ) {
				$update['post_title'] = $titles[ $track_id ];
			}
			wp_update_post( $update );
		}

		$credits = isset( $_POST['callboard_credits'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['callboard_credits'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per element.
		update_post_meta(
			$post_id,
			'_callboard_credits',
			array(
				'playlist_url' => esc_url_raw( $credits['playlist_url'] ?? '' ),
				'curator'      => $credits['curator'] ?? '',
				'curator_url'  => esc_url_raw( $credits['curator_url'] ?? '' ),
			)
		);
		update_post_meta( $post_id, '_callboard_lyrics_approved', empty( $_POST['callboard_lyrics_approved'] ) ? 0 : 1 );

		$palette = isset( $_POST['callboard_palette'] ) ? sanitize_key( wp_unslash( $_POST['callboard_palette'] ) ) : '';
		$palette = in_array( $palette, Art::palettes(), true ) ? $palette : '';
		if ( (string) get_post_meta( $post_id, '_callboard_palette', true ) !== $palette ) {
			update_post_meta( $post_id, '_callboard_palette', $palette );
			// A new palette is a new cover, so the drawing has to happen again and the colour taken
			// from it is no longer the colour of anything.
			delete_post_meta( $post_id, '_callboard_art_drawn' );
			delete_post_meta( $post_id, '_callboard_tint' );
			Importer::import_all();
		}
		Sets::flush();
	}

	/**
	 * Set editor title placeholder.
	 *
	 * @param string  $text Default placeholder.
	 * @param WP_Post $post Current post.
	 */
	public static function title_placeholder( string $text, WP_Post $post ): string {
		return Post_Types::SET === $post->post_type ? __( 'Name this set', 'callboard' ) : $text;
	}

	/**
	 * Add a direct player link to set save messages.
	 *
	 * @param array<string, array<int, string>> $messages Core post messages.
	 * @return array<string, array<int, string>>
	 */
	public static function updated_messages( array $messages ): array {
		$post = get_post();
		if ( ! $post instanceof WP_Post || Post_Types::SET !== $post->post_type || 'publish' !== $post->post_status || ! $post->post_name ) {
			return $messages;
		}
		$link                           = sprintf(
			' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( home_url( '/' . $post->post_name . '/' ) ),
			esc_html__( 'Open in player', 'callboard' ),
			esc_html__( '(opens in a new tab)', 'callboard' )
		);
		$messages[ Post_Types::SET ][1] = esc_html__( 'Set updated.', 'callboard' ) . $link;
		$messages[ Post_Types::SET ][6] = esc_html__( 'Set published.', 'callboard' ) . $link;
		return $messages;
	}

	/**
	 * Settings and Import submenus.
	 */
	public static function menu(): void {
		$parent = 'edit.php?post_type=' . Post_Types::SET;
		add_submenu_page( $parent, __( 'Get started with Callboard', 'callboard' ), __( 'Get Started', 'callboard' ), 'manage_options', 'callboard-setup', array( self::class, 'page_setup' ) );
		add_submenu_page( $parent, __( 'Import', 'callboard' ), __( 'Import', 'callboard' ), 'manage_options', 'callboard-import', array( self::class, 'page_import' ) );
		add_submenu_page( $parent, __( 'Callboard Settings', 'callboard' ), __( 'Settings', 'callboard' ), 'manage_options', 'callboard-settings', array( self::class, 'page_settings' ) );
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings(): void {
		register_setting(
			'callboard',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
		add_settings_section( 'callboard_identity', __( 'Player identity', 'callboard' ), array( self::class, 'section_identity' ), 'callboard' );
		add_settings_section( 'callboard_listening', __( 'Listening experience', 'callboard' ), array( self::class, 'section_listening' ), 'callboard' );
		add_settings_section( 'callboard_access', __( 'Access', 'callboard' ), array( self::class, 'section_access' ), 'callboard' );
		$fields = array(
			'tagline'        => array( __( 'Tagline', 'callboard' ), 'text', __( 'Shown under the title and in link previews.', 'callboard' ), 'callboard_identity' ),
			'footer_note'    => array( __( 'Home page footer', 'callboard' ), 'text', __( 'A short credit, rights note, or listening instruction.', 'callboard' ), 'callboard_identity' ),
			'badge'          => array( __( 'Playing track marker', 'callboard' ), 'text', __( 'An emoji beside the current track, or leave empty.', 'callboard' ), 'callboard_identity' ),
			'accent'         => array( __( 'Accent colour', 'callboard' ), 'color', __( 'A hex colour such as #3b82f6 for the played wave, the filament, and the marks. Empty keeps the Callboard orange.', 'callboard' ), 'callboard_identity' ),
			'show_hint'      => array( __( 'Show the "Add to Home Screen" hint on iPhone', 'callboard' ), 'checkbox', '', 'callboard_listening' ),
			'offline'        => array( __( 'Offer "Save offline"', 'callboard' ), 'checkbox', '', 'callboard_listening' ),
			'confetti'       => array( __( 'Confetti text', 'callboard' ), 'text', __( 'Triple-tap the big title to release it. A lucky number, a name. Empty turns it off.', 'callboard' ), 'callboard_listening' ),
			'hearts'         => array( __( 'Mix hearts into the confetti', 'callboard' ), 'checkbox', '', 'callboard_listening' ),
			'require_signin' => array( __( 'Require a WordPress sign-in', 'callboard' ), 'checkbox', __( 'Off, anyone with the address can open the player. On, the front end uses whatever sign-in the site already has. Audio files keep their own upload addresses either way.', 'callboard' ), 'callboard_access' ),
			'require_access' => array( __( 'Only let in users with access to Callboard', 'callboard' ), 'checkbox', __( 'Needs the sign-in setting above. On, a signed-in user also needs the view_callboard capability. Listeners and anyone who can edit posts have it. Subscribers do not.', 'callboard' ), 'callboard_access' ),
		);
		foreach ( $fields as $key => list( $label, $type, $help, $section ) ) {
			add_settings_field(
				$key,
				$label,
				array( self::class, 'field' ),
				'callboard',
				$section,
				array(
					'key'       => $key,
					'type'      => $type,
					'help'      => $help,
					'label_for' => 'callboard-' . $key,
				)
			);
		}
	}

	/**
	 * Player identity settings introduction.
	 */
	public static function section_identity(): void {
		echo '<p>' . esc_html__( 'Name the player through Settings → General, then tune how it appears here.', 'callboard' ) . '</p>';
	}

	/**
	 * Listening settings introduction.
	 */
	public static function section_listening(): void {
		echo '<p>' . esc_html__( 'Choose how people install, save, and hear about new music.', 'callboard' ) . '</p>';
	}

	/**
	 * Access settings introduction.
	 */
	public static function section_access(): void {
		echo '<p>' . esc_html__( 'The player is link-accessible by default. Add WordPress authentication only when the library needs it.', 'callboard' ) . '</p>';
	}

	/**
	 * Render a settings field.
	 *
	 * @param array<string, string> $args Field args.
	 */
	public static function field( array $args ): void {
		$value = Settings::get( $args['key'] );
		$name  = Settings::OPTION . '[' . $args['key'] . ']';
		if ( 'checkbox' === $args['type'] ) {
			printf( '<input type="checkbox" id="callboard-%1$s" name="%2$s" value="1" %3$s>', esc_attr( $args['key'] ), esc_attr( $name ), checked( (bool) $value, true, false ) );
		} elseif ( 'color' === $args['type'] ) {
			printf( '<input type="text" class="regular-text code" id="callboard-%1$s" name="%2$s" value="%3$s" placeholder="#e8541e" pattern="#[0-9a-fA-F]{6}" style="border-left:14px solid %4$s">', esc_attr( $args['key'] ), esc_attr( $name ), esc_attr( (string) $value ), esc_attr( (string) $value ? (string) $value : '#e8541e' ) );
		} else {
			printf( '<input type="text" class="regular-text" id="callboard-%1$s" name="%2$s" value="%3$s">', esc_attr( $args['key'] ), esc_attr( $name ), esc_attr( (string) $value ) );
		}
		if ( $args['help'] ) {
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
		}
	}

	/**
	 * Settings page.
	 */
	public static function page_settings(): void {
		?>
		<div class="wrap callboard-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Player settings', 'callboard' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open player', 'callboard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'callboard' ); ?></span></a>
			<hr class="wp-header-end">
			<p><?php esc_html_e( 'These settings apply to the listener-facing player. Your WordPress theme is not used there.', 'callboard' ); ?></p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php" class="callboard-settings-form">
				<?php settings_fields( 'callboard' ); ?>
				<?php do_settings_sections( 'callboard' ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Redirect an administrator to setup once after activation.
	 */
	public static function maybe_redirect_to_setup(): void {
		global $pagenow;

		$activating_many = isset( $_GET['activate-multi'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag from core's bulk activation flow.
		if ( $activating_many ) {
			delete_option( Plugin::ONBOARDING_OPTION );
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( ! get_option( Plugin::ONBOARDING_OPTION ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() || 'get' !== $method || ! in_array( $pagenow, array( 'index.php', 'plugins.php' ), true ) ) {
			return;
		}
		delete_option( Plugin::ONBOARDING_OPTION );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-setup' ) );
		exit;
	}

	/**
	 * Useful destinations on the Plugins screen.
	 *
	 * @param array<string, string> $links Existing links.
	 * @return array<string, string>
	 */
	public static function plugin_action_links( array $links ): array {
		$setup    = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-setup' ) ) . '">' . esc_html__( 'Get started', 'callboard' ) . '</a>';
		$settings = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-settings' ) ) . '">' . esc_html__( 'Settings', 'callboard' ) . '</a>';
		return array_merge( array( $setup, $settings ), $links );
	}

	/**
	 * Setup dashboard.
	 */
	public static function page_setup(): void {
		delete_option( Plugin::ONBOARDING_OPTION );
		$counts    = wp_count_posts( Post_Types::SET );
		$set_count = (int) ( $counts->publish ?? 0 );
		$has_sets  = $set_count > 0;
		if ( $has_sets ) {
			/* translators: %d: number of published sets. */
			$library_status = sprintf( _n( '%d published set', '%d published sets', $set_count, 'callboard' ), $set_count );
		} else {
			$library_status = __( 'No published sets', 'callboard' );
		}
		$access_status = Settings::get( 'require_signin' ) ? __( 'WordPress sign-in required', 'callboard' ) : __( 'Anyone with the URL', 'callboard' );
		if ( Settings::get( 'require_access' ) ) {
			$access_status = __( 'Limited to Callboard users', 'callboard' );
		}
		?>
		<!--
		THESIS: Setup gives Callboard one clear listening moment without turning WordPress into a marketing page.
		OWN-WORLD: A compact dark player header leads into core WordPress buttons, links, list tables, spacing, and status language.
		STORY: Add music, check the few settings that affect listeners, and open the published player.
		FIRST VIEWPORT: A short dark header contains the page title, one sentence, the next action, and a quiet waveform; the native task table follows.
		FORM: Branded header over a native WordPress setup table; seed 4fe72c6b.
		-->
		<div class="wrap callboard-admin callboard-setup">
			<section class="callboard-setup-head">
				<div>
					<h1><?php esc_html_e( 'Set up Callboard', 'callboard' ); ?></h1>
					<p><?php esc_html_e( 'Add a set and Callboard publishes it in the player at your site address.', 'callboard' ); ?></p>
					<p class="callboard-setup-actions">
						<a class="button button-primary" href="<?php echo esc_url( $has_sets ? admin_url( 'edit.php?post_type=' . Post_Types::SET ) : admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-import' ) ); ?>"><?php echo $has_sets ? esc_html__( 'Manage sets', 'callboard' ) : esc_html__( 'Import music', 'callboard' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Post_Types::SET ) ); ?>"><?php esc_html_e( 'Add set manually', 'callboard' ); ?></a>
						<a class="callboard-open-player" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open player', 'callboard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'callboard' ); ?></span></a>
					</p>
				</div>
				<div class="callboard-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
			</section>

			<h2><?php esc_html_e( 'Before sharing the player', 'callboard' ); ?></h2>
			<table class="widefat striped callboard-checklist">
				<thead><tr><th scope="col"><?php esc_html_e( 'Item', 'callboard' ); ?></th><th scope="col"><?php esc_html_e( 'Current setting', 'callboard' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'callboard' ); ?></span></th></tr></thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Music', 'callboard' ); ?></strong><p><?php esc_html_e( 'Check track order, titles, credits, and cover art.', 'callboard' ); ?></p></td>
						<td><?php echo esc_html( $library_status ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET ) ); ?>"><?php esc_html_e( 'Manage sets', 'callboard' ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Player details', 'callboard' ); ?></strong><p><?php esc_html_e( 'The site title is the player name. Callboard settings control its accent and listening options.', 'callboard' ); ?></p></td>
						<td><?php echo esc_html( callboard_site_name() ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-settings' ) ); ?>"><?php esc_html_e( 'Player settings', 'callboard' ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Access', 'callboard' ); ?></strong><p><?php esc_html_e( 'Choose whether listeners need a WordPress account.', 'callboard' ); ?></p></td>
						<td><?php echo esc_html( $access_status ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-settings' ) . '#callboard-require_signin' ); ?>"><?php esc_html_e( 'Access settings', 'callboard' ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Offline listening', 'callboard' ); ?></strong><p><?php esc_html_e( 'Listeners can save complete sets to their device.', 'callboard' ); ?></p></td>
						<td><?php echo Settings::get( 'offline' ) ? esc_html__( 'Enabled', 'callboard' ) : esc_html__( 'Disabled', 'callboard' ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-settings' ) . '#callboard-offline' ); ?>"><?php esc_html_e( 'Listening settings', 'callboard' ); ?></a></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Import page: folder import now, plus how to fetch from YouTube.
	 */
	public static function page_import(): void {
		$dir = Importer::source_dir();
		?>
		<div class="wrap callboard-admin callboard-import">
			<h1><?php esc_html_e( 'Import sets', 'callboard' ); ?></h1>
			<p><?php esc_html_e( 'Bring complete releases or playlists into the library. Callboard keeps the audio, order, artwork, levels, and credits together.', 'callboard' ); ?></p>
			<?php if ( Exporter::available() ) : ?>
			<h2><?php esc_html_e( 'From a file', 'callboard' ); ?></h2>
			<p><?php esc_html_e( 'A .callboard file holds a whole set: the audio, the order, the levels, and anything written about it. Somebody can send you one.', 'callboard' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="callboard_import_file">
				<?php wp_nonce_field( 'callboard_import_file' ); ?>
				<p><label for="callboard-file"><?php esc_html_e( 'Set file', 'callboard' ); ?></label><br><input type="file" id="callboard-file" name="callboard_file" accept=".callboard,application/zip" required></p>
				<?php submit_button( __( 'Import the file', 'callboard' ), 'primary', 'submit', false ); ?>
			</form>
			<hr>
			<?php endif; ?>
			<h2><?php esc_html_e( 'From a folder on this server', 'callboard' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: directory path. */
					esc_html__( 'Drop a set folder (audio files plus manifest.json) into %s and import. Folders are re-imported automatically after each deploy stamp change.', 'callboard' ),
					'<code>' . esc_html( $dir ) . '</code>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="callboard_import">
				<?php wp_nonce_field( 'callboard_import' ); ?>
				<?php submit_button( __( 'Import now', 'callboard' ), 'primary', 'submit', false ); ?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'From YouTube', 'callboard' ); ?></h2>
			<p><?php esc_html_e( 'Paste a video or playlist URL. The next `wp callboard run` fetches audio only, draws the artwork, and the set appears here.', 'callboard' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="callboard-request">
				<input type="hidden" name="action" value="callboard_request">
				<?php wp_nonce_field( 'callboard_request' ); ?>
				<p><label for="callboard-url"><?php esc_html_e( 'YouTube URL', 'callboard' ); ?></label><br><input type="url" class="regular-text" id="callboard-url" name="callboard_url" required placeholder="https://www.youtube.com/playlist?list=…"></p>
				<p><label for="callboard-name"><?php esc_html_e( 'Set name', 'callboard' ); ?></label><br><input type="text" class="regular-text" id="callboard-name" name="callboard_name" required></p>
				<?php submit_button( __( 'Queue it', 'callboard' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php self::requests_table(); ?>
			<details <?php echo Fetcher::available() ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How fetching works', 'callboard' ); ?></summary>
				<?php if ( Fetcher::available() ) : ?>
					<p><?php esc_html_e( 'This server has yt-dlp. Drain the queue with WP-CLI:', 'callboard' ); ?></p>
					<pre><code>wp callboard run</code></pre>
				<?php else : ?>
					<p><?php esc_html_e( 'This host cannot run yt-dlp, which is normal for managed hosting. Fetch on a machine that has it, with the same plugin installed (wp-env works well), then deploy the finished set folder here and import.', 'callboard' ); ?></p>
					<pre><code>wp callboard fetch '&lt;youtube url&gt;' --name="Spring Show"
wp callboard run   <?php esc_html_e( '# or drain everything queued above', 'callboard' ); ?></code></pre>
				<?php endif; ?>
				<p><?php esc_html_e( 'wp callboard doctor reports what the current environment can do.', 'callboard' ); ?></p>
			</details>
			<?php do_action( 'callboard_import_page' ); ?>
		</div>
		<?php
	}

	/**
	 * Queue table on the import page.
	 */
	private static function requests_table(): void {
		$requests = Requests::all();
		if ( ! $requests ) {
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px;margin-top:12px"><thead><tr><th>' . esc_html__( 'Set', 'callboard' ) . '</th><th>' . esc_html__( 'Source', 'callboard' ) . '</th><th>' . esc_html__( 'Status', 'callboard' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $requests as $post ) {
			$r      = Requests::to_array( $post );
			$delete = wp_nonce_url( admin_url( 'admin-post.php?action=callboard_request_delete&id=' . $r['id'] ), 'callboard_request_delete_' . $r['id'] );
			printf(
				'<tr><td><strong>%1$s</strong><br><code>%2$s</code></td><td><a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></td><td><span class="callboard-status callboard-status-%5$s">%5$s</span>%6$s</td><td><a href="%7$s" class="submitdelete">%8$s</a></td></tr>',
				esc_html( $r['name'] ),
				esc_html( $r['slug'] ),
				esc_url( $r['url'] ),
				esc_html( wp_parse_url( $r['url'], PHP_URL_HOST ) . wp_parse_url( $r['url'], PHP_URL_PATH ) ),
				esc_html( $r['status'] ),
				$r['log'] ? '<br><small>' . esc_html( mb_substr( $r['log'], -160 ) ) . '</small>' : '',
				esc_url( $delete ),
				esc_html__( 'Remove', 'callboard' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Show import results.
	 */
	public static function import_notice(): void {
		$results = get_transient( 'callboard_import_notice' );
		if ( ! $results ) {
			return;
		}
		delete_transient( 'callboard_import_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( implode( ' ', (array) $results ) ) . '</p></div>';
	}

	/**
	 * Sortable + a little CSS on the Set screen.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( $screen && Post_Types::SET === $screen->post_type ) {
			wp_enqueue_style( 'callboard-admin', CALLBOARD_URL . 'assets/admin.css', array(), CALLBOARD_VERSION );
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || Post_Types::SET !== $screen?->post_type ) {
			return;
		}
		global $post;
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media( array( 'post' => $post instanceof WP_Post ? $post->ID : 0 ) );
		}
		wp_enqueue_script( 'callboard-admin', CALLBOARD_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), CALLBOARD_VERSION, true );
		wp_localize_script(
			'callboard-admin',
			'CALLBOARD_ADMIN',
			array(
				'postId'      => $post instanceof WP_Post ? $post->ID : 0,
				'frameTitle'  => __( 'Choose tracks', 'callboard' ),
				'frameButton' => __( 'Add to set', 'callboard' ),
				'titleLabel'  => __( 'Track title', 'callboard' ),
				'moveUp'      => __( 'Move up', 'callboard' ),
				'moveDown'    => __( 'Move down', 'callboard' ),
				'editMedia'   => __( 'Edit file', 'callboard' ),
				'remove'      => __( 'Remove', 'callboard' ),
				/* translators: %s: comma-separated track titles. */
				'alreadyUsed' => __( 'These files already belong to another set and were not added: %s', 'callboard' ),
			)
		);
	}

	/**
	 * An Export link beside each set on the list table.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    The row's post.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( Post_Types::SET !== $post->post_type ) {
			return $actions;
		}
		if ( 'publish' === $post->post_status ) {
			$actions['callboard_open'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( home_url( '/' . $post->post_name . '/' ) ),
				esc_html__( 'Open in player', 'callboard' ),
				esc_html__( '(opens in a new tab)', 'callboard' )
			);
		}
		if ( ! Exporter::available() ) {
			return $actions;
		}
		$base    = admin_url( 'admin-post.php?action=callboard_export&set=' . $post->ID );
		$url     = wp_nonce_url( $base, 'callboard_export_' . $post->ID );
		$car_url = wp_nonce_url( add_query_arg( 'format', 'car', $base ), 'callboard_export_' . $post->ID );

		$actions['callboard_export']     = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Export', 'callboard' ) );
		$actions['callboard_export_car'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $car_url ), esc_html__( 'Export for a car', 'callboard' ) );

		return $actions;
	}

	/**
	 * List table columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'     => $columns['cb'],
			'title'  => $columns['title'],
			'tracks' => __( 'Tracks', 'callboard' ),
			'link'   => __( 'Link', 'callboard' ),
			'date'   => $columns['date'],
		);
	}

	/**
	 * List table column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Set ID.
	 */
	public static function column( string $column, int $post_id ): void {
		if ( 'tracks' === $column ) {
			echo esc_html( (string) count( Sets::track_posts( $post_id ) ) );
		} elseif ( 'link' === $column ) {
			$url = home_url( '/' . get_post_field( 'post_name', $post_id ) . '/' );
			printf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( wp_make_link_relative( $url ) ) );
		}
	}
}
