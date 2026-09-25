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
 * Registers one ability: list playlists.
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
				'description' => __( 'Music sets and their tracks.', 'callboard' ),
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
				'description'         => __( 'Lists the published playlists with their tracks.', 'callboard' ),
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
}
