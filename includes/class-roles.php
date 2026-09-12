<?php
/**
 * Callboard's roles and capabilities.
 *
 * @package Callboard
 * @since   2.3.0
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Director and Cast member roles, and the capabilities Callboard's post types and screens check.
 *
 * This file is loaded on its own by uninstall.php, so it must not use other Callboard classes.
 *
 * @since 2.3.0
 */
final class Roles {

	/**
	 * Role that manages calls, playlists, track notes and notifications.
	 *
	 * @since 2.3.0
	 */
	public const DIRECTOR = 'callboard_director';

	/**
	 * Role that can open the front end when the site requires it.
	 *
	 * @since 2.3.0
	 */
	public const CAST_MEMBER = 'callboard_cast_member';

	/**
	 * Capability to open the front end when the gate requires a capability.
	 *
	 * @since 2.3.0
	 */
	public const VIEW = 'view_callboard';

	/**
	 * Capability to send a notification from the Notices screen.
	 *
	 * @since 2.3.0
	 */
	public const NOTIFY = 'send_callboard_notifications';

	/**
	 * Option that stores which version of the roles a site has.
	 *
	 * @since 2.3.0
	 */
	public const OPTION = 'callboard_roles_version';

	/**
	 * Increase this when the roles or capabilities below change, so existing sites get the change.
	 *
	 * @since 2.3.0
	 */
	public const VERSION = 1;

	/**
	 * The capability type of each Callboard post type, singular and plural, as register_post_type() takes it.
	 *
	 * @since 2.3.0
	 */
	public const CAPABILITY_TYPES = array(
		'callboard_call'       => array( 'callboard_call', 'callboard_calls' ),
		'callboard_set'        => array( 'callboard_playlist', 'callboard_playlists' ),
		'callboard_subscriber' => array( 'callboard_push_subscription', 'callboard_push_subscriptions' ),
		'callboard_request'    => array( 'callboard_import_request', 'callboard_import_requests' ),
	);

	/**
	 * The primitive capabilities of the `post` capability type, which Callboard's post types used before 2.3.0.
	 *
	 * @since 2.3.0
	 */
	private const POST_CAPS = array(
		'edit_posts',
		'edit_others_posts',
		'edit_private_posts',
		'edit_published_posts',
		'publish_posts',
		'read_private_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_private_posts',
		'delete_published_posts',
	);

	/**
	 * The primitive capabilities for one post type, the same list WordPress maps meta capabilities to.
	 *
	 * @since 2.3.0
	 *
	 * @param string $post_type A key of CAPABILITY_TYPES.
	 * @return array<string, string> The `post` capability, then this post type's capability in its place.
	 */
	public static function post_type_caps( string $post_type ): array {
		$plural = self::CAPABILITY_TYPES[ $post_type ][1];
		$caps   = array();
		foreach ( self::POST_CAPS as $cap ) {
			$caps[ $cap ] = str_replace( '_posts', '_' . $plural, $cap );
		}
		return $caps;
	}

	/**
	 * The capabilities of the Director and Cast member roles.
	 *
	 * @since 2.3.0
	 *
	 * @return array<string, string[]> Role name to capabilities.
	 */
	public static function role_caps(): array {
		return array(
			self::DIRECTOR    => array_merge(
				array( 'read', 'upload_files', self::VIEW, self::NOTIFY ),
				array_values( self::post_type_caps( 'callboard_call' ) ),
				array_values( self::post_type_caps( 'callboard_set' ) )
			),
			self::CAST_MEMBER => array( 'read', self::VIEW ),
		);
	}

	/**
	 * Every capability Callboard adds.
	 *
	 * @since 2.3.0
	 *
	 * @return string[]
	 */
	public static function all_caps(): array {
		$caps = array( self::VIEW, self::NOTIFY );
		foreach ( array_keys( self::CAPABILITY_TYPES ) as $post_type ) {
			$caps = array_merge( $caps, array_values( self::post_type_caps( $post_type ) ) );
		}
		return $caps;
	}

	/**
	 * Add the roles and grant the capabilities. Safe to run more than once.
	 *
	 * Every other role gets the post type capabilities whose `post` equivalents it already has, so
	 * administrators, editors, authors and contributors can still do what they did before. Roles with
	 * `manage_options` can still send notifications, and roles with `edit_posts` can open the front end.
	 *
	 * @since 2.3.0
	 */
	public static function install(): void {
		$names = array(
			self::DIRECTOR    => __( 'Director', 'callboard' ),
			self::CAST_MEMBER => __( 'Cast member', 'callboard' ),
		);
		foreach ( self::role_caps() as $role => $caps ) {
			$object = get_role( $role );
			if ( ! $object ) {
				$object = add_role( $role, $names[ $role ] );
			}
			if ( $object ) {
				self::grant( $object, $caps );
			}
		}

		foreach ( wp_roles()->role_objects as $object ) {
			if ( isset( $names[ $object->name ] ) ) {
				continue;
			}
			$caps = array();
			foreach ( array_keys( self::CAPABILITY_TYPES ) as $post_type ) {
				foreach ( self::post_type_caps( $post_type ) as $post_cap => $cap ) {
					if ( $object->has_cap( $post_cap ) ) {
						$caps[] = $cap;
					}
				}
			}
			if ( $object->has_cap( 'manage_options' ) ) {
				$caps[] = self::NOTIFY;
			}
			if ( $object->has_cap( 'edit_posts' ) ) {
				$caps[] = self::VIEW;
			}
			self::grant( $object, $caps );
		}

		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Remove the roles, and Callboard's capabilities from every other role.
	 *
	 * @since 2.3.0
	 */
	public static function uninstall(): void {
		remove_role( self::DIRECTOR );
		remove_role( self::CAST_MEMBER );

		foreach ( wp_roles()->role_objects as $object ) {
			foreach ( self::all_caps() as $cap ) {
				if ( isset( $object->capabilities[ $cap ] ) ) {
					$object->remove_cap( $cap );
				}
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * Add capabilities a role does not have yet.
	 *
	 * @since 2.3.0
	 *
	 * @param \WP_Role $role Role.
	 * @param string[] $caps Capabilities.
	 */
	private static function grant( \WP_Role $role, array $caps ): void {
		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}
