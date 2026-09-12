=== Callboard ===
Contributors: josephfusco
Tags: audio, player, rehearsal, theatre, pwa
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rehearsal tracks for a cast. Sets of audio, a persistent player, installable as a home-screen app.

== Description ==

Source and issues: https://github.com/promptcorner/callboard. Landing page: https://promptcorner.github.io/callboard/

Callboard turns a WordPress site into a small, private music app for a cast or choir: a home page of sets, a track list per set, a player that keeps playing while you move around, lock-screen controls, optional offline saving, and an "Add to Home Screen" flow on iPhone. The whole front end is rendered by the plugin, whatever theme is active, and the site is kept out of search engines.

= The board =

The home page opens on the next call: its time, place, note, and the numbers being worked. Each number is a tap that starts the track. A call is a post; scheduling one works the way it does for any post, and publishing it sends a notification. A call leaves the board six hours after its time.

= Sets and tracks =

A set is a post. Its tracks are audio files attached to it, ordered by drag and drop, titled in the Media Library. Set a featured image for lock-screen artwork. Credits and a source link live in the set's meta boxes.

= Import =

Drop a folder into `wp-content/uploads/callboard/<slug>/` containing audio files and a `manifest.json`, then Sets → Import. The companion fetch tools produce that folder from a YouTube playlist on your own machine; hosts that allow running binaries can do it server-side.

= Settings =

Site title is the app name. The Settings screen under Sets holds the tagline, the home page footer note, an optional emoji badge on the playing track, the confetti text (and optional hearts) released by triple-tapping a title, the iPhone install hint, and switches for offline saving, notifications, new-set notices, and a count-in on tracks with a tempo.

= For developers =

Templates can be replaced through `callboard_template_path`, and filters cover the app data, a set's data, the board, and the push payload. WP-CLI commands handle fetching, importing, measuring levels, and sending a notification. The full list is in the README on GitHub.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, or unzip it into `wp-content/plugins/`.
2. Activate it. The front end is served from the site root straight away; the active theme is not used.
3. Add a set under Sets, then attach audio files to it from the Media Library and drag them into order.
4. Post a call under Sets → Calls, and publish or schedule it.
5. Share the home page URL with the cast. On iPhone they add it to the Home Screen from the Share sheet.
6. Adjust Sets → Settings.

Notifications need PHP with OpenSSL and either GMP or BCMath. `wp callboard doctor` reports what the server has.

== Frequently Asked Questions ==

= Does it work with my theme? =

It ignores it. Callboard renders the whole front end from its own templates, so the site looks the same whatever theme is active. The admin is ordinary WordPress.

= Do the cast need accounts? =

No. Anyone with the URL can open the site, which is the point: a link in a group chat is the whole setup. The site is kept out of search engines with `noindex`, a disallowed `robots.txt`, and a no-referrer policy, but treat the URL itself as the secret.

= Is YouTube required? =

No. `Sets → Import` reads a folder of audio files and a `manifest.json`, however that folder was made. The fetch tools are a convenience for building one, and they run through WP-CLI, never in the browser.

= Does offline really work? =

Save a set once and it plays with no connection, including seeking inside a track. Saved audio lives in the browser's cache on that device. It is re-fetched after a plugin update, since the cache is versioned.

= Will notifications reach an iPhone? =

Only after the site has been added to the Home Screen, which is Apple's requirement for web push, and only if the server has the PHP extensions above.

= Can I change the look? =

Set an accent colour in Settings. Beyond that, `callboard_template_path` swaps any template for one of your own, and `callboard_head` adds to the `<head>` of every front-end page.

== Screenshots ==

1. The home page with the next rehearsal: date, time, place, and the tracks to practice. Tapping a track plays it.
2. A set's track list, with the player bar at the bottom of the screen.
3. The full-screen player: cover art, waveform scrubbing, repeat, and A/B loop controls.
4. Editing a rehearsal in the admin: date and time, place, note, and the tracks to practice.

== Changelog ==

= 2.0.1 =
* Fixed: a stray screenshot no longer ships in the package.

= 2.0.0 =
* Breaking: the dot-matrix display is gone.
* Breaking: the transport is previous, play, next.
* Fixed: re-importing a set no longer deletes its cover.
* Fixed: the waveform is bars alone, centred.
* Fixed: the list and the deck share one right edge.

= 1.6.1 =
* Fixed: Playground opens in seamless mode, so its toolbar stays off the player.
* Fixed: demo asset URLs are stamped with the commit, so caching never shows an old build.

= 1.6.0 =
* Added: the board.
* Added: an accent colour, and a small developer API.
* Added: the landing page runs a live copy in WordPress Playground.
* Fixed: an iOS Safari pass on the front end.

Earlier releases are listed in CHANGELOG.md on GitHub.

== Upgrade Notice ==

= 2.0.0 =
The dot-matrix display has been removed and the transport is now previous, play, next. Nothing needs migrating, but the player looks different.
