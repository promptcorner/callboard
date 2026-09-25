<?php
/**
 * Roles and capabilities.
 *
 * @package Callboard
 */

use Callboard\Plugin;
use Callboard\Post_Types;
use Callboard\Requests;
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
		$listener = get_role( Roles::LISTENER );
		$this->assertInstanceOf( WP_Role::class, $listener );
		$this->assertSame( 'Listener', wp_roles()->role_names[ Roles::LISTENER ] );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Roles::VIEW ) );
		$this->assertTrue( get_role( 'contributor' )->has_cap( Roles::VIEW ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Roles::VIEW ) );
	}

	public function test_activation_adds_the_role_and_capability(): void {
		Roles::uninstall();
		$this->assertNull( get_role( Roles::LISTENER ) );

		Plugin::activate();

		$this->assert_installed();
		$this->assertSame( '1', get_option( Plugin::ONBOARDING_OPTION ) );
		delete_option( Plugin::ONBOARDING_OPTION );
	}

	public function test_updating_the_plugin_adds_the_role_and_capability(): void {
		Roles::uninstall();
		update_option( 'callboard_version', '2.1.0' );

		Plugin::maybe_upgrade();

		$this->assert_installed();
	}

	public function test_a_site_already_on_this_version_still_gets_the_role(): void {
		Roles::uninstall();
		update_option( 'callboard_version', CALLBOARD_VERSION );

		Plugin::maybe_upgrade();

		$this->assert_installed();
	}

	public function test_the_post_types_use_the_post_capabilities(): void {
		foreach ( array( Post_Types::SET, Requests::TYPE ) as $post_type ) {
			$this->assertSame( 'edit_posts', get_post_type_object( $post_type )->cap->edit_posts, $post_type );
		}
	}

	public function test_a_listener_can_open_the_front_end_and_nothing_else(): void {
		$listener = self::factory()->user->create( array( 'role' => Roles::LISTENER ) );
		$playlist = self::factory()->post->create( array( 'post_type' => Post_Types::SET ) );

		$this->assertTrue( user_can( $listener, Roles::VIEW ) );
		$this->assertFalse( user_can( $listener, 'edit_post', $playlist ) );
	}

	public function test_updating_renames_cast_member_and_its_users_keep_it(): void {
		remove_role( Roles::LISTENER );
		add_role(
			Roles::LISTENER,
			'Cast member',
			array(
				'read'      => true,
				Roles::VIEW => true,
			)
		);
		$cast = self::factory()->user->create( array( 'role' => Roles::LISTENER ) );
		update_option( Roles::OPTION, 2 );

		Plugin::maybe_upgrade();

		$this->assertSame( 'Listener', wp_roles()->role_names[ Roles::LISTENER ] );
		clean_user_cache( $cast );
		$this->assertTrue( user_can( $cast, Roles::VIEW ) );
	}

	public function test_updating_makes_directors_authors_and_removes_the_role(): void {
		add_role(
			'callboard_director',
			'Director',
			array(
				'read'                     => true,
				'edit_callboard_playlists' => true,
			)
		);
		$director = self::factory()->user->create( array( 'role' => 'callboard_director' ) );
		update_option( Roles::OPTION, 2 );

		Plugin::maybe_upgrade();

		$this->assertNull( get_role( 'callboard_director' ) );
		clean_user_cache( $director );
		$this->assertSame( array( 'author' ), get_userdata( $director )->roles );
		$this->assertTrue( user_can( $director, Roles::VIEW ) );
	}

	public function test_updating_takes_the_retired_capabilities_off_every_role(): void {
		$retired = array( 'send_callboard_notifications', 'edit_callboard_calls', 'edit_callboard_push_subscriptions', 'edit_others_callboard_playlists', 'publish_callboard_import_requests' );
		foreach ( $retired as $cap ) {
			get_role( 'administrator' )->add_cap( $cap );
		}
		update_option( Roles::OPTION, 1 );

		Plugin::maybe_upgrade();

		foreach ( $retired as $cap ) {
			$this->assertFalse( get_role( 'administrator' )->has_cap( $cap ), "{$cap} should be gone" );
		}
		$this->assertTrue( get_role( 'administrator' )->has_cap( Roles::VIEW ) );
	}

	public function test_updating_deletes_push_subscriptions_and_keys_but_keeps_calls(): void {
		$subscription = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_subscriber',
				'post_status' => 'private',
			)
		);
		$call         = self::factory()->post->create( array( 'post_type' => 'callboard_call' ) );
		update_option( 'callboard_vapid', array( 'publicKey' => 'x' ) );
		update_option( 'callboard_version', '2.0.0' );

		Plugin::maybe_upgrade();

		$this->assertNull( get_post( $subscription ) );
		$this->assertFalse( get_option( 'callboard_vapid' ) );
		$this->assertNotNull( get_post( $call ), 'calls are content, and stay' );
	}

	public function test_updating_trashes_directors_notes_and_leaves_other_comments(): void {
		$track   = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		$note    = self::factory()->comment->create(
			array(
				'comment_post_ID' => $track,
				'comment_type'    => 'callboard_note',
			)
		);
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $track ) );
		update_option( 'callboard_version', '2.0.0' );

		Plugin::maybe_upgrade();

		$this->assertSame( 'trash', wp_get_comment_status( $note ) );
		$this->assertSame( 'approved', wp_get_comment_status( $comment ) );
	}

	public function test_uninstall_removes_the_role_and_capability(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'callboard/callboard.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- core's own constant, which uninstall.php checks.
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertNull( get_role( Roles::LISTENER ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Roles::VIEW ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'upload_files' ), 'core capabilities stay' );
		$this->assertFalse( get_option( Roles::OPTION ) );
	}
}
