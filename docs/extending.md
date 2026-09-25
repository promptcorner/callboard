# Extending Callboard

Callboard has one way to add a feature to the player, and its own features use it. The quality readout in Now Playing is registered as an extension, through the same functions a plugin calls. A site can switch it off or replace it by id. When Callboard needs something a plugin could not do, the fix is to add it to this document and to the API.

This is API version 1: `CALLBOARD_API_VERSION` in PHP, `callboard.apiVersion` in the page.

A playlist is a `callboard_set` post, so code and data call it a set: `set_data`, `set.ext`, `$set`.

- [An extension](#an-extension)
- [Registering one](#registering-one)
- [Lifecycle](#lifecycle)
- [Contribution points](#contribution-points)
- [REST routes](#rest-routes)
- [Items and markup](#items-and-markup)
- [Events](#events)
- [State and commands](#state-and-commands)
- [Callboard's own extensions](#callboards-own-extensions)
- [The hooks underneath](#the-hooks-underneath)
- [The contract](#the-contract)
- [Caching, offline and sign-in](#caching-offline-and-sign-in)

## An extension

An extension is an id, a version, what it contributes, and a lifecycle.

- `id`: `namespace/name`, lowercase letters, digits and hyphens, the shape of a block name. Some namespaces are reserved (see [Names](#the-contract)), and registering under one is refused with a `_doing_it_wrong` notice in PHP and a warning in the page.
- `version`: the extension's own version string. It keys the playlist cache (see [Caching](#caching-offline-and-sign-in)), so change it when your data changes shape.
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
		track: ( { track } ) => console.log( 'now on', track.title ),
	},
} );
```

The PHP functions are `callboard_register_extension( $id, $args )`, `callboard_unregister_extension( $id )`, `callboard_get_extension( $id )` and `callboard_get_extensions()`. The first returns the registered extension or `false`; the second returns the removed one or `false`.

`callboard_get_setting( $key )` reads one of Callboard's settings, with its default when the site never saved one, and returns `null` for a key that does not exist. The keys are the ones on the settings screen: `tagline`, `footer_note`, `badge`, `accent`, `confetti`, `hearts`, `show_hint`, `offline`, `require_signin` and `require_access`.

Every contribution takes either a callable or `array( 'callback' => …, 'priority' => … )` (`{ callback, priority }` in the script). Priority defaults to the extension's own `priority`, which defaults to 10. Lower runs first; equal priorities run in the order extensions registered.

## Lifecycle

In PHP, Callboard registers its own extensions on `init` at priority 5, then fires `callboard_register_extensions`. Register there at the default priority; unregister or replace one of Callboard's at a later priority. From then on an extension is active on every request unless `callboard_extension_enabled` returns false for its id. Data callbacks run when playlist data is built. Slot callbacks run when a template renders.

In the script, an extension goes through four steps:

1. `registerExtension( id, args )`.
2. `setup( app )`, once per page. `app` is `window.callboard`. This is the place for anything that lasts across views: the player bar and the tab.
3. `init( view )`, on every view: the first load, and each time navigation swaps a new view into `<main>`. `view` is `{ slug, set, main, signal }`, where `slug` is `''` on home, `set` is a frozen copy of the playlist or `null`, and `main` is the element.
4. `teardown( view )`, just before the next swap replaces `<main>`. `view.signal` aborts straight after, so `addEventListener( type, fn, { signal: view.signal } )` removes itself without a teardown at all.

An extension can also register after the page is up. It gets `setup` and the current view's `init` straight away, and every slot re-renders.

`callboard.unregisterExtension( id )` tears the extension's view down, removes its filters, actions, commands and client-rendered items, and re-renders. It cannot remove what the server already rendered; switch an extension off in PHP for that.

## Contribution points

PHP names are snake_case and script names camelCase; where a point exists on both sides it is one point.

| Point | PHP argument | Script argument | Rendered by | Runs again |
| --- | --- | --- | --- | --- |
| Track data | `track_data( array $track, WP_Post $attachment ): array` | read `track.ext[ id ]` | PHP, inside `callboard_set_data` | When playlist data is rebuilt: on any save, import or attachment change, or when the active extensions change |
| Playlist data | `set_data( array $set, WP_Post $post ): array` | read `set.ext[ id ]` | PHP, inside `callboard_set_data` | As track data |
| App data | `app_data(): array` | `callboard.data( id )` | PHP, inside `callboard_app_data` | Every page load, never cached. The place for settings and anything per visitor |
| Track row badges | `slots.track_badges( $track, $set ): item[]` | `slots.trackBadges( track, view ): item[]` | Server items in the row as it renders, before the length. Client items added by the script beside them | Server: every render. Client: on `init` and `callboard.invalidate( 'trackBadges' )` |
| Track row metadata | `slots.track_meta( $track, $set ): item[]` | `slots.trackMeta( track, view ): item[]` | The row's second line, after the artist | As badges |
| Playlist header | `slots.set_header( $set ): string` | bind to it in `init` | PHP, beside Play all, Save and Share, on a playlist with tracks | Every render |
| Now Playing metadata | none | `slots.nowPlayingMeta( track, app ): item[]` | The script, between elapsed and remaining | On every track, and `callboard.invalidate( 'nowPlayingMeta' )` |
| Transport controls | `slots.transport(): string` | bind to it in `setup` | PHP, beside repeat | Once per page |
| Panels | `slots.panels(): string` | bind to it in `setup` | PHP, outside `<main>` | Once per page |
| Before play | none | `beforePlay( context, signal )` | The script, before a track that was asked to play starts | Every time a track is loaded to play |
| Events | none | `events: { track: fn, … }` | wp.hooks actions (see [Events](#events)) | As they fire |
| Commands | none | `commands: { name: fn }` | `callboard.run( 'ns/name/command', …args )` | When run |
| REST routes | `rest: array( array( '/route', $args ) )` | none | `register_rest_route()` under `callboard/v1/ext/` (see [REST routes](#rest-routes)) | n/a |
| Assets | `script`, `style`: a handle registered on `init`, or `array( 'src', 'deps', 'version' )` for Callboard to register | none | PHP enqueues them after Callboard's script and keeps them on the page | n/a |

Track data, playlist data and app data are arrays in PHP and objects in the page. PHP encodes an empty array as `[]`, so the page turns an extension's empty data into `{}`. An extension that returned `array()` reads `{}` from `callboard.data( id )`, `set.ext[ id ]` and `track.ext[ id ]`, the same shape as one that returned fields.

A `beforePlay` contribution gets `context`, which is `{ set, track, index, at }`. Return nothing to let the track start, `false` to stop it, or a promise of either. Contributions run one after another in priority order and the track starts once every one has said yes. `signal` aborts if the person presses Play (which starts the track at once), picks another track, or closes the player bar; an extension that holds the start should stop what it is doing when that happens.

Callboard removes every other script and style from its pages. An extension's assets are kept, depend on Callboard's script, load deferred in the footer, and are kept by the service worker for an offline start.

## REST routes

Callboard's own extensions get `callboard/v1/<name>/…`. Anybody else gets `callboard/v1/ext/<namespace>/<name>/…`, so `acme/call-sheet` declaring `/pages` answers at `/wp-json/callboard/v1/ext/acme/call-sheet/pages`. None of Callboard's own extensions can be called `ext`, so a plugin's routes and Callboard's never share a path, whatever Callboard adds later.

In 2.3.0 a plugin's routes were at `callboard/v1/<namespace>/<name>/…`, with no `ext/`. That path still answers, as a deprecated copy of the new one: each request to it triggers a `_deprecated_function` notice naming the new path, which WordPress sends as an `X-WP-DeprecatedFunction` header when `WP_DEBUG` is on. It is not registered when one of Callboard's own extensions is called the same as your namespace (`callboard/acme` for `acme/call-sheet`), because Callboard's route comes first there. Move requests to the `ext/` path; the old one is removed in API version 2.

These routes are open to signed-out visitors, which the rest of the REST API is not on a Callboard site. Their permission check runs `callboard_rest_can_view()` first, which applies the sign-in requirement, and then yours if you gave one.

WordPress ignores the login cookie on a REST request that carries no nonce, and treats the request as signed out. On a site that requires sign-in, a signed-in user's request without a nonce gets a 401. For a signed-in user, app data carries `rest: { root, nonce }`, read in the page as `window.CALLBOARD.rest`. For anyone else it is `null`, and there is no nonce to send; pass `rest_url()` through your own `app_data` if you need the root then. Send the nonce in an `X-WP-Nonce` header, or as `_wpnonce` in the query string where you cannot set headers, as with `navigator.sendBeacon()`:

```js
const rest = window.CALLBOARD.rest;
if ( rest ) {
	fetch( `${ rest.root }callboard/v1/ext/acme/call-sheet/pages`, { headers: { 'X-WP-Nonce': rest.nonce } } );
	navigator.sendBeacon( `${ rest.root }callboard/v1/ext/acme/call-sheet/seen?_wpnonce=${ rest.nonce }` );
}
```

## Items and markup

Badges, row metadata and Now Playing metadata take items, which are data and never markup:

```
{ text, label?, title?, tone?: 'default' | 'accent' | 'muted', className? }
```

Callboard escapes them (`esc_html` and `esc_attr` in PHP, `textContent` in the script) and renders `<span class="cb-item" data-extension="ns/name">`. Class names are checked one at a time and anything that is not a class name is dropped. An unknown tone is dropped too.

The playlist header, transport and panels take markup, passed through `wp_kses()` with `callboard_slot_allowed_html( $slot )`: buttons of type button, spans, labels, range and checkbox inputs, links, a few text elements, a small SVG subset, `class`, `id`, `title`, `role`, `hidden`, `data-*` and the common `aria-*` attributes. Scripts and styles are removed along with their contents, and so are event attributes. Behaviour belongs in the extension's script, bound to the markup. Filter `callboard_slot_allowed_html` to allow more.

The script builds DOM nodes from items and never assigns extension strings to `innerHTML`.

## Events

Events are `wp.hooks` actions named `callboard.<event>`. Each also fires as a DOM `CustomEvent` named `callboard:<event>` on `document`, with the same detail, for a script that loads nothing.

| Event | Detail |
| --- | --- |
| `ready` | `{ view, set }`, once every deferred script, extensions included, has run |
| `view` | `{ view: 'home' \| 'set', set: slug }`, after every extension's `init` |
| `viewTeardown` | `{ view, set }`, before every extension's `teardown` |
| `track` | `{ set, track, index }`, when a track loads into the player bar, playing or not |
| `play`, `pause`, `ended` | `{ set, track, index, position }` |
| `seek` | `{ from, to }` in seconds |
| `save` | `{ set, track, state }` where `state` is `saved` (downloaded) or `loaded` (from a file) |
| `unsave` | `{ set, track, state: '' }` |
| `online`, `offline` | `{}` |

Listen with the `events` argument, which removes the listener when the extension is unregistered and keeps one extension's error from reaching another, or with `wp.hooks.addAction( 'callboard.track', 'acme/call-sheet', fn )`.

An extension's own events are named `namespace.name.event` and fired with `callboard.emit( 'acme.call-sheet.turned', detail )`, which also fires `callboard:acme.call-sheet.turned` on `document`. `emit()` checks that the name belongs to a registered extension, not that the script calling it is that extension. Any script on the page can fire an event in another extension's name, so check an event's detail before acting on it.

Tracks and playlists in event details are frozen copies, so changing one has no effect on the player. That includes `track` in `callboard:track`, which was the player's own track object before 2.3.0.

## State and commands

`callboard.state` reads the player. Every getter returns a value or a frozen copy.

| Property | What |
| --- | --- |
| `view` | The slug of the view on screen, `''` for home |
| `set` | The playlist in the player bar, or `null` |
| `track`, `index` | The track in the player bar and its index, or `null` and `-1` |
| `position`, `duration` | Seconds |
| `paused` | Whether the element is paused |
| `online` | `navigator.onLine` |

`callboard.commands` moves it: `play()`, `pause()`, `seek( seconds )`, `next()`, `prev()`, `goTo( slug, index = 0, { at = 0, play = true } )` (opens the playlist if it is not on screen, and resolves to `true` or `false`), and `display( text | null, { detail, className } )`, which puts text on the player bar's title line until it is called with `null`.

An extension adds commands with the `commands` argument or `callboard.registerCommand( 'ns/name/command', fn )`, and anybody runs them with `callboard.run( 'ns/name/command', …args )`.

The rest of `window.callboard`: `version` (the plugin), `hooks` (`wp.hooks`), `extensions()` (ids registered in the page), `isActive( id )` (enabled on the server for this request), `data( id )`, `invalidate( …points )`, `emit( name, detail )`, and `deprecated( name, { since, alternative, hint } )`.

## Callboard's own extensions

| Id | What it contributes | Switched off |
| --- | --- | --- |
| `callboard/quality` | Track data `quality`, app data `format`, and a Now Playing item (class `quality-pill`) | Now Playing shows no quality |

It lives in `includes/extensions/` and in its own section at the bottom of `assets/app.js`, where they can reach `window.callboard` and nothing else. To replace one:

```php
add_action( 'callboard_register_extensions', function () {
	callboard_unregister_extension( 'callboard/quality' );
	callboard_register_extension( 'acme/quality', array( 'version' => '1.0.0', 'api_version' => 1, 'script' => 'acme-quality' ) );
}, 20 );
```

To switch one off from a setting that only changes when you save it:

```php
$hide_quality = (bool) get_option( 'acme_hide_quality', false );
add_filter( 'callboard_extension_enabled', fn( bool $on, string $id ) => $on && ! ( 'callboard/quality' === $id && $hide_quality ), 10, 2 );
```

More of Callboard's features become extensions in later releases: offline saving.

`tests/mu-plugins/callboard-example-extension.php` and `tests/mu-plugins/callboard-example/example.js` are a complete extension built on nothing but this API, exercising every point. `tests/e2e/extensions.spec.js` and `tests/php/test-extensions.php` hold it, and Callboard's own extensions, to this document.

## The hooks underneath

The registry is built on the plugin's hooks, which do not change. A site that uses them directly keeps working.

| Registry argument | Hook it runs inside |
| --- | --- |
| `track_data`, `set_data` | `callboard_set_data`, priority 5 |
| `app_data` | `callboard_app_data`, priority 5 |
| `slots.*` in PHP | `callboard_slot()` in the templates; `callboard_template_path` still replaces a whole template |
| `slots.*` in the script | `wp.hooks` filters `callboard.slot.trackBadges`, `callboard.slot.trackMeta`, `callboard.slot.nowPlayingMeta` |
| `beforePlay` | `wp.hooks` filter `callboard.beforePlay` |
| `events` | `wp.hooks` actions `callboard.*` |

The top-level `quality` on each track is also written inside `callboard_set_data` at priority 5, from the extension's track data. Before 2.3.0 it was there from the start, so a filter on `callboard_set_data` at a priority below 5 no longer sees it. Read `ext[ 'callboard/quality' ]` at priority 6 or later. Since 3.0.0 there is no `bpm`: the count-in is gone.

In the script the extension id is the `wp.hooks` namespace, so a registry contribution and `wp.hooks.addFilter( 'callboard.slot.trackBadges', 'acme/call-sheet', fn )` are the same thing, and `removeFilter` with that namespace removes both. What a hand-written filter receives and must return:

- A slot filter receives the items so far and the same arguments as the slot, and returns the list with its own items added.
- `callboard.beforePlay` receives the holds so far and the context, `{ set, track, index, at }`. Return the array with your own entry added: `{ id, callback( context, signal ) }`, or a bare function with that signature. Callboard calls each entry in order, as described under [Contribution points](#contribution-points). If the result is not an array, or the array is empty, the track starts at once.

```js
wp.hooks.addFilter( 'callboard.beforePlay', 'acme/call-sheet', ( holds ) =>
	holds.concat( [ { id: 'acme/call-sheet', callback: ( context, signal ) => turnToPage( context.track, signal ) } ] )
);
```

Core's `wp-hooks` is the only WordPress script Callboard itself puts on the page. It ships with WordPress, so there is still no build step.

## The contract

- **Versions.** Within API version 1, contribution points, arguments, event names and payload fields are only ever added. Renaming or removing one takes a deprecation that ships in at least one minor release first, and the removal waits for API version 2 and a major release of the plugin.
- **Deprecations.** PHP uses `_deprecated_hook`, `_deprecated_function` and `_deprecated_argument`. The script uses `callboard.deprecated()`, which warns once per name in the shape of `@wordpress/deprecated`. Tracks still carry `quality` at the top level for API version 1; it belongs to `ext[ 'callboard/quality' ]` now. Every copy of a track an extension receives has the field, whether `SCRIPT_DEBUG` is on or off. With it on, reading it from a copy logs a deprecation warning.
- **Names.** Ids are `namespace/name`. Data sits under `ext[ id ]`. Script hooks and events are `namespace.name.*`. Classes an extension adds start with its namespace (`acme-`). `cb-` and the plugin's unprefixed classes are Callboard's; its own extensions keep the classes they always had (`quality-pill`). So that an extension's classes cannot collide with Callboard's, these namespaces are reserved, and since 2.4.0 registering under one is refused, in PHP and in the page: `callboard`, `cb`, `wp`, `core`, `ext`, `deck`, `set`, `seek`, `loop`, `track`, `lyrics`, `dl`, `wave`, `remote`, `sheet` and `quality`. `callboard/*` is for Callboard's own extensions, and none of them can be called `callboard/ext`. The PHP list is `Callboard\Extensions::RESERVED_NAMESPACES`.
- **Escaping.** Callboard escapes items and runs markup through kses. An extension sanitises its own data before returning it. Data is JSON and small: app data carries every playlist and every track on every page, so anything heavy belongs behind a route.
- **Strings.** Translate in PHP and pass strings through `app_data`. The page loads no `wp-i18n`.

## Caching, offline and sign-in

- **Playlist data.** It is cached for a day and cleared whenever a playlist changes. The cache is keyed on the ids and versions of the extensions that contribute track or playlist data, so enabling, disabling or updating one rebuilds it. A decision that changes per request (per user, say) belongs in `app_data`, which is never cached.
- **Offline.** The service worker keeps extension assets at the URLs the page asks for, together with core's `wp-hooks`. It is rewritten when that list changes, which Callboard checks in the admin (where plugins are activated and updated), never on a front-end request, since a new worker starts a new shell cache for every visitor.
- **Sign-in.** When a site requires sign-in, a signed-out visitor gets no app data, no extension scripts or data, and a 401 from extension routes. A signed-in user's own requests need the REST nonce (see [REST routes](#rest-routes)).
