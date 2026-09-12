<?php
/**
 * Roles and capabilities.
 *
 * @package Callboard
 */

use Callboard\Calls;
use Callboard\Plugin;
use Callboard\Post_Types;
use Callboard\Roles;

/**
 * @covers \Callboard\Roles
 * @covers \Callboard\Plugin
 */
class Test_Callboard_Roles extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		// Role changes are rolled back in the database, but WP_Roles keeps its own copy in memory.
		self::flush_cache();
		wp_roles()->for_site();
	}

	private function assert_installed(): void {
		$this->assertInstanceOf( WP_Role::class, get_role( Roles::DIRECTOR ) );
		$this->assertInstanceOf( WP_Role::class, get_role( Roles::CAST_MEMBER ) );

		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );
		foreach ( array_keys( Roles::CAPABILITY_TYPES ) as $post_type ) {
			foreach ( Roles::post_type_caps( $post_type ) as $cap ) {
				$this->assertTrue( $admin->has_cap( $cap ), "administrator should have {$cap}" );
				$this->assertTrue( $editor->has_cap( $cap ), "editor should have {$cap}" );
			}
		}
		$this->assertTrue( $admin->has_cap( Roles::NOTIFY ) );
		$this->assertTrue( $admin->has_cap( Roles::VIEW ) );
		$this->assertTrue( $editor->has_cap( Roles::VIEW ) );
		$this->assertFalse( $editor->has_cap( Roles::NOTIFY ), 'editors could not send notifications before' );

		// Authors and contributors keep what `post` gave them, and nothing more.
		$this->assertTrue( get_role( 'author' )->has_cap( 'publish_callboard_calls' ) );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_others_callboard_calls' ) );
		$this->assertTrue( get_role( 'contributor' )->has_cap( 'edit_callboard_calls' ) );
		$this->assertFalse( get_role( 'contributor' )->has_cap( 'publish_callboard_calls' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Roles::VIEW ) );
	}

	public function test_activation_adds_the_roles_and_capabilities(): void {
		Roles::uninstall();
		$this->assertNull( get_role( Roles::DIRECTOR ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'edit_callboard_calls' ) );

		Plugin::activate();

		$this->assert_installed();
	}

	public function test_updating_the_plugin_adds_the_roles_and_capabilities(): void {
		Roles::uninstall();
		update_option( 'callboard_version', '2.1.0' );

		Plugin::maybe_upgrade();

		$this->assert_installed();
	}

	public function test_a_site_already_on_this_version_still_gets_the_roles(): void {
		Roles::uninstall();
		update_option( 'callboard_version', CALLBOARD_VERSION );

		Plugin::maybe_upgrade();

		$this->assert_installed();
	}

	public function test_the_post_types_use_their_own_capabilities(): void {
		foreach ( Roles::CAPABILITY_TYPES as $post_type => $type ) {
			$object = get_post_type_object( $post_type );
			$this->assertNotNull( $object, "{$post_type} should be registered" );
			$this->assertSame( "edit_{$type[1]}", $object->cap->edit_posts );

			// Everything WordPress maps this post type to, minus `read` and the meta capabilities.
			$primitive = array_unique( array_diff( (array) $object->cap, array( 'read', "read_{$type[0]}", "edit_{$type[0]}", "delete_{$type[0]}" ) ) );
			$this->assertEqualsCanonicalizing( array_values( Roles::post_type_caps( $post_type ) ), array_values( $primitive ) );
		}
	}

	public function test_a_director_can_edit_a_call_and_a_subscriber_cannot(): void {
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$director   = self::factory()->user->create( array( 'role' => Roles::DIRECTOR ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$call       = self::factory()->post->create(
			array(
				'post_type'   => Calls::TYPE,
				'post_author' => $admin,
			)
		);

		$this->assertTrue( user_can( $director, 'edit_post', $call ) );
		$this->assertTrue( user_can( $director, 'delete_post', $call ) );
		$this->assertFalse( user_can( $subscriber, 'edit_post', $call ) );
		$this->assertFalse( user_can( $subscriber, 'delete_post', $call ) );
	}

	public function test_a_director_can_edit_a_playlist_and_send_notifications_but_not_change_settings(): void {
		$director = self::factory()->user->create( array( 'role' => Roles::DIRECTOR ) );
		$playlist = self::factory()->post->create( array( 'post_type' => Post_Types::SET ) );

		$this->assertTrue( user_can( $director, 'edit_post', $playlist ) );
		$this->assertTrue( user_can( $director, Roles::NOTIFY ) );
		$this->assertTrue( user_can( $director, Roles::VIEW ) );
		$this->assertFalse( user_can( $director, 'manage_options' ) );
		$this->assertFalse( user_can( $director, 'edit_posts' ), 'a director does not get blog posts' );
	}

	public function test_a_cast_member_can_open_the_front_end_and_nothing_else(): void {
		$cast = self::factory()->user->create( array( 'role' => Roles::CAST_MEMBER ) );
		$call = self::factory()->post->create( array( 'post_type' => Calls::TYPE ) );

		$this->assertTrue( user_can( $cast, Roles::VIEW ) );
		$this->assertFalse( user_can( $cast, 'edit_post', $call ) );
		$this->assertFalse( user_can( $cast, Roles::NOTIFY ) );
	}

	public function test_uninstall_removes_the_roles_and_capabilities(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'callboard/callboard.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's own constant, which uninstall.php checks.
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertNull( get_role( Roles::DIRECTOR ) );
		$this->assertNull( get_role( Roles::CAST_MEMBER ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'edit_callboard_playlists' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Roles::VIEW ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'upload_files' ), 'core capabilities stay' );
		$this->assertFalse( get_option( Roles::OPTION ) );
	}
}
