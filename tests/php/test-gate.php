<?php
/**
 * Who may see the front end.
 *
 * The gate is one boolean, but it is the boolean the whole "bring your own auth" story rests on:
 * the filter has to be able to answer for a visitor without knowing what the setting says, and the
 * setting has to be the answer when no filter speaks up.
 *
 * @package Callboard
 */

use Callboard\Gate;
use Callboard\Roles;
use Callboard\Settings;

/**
 * @covers \Callboard\Gate
 */
class Test_Callboard_Gate extends WP_UnitTestCase {

	private ?string $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null;
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		remove_all_filters( 'callboard_can_view' );
		// WP-Cron reads REQUEST_URI on shutdown, so a test that borrows it has to give it back.
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	private function require_signin( bool $on, bool $access = false ): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'require_signin' => $on,
					'require_access' => $access,
				)
			)
		);
	}

	public function test_the_gate_is_open_by_default(): void {
		$this->assertFalse( Gate::required(), 'a link in a group chat is the whole setup' );
		$this->assertTrue( Gate::allowed() );
	}

	public function test_a_signed_out_visitor_is_turned_away_once_the_setting_is_on(): void {
		$this->require_signin( true );
		wp_set_current_user( 0 );

		$this->assertTrue( Gate::required() );
		$this->assertFalse( Gate::allowed() );
	}

	public function test_any_signed_in_user_is_let_through(): void {
		$this->require_signin( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( Gate::allowed(), 'a subscriber is a cast member, not an editor' );
	}

	public function test_with_the_capability_required_a_subscriber_is_turned_away_and_a_cast_member_is_let_in(): void {
		$this->require_signin( true, true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( Gate::capability_required() );
		$this->assertFalse( Gate::allowed() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::CAST_MEMBER ) ) );
		$this->assertTrue( Gate::allowed() );

		wp_set_current_user( 0 );
		$this->assertFalse( Gate::allowed() );
	}

	public function test_the_capability_setting_does_nothing_without_the_signin_setting(): void {
		$this->require_signin( false, true );
		wp_set_current_user( 0 );

		$this->assertFalse( Gate::capability_required() );
		$this->assertTrue( Gate::allowed() );
	}

	public function test_a_filter_still_outranks_the_capability_setting(): void {
		$this->require_signin( true, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'callboard_can_view', '__return_true' );

		$this->assertTrue( Gate::allowed() );
	}

	/**
	 * Returning null is how a filter says "I have no opinion", which is what lets somebody hook
	 * the gate for one condition without having to reimplement the setting for every other.
	 */
	public function test_a_filter_returning_null_leaves_the_setting_in_charge(): void {
		add_filter( 'callboard_can_view', '__return_null' );

		$this->require_signin( true );
		wp_set_current_user( 0 );
		$this->assertFalse( Gate::allowed() );

		$this->require_signin( false );
		$this->assertTrue( Gate::allowed() );
	}

	public function test_a_filter_can_let_somebody_in_that_the_setting_would_not(): void {
		$this->require_signin( true );
		wp_set_current_user( 0 );
		add_filter( 'callboard_can_view', '__return_true' );

		$this->assertTrue( Gate::allowed() );
	}

	public function test_a_filter_can_turn_away_somebody_the_setting_would_admit(): void {
		$this->require_signin( false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'callboard_can_view', '__return_false' );

		$this->assertFalse( Gate::allowed(), 'a filter outranks the setting in both directions' );
	}

	public function test_the_login_url_comes_back_to_where_the_visitor_was(): void {
		$_SERVER['REQUEST_URI'] = '/velvetcore/?t=42';

		$url = Gate::login_url();
		$this->assertStringContainsString( 'wp-login.php', $url );

		$redirect = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $redirect );
		$this->assertArrayHasKey( 'redirect_to', $redirect );
		$this->assertStringContainsString( '/velvetcore/', $redirect['redirect_to'] );
	}

	public function test_the_login_url_will_not_carry_a_visitor_off_site(): void {
		$_SERVER['REQUEST_URI'] = '//evil.example.com/phish';

		$redirect = array();
		parse_str( (string) wp_parse_url( Gate::login_url(), PHP_URL_QUERY ), $redirect );
		$this->assertStringNotContainsString( 'evil.example.com', $redirect['redirect_to'] ?? '' );
	}
}
