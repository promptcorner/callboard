<?php
/**
 * Plugin Name: Callboard example extension (development only)
 * Description: A third-party extension built on the public API alone, for the contract tests.
 *
 * It uses nothing a plugin outside this repository could not: callboard_register_extension() and
 * friends in PHP, window.callboard in example.js. If a test here needs something that is not public,
 * the fix is to make it public, not to reach past it.
 *
 * Nothing happens without a cookie, so the development site never shows any of it:
 *
 *   callboard_example=1                register the example extensions
 *   callboard_example_replace=1        with them, unregister callboard/quality and register example/quality
 *   callboard_example_disable=callboard/count-in,callboard/badging   switch those off by id
 *   callboard_example_count_in=1       the count-in setting, on for this request only
 *   callboard_example_gate=1           the front end closed to everyone, for this request only
 *   callboard_example_visitor=<text>   a value example/demo returns from app_data
 *   callboard_example_signin=1         the "require sign-in" setting, on for this request only
 *   callboard_example_debug=1|0        app data says SCRIPT_DEBUG is on (1) or off (0), whatever the site says
 *   callboard_example_reserved=1       app data claims deck/meta is active, which the page must still refuse
 *   callboard_example_inline=1         an inline script after example/demo's, which WordPress will not defer
 *   callboard_example_script_version=<v>   example/demo's script at ?ver=<v> rather than 1.0.0
 *   callboard_example_missing=1        with them, register example/missing, whose script is not there
 *
 * Mapped in by .wp-env.json and kept under tests/, which the plugin zip excludes.
 *
 * @package Callboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * A cookie the tests set, read as a string.
 *
 * @param string $name Cookie name.
 */
function callboard_example_cookie( string $name ): string {
	return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
}

add_action(
	'callboard_register_extensions',
	static function () {
		if ( '1' !== callboard_example_cookie( 'callboard_example' ) ) {
			return;
		}
		callboard_register_extension(
			'example/demo',
			array(
				'version'     => '1.0.0',
				'api_version' => 1,
				'track_data'  => static fn( array $track ) => array( 'seconds' => (int) round( (float) $track['duration'] ) ),
				'set_data'    => static fn( array $set ) => array( 'tracks' => count( $set['tracks'] ) ),
				// Built on every request: `visitor` follows a cookie, so a cached copy would show the old value.
				'app_data'    => static fn() => array(
					'greeting' => 'hello',
					'visitor'  => callboard_example_cookie( 'callboard_example_visitor' ),
				),
				'slots'       => array(
					// Ahead of the count-in's ♩ badge, which sits at the default 10.
					'track_badges' => array(
						'priority' => 5,
						'callback' => static fn() => array(
							array(
								'text'      => '<b>demo</b>',
								'label'     => 'Example badge',
								'tone'      => 'accent',
								'className' => 'example-badge" onclick="alert(1)',
							),
						),
					),
					'set_header'   => static fn( array $set ) => '<button type="button" class="btn btn-quiet example-header" data-tracks="' . esc_attr( (string) count( $set['tracks'] ) ) . '" onclick="window.__exampleClicked = true">Demo</button><script>window.__exampleXss = true;</script><style>.track{display:none}</style>',
					'transport'    => static fn() => '<button type="button" class="ctl example-transport" aria-label="Example control" onmouseover="window.__exampleXss = true">D</button>',
					'panels'       => static fn() => '<div class="example-panel" data-example="panel" hidden>Example panel</div>',
				),
				'rest'        => array(
					array(
						'/ping',
						array(
							'methods'  => 'GET',
							'callback' => static fn() => array( 'pong' => true ),
						),
					),
				),
				'script'      => array(
					'src'     => content_url( 'mu-plugins/callboard-example/example.js' ),
					'version' => callboard_example_cookie( 'callboard_example_script_version' ) ? callboard_example_cookie( 'callboard_example_script_version' ) : '1.0.0',
				),
			)
		);

		// Returns empty data everywhere, which PHP encodes as [] and the page must still read as {}.
		callboard_register_extension(
			'example/empty',
			array(
				'version'     => '1.0.0',
				'api_version' => 1,
				'track_data'  => static fn() => array(),
				'set_data'    => static fn() => array(),
				'app_data'    => static fn() => array(),
			)
		);

		// After the count-in's ♩ badge.
		callboard_register_extension(
			'example/late',
			array(
				'version'     => '1.0.0',
				'api_version' => 1,
				'priority'    => 20,
				'slots'       => array(
					'track_badges' => static fn() => array(
						array(
							'text'      => 'late',
							'className' => 'example-late',
						),
					),
				),
			)
		);

		// A script that 404s, the way one does once its plugin is gone.
		if ( '1' === callboard_example_cookie( 'callboard_example_missing' ) ) {
			callboard_register_extension(
				'example/missing',
				array(
					'version'     => '1.0.0',
					'api_version' => 1,
					'script'      => array(
						'src' => content_url( 'mu-plugins/callboard-example/missing.js' ),
					),
				)
			);
		}

		if ( '1' === callboard_example_cookie( 'callboard_example_replace' ) ) {
			callboard_unregister_extension( 'callboard/quality' );
			callboard_register_extension(
				'example/quality',
				array(
					'version'     => '1.0.0',
					'api_version' => 1,
				)
			);
		}
	},
	20
);

add_filter(
	'callboard_extension_enabled',
	static function ( bool $enabled, string $id ): bool {
		$off = array_filter( explode( ',', callboard_example_cookie( 'callboard_example_disable' ) ) );
		return $enabled && ! in_array( $id, $off, true );
	},
	10,
	2
);

// WordPress defers no script with an inline script after it, and none of the scripts it depends on.
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( '1' === callboard_example_cookie( 'callboard_example_inline' ) ) {
			wp_add_inline_script( 'example-demo', 'window.__exampleInline = true;', 'after' );
		}
	}
);

$callboard_example_count_in = static function ( $settings ) {
	if ( '1' === callboard_example_cookie( 'callboard_example_count_in' ) ) {
		$settings             = is_array( $settings ) ? $settings : array();
		$settings['count_in'] = true;
	}
	return $settings;
};
add_filter( 'option_callboard_settings', $callboard_example_count_in );
add_filter( 'default_option_callboard_settings', $callboard_example_count_in );

add_filter(
	'callboard_can_view',
	static fn( $allowed ) => '1' === callboard_example_cookie( 'callboard_example_gate' ) ? false : $allowed
);

$callboard_example_signin = static function ( $settings ) {
	if ( '1' === callboard_example_cookie( 'callboard_example_signin' ) ) {
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['require_signin'] = true;
	}
	return $settings;
};
add_filter( 'option_callboard_settings', $callboard_example_signin );
add_filter( 'default_option_callboard_settings', $callboard_example_signin );

add_filter(
	'callboard_app_data',
	static function ( array $data ): array {
		$debug = callboard_example_cookie( 'callboard_example_debug' );
		if ( '' !== $debug ) {
			$data['debug'] = '1' === $debug;
		}
		if ( '1' === callboard_example_cookie( 'callboard_example_reserved' ) ) {
			$data['extensions']['deck/meta'] = '1.0.0';
		}
		return $data;
	},
	20
);
