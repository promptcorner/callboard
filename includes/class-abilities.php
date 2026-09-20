<?php
/**
 * Callboard's abilities for the WordPress Abilities API.
 *
 * @package Callboard
 * @since   2.5.0
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers three abilities: list playlists, list upcoming calls, and post a call.
 *
 * Each one checks the same capability wp-admin checks for that screen, and runs the same code the
 * front end and the editor use. Other plugins register their own abilities under their own namespace.
 *
 * @since 2.5.0
 */
final class Abilities {

	/**
	 * The ability category every Callboard ability belongs to.
	 *
	 * @since 2.5.0
	 */
	public const CATEGORY = 'callboard';

	/**
	 * Hook registration. Does nothing before WordPress 6.9, which added the Abilities API.
	 *
	 * @since 2.5.0
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/**
	 * The Callboard category.
	 *
	 * @since 2.5.0
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Callboard', 'callboard' ),
				'description' => __( 'Music sets, tracks, and optional rehearsal calls.', 'callboard' ),
			)
		);
	}

	/**
	 * The abilities.
	 *
	 * @since 2.5.0
	 */
	public static function register(): void {
		$read = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'callboard/list-playlists',
			array(
				'label'               => __( 'List playlists', 'callboard' ),
				'description'         => __( 'Lists the published playlists with their tracks. Track ids are what a posted call takes as its numbers.', 'callboard' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'playlists' => array(
							'type'  => 'array',
							'items' => self::playlist_schema(),
						),
					),
					'required'   => array( 'playlists' ),
				),
				'execute_callback'    => array( self::class, 'list_playlists' ),
				'permission_callback' => array( self::class, 'can_list_playlists' ),
				'meta'                => $read,
			)
		);

		wp_register_ability(
			'callboard/list-upcoming-calls',
			array(
				'label'               => __( 'List upcoming calls', 'callboard' ),
				'description'         => __( 'Lists what the board shows: calls still to come, soonest first, then notices without a time, newest first.', 'callboard' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'calls' => array(
							'type'  => 'array',
							'items' => self::call_schema(),
						),
					),
					'required'   => array( 'calls' ),
				),
				'execute_callback'    => array( self::class, 'list_upcoming_calls' ),
				'permission_callback' => array( self::class, 'can_list_calls' ),
				'meta'                => $read,
			)
		);

		wp_register_ability(
			'callboard/post-call',
			array(
				'label'               => __( 'Post a call', 'callboard' ),
				'description'         => __( 'Publishes a call to the board, which notifies the cast when call notifications are on.', 'callboard' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'What is called, e.g. Act I run.', 'callboard' ),
						),
						'note'    => array(
							'type'        => 'string',
							'description' => __( 'The note under the call.', 'callboard' ),
						),
						'when'    => array(
							'type'        => 'string',
							'pattern'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$',
							'description' => __( 'When the call is, as YYYY-MM-DDTHH:MM in the site\'s time zone. Leave it out for a notice with no time.', 'callboard' ),
						),
						'where'   => array(
							'type'        => 'string',
							'description' => __( 'Room, stage, address.', 'callboard' ),
						),
						'numbers' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Ids of the tracks being worked, from callboard/list-playlists.', 'callboard' ),
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::call_schema(),
				'execute_callback'    => array( self::class, 'post_call' ),
				'permission_callback' => array( self::class, 'can_post_call' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Whether the current user can see the playlists screen in wp-admin.
	 *
	 * @since 2.5.0
	 */
	public static function can_list_playlists(): bool {
		return self::can( Post_Types::SET, 'edit_posts' );
	}

	/**
	 * Whether the current user can see the calls screen in wp-admin.
	 *
	 * @since 2.5.0
	 */
	public static function can_list_calls(): bool {
		return self::can( Calls::TYPE, 'edit_posts' );
	}

	/**
	 * Whether the current user can publish a call in wp-admin.
	 *
	 * @since 2.5.0
	 */
	public static function can_post_call(): bool {
		return self::can( Calls::TYPE, 'publish_posts' );
	}

	/**
	 * The published playlists and their tracks.
	 *
	 * @since 2.5.0
	 *
	 * @return array{playlists: array<int, array<string, mixed>>}
	 */
	public static function list_playlists(): array {
		$playlists = array();
		foreach ( Sets::all() as $set ) {
			$playlists[] = array(
				'id'     => (int) $set['id'],
				'slug'   => (string) $set['slug'],
				'name'   => (string) $set['name'],
				'url'    => home_url( '/' . $set['slug'] . '/' ),
				'tracks' => array_map(
					static fn( array $track ): array => array(
						'id'    => (int) $track['id'],
						'index' => (int) $track['index'],
						'title' => (string) $track['title'],
					),
					$set['tracks']
				),
			);
		}
		return array( 'playlists' => $playlists );
	}

	/**
	 * The calls the board shows.
	 *
	 * @since 2.5.0
	 *
	 * @return array{calls: array<int, array<string, mixed>>}
	 */
	public static function list_upcoming_calls(): array {
		return array( 'calls' => array_values( Calls::board() ) );
	}

	/**
	 * Publish a call.
	 *
	 * @since 2.5.0
	 *
	 * @param array<string, mixed> $input Validated against the input schema.
	 * @return array<string, mixed>|\WP_Error The call as the board shows it.
	 */
	public static function post_call( array $input ): array|\WP_Error {
		$id = Calls::post(
			(string) $input['title'],
			(string) ( $input['note'] ?? '' ),
			(string) ( $input['when'] ?? '' ),
			(string) ( $input['where'] ?? '' ),
			(array) ( $input['numbers'] ?? array() )
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return Calls::build( get_post( $id ) );
	}

	/**
	 * Whether the current user has one of a post type's capabilities, as wp-admin checks it.
	 *
	 * @param string $post_type Post type.
	 * @param string $cap       A key of the post type's `cap` object, e.g. `edit_posts`.
	 */
	private static function can( string $post_type, string $cap ): bool {
		$type = get_post_type_object( $post_type );
		return $type && current_user_can( $type->cap->{$cap} );
	}

	/**
	 * Input schema for an ability that takes nothing.
	 *
	 * @return array<string, mixed>
	 */
	private static function no_input(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * One playlist in callboard/list-playlists.
	 *
	 * @return array<string, mixed>
	 */
	private static function playlist_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'slug'   => array( 'type' => 'string' ),
				'name'   => array( 'type' => 'string' ),
				'url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'tracks' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array( 'type' => 'integer' ),
							'index' => array(
								'type'        => 'integer',
								'description' => __( 'Position in the playlist, starting at 1.', 'callboard' ),
							),
							'title' => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'index', 'title' ),
					),
				),
			),
			'required'   => array( 'id', 'slug', 'name', 'url', 'tracks' ),
		);
	}

	/**
	 * One call, as Calls::build() returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function call_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'body'      => array(
					'type'        => 'string',
					'description' => __( 'The note, as HTML.', 'callboard' ),
				),
				'when'      => array(
					'type'        => 'integer',
					'description' => __( 'Unix time of the call, or 0 for a notice with no time.', 'callboard' ),
				),
				'when_iso'  => array( 'type' => 'string' ),
				'when_text' => array( 'type' => 'string' ),
				'relative'  => array( 'type' => 'string' ),
				'where'     => array( 'type' => 'string' ),
				'numbers'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'  => array( 'type' => 'string' ),
							'set'   => array(
								'type'        => 'string',
								'description' => __( 'The playlist name.', 'callboard' ),
							),
							'index' => array(
								'type'        => 'integer',
								'description' => __( 'Position in the playlist, starting at 0.', 'callboard' ),
							),
							'title' => array( 'type' => 'string' ),
						),
						'required'   => array( 'slug', 'set', 'index', 'title' ),
					),
				),
				'posted'    => array( 'type' => 'string' ),
				'is_next'   => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'id', 'title', 'body', 'when', 'when_iso', 'when_text', 'relative', 'where', 'numbers', 'posted', 'is_next' ),
		);
	}
}
