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
		add_action( 'admin_menu', array( self::class, 'new_call_menu' ), 11 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'import_notice' ) );
		add_filter( 'manage_' . Post_Types::SET . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . Post_Types::SET . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
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
		if ( ! $tracks ) {
			echo '<p>' . esc_html__( 'No audio yet. Upload audio files to the Media Library and attach them to this set, or import a folder.', 'callboard' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Drag to reorder. Titles are what the cast sees. Open a track for its tempo and director notes.', 'callboard' ) . '</p>';
		echo '<ol class="callboard-tracks" id="callboard-tracks">';
		foreach ( $tracks as $track ) {
			$meta  = (array) wp_get_attachment_metadata( $track->ID );
			$bpm   = (int) get_post_meta( $track->ID, '_callboard_bpm', true );
			$notes = get_post_meta( $track->ID, '_callboard_notes', true );
			$lines = array();
			foreach ( is_array( $notes ) ? $notes : array() as $n ) {
				$lines[] = callboard_fmt( (float) $n['t'] ) . ' ' . $n['text'];
			}
			printf(
				'<li data-id="%1$d"><div class="callboard-track-row"><span class="dashicons dashicons-menu" aria-hidden="true"></span><input type="hidden" name="callboard_order[]" value="%1$d"><label class="screen-reader-text" for="callboard-title-%1$d">%6$s</label><input type="text" class="regular-text" id="callboard-title-%1$d" name="callboard_title[%1$d]" value="%2$s"><span class="callboard-len">%3$s</span><button type="button" class="button-link callboard-move" data-dir="-1" aria-label="%7$s">&uarr;</button><button type="button" class="button-link callboard-move" data-dir="1" aria-label="%8$s">&darr;</button><a href="%4$s">%5$s</a></div>'
				. '<details class="callboard-track-more"%9$s><summary>%10$s</summary><p><label for="callboard-bpm-%1$d">%11$s</label> <input type="number" id="callboard-bpm-%1$d" name="callboard_bpm[%1$d]" value="%12$s" min="30" max="300" step="1" class="small-text"> <span class="description">%13$s</span></p>'
				. '<p><label for="callboard-notes-%1$d">%14$s</label><br><textarea id="callboard-notes-%1$d" name="callboard_notes[%1$d]" rows="3" class="large-text code" placeholder="1:32 Softer here">%15$s</textarea><span class="description">%16$s</span></p></details></li>',
				(int) $track->ID,
				esc_attr( $track->post_title ),
				esc_html( $meta['length_formatted'] ?? '' ),
				esc_url( get_edit_post_link( $track->ID ) ),
				esc_html__( 'Media', 'callboard' ),
				/* translators: %s: track title. */
				esc_attr( sprintf( __( 'Title for %s', 'callboard' ), $track->post_title ) ),
				esc_attr__( 'Move up', 'callboard' ),
				esc_attr__( 'Move down', 'callboard' ),
				$bpm || $lines ? ' open' : '',
				esc_html__( 'Tempo and notes', 'callboard' ),
				esc_html__( 'Tempo (BPM)', 'callboard' ),
				esc_attr( $bpm ? (string) $bpm : '' ),
				esc_html__( 'With a tempo, Play from the top counts in four beats.', 'callboard' ),
				esc_html__( 'Director notes', 'callboard' ),
				esc_textarea( implode( "\n", $lines ) ),
				esc_html__( 'One per line: a time, then the note. Each note is dated the day it is first saved and pinned at that time on the player.', 'callboard' )
			);
		}
		echo '</ol>';
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

		echo '<select name="callboard_palette" style="width:100%">';
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
		$order  = array_map( 'intval', (array) ( $_POST['callboard_order'] ?? array() ) );
		$titles = isset( $_POST['callboard_title'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['callboard_title'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per element.
		foreach ( $order as $i => $track_id ) {
			if ( (int) get_post_field( 'post_parent', $track_id ) !== $post_id ) {
				continue;
			}
			$update = array(
				'ID'         => $track_id,
				'menu_order' => $i + 1,
			);
			if ( ! empty( $titles[ $track_id ] ) ) {
				$update['post_title'] = $titles[ $track_id ];
			}
			wp_update_post( $update );
			$bpms = array_map( 'intval', (array) ( $_POST['callboard_bpm'] ?? array() ) );
			update_post_meta( $track_id, '_callboard_bpm', Importer::clamp_bpm( (int) ( $bpms[ $track_id ] ? $bpms[ $track_id ] : 0 ) ) );
			$raw = isset( $_POST['callboard_notes'][ $track_id ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['callboard_notes'][ $track_id ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized here.
			update_post_meta( $track_id, '_callboard_notes', self::parse_notes( $raw, (array) get_post_meta( $track_id, '_callboard_notes', true ) ) );
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
	 * "m:ss text" lines into dated notes. A line that already existed keeps its date.
	 *
	 * @param string            $raw      Textarea content.
	 * @param array<int, mixed> $existing Stored notes.
	 * @return array<int, array{t: float, text: string, date: string}>
	 */
	public static function parse_notes( string $raw, array $existing ): array {
		$dates = array();
		foreach ( $existing as $n ) {
			if ( is_array( $n ) && isset( $n['t'], $n['text'] ) ) {
				$dates[ (string) (float) $n['t'] . '|' . $n['text'] ] = (string) ( $n['date'] ?? '' );
			}
		}
		$notes = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			if ( ! preg_match( '/^\s*(?:(\d+):)?(\d+(?:\.\d+)?)\s+(.+?)\s*$/', $line, $m ) ) {
				continue;
			}
			$t       = ( (int) $m[1] ) * 60 + (float) $m[2];
			$text    = sanitize_text_field( $m[3] );
			$notes[] = array(
				't'    => $t,
				'text' => $text,
				'date' => $dates[ (string) $t . '|' . $text ] ?? '',
			);
		}
		return Importer::sanitize_notes( $notes );
	}

	/**
	 * Settings and Import submenus.
	 */
	public static function menu(): void {
		$parent = 'edit.php?post_type=' . Post_Types::SET;
		add_submenu_page( $parent, __( 'Import', 'callboard' ), __( 'Import', 'callboard' ), 'manage_options', 'callboard-import', array( self::class, 'page_import' ) );
		add_submenu_page( $parent, __( 'Notices', 'callboard' ), __( 'Notices', 'callboard' ), Roles::NOTIFY, 'callboard-notices', array( self::class, 'page_notices' ) );
		add_submenu_page( $parent, __( 'Callboard Settings', 'callboard' ), __( 'Settings', 'callboard' ), 'manage_options', 'callboard-settings', array( self::class, 'page_settings' ) );
	}

	/**
	 * A "Post a Call" submenu for users who can post calls but not blog posts, such as directors.
	 *
	 * Calls are shown under the Sets menu. WordPress then refuses post-new.php for that post type to anyone
	 * without `edit_posts`, unless the page is in a menu (core ticket #22895).
	 *
	 * @since 2.3.0
	 */
	public static function new_call_menu(): void {
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}
		$type = get_post_type_object( Calls::TYPE );
		if ( $type ) {
			add_submenu_page( 'edit.php?post_type=' . Post_Types::SET, $type->labels->add_new_item, $type->labels->add_new_item, $type->cap->create_posts, 'post-new.php?post_type=' . Calls::TYPE );
		}
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
		add_settings_section( 'callboard_main', '', '__return_false', 'callboard' );
		$fields = array(
			'tagline'         => array( __( 'Tagline', 'callboard' ), 'text', __( 'Shown under the title and in link previews.', 'callboard' ) ),
			'footer_note'     => array( __( 'Home page footer', 'callboard' ), 'text', __( 'A short disclosure, e.g. "For rehearsal use only."', 'callboard' ) ),
			'badge'           => array( __( 'Badge on the playing track', 'callboard' ), 'text', __( 'An emoji, or leave empty.', 'callboard' ) ),
			'accent'          => array( __( 'Accent colour', 'callboard' ), 'color', __( 'A hex colour such as #3b82f6 for the played wave, the filament, and the marks. Empty keeps the house orange.', 'callboard' ) ),
			'confetti'        => array( __( 'Confetti text', 'callboard' ), 'text', __( 'Triple-tap the big title to release it. A lucky number, a name. Empty turns it off.', 'callboard' ) ),
			'hearts'          => array( __( 'Mix hearts into the confetti', 'callboard' ), 'checkbox', '' ),
			'show_hint'       => array( __( 'Show the "Add to Home Screen" hint on iPhone', 'callboard' ), 'checkbox', '' ),
			'offline'         => array( __( 'Offer "Save offline"', 'callboard' ), 'checkbox', '' ),
			'push'            => array( __( 'Offer notifications', 'callboard' ), 'checkbox', __( 'A bell on the home page lets the cast opt in. On iPhone this needs the app added to the Home Screen.', 'callboard' ) ),
			'notify_new_sets' => array( __( 'Notify when a set is published', 'callboard' ), 'checkbox', '' ),
			'notify_calls'    => array( __( 'Notify when a call is posted', 'callboard' ), 'checkbox', __( 'Calls live under Sets. Publishing one, or a scheduled one going live, sends it to the cast.', 'callboard' ) ),
			'count_in'        => array( __( 'Count in tracks that have a tempo', 'callboard' ), 'checkbox', __( 'Four clicks at the marked tempo before a track starts from the top, so singers come in on the beat. Off, the tempo still shows on the track.', 'callboard' ) ),
			'practice'        => array( __( 'Count practice', 'callboard' ), 'checkbox', __( 'Counts how many times each track is opened, how many loops are set on it, and how many minutes it plays. Counts are anonymous: no names, accounts, IP addresses or cookies are stored. They are grouped by hour and shown on each call in the editor.', 'callboard' ) ),
			'require_signin'  => array( __( 'Require a WordPress sign-in', 'callboard' ), 'checkbox', __( 'Off, anyone with the address can open the site, which is usually what a cast wants. On, the front end asks for a signed-in user, so whatever sign-in the site already has — Apple, Google, a membership plugin, passkeys through Two Factor — guards it too. Audio files keep their own upload addresses either way.', 'callboard' ) ),
			'require_access'  => array( __( 'Only let in users with access to Callboard', 'callboard' ), 'checkbox', __( 'Needs the sign-in setting above. On, a signed-in user also needs the view_callboard capability. Cast members, directors and anyone who can edit posts have it. Subscribers do not.', 'callboard' ) ),
		);
		foreach ( $fields as $key => list( $label, $type, $help ) ) {
			add_settings_field(
				$key,
				$label,
				array( self::class, 'field' ),
				'callboard',
				'callboard_main',
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Callboard', 'callboard' ); ?></h1>
			<p><?php esc_html_e( 'The site title (Settings → General) is the app name and the home page heading.', 'callboard' ); ?></p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'callboard' ); ?>
				<?php do_settings_sections( 'callboard' ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Notices: push a message to everyone who opted in.
	 */
	public static function page_notices(): void {
		$count = Push::count();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Notices', 'callboard' ); ?></h1>
			<?php if ( ! Push::available() ) : ?>
				<p><?php esc_html_e( 'Push is not available on this server (needs OpenSSL and GMP or BCMath).', 'callboard' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					/* translators: %d: subscriber count. */
					echo esc_html( sprintf( _n( '%d device is subscribed.', '%d devices are subscribed.', $count, 'callboard' ), $count ) );
					?>
					<?php esc_html_e( 'A notice goes to all of them and opens the link when tapped.', 'callboard' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px">
					<input type="hidden" name="action" value="callboard_notify">
					<?php wp_nonce_field( 'callboard_notify' ); ?>
					<p><label for="callboard-ntitle"><?php esc_html_e( 'Title', 'callboard' ); ?></label><br><input type="text" class="large-text" id="callboard-ntitle" name="callboard_title" placeholder="<?php echo esc_attr( callboard_site_name() ); ?>"></p>
					<p><label for="callboard-nbody"><?php esc_html_e( 'Message', 'callboard' ); ?></label><br><textarea class="large-text" rows="3" id="callboard-nbody" name="callboard_body" required></textarea></p>
					<p><label for="callboard-nlink"><?php esc_html_e( 'Link (optional)', 'callboard' ); ?></label><br><input type="url" class="large-text" id="callboard-nlink" name="callboard_link" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></p>
					<?php submit_button( __( 'Send to the cast', 'callboard' ), 'primary', 'submit', false, $count ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Import page: folder import now, plus how to fetch from YouTube.
	 */
	public static function page_import(): void {
		$dir = Importer::source_dir();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import sets', 'callboard' ); ?></h1>
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
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || get_current_screen()?->post_type !== Post_Types::SET ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_add_inline_script( 'jquery-ui-sortable', 'jQuery(function($){$("#callboard-tracks").sortable({handle:".dashicons-menu"});$("#callboard-tracks").on("click",".callboard-move",function(){var li=$(this).closest("li"),dir=+$(this).data("dir");if(dir<0){li.prev().before(li);}else{li.next().after(li);}$(this).focus();});});' );
		wp_add_inline_style( 'wp-admin', '.callboard-move{padding:0 6px;font-size:16px;line-height:1}.callboard-tracks{margin:0}.callboard-tracks li{padding:6px 0;border-bottom:1px solid #dcdcde}.callboard-track-row{display:flex;align-items:center;gap:10px}.callboard-track-more{margin:4px 0 0 34px}.callboard-track-more summary{cursor:pointer;color:#2271b1}.callboard-track-more p{margin:8px 0}.callboard-tracks .dashicons-menu{cursor:grab;color:#787c82}.callboard-tracks input[type=text]{flex:1}.callboard-len{color:#646970;font-variant-numeric:tabular-nums;min-width:3em}' );
	}

	/**
	 * An Export link beside each set on the list table.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    The row's post.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( Post_Types::SET !== $post->post_type || ! Exporter::available() ) {
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
