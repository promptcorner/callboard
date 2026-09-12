/**
 * The extension standard, tested from the outside. Everything here goes through the example extension in
 * tests/mu-plugins, which is built on the public API alone, or through Callboard's own extensions switched
 * off and replaced by id. docs/extending.md is the contract these hold it to.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const base = new URL( process.env.WP_BASE_URL || 'http://localhost:8889' );

// The example extension only exists for requests that carry its cookie, and a few more cookies switch
// Callboard's own extensions around for one request. See callboard-example-extension.php.
const useExample = ( page, extra = {} ) =>
	page.context().addCookies(
		Object.entries( { callboard_example: '1', ...extra } ).map(
			( [ name, value ] ) => ( {
				name,
				value,
				domain: base.hostname,
				path: '/',
			} )
		)
	);
const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();
const body = ( page, key ) =>
	page.evaluate( ( k ) => document.body.dataset[ k ], key );

test.describe( 'Extensions', () => {
	test( 'row badges and metadata render in priority order, escaped, and once after every swap', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/demo-set/' );
		const rows = page.locator( '.track' );
		await expect( rows ).toHaveCount( 10 );

		// Server items: the example at priority 5, Callboard's ♩ at 10, example/late at 20.
		const tempo = page.locator( '.track', {
			has: page.locator( '.bpm' ),
		} );
		await expect( tempo.locator( '.len .cb-item' ) ).toHaveCount( 3 );
		await expect( tempo.locator( '.len .cb-item' ).nth( 0 ) ).toHaveClass(
			/example-badge/
		);
		await expect( tempo.locator( '.len .cb-item' ).nth( 1 ) ).toHaveClass(
			/\bbpm\b/
		);
		await expect( tempo.locator( '.len .cb-item' ).nth( 2 ) ).toHaveClass(
			/example-late/
		);
		// Text is text: the markup in it is printed, and the quote in its class name did not open an attribute.
		const badge = page.locator( '.track .example-badge' ).first();
		await expect( badge ).toHaveText( '<b>demo</b>' );
		await expect( badge.locator( 'b' ) ).toHaveCount( 0 );
		expect( await badge.getAttribute( 'onclick' ) ).toBeNull();
		await expect( badge ).toHaveAttribute( 'data-tone', 'accent' );
		await expect( badge ).toHaveAttribute(
			'data-extension',
			'example/demo'
		);

		// Client items, from track data the server added under ext.
		const meta = page.locator( '.track .example-meta' );
		await expect( meta ).toHaveCount( 10 );
		await expect( meta.first() ).toHaveText(
			/^\d+s <img src=x onerror="window.__exampleXss=true">$/
		);
		await expect( meta.first().locator( 'img' ) ).toHaveCount( 0 );
		expect( await page.evaluate( () => window.__exampleXss ) ).toBeFalsy();

		// Home and back, in place: the view is torn down and set up again, and nothing doubles.
		const inits = Number( await body( page, 'exampleInits' ) );
		await page.locator( 'a.back' ).click();
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-slug',
			''
		);
		await expect( page.locator( 'main' ) ).toHaveAttribute(
			'data-example-view',
			'home'
		);
		await page.locator( 'a.set', { hasText: 'Shakespeare' } ).click();
		await expect( rows ).toHaveCount( 10 );
		await expect( page.locator( '.track .example-meta' ) ).toHaveCount(
			10
		);
		await expect( page.locator( '.track .example-badge' ) ).toHaveCount(
			10
		);
		expect( Number( await body( page, 'exampleInits' ) ) ).toBe(
			inits + 2
		);
		expect( Number( await body( page, 'exampleTeardowns' ) ) ).toBe( 2 );
		expect( await body( page, 'exampleSetups' ) ).toBe( '1' );

		// A listener bound to the view's signal went with the view it was bound in.
		await page.evaluate( () =>
			document.dispatchEvent( new CustomEvent( 'example:ping' ) )
		);
		expect( await body( page, 'examplePings' ) ).toBe( '1' );
	} );

	test( 'data, events, state and commands reach an extension through window.callboard alone', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/' );
		const api = await page.evaluate( () => ( {
			apiVersion: window.callboard.apiVersion,
			hooks: typeof window.callboard.hooks?.addAction,
			extensions: window.callboard.extensions(),
			greeting: window.callboard.data( 'example/demo' )?.greeting,
			setData: window.CALLBOARD.sets.find(
				( s ) => s.slug === 'demo-set'
			).ext[ 'example/demo' ],
		} ) );
		expect( api.apiVersion ).toBe( 1 );
		expect( api.hooks ).toBe( 'function' );
		expect( api.extensions ).toEqual(
			expect.arrayContaining( [
				'callboard/count-in',
				'callboard/quality',
				'callboard/badging',
				'example/demo',
				'example/late',
			] )
		);
		expect( api.greeting ).toBe( 'hello' );
		expect( api.setData ).toEqual( { tracks: 10 } );

		// The DOM event the script has always fired still fires, beside the wp.hooks action.
		await page.evaluate( () => {
			document.addEventListener( 'callboard:track', ( e ) => {
				window.__domTrack = e.detail.index;
			} );
		} );
		const opened = await page.evaluate( () =>
			window.callboard.commands.goTo( 'demo-set', 3, { play: false } )
		);
		expect( opened ).toBe( true );
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-slug',
			'demo-set'
		);
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-example-track',
			'3'
		);
		expect( await page.evaluate( () => window.__domTrack ) ).toBe( 3 );

		const state = await page.evaluate( () => {
			const s = window.callboard.state;
			const track = s.track;
			let mutated = true;
			try {
				track.title = 'changed';
				mutated = track.title === 'changed';
			} catch {
				mutated = false;
			}
			return {
				view: s.view,
				index: s.index,
				title: track.title,
				frozen: Object.isFrozen( track ),
				mutated,
				player: window.CALLBOARD.sets.find(
					( x ) => x.slug === 'demo-set'
				).tracks[ 3 ].title,
			};
		} );
		expect( state.view ).toBe( 'demo-set' );
		expect( state.index ).toBe( 3 );
		expect( state.frozen ).toBe( true );
		expect( state.mutated ).toBe( false );
		expect( state.player ).toBe( state.title );

		expect(
			await page.evaluate( () =>
				window.callboard.run( 'example/demo/shout', 'hey' )
			)
		).toBe( 'HEY' );
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-example-shout',
			'hey'
		);
		// An extension that PHP did not register is refused, whoever asks.
		expect(
			await page.evaluate( () =>
				window.callboard.registerExtension( 'rogue/thing', {} )
			)
		).toBe( false );
		expect(
			await page.evaluate( () =>
				window.callboard.registerCommand( 'rogue/thing/go', () => 1 )
			)
		).toBe( false );
	} );

	test( 'HTML slots keep controls and lose scripts, styles and handlers', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/demo-set/' );
		const header = page.locator( '.masthead .actions .example-header' );
		await expect( header ).toHaveText( 'Demo' );
		await expect( header ).toHaveAttribute( 'data-tracks', '10' );
		expect( await header.getAttribute( 'onclick' ) ).toBeNull();
		await expect( page.locator( '.masthead .actions' ) ).not.toContainText(
			'__exampleXss'
		);
		await expect(
			page.locator( '.masthead script, .masthead style' )
		).toHaveCount( 0 );
		await expect( page.locator( '.track' ).first() ).toBeVisible(); // the stripped style would have hidden it

		const control = page.locator(
			'#deck .deck-controls-secondary .example-transport'
		);
		await expect( control ).toHaveCount( 1 );
		expect( await control.getAttribute( 'onmouseover' ) ).toBeNull();
		await expect(
			page.locator( '[data-example="panel"].example-panel' )
		).toHaveCount( 1 );
		expect( await page.evaluate( () => window.__exampleXss ) ).toBeFalsy();
	} );

	test( 'a transport control is bound by its script in setup, and goes when the extension is switched off', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		const control = page.locator( '#deck .example-transport' );
		await control.click();
		await control.click();
		expect( await body( page, 'exampleTransportClicks' ) ).toBe( '2' );
		expect( await body( page, 'exampleSetups' ) ).toBe( '1' );

		await useExample( page, { callboard_example_disable: 'example/demo' } );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.example-transport' ) ).toHaveCount( 0 );
		await page
			.context()
			.clearCookies( { name: 'callboard_example_disable' } );
	} );

	test( 'an event one extension emits reaches another extension’s events listener', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/' );
		await page.evaluate( () => {
			document.addEventListener(
				'callboard:example.demo.pinged',
				( e ) => {
					window.__domPing = e.detail.word;
				}
			);
		} );
		expect(
			await page.evaluate( () =>
				window.callboard.run( 'example/demo/ping', 'first' )
			)
		).toBe( true );
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-example-pinged',
			'first'
		);
		expect( await page.evaluate( () => window.__domPing ) ).toBe( 'first' );

		// Unregistering the listener's extension removes the listener.
		await page.evaluate( () => {
			window.callboard.unregisterExtension( 'example/late' );
			window.callboard.run( 'example/demo/ping', 'second' );
		} );
		expect( await body( page, 'examplePinged' ) ).toBe( 'first' );
		expect( await page.evaluate( () => window.__domPing ) ).toBe(
			'second'
		);

		// Only the extension that owns a name can fire it.
		expect(
			await page.evaluate( () =>
				window.callboard.emit( 'example.late.pinged', {} )
			)
		).toBe( false );
	} );

	test( 'app data is built on every request and never cached with set data', async ( {
		page,
	} ) => {
		await useExample( page, { callboard_example_visitor: 'first' } );
		await page.goto( '/' );
		expect(
			await page.evaluate(
				() => window.callboard.data( 'example/demo' ).visitor
			)
		).toBe( 'first' );

		await useExample( page, { callboard_example_visitor: 'second' } );
		await page.goto( '/demo-set/' );
		const seen = await page.evaluate( () => ( {
			visitor: window.callboard.data( 'example/demo' ).visitor,
			inSets: JSON.stringify( window.CALLBOARD.sets ).includes( 'first' ),
		} ) );
		expect( seen ).toEqual( { visitor: 'second', inSets: false } );
		await page
			.context()
			.clearCookies( { name: 'callboard_example_visitor' } );
	} );

	test( 'a route an extension declares is open to a stranger and still obeys the gate', async ( {
		playwright,
	} ) => {
		const stranger = async ( cookie ) =>
			playwright.request.newContext( {
				baseURL: base.href,
				storageState: { cookies: [], origins: [] },
				extraHTTPHeaders: { cookie },
			} );
		const open = await stranger( 'callboard_example=1' );
		const ping = await open.get(
			'/wp-json/callboard/v1/example/demo/ping'
		);
		expect( ping.status() ).toBe( 200 );
		expect( await ping.json() ).toEqual( { pong: true } );
		// Declaring a route does not open the rest of the API.
		expect( ( await open.get( '/wp-json/wp/v2/posts' ) ).status() ).toBe(
			401
		);
		await open.dispose();

		const gated = await stranger(
			'callboard_example=1; callboard_example_gate=1'
		);
		expect(
			(
				await gated.get( '/wp-json/callboard/v1/example/demo/ping' )
			).status()
		).toBe( 401 );
		await gated.dispose();
	} );

	test( 'the service worker keeps core’s hooks script for an offline start', async ( {
		admin,
		request,
	} ) => {
		await admin.visitAdminPage( 'index.php' ); // the worker is rewritten from the admin, when assets change
		const sw = await ( await request.get( '/sw.js' ) ).text();
		expect( sw ).toMatch(
			/"\/wp-includes\/js\/dist\/hooks(\.min)?\.js\?ver=[^"]+"/
		);
	} );
} );

test.describe( 'Callboard’s own features are extensions', () => {
	test( 'count-in: switched off by id, the badge and the count both go', async ( {
		page,
	} ) => {
		await useExample( page, { callboard_example_count_in: '1' } );
		await page.goto( '/demo-set/' );
		await page.evaluate( () => localStorage.clear() );
		await page.reload();
		await expect( page.locator( '.track .bpm' ) ).toHaveText( '♩ 96' );
		const tempo = page.locator( '.track', { has: page.locator( '.bpm' ) } );
		await tempo.click();
		await expect( page.locator( '#deck' ) ).toHaveClass( /counting/ );
		await expect( page.locator( '#now-title' ) ).toHaveClass( /is-count/ );

		await useExample( page, {
			callboard_example_count_in: '1',
			callboard_example_disable: 'callboard/count-in',
		} );
		await page.evaluate( () => localStorage.clear() );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await expect( page.locator( '.track .bpm' ) ).toHaveCount( 0 );
		expect(
			await page.evaluate( () =>
				window.callboard.isActive( 'callboard/count-in' )
			)
		).toBe( false );
		// Its script is still in the file, and still asks to register; the page refuses it.
		expect(
			await page.evaluate( () => window.callboard.extensions() )
		).not.toContain( 'callboard/count-in' );
		await page.locator( '.track' ).nth( 2 ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 21–30'
		);
		await expect( page.locator( '#deck' ) ).not.toHaveClass( /counting/ );
	} );

	test( 'quality: switched off, Now Playing has no pill; replaced, it shows the replacement', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect(
			page.locator( '#deck-meta .quality-pill' )
		).toBeVisible();
		await expect( page.locator( '#deck-meta .quality-pill' ) ).toHaveText(
			/\S/
		);

		await useExample( page, {
			callboard_example_disable: 'callboard/quality',
		} );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect( page.locator( '#deck-meta' ) ).toBeHidden();
		await expect( page.locator( '.quality-pill' ) ).toHaveCount( 0 );

		await useExample( page, {
			callboard_example_disable: '',
			callboard_example_replace: '1',
		} );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect(
			page.locator( '#deck-meta .example-quality' )
		).toHaveText( /^Example · Sonnets 1–10$/ );
		await expect( page.locator( '.quality-pill' ) ).toHaveCount( 0 );
		expect(
			await page.evaluate( () =>
				window.callboard.data( 'callboard/quality' )
			)
		).toBeNull();
	} );

	test( 'badging: the Home Screen badge is the sum of what extensions contribute', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.__badge = [];
			Navigator.prototype.setAppBadge = function setAppBadge( n ) {
				window.__badge.push( [ 'set', n ] );
				return Promise.resolve();
			};
			Navigator.prototype.clearAppBadge = function clearAppBadge() {
				window.__badge.push( [ 'clear' ] );
				return Promise.resolve();
			};
		} );
		const calls = () => page.evaluate( () => window.__badge );

		// Callboard alone: the badging extension contributes zero, which clears what a notification set.
		await page.goto( '/' );
		await expect.poll( calls ).toContainEqual( [ 'clear' ] );

		// The example contributes three.
		await useExample( page );
		await page.goto( '/' );
		await expect.poll( calls ).toContainEqual( [ 'set', 3 ] );

		// Switched off, nothing touches the badge at all.
		await useExample( page, {
			callboard_example: '0',
			callboard_example_disable: 'callboard/badging',
		} );
		await page.goto( '/' );
		await page.waitForLoadState( 'load' );
		await expect
			.poll( () =>
				page.evaluate( () => window.callboard.extensions().length )
			)
			.toBeGreaterThan( 0 );
		expect( await calls() ).toEqual( [] );
	} );
} );
