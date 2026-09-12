<?php
/**
 * The board: posted calls. A call is a post with a time, a place, a note, and the numbers being worked.
 * The stage manager writes it in the editor (scheduling works the way it does for any post), publishing
 * sends the push, and the home page opens with the next call at the top until it has passed.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Calls: the post type, its meta box, and the read model for the board.
 */
final class Calls {

	public const TYPE = 'callboard_call';

	private const WHEN    = '_callboard_when'; // 'Y-m-d H:i' in the site's time zone; a call without one is a plain notice.
	private const WHERE   = '_callboard_where';
	private const NUMBERS = '_callboard_numbers'; // attachment ids of the tracks being worked.
	private const NONCE   = 'callboard_call';
	private const LINGER  = 6 * HOUR_IN_SECONDS; // a call stays on the board this long after its time.
	private const LIMIT   = 5;

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'on_publish' ), 10, 3 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * The post type. Not public: it renders only on the board, through the plugin's own templates.
	 */
	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Calls', 'callboard' ),
					'singular_name' => __( 'Call', 'callboard' ),
					'add_new_item'  => __( 'Post a Call', 'callboard' ),
					'edit_item'     => __( 'Edit Call', 'callboard' ),
					'not_found'     => __( 'Nothing posted yet.', 'callboard' ),
				),
				'description'         => __( 'What is posted on the callboard: call times, notes, the numbers being worked.', 'callboard' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Post_Types::SET,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
				'rewrite'             => false,
				'has_archive'         => false,
				'capability_type'     => Roles::CAPABILITY_TYPES[ self::TYPE ],
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * The editor's title placeholder.
	 *
	 * @param string  $text Default.
	 * @param WP_Post $post Post.
	 */
	public static function title_placeholder( string $text, WP_Post $post ): string {
		return self::TYPE === $post->post_type ? __( 'What is called, e.g. Act I run', 'callboard' ) : $text;
	}

	/**
	 * Meta box registration.
	 */
	public static function meta_boxes(): void {
		add_meta_box( 'callboard-call', __( 'The call', 'callboard' ), array( self::class, 'box' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * When, where, and which numbers.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box( WP_Post $post ): void {
		$when    = (string) get_post_meta( $post->ID, self::WHEN, true );
		$where   = (string) get_post_meta( $post->ID, self::WHERE, true );
		$numbers = array_map( 'intval', (array) get_post_meta( $post->ID, self::NUMBERS, true ) );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="callboard-when"><strong><?php esc_html_e( 'When', 'callboard' ); ?></strong></label><br>
			<input type="datetime-local" id="callboard-when" name="callboard_when" value="<?php echo esc_attr( $when ? str_replace( ' ', 'T', $when ) : '' ); ?>">
			<span class="description"><?php esc_html_e( 'Leave empty for a notice with no time.', 'callboard' ); ?></span>
		</p>
		<p>
			<label for="callboard-where"><strong><?php esc_html_e( 'Where', 'callboard' ); ?></strong></label><br>
			<input type="text" id="callboard-where" name="callboard_where" class="regular-text" value="<?php echo esc_attr( $where ); ?>" placeholder="<?php esc_attr_e( 'Room, stage, address', 'callboard' ); ?>">
		</p>
		<fieldset class="callboard-numbers">
			<legend><strong><?php esc_html_e( 'Numbers being worked', 'callboard' ); ?></strong></legend>
			<p class="description"><?php esc_html_e( 'Each one becomes a tap on the board that starts the track.', 'callboard' ); ?></p>
			<?php foreach ( Sets::all() as $set ) : ?>
				<?php
				if ( ! $set['tracks'] ) {
					continue;
				}
				?>
				<details <?php echo array_intersect( array_column( $set['tracks'], 'id' ), $numbers ) ? 'open' : ''; ?>>
					<summary><?php echo esc_html( $set['name'] ); ?></summary>
					<?php foreach ( $set['tracks'] as $track ) : ?>
						<label style="display:block;margin:2px 0 2px 12px">
							<input type="checkbox" name="callboard_numbers[]" value="<?php echo (int) $track['id']; ?>" <?php checked( in_array( (int) $track['id'], $numbers, true ) ); ?>>
							<?php echo esc_html( str_pad( (string) $track['index'], 2, '0', STR_PAD_LEFT ) . '  ' . $track['title'] ); ?>
						</label>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Save the meta box.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$when = sanitize_text_field( wp_unslash( $_POST['callboard_when'] ?? '' ) );
		$dt   = $when ? date_create_immutable_from_format( 'Y-m-d\TH:i', $when, wp_timezone() ) : false;
		if ( $dt ) {
			update_post_meta( $post_id, self::WHEN, $dt->format( 'Y-m-d H:i' ) );
		} else {
			delete_post_meta( $post_id, self::WHEN );
		}
		$where = sanitize_text_field( wp_unslash( $_POST['callboard_where'] ?? '' ) );
		if ( '' !== $where ) {
			update_post_meta( $post_id, self::WHERE, $where );
		} else {
			delete_post_meta( $post_id, self::WHERE );
		}
		$numbers = array_values(
			array_filter(
				array_map( 'intval', (array) ( $_POST['callboard_numbers'] ?? array() ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is cast to int.
				static fn( int $id ) => $id > 0 && 'attachment' === get_post_type( $id )
			)
		);
		if ( $numbers ) {
			update_post_meta( $post_id, self::NUMBERS, $numbers );
		} else {
			delete_post_meta( $post_id, self::NUMBERS );
		}
	}

	/**
	 * Publishing a call sends it to the cast.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( self::TYPE !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status || ! Settings::get( 'notify_calls' ) ) {
			return;
		}
		$call = self::build( $post );
		$line = array_filter( array( $call['when_text'], $call['where'] ) );
		Push::send(
			$post->post_title,
			$line ? implode( ' · ', $line ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 18 ),
			home_url( '/' )
		);
		/**
		 * A call went up on the board.
		 *
		 * @param WP_Post              $post The call.
		 * @param array<string, mixed> $call Calls::build() for it.
		 */
		do_action( 'callboard_call_published', $post, $call );
	}

	/**
	 * What the board shows: calls still to come, soonest first, then notices without a time, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function board(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$now   = time();
		$timed = array();
		$plain = array();
		foreach ( $posts as $post ) {
			$call = self::build( $post );
			if ( $call['when'] ) {
				if ( $call['when'] + self::LINGER >= $now ) {
					$timed[] = $call;
				}
			} else {
				$plain[] = $call;
			}
		}
		usort( $timed, static fn( array $a, array $b ) => $a['when'] <=> $b['when'] );
		if ( $timed ) {
			$timed[0]['is_next'] = true;
		}
		/**
		 * What the board shows. Each call is the array Calls::build() returns; the first timed one carries is_next.
		 *
		 * @param array<int, array<string, mixed>> $calls Calls, soonest first, then undated notices.
		 */
		return apply_filters( 'callboard_board', array_slice( array_merge( $timed, $plain ), 0, self::LIMIT ) );
	}

	/**
	 * One call, ready for the template.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function build( WP_Post $post ): array {
		$when    = (string) get_post_meta( $post->ID, self::WHEN, true );
		$ts      = $when ? (int) strtotime( get_gmt_from_date( $when . ':00' ) . ' UTC' ) : 0;
		$numbers = array();
		foreach ( array_map( 'intval', (array) get_post_meta( $post->ID, self::NUMBERS, true ) ) as $id ) {
			foreach ( Sets::all() as $set ) {
				foreach ( $set['tracks'] as $track ) {
					if ( (int) $track['id'] === $id ) {
						$numbers[] = array(
							'slug'  => $set['slug'],
							'set'   => $set['name'],
							'index' => $track['index'] - 1,
							'title' => $track['title'],
						);
					}
				}
			}
		}
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'body'      => $post->post_content ? wp_kses_post( wpautop( $post->post_content ) ) : '',
			'when'      => $ts,
			'when_iso'  => $ts ? gmdate( 'c', $ts ) : '',
			'when_text' => $ts ? self::when_text( $ts ) : '',
			'relative'  => $ts ? self::relative( $ts ) : '',
			'where'     => (string) get_post_meta( $post->ID, self::WHERE, true ),
			'numbers'   => $numbers,
			/* translators: %s: how long ago, e.g. 2 hours. */
			'posted'    => sprintf( __( 'Posted %s ago', 'callboard' ), human_time_diff( get_post_time( 'U', true, $post ), time() ) ),
			'is_next'   => false,
		);
	}

	/**
	 * "Thu, Sep 11 · 7:00 PM", in the site's time zone and formats.
	 *
	 * @param int $ts Unix time.
	 */
	private static function when_text( int $ts ): string {
		return wp_date( 'D, M j', $ts ) . ' · ' . wp_date( (string) get_option( 'time_format', 'g:i a' ), $ts );
	}

	/**
	 * "in 2 days", "in 40 mins", "now", "started 1 hour ago". The script refreshes this on the client.
	 *
	 * @param int $ts Unix time.
	 */
	private static function relative( int $ts ): string {
		$now = time();
		if ( abs( $ts - $now ) < 5 * MINUTE_IN_SECONDS ) {
			return __( 'now', 'callboard' );
		}
		if ( $ts > $now ) {
			/* translators: %s: how long from now, e.g. 2 days. */
			return sprintf( __( 'in %s', 'callboard' ), human_time_diff( $now, $ts ) );
		}
		/* translators: %s: how long ago, e.g. 1 hour. */
		return sprintf( __( 'started %s ago', 'callboard' ), human_time_diff( $ts, $now ) );
	}

	/**
	 * A "When" column in the admin list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['callboard_when'] = __( 'When', 'callboard' );
			}
		}
		return $out;
	}

	/**
	 * The "When" cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public static function column( string $column, int $post_id ): void {
		if ( 'callboard_when' !== $column ) {
			return;
		}
		$call = self::build( get_post( $post_id ) );
		echo esc_html( $call['when'] ? $call['when_text'] . ' (' . $call['relative'] . ')' : __( 'Notice, no time', 'callboard' ) );
	}
}
