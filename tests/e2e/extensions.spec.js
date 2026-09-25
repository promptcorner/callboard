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
// The worker registers at idle and controls the page after a reload.
const controlled = ( page ) =>
	page
		.waitForFunction( () => navigator.serviceWorker?.controller, null, {
			timeout: 15000,
		} )
		.catch( async () => {
			await page.reload();
			await page.waitForFunction(
				() => navigator.serviceWorker?.controller,
				null,
				{ timeout: 15000 }
			);
		} );
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

		// Server items: the example at priority 5, example/late at 20.
		const items = rows.first().locator( '.len .cb-item' );
		await expect( items ).toHaveCount( 2 );
		await expect( items.nth( 0 ) ).toHaveClass( /example-badge/ );
		await expect( items.nth( 1 ) ).toHaveClass( /example-late/ );
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
		await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
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
				'callboard/quality',
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

	test( 'a route an extension declares is open to a stranger at its new and 2.3.0 paths, and still obeys the gate', async ( {
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
			'/wp-json/callboard/v1/ext/example/demo/ping'
		);
		expect( ping.status() ).toBe( 200 );
		expect( await ping.json() ).toEqual( { pong: true } );
		// The 2.3.0 path, without ext/, still answers. PHPUnit checks its deprecation notice, since the tests site runs without WP_DEBUG.
		const old = await open.get( '/wp-json/callboard/v1/example/demo/ping' );
		expect( old.status() ).toBe( 200 );
		expect( await old.json() ).toEqual( { pong: true } );
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
				await gated.get( '/wp-json/callboard/v1/ext/example/demo/ping' )
			).status()
		).toBe( 401 );
		expect(
			(
				await gated.get( '/wp-json/callboard/v1/example/demo/ping' )
			).status()
		).toBe( 401 );
		await gated.dispose();
	} );

	test( 'a signed-in user reaches an extension route on a site that requires sign-in only with the REST nonce', async ( {
		page,
		browser,
	} ) => {
		// The page fixture is signed in as the admin.
		await useExample( page, { callboard_example_signin: '1' } );
		await page.goto( '/' );
		const rest = await page.evaluate( () => window.CALLBOARD.rest );
		expect( rest?.root ).toBeTruthy();
		expect( rest?.nonce ).toBeTruthy();
		const statuses = await page.evaluate( async ( { root, nonce } ) => {
			const url = new URL( 'callboard/v1/ext/example/demo/ping', root );
			const inQuery = new URL( url );
			inQuery.searchParams.set( '_wpnonce', nonce );
			const status = async ( target, init = {} ) =>
				( await fetch( target, init ) ).status;
			return {
				header: await status( url, {
					headers: { 'X-WP-Nonce': nonce },
				} ),
				query: await status( inQuery ),
				none: await status( url ),
			};
		}, rest );
		expect( statuses ).toEqual( { header: 200, query: 200, none: 401 } );

		// Somebody who is not signed in gets no nonce.
		const context = await browser.newContext( {
			baseURL: base.href,
			storageState: { cookies: [], origins: [] },
		} );
		const signedOut = await context.newPage();
		await useExample( signedOut );
		await signedOut.goto( '/' );
		expect(
			await signedOut.evaluate( () => window.CALLBOARD.rest )
		).toBeNull();
		await context.close();
	} );

	test( 'the page refuses a reserved namespace, even one app data says is active', async ( {
		page,
	} ) => {
		const warnings = [];
		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'warning' ) {
				warnings.push( msg.text() );
			}
		} );
		await useExample( page, { callboard_example_reserved: '1' } );
		await page.goto( '/' );
		expect(
			await page.evaluate( () =>
				window.callboard.isActive( 'deck/meta' )
			)
		).toBe( true );

		const ids = [
			'cb',
			'wp',
			'core',
			'ext',
			'deck',
			'set',
			'seek',
			'loop',
			'track',
			'lyrics',
			'dl',
			'wave',
			'remote',
			'sheet',
			'quality',
		]
			.map( ( namespace ) => `${ namespace }/meta` )
			.concat( 'callboard/ext' );
		const accepted = await page.evaluate(
			( list ) =>
				list.filter( ( id ) =>
					window.callboard.registerExtension( id, { apiVersion: 1 } )
				),
			ids
		);
		expect( accepted ).toEqual( [] );
		await expect
			.poll( () =>
				ids.filter( ( id ) =>
					warnings.some(
						( w ) => w.includes( id ) && w.includes( 'reserved' )
					)
				)
			)
			.toEqual( ids );
		expect(
			await page.evaluate( () => window.callboard.extensions() )
		).not.toContain( 'deck/meta' );
	} );

	test( 'extension data is an object even when an extension returned an empty array', async ( {
		page,
	} ) => {
		await useExample( page );
		await page.goto( '/demo-set/' );
		const shapes = await page.evaluate( async () => {
			const shape = ( v ) => {
				if ( Array.isArray( v ) ) {
					return 'array';
				}
				return v === null ? 'null' : typeof v;
			};
			await window.callboard.commands.goTo( 'demo-set', 0, {
				play: false,
			} );
			const { set, track } = window.callboard.state;
			const app = window.callboard.data( 'example/empty' );
			return {
				app: shape( app ),
				appKeys: Object.keys( app || {} ).length,
				set: shape( set.ext[ 'example/empty' ] ),
				track: shape( track.ext[ 'example/empty' ] ),
			};
		} );
		expect( shapes ).toEqual( {
			app: 'object',
			appKeys: 0,
			set: 'object',
			track: 'object',
		} );
	} );

	for ( const debug of [ true, false ] ) {
		test( `a copy of a track keeps quality with debugging ${
			debug ? 'on' : 'off'
		}, and only warns with it on`, async ( { page } ) => {
			const warnings = [];
			page.on( 'console', ( msg ) => {
				if ( msg.type() === 'warning' ) {
					warnings.push( msg.text() );
				}
			} );
			await useExample( page, {
				callboard_example_debug: debug ? '1' : '0',
			} );
			await page.goto( '/demo-set/' );
			const result = await page.evaluate( async () => {
				const set = window.CALLBOARD.sets.find(
					( s ) => s.slug === 'demo-set'
				);
				const index = set.tracks.findIndex(
					( t ) => t.ext[ 'callboard/quality' ]?.quality
				);
				const player = set.tracks[ index ];
				await window.callboard.commands.goTo( 'demo-set', index, {
					play: false,
				} );
				const copy = window.callboard.state.track;
				const keys = Object.keys( copy );
				return {
					debug: !! window.CALLBOARD.debug,
					keys: [ 'quality' ].filter( ( k ) => keys.includes( k ) ),
					inJson: Object.keys(
						JSON.parse(
							JSON.stringify( window.callboard.state.set )
						).tracks[ index ]
					).includes( 'quality' ),
					quality:
						copy.quality ===
						player.ext[ 'callboard/quality' ].quality,
					// The player's own track is plain data in both modes.
					plain:
						'value' in
						Object.getOwnPropertyDescriptor( player, 'quality' ),
				};
			} );
			expect( result ).toEqual( {
				debug,
				keys: [ 'quality' ],
				inJson: true,
				quality: true,
				plain: true,
			} );
			// A marker, so every warning the page logged before it has arrived.
			await page.evaluate( () =>
				// eslint-disable-next-line no-console
				console.warn( 'callboard-test-marker' )
			);
			await expect
				.poll( () => warnings.includes( 'callboard-test-marker' ) )
				.toBe( true );
			expect(
				warnings.filter( ( w ) =>
					w.includes( 'track.quality is deprecated' )
				)
			).toHaveLength( debug ? 1 : 0 );
		} );
	}

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

	test( 'the player works when an extension’s inline script takes the defer off Callboard’s script', async ( {
		page,
	} ) => {
		await useExample( page, { callboard_example_inline: '1' } );
		await page.goto( '/demo-set/' );
		// WordPress defers no script with an inline script after it, and none of the scripts it depends on.
		const app = page.locator( 'script[src*="/assets/app.js"]' );
		await expect( app ).toHaveCount( 1 );
		expect( await app.getAttribute( 'defer' ) ).toBeNull();

		// The first track is already in the player bar, so a later one is what loads and fires `track`.
		await page.locator( '.track' ).nth( 2 ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Trombone Detritus'
		);
		await expect( page.locator( 'body' ) ).toHaveAttribute(
			'data-example-track',
			'2'
		);
		expect( await page.evaluate( () => window.__exampleInline ) ).toBe(
			true
		);
	} );

	test( 'an extension script that 404s leaves the rest of the app to open offline', async ( {
		admin,
		page,
		context,
		request,
	} ) => {
		await useExample( page, { callboard_example_missing: '1' } );
		try {
			await admin.visitAdminPage( 'index.php' ); // the worker is rewritten from the admin, when assets change
			expect( await ( await request.get( '/sw.js' ) ).text() ).toContain(
				'/callboard-example/missing.js?ver=1.0.0'
			);
			// Not home, so the only copy of home the worker can have is the one it installed with.
			await page.goto( '/demo-set/' );
			await controlled( page );

			await context.setOffline( true );
			await page.goto( '/' );
			await expect(
				page.locator( 'a.set', { hasText: 'Compositions' } )
			).toBeVisible();
			await expect
				.poll( () =>
					page.evaluate( () => typeof window.callboard?.commands )
				)
				.toBe( 'object' );
		} finally {
			await context.setOffline( false );
			await useExample( page, {
				callboard_example: '0',
				callboard_example_missing: '0',
			} );
			await admin.visitAdminPage( 'index.php' ); // and a worker without it for the tests after this one
		}
	} );

	test( 'offline, an extension script at a newer ?ver= than the worker kept loads from the older copy', async ( {
		admin,
		page,
		context,
		request,
	} ) => {
		await useExample( page );
		try {
			await admin.visitAdminPage( 'index.php' );
			expect( await ( await request.get( '/sw.js' ) ).text() ).toContain(
				'/callboard-example/example.js?ver=1.0.0'
			);
			await page.goto( '/demo-set/' );
			await controlled( page );

			// The extension updates and nobody opens the admin, so the worker still lists 1.0.0. Saving the
			// set keeps its page as the site prints it now, asking for 2.0.0, which the browser never fetched.
			await useExample( page, {
				callboard_example_script_version: '2.0.0',
			} );
			await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
				timeout: 15000,
			} );
			await page.locator( '#offline' ).click();
			await expect( page.locator( '#offline' ) ).toContainText(
				/Saved offline/,
				{ timeout: 30000 }
			);

			await context.setOffline( true );
			await page.goto( '/demo-set/' );
			await expect(
				page.locator( 'script[src*="/example.js?ver=2.0.0"]' )
			).toHaveCount( 1 );
			// The extension's own script draws these, so they are only here if it ran.
			await expect( page.locator( '.track .example-meta' ) ).toHaveCount(
				10
			);
		} finally {
			await context.setOffline( false );
			await useExample( page, { callboard_example: '0' } );
			await admin.visitAdminPage( 'index.php' );
		}
	} );
} );

test.describe( 'Callboard’s own features are extensions', () => {
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
		).toHaveText( /^Example · Intensities in Ten Cities$/ );
		await expect( page.locator( '.quality-pill' ) ).toHaveCount( 0 );
		expect(
			await page.evaluate( () =>
				window.callboard.data( 'callboard/quality' )
			)
		).toBeNull();
	} );
} );
