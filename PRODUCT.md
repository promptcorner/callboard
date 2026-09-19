# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Callboard primarily serves independent artists, labels, and music publishers building an owned streaming experience with WordPress. They use WordPress to organize audio into sets, manage metadata and artwork, and publish a polished player without giving up control of the library. Casts and choirs are an important use case, with stage managers and directors also posting calls, adding timed notes, and notifying performers.

## Product Purpose

Callboard turns WordPress into a focused, premium music player with the fit and finish of a dedicated streaming app while keeping the library under the site owner’s control. Success means listeners can open one link and play music immediately, with dependable playback online or offline and no account required by default.

## Positioning

Callboard is an open-source music player for WordPress: WordPress is the library and publishing engine, while Callboard supplies a dedicated app-like listening experience rather than a themed audio embed. Its rehearsal tools demonstrate how the open extension contract can adapt the player to a specific listening context.

## Operating Context

Publishers work in familiar WordPress screens to create sets, import or attach audio, order and title tracks, manage artwork and credits, approve lyrics, and configure access and playback features. Listeners usually arrive from a shared URL on a phone and may install the site to their Home Screen or save sets offline. Rehearsal publishers can also post calls, add tempo and timed director notes, and notify performers.

## Capabilities and Constraints

- The plugin owns the whole cast-facing front end; the active theme is not used.
- The front end ships one stylesheet and one script with no build step. Browser features must degrade safely.
- Sets and calls use WordPress post types. Audio attachments are tracks.
- Audio can be imported from a prepared folder or `.callboard` file, fetched through WP-CLI or the request queue where host tools are available, or attached through WordPress.
- The player supports persistent playback, offline sets, waveform seeking, A/B loops, timed lyrics and notes, count-in, notifications, AirPlay, and lock-screen controls.
- No cast account is required by default. Access controls are optional, and the URL is treated as the secret.
- The extension API is a durable product surface. New player features should use it and include a contract test.
- The listener-facing shell stays purpose-built, but logged-in WordPress chrome and standard lifecycle hooks remain available.
- Public data should have one typed domain representation that can serve PHP, Abilities, REST, blocks, WP-CLI, and optional GraphQL adapters without duplicating business rules.

## Brand Commitments

Keep the Callboard name and its direct, practical voice. Product language should sound like a capable stage manager: concise, calm, specific, and free of marketing hype.

## Evidence on Hand

- Product behavior and architecture: `README.md`
- WordPress listing copy: `readme.txt`
- Existing administration: `includes/class-admin.php`, `includes/class-calls.php`, and `includes/class-settings.php`
- Cast-facing implementation: `templates/`, `assets/app.css`, and `assets/app.js`
- Automated admin coverage: `tests/e2e/admin.spec.js`
- Public-domain rehearsal fixtures and screenshots: `tests/fixtures/` and `site/`

No testimonials, adoption metrics, customer logos, or performance claims are available and none should be invented.

## Product Principles

1. Music starts in one tap.
2. The publisher always knows the next useful action.
3. Poor connectivity is normal, not an edge case.
4. WordPress conventions are an advantage; extend them instead of hiding them.
5. Open interfaces should stay small, documented, and dependable.

## Extension Direction

Callboard's versioned browser/PHP extension contract and WordPress Abilities are the foundation, not the finish line. The next platform layer should:

- Introduce stable read and mutation services for sets, tracks, notes, and calls. Admin saves, REST controllers, blocks, CLI commands, and GraphQL resolvers should call those services rather than update metadata independently.
- Separate public music metadata from private operational data. Public schemas can expose published sets, ordered tracks, artwork, credits, duration, tempo, waveform data, and approved lyrics; request logs, push credentials, unapproved lyrics, and private notes stay capability-gated.
- Add an opt-in embedded mode before shipping a Set block. The useful block selects a published set and renders the canonical player inside a normal post or page; it should not become a second audio library or duplicate playback implementation.
- Keep WPGraphQL integration optional. A small adapter can register typed Set, Track, Note, and Call objects and cursor-paginated connections, use loaders to avoid N+1 queries, and route mutations through the same capability-checked services.
- Publish lifecycle actions after successful mutations and filters around presentation data, with explicit payloads and compatibility guarantees.

## Accessibility & Inclusion

Preserve native controls, keyboard operation, visible focus, reduced-motion support, mobile touch targets, semantic labels, and the existing automated accessibility checks.
