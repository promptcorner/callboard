<?php
/**
 * The extension registry: the one way a feature adds itself to Callboard, ours or anybody's.
 *
 * An extension is an id, a version, what it contributes, and a lifecycle. Callboard's own features
 * register here through the same public functions a third-party plugin calls, so anything one of them
 * does, a plugin could have done. The contract is written up in docs/extending.md; this file is the
 * PHP half of it, and the section of assets/app.js that builds window.callboard is the other.
 *
 * The existing hooks stay underneath. Data contributions run inside callboard_set_data and
 * callboard_app_data at priority 5, so a site filtering those at the default priority still sees
 * everything, extensions included.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of extensions and the contribution points they fill.
 */
final class Extensions {

	/**
	 * `namespace/name`, the shape of a block name.
	 */
	public const ID_PATTERN = '#^[a-z0-9-]+/[a-z0-9-]+$#';

	/**
	 * Contract versions this build of the plugin can run.
	 */
	public const SUPPORTED_API_VERSIONS = array( 1 );

	/**
	 * Slots that take structured items, escaped by Callboard.
	 */
	public const ITEM_SLOTS = array( 'track_badges', 'track_meta' );

	/**
	 * Slots that take markup, passed through wp_kses with callboard_slot_allowed_html().
	 */
	public const HTML_SLOTS = array( 'set_header', 'transport', 'panels' );

	/**
	 * Item tones a slot understands. Anything else is dropped rather than invented.
	 */
	public const TONES = array( 'default', 'accent', 'muted' );

	/**
	 * Fields that sat at the top level of a track before they belonged to an extension. They stay
	 * there as deprecated copies for API v1; the extension's own `ext` entry is the real one.
	 */
	private const TRACK_ALIASES = array(
		'bpm'     => array( 'callboard/count-in', 'bpm' ),
		'quality' => array( 'callboard/quality', 'quality' ),
	);

	/**
	 * Registered extensions, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $registered = array();

	/**
	 * Active extensions for the current request and the filter state that produced them.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $active_cache = null;

	/**
	 * Signature of callboard_extension_enabled callbacks that produced the active cache.
	 *
	 * @var string
	 */
	private static string $active_cache_key = '';

	/**
	 * True only while Callboard loads its own extensions, the one moment `callboard/*` is accepted.
	 *
	 * @var bool
	 */
	private static bool $first_party = false;

	/**
	 * Registrations so far, so equal priorities keep the order extensions arrived in.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'fire_registration' ), 5 );
		add_action( 'init', array( self::class, 'register_assets' ), 99 );
		add_action( 'admin_init', array( self::class, 'maybe_refresh_worker' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'callboard_set_data', array( self::class, 'filter_set_data' ), 5, 2 );
		add_filter( 'callboard_app_data', array( self::class, 'filter_app_data' ), 5 );
	}

	/**
	 * Load Callboard's own extensions, then let everybody else register.
	 */
	public static function fire_registration(): void {
		self::$first_party = true;
		foreach ( glob( CALLBOARD_DIR . 'includes/extensions/class-*.php' ) as $file ) {
			require_once $file;
		}
		foreach ( array( Extension\Count_In::class, Extension\Quality::class, Extension\Badging::class, Extension\Practice::class ) as $extension ) {
			$extension::register();
		}
		self::$first_party = false;

		/**
		 * Register extensions here, with callboard_register_extension().
		 *
		 * Fires on `init` at priority 5, after Callboard's own extensions are in, so a later callback
		 * on this action can unregister or replace one of them by id.
		 */
		do_action( 'callboard_register_extensions' );
	}

