<?php
/**
 * Callboard's role and capability.
 *
 * @package Callboard
 * @since   2.3.0
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Listener role and the capability the sign-in gate can ask for.
 *
 * This file is loaded on its own by uninstall.php, so it must not use other Callboard classes.
 *
 * @since 2.3.0
 * @since 3.0.0 The Director role and the post type capabilities are gone. Sets and import
 *              requests use the `post` capabilities, as they did before 2.3.0.
 */
final class Roles {

	/**
	 * Role that can open the front end when the site requires it. Called Cast member before 3.0.0,
	 * and the name stays so users who had the role keep it.
	 *
	 * @since 3.0.0
	 */
	public const LISTENER = 'callboard_cast_member';

	/**
	 * Capability to open the front end when the gate requires a capability.
	 *
	 * @since 2.3.0
	 */
	public const VIEW = 'view_callboard';

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
	public const VERSION = 3;

	/**
	 * The Director role, which 3.0.0 removed. Its users become authors.
	 *
	 * @since 3.0.0
	 */
	private const DIRECTOR = 'callboard_director';

	/**
	 * Capabilities of removed features, taken off every role on upgrade: the notify capability and
	 * the post type capabilities of calls, push subscriptions, playlists and import requests.
	 *
	 * @since 3.0.0
	 */
	private const RETIRED_CAPS = '/^(send_callboard_notifications|[a-z_]+_callboard_(calls|push_subscriptions|playlists|import_requests))$/';

	/**
	 * Add the Listener role and grant the view capability. Safe to run more than once.
	 *
	 * Roles with `edit_posts` can open the front end, so whoever edits the sets can see them.
	 *
	 * @since 2.3.0
	 */
	public static function install(): void {
		// Removing and adding again renames a role that exists already. Users keep it, since each
		// user stores the role's slug.
		remove_role( self::LISTENER );
		add_role(
			self::LISTENER,
			__( 'Listener', 'callboard' ),
			array(
				'read'     => true,
				self::VIEW => true,
			)
		);

		foreach ( wp_roles()->role_objects as $object ) {
			if ( $object->has_cap( 'edit_posts' ) && ! $object->has_cap( self::VIEW ) ) {
				$object->add_cap( self::VIEW );
			}
		}

		self::remove_director();
		self::remove_retired();
		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Move the Director role's users to Author, the nearest core role that can upload and publish,
	 * then remove the role.
	 *
	 * @since 3.0.0
	 */
	private static function remove_director(): void {
		if ( ! get_role( self::DIRECTOR ) ) {
			return;
		}
		foreach ( get_users( array( 'role' => self::DIRECTOR ) ) as $user ) {
			$user->remove_role( self::DIRECTOR );
			$user->add_role( 'author' );
		}
		remove_role( self::DIRECTOR );
	}

	/**
	 * Take the capabilities of removed features off every role.
	 *
	 * @since 3.0.0
	 */
	private static function remove_retired(): void {
		foreach ( wp_roles()->role_objects as $object ) {
			foreach ( array_keys( $object->capabilities ) as $cap ) {
				if ( preg_match( self::RETIRED_CAPS, $cap ) ) {
					$object->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove the role, and the view capability from every other role.
	 *
	 * @since 2.3.0
	 */
	public static function uninstall(): void {
		remove_role( self::LISTENER );
		remove_role( self::DIRECTOR );

		foreach ( wp_roles()->role_objects as $object ) {
			if ( isset( $object->capabilities[ self::VIEW ] ) ) {
				$object->remove_cap( self::VIEW );
			}
		}
		self::remove_retired();
		delete_option( self::OPTION );
	}
}
