# Extending Callboard

Callboard has one way to add a feature to the player, and its own features use it. A count-in, the quality readout in Now Playing and the number on the Home Screen icon are all registered as extensions, through the same functions a plugin calls. A site can switch any of them off or replace it by id. When Callboard needs something a plugin could not do, the fix is to add it to this document and to the API.

This is API version 1: `CALLBOARD_API_VERSION` in PHP, `callboard.apiVersion` in the page.

- [An extension](#an-extension)
- [Registering one](#registering-one)
- [Lifecycle](#lifecycle)
- [Contribution points](#contribution-points)
- [Items and markup](#items-and-markup)
- [Events](#events)
- [State and commands](#state-and-commands)
- [Callboard's own extensions](#callboards-own-extensions)
- [The hooks underneath](#the-hooks-underneath)
- [The contract](#the-contract)
- [Caching, offline and the gate](#caching-offline-and-the-gate)

## An extension

An extension is an id, a version, what it contributes, and a lifecycle.

- `id`: `namespace/name`, lowercase letters, digits and hyphens, the shape of a block name. `callboard/*` belongs to the plugin. Registering one from anywhere else is refused with a `_doing_it_wrong` notice.
- `version`: the extension's own version string. It keys the set cache (see [Caching](#caching-offline-and-the-gate)), so change it when your data changes shape.
- `api_version` (`apiVersion` in the script): the contract the extension was written for. Anything other than a version this Callboard runs is refused with a notice.
- What it contributes: the points in the table below.

An extension is registered in PHP first. The script can only register an id that PHP registered and left enabled for this request, so a feature switched off on the server cannot come back on in the page.

## Registering one

In PHP, on `callboard_register_extensions`:

```php
add_action( 'callboard_register_extensions', function () {
	callboard_register_extension( 'acme/call-sheet', array(
		'version'     => '1.0.0',
		'api_version' => 1,
		'track_data'  => fn( array $track, WP_Post $attachment ) => array(
			'page' => (int) get_post_meta( $attachment->ID, '_acme_page', true ),
		),
		'slots'       => array(
			'track_badges' => fn( array $track ) => $track['ext']['acme/call-sheet']['page']
				? array( array( 'text' => 'p. ' . $track['ext']['acme/call-sheet']['page'], 'tone' => 'muted' ) )
				: array(),
		),
		'script'      => array( 'src' => plugins_url( 'call-sheet.js', __FILE__ ), 'version' => '1.0.0' ),
	) );
} );
```

In the script, which Callboard loads after its own:

```js
window.callboard.registerExtension( 'acme/call-sheet', {
	version: '1.0.0',
	apiVersion: 1,
	slots: {
		nowPlayingMeta: ( track ) => [ { text: `Script p. ${ track.ext[ 'acme/call-sheet' ].page }` } ],
	},
	events: {
		loop: ( loop ) => loop && console.log( 'working a section', loop.a, loop.b ),
	},
} );
```

The PHP functions are `callboard_register_extension( $id, $args )`, `callboard_unregister_extension( $id )`, `callboard_get_extension( $id )` and `callboard_get_extensions()`. The first returns the registered extension or `false`; the second returns the removed one or `false`.

Every contribution takes either a callable or `array( 'callback' => …, 'priority' => … )` (`{ callback, priority }` in the script). Priority defaults to the extension's own `priority`, which defaults to 10. Lower runs first; equal priorities run in the order extensions registered.

## Lifecycle

In PHP, Callboard registers its own extensions on `init` at priority 5, then fires `callboard_register_extensions`. Register there at the default priority; unregister or replace one of Callboard's at a later priority. From then on an extension is active on every request unless `callboard_extension_enabled` returns false for its id. Data callbacks run when set data is built. Slot callbacks run when a template renders.

In the script, an extension goes through four steps:

1. `registerExtension( id, args )`.
2. `setup( app )`, once per page. `app` is `window.callboard`. This is the place for anything that lasts across views: the deck, the tab, the badge.
3. `init( view )`, on every view: the first load, and each time navigation swaps a new view into `<main>`. `view` is `{ slug, set, main, signal }`, where `slug` is `''` on home, `set` is a frozen copy or `null`, and `main` is the element.
4. `teardown( view )`, just before the next swap replaces `<main>`. `view.signal` aborts straight after, so `addEventListener( type, fn, { signal: view.signal } )` removes itself without a teardown at all.

An extension can also register after the page is up. It gets `setup` and the current view's `init` straight away, and every slot re-renders.

`callboard.unregisterExtension( id )` tears the extension's view down, removes its filters, actions, commands and client-rendered items, and re-renders. It cannot remove what the server already rendered; switch an extension off in PHP for that.

## Contribution points

PHP names are snake_case and script names camelCase; where a point exists on both sides it is one point.

| Point | PHP argument | Script argument | Rendered by | Runs again |
| --- | --- | --- | --- | --- |
| Track data | `track_data( array $track, WP_Post $attachment ): array` | read `track.ext[ id ]` | PHP, inside `callboard_set_data` | When set data is rebuilt: on any save, import or attachment change, or when the active extensions change |
| Set data | `set_data( array $set, WP_Post $post ): array` | read `set.ext[ id ]` | PHP, inside `callboard_set_data` | As track data |
| App data | `app_data(): array` | `callboard.data( id )` | PHP, inside `callboard_app_data` | Every page load, never cached. The place for settings and anything per visitor |
| Track row badges | `slots.track_badges( $track, $set ): item[]` | `slots.trackBadges( track, view ): item[]` | Server items in the row as it renders, before the length. Client items added by the script beside them | Server: every render. Client: on `init` and `callboard.invalidate( 'trackBadges' )` |
| Track row metadata | `slots.track_meta( $track, $set ): item[]` | `slots.trackMeta( track, view ): item[]` | The row's second line, after the artist | As badges |
| Set header | `slots.set_header( $set ): string` | bind to it in `init` | PHP, beside Play all, Save and Share, on a set with tracks | Every render |
| Now Playing metadata | none | `slots.nowPlayingMeta( track, app ): item[]` | The script, between elapsed and remaining | On every track, and `callboard.invalidate( 'nowPlayingMeta' )` |
| Transport controls | `slots.transport(): string` | bind to it in `setup` | PHP, beside loop and repeat | Once per page |
| Panels | `slots.panels(): string` | bind to it in `setup` | PHP, after the lyrics sheet, outside `<main>` | Once per page |
| Before play | none | `beforePlay( context, signal )` | The script, before a track that was asked to play starts | Every time a track is loaded to play |
| App badge | none | `badge( app ): number \| Promise<number>` | The script sums every contribution, then `setAppBadge( sum )`, or `clearAppBadge()` for zero | On ready, when the page becomes visible, and `callboard.invalidate( 'badge' )` |
| Events | none | `events: { track: fn, … }` | wp.hooks actions (see [Events](#events)) | As they fire |
| Commands | none | `commands: { name: fn }` | `callboard.run( 'ns/name/command', …args )` | When run |
| REST routes | `rest: array( array( '/route', $args ) )` | none | `register_rest_route()` under `callboard/v1` | n/a |
| Assets | `script`, `style`: a handle registered on `init`, or `array( 'src', 'deps', 'version' )` for Callboard to register | none | PHP enqueues them after Callboard's script and keeps them on the page | n/a |

A `beforePlay` contribution gets `context`, which is `{ set, track, index, at }`. Return nothing to let the track start, `false` to stop it, or a promise of either. Contributions run one after another in priority order and the track starts once every one has said yes. `signal` aborts if the person presses Play (which starts the track at once), picks another track, or dismisses the deck; an extension that holds the start should stop what it is doing when that happens. The count-in is built on this.

For REST routes, Callboard's own extensions get `callboard/v1/<name>/…`; anybody else gets `callboard/v1/<namespace>/<name>/…`, so `acme/call-sheet` declaring `/pages` answers at `/wp-json/callboard/v1/acme/call-sheet/pages`. These routes are open to signed-out visitors, which the rest of the REST API is not on a Callboard site. Their permission check runs `callboard_rest_can_view()` first, which applies the front-end gate, and then yours if you gave one.

Callboard removes every other script and style from its pages. An extension's assets are kept, depend on Callboard's script, load deferred, and are kept by the service worker for an offline start.

## Items and markup

Badges, row metadata and Now Playing metadata take items, which are data and never markup:

```
{ text, label?, title?, tone?: 'default' | 'accent' | 'muted', className? }
```

Callboard escapes them (`esc_html` and `esc_attr` in PHP, `textContent` in the script) and renders `<span class="cb-item" data-extension="ns/name">`. Class names are checked one at a time and anything that is not a class name is dropped. An unknown tone is dropped too.

The set header, transport and panels take markup, passed through `wp_kses()` with `callboard_slot_allowed_html( $slot )`: buttons of type button, spans, labels, range and checkbox inputs, links, a few text elements, a small SVG subset, `class`, `id`, `title`, `role`, `hidden`, `data-*` and the common `aria-*` attributes. Scripts and styles are removed along with their contents, and so are event attributes. Behaviour belongs in the extension's script, bound to the markup. Filter `callboard_slot_allowed_html` to allow more.

The script builds DOM nodes from items and never assigns extension strings to `innerHTML`.

## Events

Events are `wp.hooks` actions named `callboard.<event>`. Each also fires as a DOM `CustomEvent` named `callboard:<event>` on `document`, with the same detail, for a script that loads nothing.

| Event | Detail |
| --- | --- |
| `ready` | `{ view, set }`, once every deferred script, extensions included, has run |
| `view` | `{ view: 'home' \| 'set', set: slug }`, after every extension's `init` |
| `viewTeardown` | `{ view, set }`, before every extension's `teardown` |
| `track` | `{ set, track, index }`, when a track loads into the deck, playing or not |
| `play`, `pause`, `ended` | `{ set, track, index, position }` |
| `seek` | `{ from, to }` in seconds |
| `loop` | `{ a, b }` in seconds when an A-B loop is set, `null` when it is cleared |
| `save` | `{ set, track, state }` where `state` is `saved` (downloaded) or `loaded` (from a file) |
| `unsave` | `{ set, track, state: '' }` |
| `online`, `offline` | `{}` |

Listen with the `events` argument, which removes the listener when the extension is unregistered and keeps one extension's error from reaching another, or with `wp.hooks.addAction( 'callboard.track', 'acme/call-sheet', fn )`.

An extension's own events are named `namespace.name.event` and fired with `callboard.emit( 'acme.call-sheet.turned', detail )`, which also fires `callboard:acme.call-sheet.turned` on `document`.

Tracks and sets in event details are frozen copies, so changing one has no effect on the player.

## State and commands

`callboard.state` reads the player. Every getter returns a value or a frozen copy.

| Property | What |
| --- | --- |
| `view` | The slug of the view on screen, `''` for home |
| `set` | The set in the deck, or `null` |
| `track`, `index` | The track in the deck and its index, or `null` and `-1` |
| `position`, `duration` | Seconds |
| `paused` | Whether the element is paused |
| `loop` | `{ a, b }` or `null` |
| `online` | `navigator.onLine` |

`callboard.commands` moves it: `play()`, `pause()`, `seek( seconds )`, `next()`, `prev()`, `goTo( slug, index = 0, { at = 0, play = true } )` (opens the set if it is not on screen, and resolves to `true` or `false`), and `display( text | null, { detail, className } )`, which puts text on the deck's title line until it is called with `null`.

An extension adds commands with the `commands` argument or `callboard.registerCommand( 'ns/name/command', fn )`, and anybody runs them with `callboard.run( 'ns/name/command', …args )`.

The rest of `window.callboard`: `version` (the plugin), `hooks` (`wp.hooks`), `extensions()` (ids registered in the page), `isActive( id )` (enabled on the server for this request), `data( id )`, `invalidate( …points )`, `emit( name, detail )`, and `deprecated( name, { since, alternative, hint } )`.

## Callboard's own extensions

| Id | What it contributes | Switched off |
| --- | --- | --- |
| `callboard/count-in` | Track data `bpm`, app data `enabled`, a track badge (`♩ 96`, class `bpm`), and a `beforePlay` hold that taps four beats | No badge, and tracks start at once |
| `callboard/quality` | Track data `quality`, app data `format`, and a Now Playing item (class `quality-pill`) | Now Playing shows no quality |
| `callboard/badging` | An app badge contribution of zero, so opening the app clears what a notification set | The page leaves the badge alone |
| `callboard/practice` | App data `url` and, for signed-in users, `nonce`; a REST route, `POST callboard/v1/practice/counts`; and listeners on the `track`, `loop`, `play`, `pause` and `ended` events. Registered only when the **Count practice** setting is on | Nothing is counted or sent |

They live in `includes/extensions/` and in their own sections at the bottom of `assets/app.js`, where they can reach `window.callboard` and nothing else. To replace one:

```php
add_action( 'callboard_register_extensions', function () {
	callboard_unregister_extension( 'callboard/quality' );
	callboard_register_extension( 'acme/quality', array( 'version' => '1.0.0', 'api_version' => 1, 'script' => 'acme-quality' ) );
}, 20 );
```

To switch one off from a setting that only changes when you save it:

```php
$disable_count_in = (bool) get_option( 'acme_disable_count_in', false );
add_filter( 'callboard_extension_enabled', fn( bool $on, string $id ) => $on && ! ( 'callboard/count-in' === $id && $disable_count_in ), 10, 2 );
```

More of Callboard's features become extensions in later releases: offline saving, lyrics, director's notes and push.

`tests/mu-plugins/callboard-example-extension.php` and `tests/mu-plugins/callboard-example/example.js` are a complete extension built on nothing but this API, exercising every point. `tests/e2e/extensions.spec.js` and `tests/php/test-extensions.php` hold it, and Callboard's own extensions, to this document.

## The hooks underneath

The registry is built on the plugin's hooks, which do not change. A site that uses them directly keeps working.

| Registry argument | Hook it runs inside |
| --- | --- |
| `track_data`, `set_data` | `callboard_set_data`, priority 5 |
| `app_data` | `callboard_app_data`, priority 5 |
| `slots.*` in PHP | `callboard_slot()` in the templates; `callboard_template_path` still replaces a whole template |
| `slots.*` in the script | `wp.hooks` filters `callboard.slot.trackBadges`, `callboard.slot.trackMeta`, `callboard.slot.nowPlayingMeta` |
| `badge` | `wp.hooks` filter `callboard.badge` |
| `beforePlay` | `wp.hooks` filter `callboard.beforePlay` |
| `events` | `wp.hooks` actions `callboard.*` |

In the script the extension id is the `wp.hooks` namespace, so a registry contribution and `wp.hooks.addFilter( 'callboard.slot.trackBadges', 'acme/call-sheet', fn )` are the same thing, and `removeFilter` with that namespace removes both. A hand-written slot filter receives the items so far and returns the list with its own added.

Core's `wp-hooks` is the only WordPress script Callboard itself puts on the page. It ships with WordPress, so there is still no build step.

## The contract

- **Versions.** Within API version 1, contribution points, arguments, event names and payload fields are only ever added. Renaming or removing one takes a deprecation that ships in at least one minor release first, and the removal waits for API version 2 and a major release of the plugin.
- **Deprecations.** PHP uses `_deprecated_hook`, `_deprecated_function` and `_deprecated_argument`. The script uses `callboard.deprecated()`, which warns once per name in the shape of `@wordpress/deprecated`. Tracks still carry `bpm` and `quality` at the top level for API version 1; they belong to `ext[ 'callboard/count-in' ]` and `ext[ 'callboard/quality' ]` now, and reading the old ones warns where `SCRIPT_DEBUG` is on.
- **Names.** Ids are `namespace/name`. Data sits under `ext[ id ]`. Script hooks and events are `namespace.name.*`. Classes an extension adds start with its namespace (`acme-`). `cb-` and the plugin's unprefixed classes are Callboard's; its own extensions keep the classes they always had (`bpm`, `quality-pill`), so existing styles and tests hold.
- **Escaping.** Callboard escapes items and runs markup through kses. An extension sanitises its own data before returning it. Data is JSON and small: app data carries every set and every track on every page, so anything heavy belongs behind a route.
- **Strings.** Translate in PHP and pass strings through `app_data`. The page loads no `wp-i18n`.

## Caching, offline and the gate

- **Set data.** It is cached for a day and cleared whenever a set changes. The cache is keyed on the ids and versions of the extensions that contribute track or set data, so enabling, disabling or updating one rebuilds it. A decision that changes per request (per user, say) belongs in `app_data`, which is never cached.
- **Offline.** The service worker keeps extension assets at the URLs the page asks for, together with core's `wp-hooks`. It is rewritten when that list changes, which Callboard checks in the admin (where plugins are activated and updated), never on a front-end request, since a new worker starts a new shell cache for every visitor.
- **The gate.** When a site requires sign-in, a signed-out visitor gets no app data, no extension scripts or data, and a 401 from extension routes.
