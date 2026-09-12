<?php
/**
 * Settings, and what they refuse to store.
 *
 * Every value here is written by an administrator and then printed into the front end — the accent
 * lands in a CSS custom property, the badge and confetti land in text nodes — so sanitize() is the
 * only thing standing between the settings screen and the app.
 *
 * @package Callboard
 */

use Callboard\Settings;

/**
 * @covers \Callboard\Settings
 */
class Test_Callboard_Settings extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_a_fresh_install_has_every_key_the_code_asks_for(): void {
		$all = Settings::all();
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $all );
		}
		$this->assertNull( Settings::get( 'no_such_setting' ) );
	}

	public function test_a_stored_option_is_merged_over_the_defaults_rather_than_replacing_them(): void {
		update_option( Settings::OPTION, array( 'tagline' => 'Practice tracks' ) );

		$this->assertSame( 'Practice tracks', Settings::get( 'tagline' ) );
		$this->assertTrue( Settings::get( 'offline' ), 'a partial option must not blank the rest' );
	}

	public function test_an_accent_that_is_not_a_colour_is_dropped(): void {
		$this->assertSame( '#3b82f6', Settings::sanitize( array( 'accent' => '#3b82f6' ) )['accent'] );
		$this->assertSame( '#3b82f6', Settings::sanitize( array( 'accent' => '  #3b82f6  ' ) )['accent'] );
		$this->assertSame( '', Settings::sanitize( array( 'accent' => 'red' ) )['accent'] );
		$this->assertSame( '', Settings::sanitize( array( 'accent' => 'javascript:alert(1)' ) )['accent'] );
		$this->assertSame( '', Settings::sanitize( array( 'accent' => '#3b82f6;}body{display:none' ) )['accent'] );
	}

	public function test_the_badge_is_capped_so_it_cannot_take_over_the_row(): void {
		$this->assertSame( '★', Settings::sanitize( array( 'badge' => '★' ) )['badge'] );
		$this->assertSame( 4, mb_strlen( Settings::sanitize( array( 'badge' => '★★★★★★★★' ) )['badge'] ) );
	}

	public function test_text_fields_carry_no_markup(): void {
		$clean = Settings::sanitize(
			array(
				'tagline'     => 'Rehearsal <script>alert(1)</script> tracks',
				'footer_note' => '<b>For rehearsal use only.</b>',
			)
		);

		$this->assertStringNotContainsString( '<script>', $clean['tagline'] );
		$this->assertStringNotContainsString( '<b>', $clean['footer_note'] );
	}

	/**
	 * An unchecked checkbox is absent from $_POST, not false, which is why every boolean is read
	 * with ! empty() rather than a cast.
	 */
	public function test_an_absent_checkbox_reads_as_off(): void {
		$clean = Settings::sanitize( array( 'tagline' => 'Only this' ) );

		foreach ( array( 'hearts', 'show_hint', 'offline', 'push', 'notify_new_sets', 'notify_calls', 'count_in', 'require_signin', 'require_access' ) as $flag ) {
			$this->assertFalse( $clean[ $flag ], "{$flag} should be off when the box is not posted" );
		}
	}

	public function test_a_checked_box_reads_as_on_however_the_browser_spells_it(): void {
		$clean = Settings::sanitize(
			array(
				'hearts'         => '1',
				'offline'        => 'on',
				'require_signin' => 'yes',
			)
		);

		$this->assertTrue( $clean['hearts'] );
		$this->assertTrue( $clean['offline'] );
		$this->assertTrue( $clean['require_signin'] );
	}

	public function test_sanitize_survives_being_handed_something_that_is_not_an_array(): void {
		$this->assertIsArray( Settings::sanitize( null ) );
		$this->assertIsArray( Settings::sanitize( 'nonsense' ) );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( Settings::sanitize( null ) ) );
	}

	/**
	 * The client payload is a deliberate subset. A key added to defaults() and forgotten here is
	 * fine; a key added here that the front end has no business seeing is not.
	 */
	public function test_the_client_payload_carries_nothing_private(): void {
		$client = Settings::for_client();

		$this->assertArrayNotHasKey( 'require_signin', $client );
		$this->assertArrayNotHasKey( 'tagline', $client );
		$this->assertArrayNotHasKey( 'footer_note', $client );
		$this->assertArrayHasKey( 'offline', $client );
		$this->assertIsBool( $client['hearts'] );
	}
}