	/**
	 * Add an extension.
	 *
	 * @param string               $id   `namespace/name`. `callboard/*` is reserved.
	 * @param array<string, mixed> $args See docs/extending.md.
	 * @return array<string, mixed>|false The registered extension, or false when refused.
	 */
	public static function register( string $id, array $args = array() ) {
		if ( ! preg_match( self::ID_PATTERN, $id ) ) {
			_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension ids are "namespace/name" in lowercase letters, digits and hyphens. "%s" is not.', $id ) ), '2.3.0' );
			return false;
		}
		if ( str_starts_with( $id, 'callboard/' ) && ! self::$first_party ) {
			_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'The callboard namespace is reserved for the plugin\'s own extensions. Register "%s" under your own.', $id ) ), '2.3.0' );
			return false;
		}
		if ( isset( self::$registered[ $id ] ) ) {
			_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension "%s" is already registered. Unregister it first to replace it.', $id ) ), '2.3.0' );
			return false;
		}

		$api = $args['api_version'] ?? 1;
		if ( ! in_array( $api, self::SUPPORTED_API_VERSIONS, true ) ) {
			_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension "%1$s" was written for API version %2$s; this Callboard runs version %3$d.', $id, is_scalar( $api ) ? (string) $api : '?', CALLBOARD_API_VERSION ) ), '2.3.0' );
			return false;
		}

		$priority  = isset( $args['priority'] ) ? (int) $args['priority'] : 10;
		$extension = array(
			'id'          => $id,
			'version'     => isset( $args['version'] ) ? (string) $args['version'] : '',
			'api_version' => $api,
			'title'       => isset( $args['title'] ) ? (string) $args['title'] : '',
			'priority'    => $priority,
			'track_data'  => self::point( $id, 'track_data', $args['track_data'] ?? null, $priority ),
			'set_data'    => self::point( $id, 'set_data', $args['set_data'] ?? null, $priority ),
			'app_data'    => self::point( $id, 'app_data', $args['app_data'] ?? null, $priority ),
			'slots'       => array(),
			'rest'        => array(),
			'script'      => $args['script'] ?? null,
			'style'       => $args['style'] ?? null,
			'order'       => self::$sequence++,
		);

		foreach ( (array) ( $args['slots'] ?? array() ) as $slot => $callback ) {
			if ( ! in_array( $slot, array_merge( self::ITEM_SLOTS, self::HTML_SLOTS ), true ) ) {
				_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension "%1$s" asks for a slot called "%2$s", which does not exist.', $id, $slot ) ), '2.3.0' );
				continue;
			}
			$point = self::point( $id, 'slots.' . $slot, $callback, $priority );
			if ( $point ) {
				$extension['slots'][ $slot ] = $point;
			}
		}

		foreach ( (array) ( $args['rest'] ?? array() ) as $route ) {
			if ( ! is_array( $route ) || ! isset( $route[0], $route[1] ) || ! is_string( $route[0] ) || ! is_array( $route[1] ) ) {
				_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension "%s" has a rest entry that is not [ route, args ].', $id ) ), '2.3.0' );
				continue;
			}
			$extension['rest'][] = array( '/' . ltrim( $route[0], '/' ), $route[1] );
		}

		self::$registered[ $id ] = $extension;
		self::reset_active_cache();
		return $extension;
	}

	/**
	 * A contribution as { callback, priority }, from either a callable or that array.
	 *
	 * @param string $id       Extension id, for the notice.
	 * @param string $name     Point name, for the notice.
	 * @param mixed  $value    Callable, or array with `callback` and optional `priority`.
	 * @param int    $priority The extension's priority, used when the point names none.
	 * @return array{callback: callable, priority: int}|null
	 */
	private static function point( string $id, string $name, $value, int $priority ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( is_array( $value ) && isset( $value['callback'] ) ) {
			$priority = isset( $value['priority'] ) ? (int) $value['priority'] : $priority;
			$value    = $value['callback'];
		}
		if ( ! is_callable( $value ) ) {
			_doing_it_wrong( 'callboard_register_extension', esc_html( sprintf( 'Extension "%1$s" gave %2$s something that is not callable.', $id, $name ) ), '2.3.0' );
			return null;
		}
		return array(
			'callback' => $value,
			'priority' => $priority,
		);
	}

	/**
	 * Remove an extension.
	 *
	 * @param string $id Extension id.
	 * @return array<string, mixed>|false The removed extension, or false when there was none.
	 */
	public static function unregister( string $id ) {
		if ( ! isset( self::$registered[ $id ] ) ) {
			_doing_it_wrong( 'callboard_unregister_extension', esc_html( sprintf( 'Extension "%s" is not registered.', $id ) ), '2.3.0' );
			return false;
		}
		$extension = self::$registered[ $id ];
		unset( self::$registered[ $id ] );
		self::reset_active_cache();
		return $extension;
	}

	/**
	 * One registered extension.
	 *
	 * @param string $id Extension id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::$registered[ $id ] ?? null;
	}

	/**
	 * Every registered extension, enabled or not.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::$registered;
	}

	/**
	 * The extensions that reach this request, in registration order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function active(): array {
		$key = self::active_filter_key();
		if ( null !== self::$active_cache && self::$active_cache_key === $key ) {
			return self::$active_cache;
		}
		$out = array();
		foreach ( self::$registered as $id => $extension ) {
			/**
			 * Whether a registered extension runs on this request.
			 *
			 * Return false to switch one off, Callboard's own included: its data, markup and script
			 * then never reach the page. Set data is cached, and the cache is keyed on which
			 * extensions are enabled, so a decision that changes per request belongs in app_data.
			 *
			 * @param bool   $enabled Whether the extension runs.
			 * @param string $id      Extension id.
			 */
			if ( apply_filters( 'callboard_extension_enabled', true, $id ) ) {
				$out[ $id ] = $extension;
			}
		}
		self::$active_cache     = $out;
		self::$active_cache_key = $key;
		return $out;
	}

	/**
	 * A small shape hash for callboard_extension_enabled so runtime filter changes can invalidate memo.
	 */
	private static function active_filter_key(): string {
		global $wp_filter;
		$hook = $wp_filter['callboard_extension_enabled'] ?? null;
		if ( ! ( $hook instanceof \WP_Hook ) || ! is_array( $hook->callbacks ) || ! $hook->callbacks ) {
			return '';
		}
		$parts = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				$parts[] = $priority . ':' . $id . ':' . (int) ( $callback['accepted_args'] ?? 0 );
			}
		}
		return implode( '|', $parts );
	}

	/**
	 * Drop active-extension memoized state.
	 */
	private static function reset_active_cache(): void {
		self::$active_cache     = null;
		self::$active_cache_key = '';
	}

	/**
	 * Every active contribution to one point, lowest priority first, then in registration order.
	 *
	 * @param string $point `track_data`, `set_data`, `app_data`, or `slots.<name>`.
	 * @return array<int, array{id: string, callback: callable, priority: int}>
	 */
	public static function contributions( string $point ): array {
		$list = array();
		foreach ( self::active() as $id => $extension ) {
			$found = str_starts_with( $point, 'slots.' ) ? ( $extension['slots'][ substr( $point, 6 ) ] ?? null ) : ( $extension[ $point ] ?? null );
			if ( $found ) {
				$list[] = array(
					'id'       => $id,
					'callback' => $found['callback'],
					'priority' => $found['priority'],
					'order'    => $extension['order'],
				);
			}
		}
		usort( $list, static fn( array $a, array $b ) => array( $a['priority'], $a['order'] ) <=> array( $b['priority'], $b['order'] ) );
		return $list;
	}

	/**
	 * Which extensions shape cached set data. Sets::all() keys its cache on this, so enabling,
	 * disabling or updating one rebuilds the data rather than serving yesterday's.
	 */
	public static function data_fingerprint(): string {
		$parts = array();
		foreach ( self::active() as $id => $extension ) {
			if ( $extension['track_data'] || $extension['set_data'] ) {
				$parts[] = $id . '@' . $extension['version'];
			}
		}
		return md5( implode( ',', $parts ) );
	}

	/**
	 * Run track_data and set_data inside callboard_set_data.
	 *
	 * @param array<string, mixed> $set  Set data.
	 * @param WP_Post              $post Set post.
	 * @return array<string, mixed>
	 */
	public static function filter_set_data( array $set, WP_Post $post ): array {
		$track_points = self::contributions( 'track_data' );
		foreach ( $set['tracks'] as $k => $track ) {
			$ext        = array();
			$attachment = get_post( (int) $track['id'] );
			if ( $attachment instanceof WP_Post ) {
				foreach ( $track_points as $point ) {
					$value = call_user_func( $point['callback'], $track, $attachment );
					if ( is_array( $value ) ) {
						$ext[ $point['id'] ] = $value;
					}
				}
			}
			foreach ( self::TRACK_ALIASES as $field => $source ) {
				$track[ $field ] = $ext[ $source[0] ][ $source[1] ] ?? null;
			}
			$track['ext']        = $ext;
			$set['tracks'][ $k ] = $track;
		}

		$ext = array();
		foreach ( self::contributions( 'set_data' ) as $point ) {
			$value = call_user_func( $point['callback'], $set, $post );
			if ( is_array( $value ) ) {
				$ext[ $point['id'] ] = $value;
			}
		}
		$set['ext'] = $ext;
		return $set;
	}

	/**
	 * Run app_data inside callboard_app_data, and tell the script which extensions are active.
	 *
	 * @param array<string, mixed> $data App data.
	 * @return array<string, mixed>
	 */
	public static function filter_app_data( array $data ): array {
		$ext = array();
		foreach ( self::contributions( 'app_data' ) as $point ) {
			$value = call_user_func( $point['callback'] );
			if ( is_array( $value ) ) {
				$ext[ $point['id'] ] = $value;
			}
		}
		$active = array();
		foreach ( self::active() as $id => $extension ) {
			$active[ $id ] = $extension['version'];
		}
		$data['apiVersion'] = CALLBOARD_API_VERSION;
		$data['extensions'] = $active;
		$data['ext']        = $ext;
		$data['debug']      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		return $data;
	}

	/**
	 * The items every active extension puts in an item slot, escaped and ready to print.
	 *
	 * @param string $slot    `track_badges` or `track_meta`.
	 * @param mixed  ...$context Passed to each callback: the track and the set.
	 */
	public static function render_items( string $slot, ...$context ): string {
		$html = '';
		foreach ( self::contributions( 'slots.' . $slot ) as $point ) {
			$items = call_user_func_array( $point['callback'], $context );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$html .= self::item( $item, $point['id'] );
			}
		}
		return $html;
	}

	/**
	 * One item as a span. Text is text: an item never carries markup.
	 *
	 * @param mixed  $item Item array.
	 * @param string $id   Contributing extension.
	 */
	public static function item( $item, string $id ): string {
		if ( ! is_array( $item ) || ! isset( $item['text'] ) || ! is_scalar( $item['text'] ) || '' === (string) $item['text'] ) {
			return '';
		}
		$classes = array( 'cb-item' );
		foreach ( preg_split( '/\s+/', (string) ( $item['className'] ?? '' ) ) as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		$attrs = ' class="' . esc_attr( implode( ' ', $classes ) ) . '" data-extension="' . esc_attr( $id ) . '"';
		if ( isset( $item['tone'] ) && in_array( $item['tone'], self::TONES, true ) && 'default' !== $item['tone'] ) {
			$attrs .= ' data-tone="' . esc_attr( $item['tone'] ) . '"';
		}
		if ( ! empty( $item['title'] ) && is_scalar( $item['title'] ) ) {
			$attrs .= ' title="' . esc_attr( (string) $item['title'] ) . '"';
		}
		if ( ! empty( $item['label'] ) && is_scalar( $item['label'] ) ) {
			$attrs .= ' aria-label="' . esc_attr( (string) $item['label'] ) . '"';
		}
		return '<span' . $attrs . '>' . esc_html( (string) $item['text'] ) . '</span>';
	}

	/**
	 * What every active extension puts in an HTML slot, through kses.
	 *
	 * @param string $slot    `set_header`, `transport` or `panels`.
	 * @param mixed  ...$context Passed to each callback: the set, for set_header.
	 */
	public static function render_html( string $slot, ...$context ): string {
		$html = '';
		foreach ( self::contributions( 'slots.' . $slot ) as $point ) {
			$markup = call_user_func_array( $point['callback'], $context );
			if ( is_string( $markup ) && '' !== $markup ) {
				// kses drops a tag it does not allow but keeps what was inside it, which for a script or
				// a style is code printed as text. Those go whole, the way wp_strip_all_tags() takes them.
				$markup = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $markup );
				$html  .= wp_kses( $markup, self::allowed_html( $slot ) );
			}
		}
		return $html;
	}

	/**
	 * The markup an HTML slot accepts: controls and the text around them. No scripts, no styles, no
	 * event attributes. Behaviour belongs in the extension's script, which binds to this markup.
	 *
	 * @param string $slot Slot name.
	 * @return array<string, array<string, mixed>>
	 */
	public static function allowed_html( string $slot ): array {
		$global = array(
			'class'            => true,
			'id'               => true,
			'title'            => true,
			'role'             => true,
			'hidden'           => true,
			'tabindex'         => true,
			'data-*'           => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
			'aria-expanded'    => true,
			'aria-controls'    => true,
			'aria-pressed'     => true,
			'aria-live'        => true,
			'aria-current'     => true,
		);
		$svg    = array(
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'focusable'       => true,
		);
		$html   = array(
			'span'   => $global,
			'b'      => $global,
			'i'      => $global,
			'strong' => $global,
			'em'     => $global,
			'small'  => $global,
			'p'      => $global,
			'div'    => $global,
			'ul'     => $global,
			'ol'     => $global,
			'li'     => $global,
			'a'      => $global + array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'button' => $global + array(
				'type'     => array( 'values' => array( 'button' ) ),
				'disabled' => true,
				'name'     => true,
				'value'    => true,
			),
			'label'  => $global + array( 'for' => true ),
			'input'  => $global + array(
				'type'     => array( 'values' => array( 'range', 'checkbox' ) ),
				'name'     => true,
				'value'    => true,
				'min'      => true,
				'max'      => true,
				'step'     => true,
				'checked'  => true,
				'disabled' => true,
			),
			'svg'    => $global + $svg,
			'g'      => $global + $svg,
			'path'   => $global + $svg + array( 'd' => true ),
			'circle' => $global + $svg + array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'rect'   => $global + $svg + array(
				'x'  => true,
				'y'  => true,
				'rx' => true,
			),
		);

		/**
		 * The markup an HTML slot accepts, in wp_kses form.
		 *
		 * @param array<string, array<string, mixed>> $html Allowed tags and attributes.
		 * @param string                              $slot `set_header`, `transport` or `panels`.
		 */
		return (array) apply_filters( 'callboard_slot_allowed_html', $html, $slot );
	}

	/**
	 * REST routes extensions declare, under callboard/v1.
	 *
	 * Callboard's own extensions get `/<name>/`, which is where the plugin's routes have always
	 * lived. Anybody else gets `/<namespace>/<name>/`, so two plugins that both call a thing "notes"
	 * cannot land on the same URL.
	 */
	public static function register_routes(): void {
		foreach ( self::active() as $id => $extension ) {
			foreach ( $extension['rest'] as $route ) {
				$args     = $route[1];
				$declared = $args['permission_callback'] ?? null;
				// The gate is not optional: a route that serves a cast-only site's data obeys the
				// same rule as the page. An extension's own permission check runs after it.
				$args['permission_callback'] = static function ( WP_REST_Request $request ) use ( $declared ) {
					if ( ! callboard_rest_can_view() ) {
						return false;
					}
					return is_callable( $declared ) ? call_user_func( $declared, $request ) : true;
				};
				/**
				 * A route an extension declared in its `rest` argument, under callboard/v1/<name>/ for
				 * Callboard's own and callboard/v1/<namespace>/<name>/ for anybody else's.
				 */
				register_rest_route( 'callboard/v1', self::rest_base( $id ) . $route[0], $args );
			}
		}
	}

	/**
	 * `/push` for callboard/push, `/example/demo` for example/demo.
	 *
	 * @param string $id Extension id.
	 */
	public static function rest_base( string $id ): string {
		list( $namespace, $name ) = explode( '/', $id, 2 );
		return 'callboard' === $namespace ? '/' . $name : '/' . $namespace . '/' . $name;
	}

	/**
	 * Whether a REST route belongs to an extension that declared it. Privacy lets these through for
	 * signed-out visitors; the route's own permission callback still applies the gate.
	 *
	 * @param string $route Route path, such as /callboard/v1/example/demo/ping.
	 */
	public static function is_extension_route( string $route ): bool {
		foreach ( self::active() as $id => $extension ) {
			if ( $extension['rest'] && str_starts_with( $route, '/callboard/v1' . self::rest_base( $id ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Register `script` and `style` given as { src, deps, version } under a handle of their own.
	 * Handles given as strings are the extension's to register, before this runs.
	 */
	public static function register_assets(): void {
		foreach ( self::$registered as $id => $extension ) {
			foreach ( array( 'script', 'style' ) as $kind ) {
				$asset = $extension[ $kind ];
				if ( ! is_array( $asset ) || empty( $asset['src'] ) ) {
					continue;
				}
				$handle = str_replace( '/', '-', $id );
				if ( 'script' === $kind ) {
					wp_register_script( $handle, (string) $asset['src'], (array) ( $asset['deps'] ?? array() ), $asset['version'] ?? $extension['version'], array( 'strategy' => 'defer' ) );
				} else {
					wp_register_style( $handle, (string) $asset['src'], (array) ( $asset['deps'] ?? array() ), $asset['version'] ?? $extension['version'] );
				}
				self::$registered[ $id ][ $kind ] = $handle;
			}
		}
	}

	/**
	 * Script and style handles of the active extensions.
	 *
	 * @return array{scripts: string[], styles: string[]}
	 */
	public static function handles(): array {
		$out = array(
			'scripts' => array(),
			'styles'  => array(),
		);
		foreach ( self::active() as $extension ) {
			if ( is_string( $extension['script'] ) && '' !== $extension['script'] ) {
				$out['scripts'][] = $extension['script'];
			}
			if ( is_string( $extension['style'] ) && '' !== $extension['style'] ) {
				$out['styles'][] = $extension['style'];
			}
		}
		return $out;
	}

	/**
	 * Enqueue extension assets on the front end. Each script runs after Callboard's, so
	 * window.callboard is there when it does.
	 */
	public static function enqueue(): void {
		$handles = self::handles();
		foreach ( $handles['scripts'] as $handle ) {
			$script = wp_scripts()->query( $handle, 'registered' );
			if ( ! $script ) {
				continue;
			}
			if ( ! in_array( 'callboard', $script->deps, true ) ) {
				$script->deps[] = 'callboard';
			}
			// A blocking script that depends on a deferred one makes WordPress load that one blocking
			// too, which would undo the defer on Callboard's own script for every extension added.
			wp_script_add_data( $handle, 'strategy', 'defer' );
			wp_enqueue_script( $handle );
		}
		foreach ( $handles['styles'] as $handle ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * The URLs a registered script or style is requested at, the way WordPress prints them.
	 *
	 * @param string $handle Handle.
	 * @param string $kind   `script` or `style`.
	 */
	public static function asset_url( string $handle, string $kind = 'script' ): string {
		$deps = 'style' === $kind ? wp_styles() : wp_scripts();
		$item = $deps->query( $handle, 'registered' );
		if ( ! $item || ! $item->src ) {
			return '';
		}
		$src = (string) $item->src;
		if ( ! preg_match( '|^(https?:)?//|', $src ) && ! ( $deps->content_url && str_starts_with( $src, $deps->content_url ) ) ) {
			$src = $deps->base_url . $src;
		}
		$ver = '';
		if ( empty( $item->ver ) && null !== $item->ver && is_string( $deps->default_version ) ) {
			$ver = $deps->default_version;
		} elseif ( is_scalar( $item->ver ) ) {
			$ver = (string) $item->ver;
		}
		if ( '' !== $ver ) {
			$src .= ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
		}
		// Core's own filter on the URL it prints, so a CDN rewrite reaches the precache list too.
		$filter = 'style' === $kind ? 'style_loader_src' : 'script_loader_src';
		return (string) apply_filters( $filter, $src, $handle ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's filter, applied the way core applies it.
	}

	/**
	 * What the service worker keeps beyond Callboard's own files: core's hooks script and every
	 * active extension's script and style.
	 *
	 * @return string[] Site-relative URLs.
	 */
	public static function precache_urls(): array {
		$urls    = array( self::asset_url( 'wp-hooks' ) );
		$handles = self::handles();
		foreach ( $handles['scripts'] as $handle ) {
			$urls[] = self::asset_url( $handle );
		}
		foreach ( $handles['styles'] as $handle ) {
			$urls[] = self::asset_url( $handle, 'style' );
		}
		return array_values( array_unique( array_map( 'wp_make_link_relative', array_filter( $urls ) ) ) );
	}

	/**
	 * Rewrite the service worker when the set of extension assets changed, and at no other time.
	 *
	 * Runs in the admin, where a plugin is activated or updated, rather than on every front-end
	 * request. Rewriting sw.js starts a new shell cache for every visitor, so a filter that switches
	 * an extension per request must never be able to trigger it.
	 *
	 * @return bool Whether the worker was rewritten.
	 */
	public static function maybe_refresh_worker(): bool {
		if ( get_option( 'callboard_extension_assets' ) === self::assets_fingerprint() ) {
			return false;
		}
		Pwa::write_sw(); // Records the fingerprint of what it wrote, so the next request finds it current.
		return true;
	}

	/**
	 * A short name for the list of extension assets, stored beside the service worker it went into.
	 */
	public static function assets_fingerprint(): string {
		return md5( implode( ',', self::precache_urls() ) );
	}
}
