<?php
/**
 * The extension registry: what it accepts, what it refuses, and what reaches the page.
 *
 * These hold the PHP half of docs/extending.md. The front-end half is tests/e2e/extensions.spec.js.
 *
 * @package Callboard
 */

use Callboard\Extensions;
use Callboard\Privacy;
use Callboard\Sets;

/**
 * @covers \Callboard\Extensions
 */
class Test_Callboard_Extensions extends WP_UnitTestCase {

	/**
	 * Ids registered by a test, unregistered after it: the registry is static and outlives a test.
	 *
	 * @var string[]
	 */
	private array $ids = array();

	/**
	 * Files written during a test.
	 *
	 * @var string[]
	 */
	private array $trash = array();

	public function tear_down(): void {
		foreach ( $this->ids as $id ) {
			if ( callboard_get_extension( $id ) ) {
				callboard_unregister_extension( $id );
			}
		}
		$this->ids = array();
		foreach ( $this->trash as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->trash = array();
		remove_all_filters( 'callboard_extension_enabled' );
		remove_all_filters( 'callboard_can_view' );
		Sets::flush();
		parent::tear_down();
	}

	/**
	 * Register an extension that this test cleans up.
	 *
	 * @param string               $id   Id.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|false
	 */
	private function register( string $id, array $args = array() ) {
		$this->ids[] = $id;
		return callboard_register_extension( $id, $args + array( 'version' => '1.0.0' ) );
	}

	/**
	 * A published set with one audio track whose file exists, which is what Sets::build() needs.
	 *
	 * @return array{0: int, 1: int} Set and track ids.
	 */
	private function set_with_a_track(): array {
		$set  = self::factory()->post->create(
			array(
				'post_type'   => 'callboard_set',
				'post_status' => 'publish',
				'post_name'   => 'registry-set',
				'post_title'  => 'Registry Set',
			)
		);
		$file = wp_upload_dir()['basedir'] . '/registry-track.mp3';
		file_put_contents( $file, str_repeat( "\0", 128 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->trash[] = $file;
		$track         = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'audio/mpeg',
				'post_parent'    => $set,
				'post_title'     => 'Registry Track',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $track, '_wp_attached_file', 'registry-track.mp3' );
		update_post_meta( $track, '_callboard_bpm', 104 );
		return array( $set, $track );
	}

	public function test_callboards_own_features_are_registered_through_the_registry(): void {
		foreach ( array( 'callboard/count-in', 'callboard/quality', 'callboard/badging' ) as $id ) {
			$this->assertNotNull( callboard_get_extension( $id ), "{$id} is not registered" );
		}
		$this->assertSame( 1, CALLBOARD_API_VERSION );
	}

	public function test_an_extension_registers_reads_back_and_unregisters(): void {
		$registered = $this->register( 'test/round-trip', array( 'title' => 'Round trip' ) );

		$this->assertIsArray( $registered );
		$this->assertSame( 'Round trip', callboard_get_extension( 'test/round-trip' )['title'] );
		$this->assertArrayHasKey( 'test/round-trip', callboard_get_extensions() );
		$this->assertSame( 'test/round-trip', callboard_unregister_extension( 'test/round-trip' )['id'] );
		$this->assertNull( callboard_get_extension( 'test/round-trip' ) );
	}

	/**
	 * @dataProvider bad_ids
	 */
	public function test_an_id_that_is_not_namespace_slash_name_is_refused( string $id ): void {
		$this->setExpectedIncorrectUsage( 'callboard_register_extension' );

		$this->assertFalse( $this->register( $id ) );
		$this->assertNull( callboard_get_extension( $id ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function bad_ids(): array {
		return array(
			'no namespace'    => array( 'lonely' ),
			'capitals'        => array( 'Test/Thing' ),
			'two slashes'     => array( 'test/thing/more' ),
			'underscore'      => array( 'test/some_thing' ),
			'empty name'      => array( 'test/' ),
			'dot in the name' => array( 'test/thing.js' ),
		);
	}

	public function test_the_callboard_namespace_is_reserved(): void {
		$this->setExpectedIncorrectUsage( 'callboard_register_extension' );

		$this->assertFalse( $this->register( 'callboard/impostor' ) );
		$this->assertNull( callboard_get_extension( 'callboard/impostor' ) );
	}

	public function test_registering_the_same_id_twice_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'callboard_register_extension' );

		$this->assertIsArray( $this->register( 'test/twice', array( 'title' => 'First' ) ) );
		$this->assertFalse( $this->register( 'test/twice', array( 'title' => 'Second' ) ) );
		$this->assertSame( 'First', callboard_get_extension( 'test/twice' )['title'] );
	}

	public function test_an_extension_written_for_another_api_version_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'callboard_register_extension' );

		$this->assertFalse( $this->register( 'test/future', array( 'api_version' => 2 ) ) );
		$this->assertFalse( $this->register( 'test/stringly', array( 'api_version' => '1' ) ) );
	}

	public function test_a_disabled_extension_contributes_nothing(): void {
		$this->register(
			'test/switchable',
			array(
				'app_data' => static fn() => array( 'on' => true ),
			)
		);
		$this->assertArrayHasKey( 'test/switchable', Callboard\Extensions::filter_app_data( array() )['ext'] );

		add_filter( 'callboard_extension_enabled', static fn( bool $on, string $id ) => $on && 'test/switchable' !== $id, 10, 2 );

		$data = Callboard\Extensions::filter_app_data( array() );
		$this->assertArrayNotHasKey( 'test/switchable', $data['ext'] );
		$this->assertArrayNotHasKey( 'test/switchable', $data['extensions'] );
		$this->assertArrayHasKey( 'callboard/count-in', $data['extensions'], 'switching one off leaves the others' );
	}

	public function test_track_and_set_data_reach_the_set_under_ext_and_the_old_fields_stay(): void {
		list( $set, $track ) = $this->set_with_a_track();
		$this->register(
			'test/data',
			array(
				'track_data' => static fn( array $t, WP_Post $attachment ) => array( 'id' => $attachment->ID ),
				'set_data'   => static fn( array $s ) => array( 'count' => count( $s['tracks'] ) ),
			)
		);

		$built = Sets::build( get_post( $set ) );

		$this->assertSame( array( 'id' => $track ), $built['tracks'][0]['ext']['test/data'] );
		$this->assertSame( array( 'count' => 1 ), $built['ext']['test/data'] );
		$this->assertSame( 104, $built['tracks'][0]['ext']['callboard/count-in']['bpm'] );
		$this->assertSame( 104, $built['tracks'][0]['bpm'], 'the deprecated top-level copy is still there for API v1' );
		$this->assertArrayHasKey( 'quality', $built['tracks'][0] );
	}

	public function test_switching_an_extension_off_rebuilds_the_cached_sets(): void {
		$this->set_with_a_track();
		$bpm = static fn() => Sets::by_slug( 'registry-set' )['tracks'][0]['bpm'];

		$this->assertSame( 104, $bpm() );

		add_filter( 'callboard_extension_enabled', static fn( bool $on, string $id ) => $on && 'callboard/count-in' !== $id, 10, 2 );

		$this->assertNull( $bpm(), 'the cache still held the tempo of an extension that is off' );
	}

	public function test_alternating_fingerprints_use_cached_variants(): void {
		$this->set_with_a_track();
		$count_in = true;
		$builds   = 0;
		add_filter(
			'callboard_extension_enabled',
			static function ( bool $on, string $id ) use ( &$count_in ): bool {
				if ( 'callboard/count-in' !== $id ) {
					return $on;
				}
				return $count_in;
			},
			10,
			2
		);
		add_filter(
			'callboard_set_data',
			static function ( array $set ) use ( &$builds ): array {
				++$builds;
				return $set;
			},
			999
		);
		$read_bpm = static function ( bool $enabled ) use ( &$count_in ) {
			$count_in = $enabled;
			// Tests run in one PHP process; poke the registry to emulate a fresh request's active memo.
			callboard_register_extension( 'test/poke', array( 'version' => '1.0.0' ) );
			callboard_unregister_extension( 'test/poke' );
			return Sets::by_slug( 'registry-set' )['tracks'][0]['bpm'];
		};

		$this->assertSame( 104, $read_bpm( true ) );
		$this->assertSame( 1, $builds );
		$this->assertNull( $read_bpm( false ) );
		$this->assertSame( 2, $builds );
		$this->assertSame( 104, $read_bpm( true ) );
		$this->assertSame( 2, $builds, 'the first fingerprint variant should be reused' );
	}

	public function test_active_extensions_are_memoized_until_registry_changes(): void {
		$calls = 0;
		add_filter(
			'callboard_extension_enabled',
			static function ( bool $on ) use ( &$calls ): bool {
				++$calls;
				return $on;
			}
		);

		Extensions::active();
		$first_pass = $calls;
		$this->assertGreaterThan( 0, $first_pass );
		Extensions::contributions( 'track_data' );
		Extensions::contributions( 'set_data' );
		Extensions::active();
		$this->assertSame( $first_pass, $calls );

		$this->register( 'test/memo-reset' );
		Extensions::active();
		$this->assertGreaterThan( $first_pass, $calls );
		$after_register = $calls;
		callboard_unregister_extension( 'test/memo-reset' );
		Extensions::active();
		$this->assertGreaterThan( $after_register, $calls );
	}

	public function test_contributions_run_by_priority_then_registration_order(): void {
		$this->register( 'test/b', array( 'slots' => array( 'track_badges' => static fn() => array( array( 'text' => 'b' ) ) ) ) );
		$this->register(
			'test/a',
			array(
				'priority' => 5,
				'slots'    => array( 'track_badges' => static fn() => array( array( 'text' => 'a' ) ) ),
			)
		);
		$this->register(
			'test/c',
			array(
				'slots' => array(
					'track_badges' => array(
						'priority' => 20,
						'callback' => static fn() => array( array( 'text' => 'c' ) ),
					),
				),
			)
		);
		$track = array( 'ext' => array() );

		preg_match_all( '#data-extension="(test/[abc])"#', callboard_get_slot( 'track_badges', $track, array() ), $m );

		$this->assertSame( array( 'test/a', 'test/b', 'test/c' ), $m[1] );
	}

	public function test_an_item_is_text_and_its_class_cannot_open_an_attribute(): void {
		$html = Extensions::item(
			array(
				'text'      => '<img src=x onerror=alert(1)>',
				'label'     => '"><script>alert(1)</script>',
				'className' => 'ok" onclick="alert(1)',
				'tone'      => 'loud',
			),
			'test/item'
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( ' onclick=', $html );
		$this->assertStringNotContainsString( 'data-tone', $html, 'an unknown tone is dropped' );
		$this->assertStringContainsString( 'class="cb-item ok onclickalert1"', $html );
		$this->assertSame( '', Extensions::item( array( 'text' => '' ), 'test/item' ) );
		$this->assertSame( '', Extensions::item( 'just a string', 'test/item' ) );
	}

	public function test_an_html_slot_keeps_controls_and_loses_scripts_styles_and_handlers(): void {
		$this->register(
			'test/html',
			array(
				'slots' => array(
					'transport' => static fn() => '<button type="button" class="ctl" aria-label="Go" data-x="1" onclick="alert(1)">Go</button><script>alert(1)</script><style>body{display:none}</style><input type="text" name="n"><input type="range" min="0">',
				),
			)
		);

		$html = callboard_get_slot( 'transport' );

		$this->assertStringContainsString( '<button type="button" class="ctl" aria-label="Go" data-x="1">Go</button>', $html );
		$this->assertStringNotContainsString( 'alert', $html );
		$this->assertStringNotContainsString( 'display:none', $html );
		$this->assertStringNotContainsString( 'type="text"', $html );
		$this->assertStringContainsString( 'type="range"', $html );
	}

	public function test_an_unknown_slot_is_refused_and_the_rest_of_the_extension_stands(): void {
		$this->setExpectedIncorrectUsage( 'callboard_register_extension' );

		$this->register( 'test/slots', array( 'slots' => array( 'sidebar' => static fn() => 'x' ) ) );

		$this->assertSame( array(), callboard_get_extension( 'test/slots' )['slots'] );
	}

	public function test_a_declared_route_is_open_to_strangers_and_still_obeys_the_gate(): void {
		$this->register(
			'test/route',
			array(
				'rest' => array(
					array(
						'/ping',
						array(
							'methods'  => 'GET',
							'callback' => static fn() => array( 'pong' => true ),
						),
					),
				),
			)
		);
		$GLOBALS['wp_rest_server'] = null; // A fresh server fires rest_api_init, which registers the route.
		rest_get_server();
		wp_set_current_user( 0 );

		$GLOBALS['wp']->query_vars['rest_route'] = '/callboard/v1/test/route/ping';
		$this->assertNull( Privacy::require_auth_for_rest( null ), 'a declared route is let past the signed-in rule' );
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/posts';
		$this->assertWPError( Privacy::require_auth_for_rest( null ), 'and nothing else is' );
		unset( $GLOBALS['wp']->query_vars['rest_route'] );

		$request = new WP_REST_Request( 'GET', '/callboard/v1/test/route/ping' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		add_filter( 'callboard_can_view', '__return_false' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_the_worker_is_rewritten_once_when_extension_assets_change(): void {
		$sw     = ABSPATH . 'sw.js';
		$before = file_exists( $sw ) ? (string) file_get_contents( $sw ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_register_script( 'test-asset', 'https://example.org/wp-content/plugins/test/asset.js', array(), '3', true );
		$this->register( 'test/asset', array( 'script' => 'test-asset' ) );

		try {
			$this->assertContains( '/wp-content/plugins/test/asset.js?ver=3', Extensions::precache_urls() );
			$this->assertTrue( Extensions::maybe_refresh_worker(), 'a new asset rewrites the worker' );
			$this->assertStringContainsString( '/wp-content/plugins/test/asset.js?ver=3', (string) file_get_contents( $sw ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertFalse( Extensions::maybe_refresh_worker(), 'and the next request leaves it alone' );
		} finally {
			// The tests container serves the end-to-end site from this ABSPATH: put its worker back.
			if ( null === $before ) {
				wp_delete_file( $sw );
			} else {
				file_put_contents( $sw, $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			wp_deregister_script( 'test-asset' );
		}
	}
}
