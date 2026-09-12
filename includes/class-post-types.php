<?php
/**
 * The Set post type. Tracks are audio attachments parented to a set.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Post type registration.
 */
final class Post_Types {

	public const SET = 'callboard_set';

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Register the Set post type and image sizes.
	 */
	public static function register(): void {
		register_post_type(
			self::SET,
			array(
				'labels'          => array(
					'name'               => __( 'Sets', 'callboard' ),
					'singular_name'      => __( 'Set', 'callboard' ),
					'add_new_item'       => __( 'Add New Set', 'callboard' ),
					'edit_item'          => __( 'Edit Set', 'callboard' ),
					'featured_image'     => __( 'Cover', 'callboard' ),
					'set_featured_image' => __( 'Set cover', 'callboard' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-playlist-audio',
				'menu_position'   => 5,
				'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
				'rewrite'         => false,
				'has_archive'     => false,
				'capability_type' => Roles::CAPABILITY_TYPES[ self::SET ],
				'map_meta_cap'    => true,
			)
		);
		add_image_size( 'callboard-cover-512', 512, 512, true );
	}
}
