<?php
/**
 * The data model, and the promise that it stays the data model.
 *
 * @package Callboard
 */

use Callboard\Meta;

/**
 * @covers \Callboard\Meta
 */
class Test_Callboard_Meta extends WP_UnitTestCase {

	/**
	 * WP_UnitTestCase unregisters every meta key in its teardown, so only the first test in a run
	 * would otherwise see the plugin's registrations. Put them back before each one.
	 */
	public function set_up(): void {
		parent::set_up();
		Meta::register();
	}

	/**
	 * Every key the plugin actually writes, read out of the source rather than out of a list
	 * somebody has to remember to update.
	 *
	 * @return string[]
	 */
	private function keys_in_source(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		foreach ( array( '/includes', '/templates' ) as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				preg_match_all( "/'(_callboard_[a-z0-9_]+)'/", (string) file_get_contents( $file->getPathname() ), $m );
				$found = array_merge( $found, $m[1] );
			}
		}

		// Built as '_callboard_' . $role . '_image', so no literal appears for either of them.
		$found[] = '_callboard_cover_image';
		$found[] = '_callboard_share_image';

		return array_values( array_unique( $found ) );
	}

	/**
	 * @return string[]
	 */
	private function declared(): array {
		$keys = array();
		foreach ( Meta::map() as $fields ) {
			$keys = array_merge( $keys, array_keys( $fields ) );
		}
		return $keys;
	}

	/**
	 * The one that matters. A field added to the plugin and not to the map is a field with no type,
	 * no description and no auth callback, and nobody notices until they go looking for it.
	 */
	public function test_every_meta_key_the_plugin_writes_is_declared(): void {
		$undeclared = array_diff( $this->keys_in_source(), $this->declared() );

		$this->assertSame(
			array(),
			array_values( $undeclared ),
			'These meta keys are used in the source but missing from Meta::map(): ' . implode( ', ', $undeclared )
		);
	}

	/**
	 * And the other way, so the map does not accumulate fields that were removed years ago.
	 */
	public function test_nothing_is_declared_that_the_plugin_never_writes(): void {
		$orphans = array_diff( $this->declared(), $this->keys_in_source() );

		$this->assertSame(
			array(),
			array_values( $orphans ),
			'These meta keys are declared in Meta::map() but appear nowhere in the source: ' . implode( ', ', $orphans )
		);
	}

	public function test_every_field_registers_against_its_post_type(): void {
		foreach ( Meta::map() as $post_type => $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$this->assertTrue(
					registered_meta_key_exists( 'post', $key, $post_type ),
					"{$key} is not registered for {$post_type}"
				);
			}
		}
	}

	public function test_every_field_declares_a_type_and_says_what_it_is(): void {
		foreach ( Meta::map() as $post_type => $fields ) {
			foreach ( $fields as $key => $field ) {
				$this->assertContains(
					$field['type'],
					array( 'string', 'integer', 'number', 'boolean', 'array' ),
					"{$key} has an unusable type"
				);
				// A sentence, not a fragment. Length is the wrong test — "Where to be." says everything
				// _callboard_where needs — so this asks for prose instead. Anything stricter starts
				// failing honest English: "When the call is" opens with the key's own word.
				$this->assertNotEmpty( $field['description'], "{$key} has no description" );
				$this->assertStringContainsString( ' ', $field['description'], "{$key}'s description is one word" );
				$this->assertStringEndsWith( '.', $field['description'], "{$key}'s description is not a sentence" );
			}
		}
	}

	/**
	 * A set's contents are the reason Gate exists, and the subscriber fields are live Web Push
	 * credentials. None of it belongs on a public endpoint, so this is asserted rather than assumed.
	 */
	public function test_nothing_is_exposed_over_rest(): void {
		$registered = get_registered_meta_keys( 'post' );

		foreach ( Meta::map() as $post_type => $fields ) {
			$for_type = get_registered_meta_keys( 'post', $post_type );
			foreach ( array_keys( $fields ) as $key ) {
				$args = $for_type[ $key ] ?? $registered[ $key ] ?? array();
				$this->assertFalse(
					(bool) ( $args['show_in_rest'] ?? false ),
					"{$key} is exposed over REST"
				);
			}
		}
	}

	/**
	 * Arrays are written through sanitizers that understand their shape. A generic callback here
	 * would have to re-derive that shape from nothing, and would flatten it.
	 */
	public function test_array_fields_carry_no_scalar_sanitizer(): void {
		foreach ( Meta::map() as $post_type => $fields ) {
			foreach ( $fields as $key => $field ) {
				if ( 'array' === $field['type'] ) {
					$this->assertArrayNotHasKey( 'sanitize', $field, "{$key} is an array with a scalar sanitizer" );
				}
			}
		}
	}

	/**
	 * Registering must not have changed what gets stored. These are the shapes the rest of the
	 * plugin reads back, so a sanitizer that quietly casts one of them breaks a feature elsewhere.
	 */
	public function test_registering_did_not_change_what_is_stored(): void {
		$track = self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		update_post_meta( $track, '_callboard_duration', 271.5 );
		$this->assertEqualsWithDelta( 271.5, (float) get_post_meta( $track, '_callboard_duration', true ), 0.01 );

		update_post_meta( $track, '_callboard_levels', '0123456789' );
		$this->assertSame( '0123456789', get_post_meta( $track, '_callboard_levels', true ) );
	}

	public function test_the_levels_sanitizer_keeps_only_digits(): void {
		$track = self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		update_post_meta( $track, '_callboard_levels', '01a2<script>3' );
		$this->assertSame( '0123', get_post_meta( $track, '_callboard_levels', true ) );
	}
}
