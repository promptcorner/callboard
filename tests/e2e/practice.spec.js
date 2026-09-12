/**
 * Practice counts (callboard/practice): what the page sends, what the call editor shows, and that nothing
 * is counted or sent while the setting is off.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const base = new URL( process.env.WP_BASE_URL || 'http://localhost:8889' );

/**
 * Turn settings on or off through the settings screen.
 *
 * @param {Object} admin    Admin utils.
 * @param {Object} page     Page.
 * @param {Object} settings Checkbox id suffixes and whether each is checked.
 */
const saveSettings = async ( admin, page, settings ) => {
	await admin.visitAdminPage(
		'edit.php',
		'post_type=callboard_set&page=callboard-settings'
	);
	for ( const [ key, on ] of Object.entries( settings ) ) {
		await page.locator( `#callboard-${ key }` ).setChecked( on );
	}
	await page.click( '#submit' );
	await expect(
		page
			.locator( '#setting-error-settings_updated, .notice-success' )
			.first()
	).toBeVisible();
};

// Record every beacon the page sends, and still send it.
const spyOnBeacons = ( page ) =>
	page.addInitScript( () => {
		window.__beacons = [];
		const send = navigator.sendBeacon.bind( navigator );
		navigator.sendBeacon = ( url, data ) => {
			window.__beacons.push( { url: String( url ), data } );
			return send( url, data );
		};
		const fetchOriginal = window.fetch.bind( window );
		window.__fetches = [];
		window.fetch = ( input, init ) => {
			window.__fetches.push( String( input?.url || input ) );
			return fetchOriginal( input, init );
		};
	} );

const beacons = ( page ) =>
	page.evaluate( () =>
		Promise.all(
			window.__beacons
				.filter( ( b ) => b.url.includes( '/practice/counts' ) )
				.map( async ( b ) => ( {
					url: b.url,
					body: JSON.parse( await b.data.text() ),
				} ) )
		)
	);

// What the page does when the tab goes to the background.
const hideTab = ( page ) =>
	page.evaluate( () => {
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'hidden',
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	} );

// Open the first track, set a loop across it, and play it for a moment. Headless Chromium may not decode
// the audio, so play and pause are fired on the element the way the browser would fire them.
const practiseFirstTrack = async ( page ) => {
	await page.locator( '.track' ).first().click();
	await page.locator( 'h1' ).click();
	await page.keyboard.press( '[' );
	await page.evaluate( async () => {
		const audio = document.getElementById( 'audio' );
		audio.pause();
		// The element fires its own pause event a task later; let it land before the play below.
		await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
		audio.dispatchEvent( new Event( 'play' ) );
	} );
	await page.waitForTimeout( 1200 ); // eslint-disable-line playwright/no-wait-for-timeout -- measuring play time needs time to pass.
	await page.evaluate( () =>
		document.getElementById( 'audio' ).dispatchEvent( new Event( 'pause' ) )
	);
};

const playingTrackId = ( page ) =>
	page.evaluate( () => window.callboard.state.track?.id );

test.describe( 'Practice counts', () => {
	test.afterEach( async ( { admin, page } ) => {
		await saveSettings( admin, page, {
			practice: false,
			require_signin: false,
		} );
	} );

	test( 'with the setting off, nothing is counted or sent', async ( {
		page,
		admin,
	} ) => {
		await saveSettings( admin, page, { practice: false } );
		await spyOnBeacons( page );
		await page.goto( '/demo-set/' );
		await practiseFirstTrack( page );
		await hideTab( page );

		expect(
			await page.evaluate( () =>
				window.callboard.isActive( 'callboard/practice' )
			)
		).toBe( false );
		expect( await beacons( page ) ).toEqual( [] );
		expect(
			await page.evaluate( () =>
				window.__fetches.filter( ( u ) => u.includes( 'practice' ) )
			)
		).toEqual( [] );
		expect(
			await page.evaluate( () => window.CALLBOARD.ext )
		).not.toHaveProperty( 'callboard/practice' );
	} );

	test( 'switched off by id, it counts nothing and sends nothing', async ( {
		page,
		admin,
	} ) => {
		await saveSettings( admin, page, { practice: true } );
		await page.context().addCookies( [
			{
				name: 'callboard_example_disable',
				value: 'callboard/practice',
				domain: base.hostname,
				path: '/',
			},
		] );
		await spyOnBeacons( page );
		await page.goto( '/demo-set/' );
		await practiseFirstTrack( page );
		await hideTab( page );

		expect(
			await page.evaluate( () => window.callboard.extensions() )
		).not.toContain( 'callboard/practice' );
		expect( await beacons( page ) ).toEqual( [] );
		await page.context().clearCookies( {
			name: 'callboard_example_disable',
		} );
	} );

	test( 'opening and looping a track sends its counts, and the call editor shows them', async ( {
		page,
		admin,
	} ) => {
		await saveSettings( admin, page, { practice: true } );

		// A draft call listing the first track. No title is typed, so no autosave races the save.
		await admin.visitAdminPage(
			'post-new.php',
			'post_type=callboard_call'
		);
		const demo = page.locator( '.callboard-numbers details', {
			hasText: 'Sonnets',
		} );
		await demo.locator( 'summary' ).click();
		await demo.locator( 'input[type=checkbox]' ).first().check();
		await page.click( '#save-post' );
		await page.waitForURL( /post\.php\?post=\d+&action=edit&message=/ );
		const editor = page.url();
		const row = page.locator( '#callboard-practice tbody tr' ).first();
		await expect( row ).toBeVisible();
		const before = {
			opens: Number(
				( await row.locator( '.callboard-practice-opens' ).count() )
					? await row
							.locator( '.callboard-practice-opens' )
							.innerText()
					: 0
			),
			loops: Number(
				( await row.locator( '.callboard-practice-loops' ).count() )
					? await row
							.locator( '.callboard-practice-loops' )
							.innerText()
					: 0
			),
		};

		try {
			await spyOnBeacons( page );
			await page.goto( '/demo-set/' );
			await practiseFirstTrack( page );
			const track = await playingTrackId( page );
			await hideTab( page );

			await expect
				.poll( async () => ( await beacons( page ) ).length )
				.toBe( 1 );
			const [ sent ] = await beacons( page );
			// Signed in, so the REST nonce rides along as a query argument.
			expect(
				new URL( sent.url ).searchParams.get( '_wpnonce' )
			).toBeTruthy();
			expect( sent.body ).toHaveLength( 1 );
			expect( sent.body[ 0 ] ).toMatchObject( {
				track,
				opens: 1,
				loops: 1,
			} );
			expect( sent.body[ 0 ].seconds ).toBeGreaterThanOrEqual( 1 );

			// A second hide with nothing new sends nothing.
			await hideTab( page );
			expect( await beacons( page ) ).toHaveLength( 1 );

			await expect
				.poll( async () => {
					await page.goto( editor );
					const cells = page
						.locator( '#callboard-practice tbody tr' )
						.first();
					if (
						! ( await cells
							.locator( '.callboard-practice-opens' )
							.count() )
					) {
						return null;
					}
					return {
						opens: Number(
							await cells
								.locator( '.callboard-practice-opens' )
								.innerText()
						),
						loops: Number(
							await cells
								.locator( '.callboard-practice-loops' )
								.innerText()
						),
					};
				} )
				.toEqual( {
					opens: before.opens + 1,
					loops: before.loops + 1,
				} );
		} finally {
			await page.goto( editor );
			await page.locator( '#delete-action a' ).click();
			await page.waitForURL( /edit\.php\?post_type=callboard_call/ );
		}
	} );

	test( 'on a site that requires sign-in, counts need the REST nonce', async ( {
		page,
		admin,
	} ) => {
		await saveSettings( admin, page, {
			practice: true,
			require_signin: true,
		} );
		await page.goto( '/demo-set/' );
		const config = await page.evaluate( () =>
			window.callboard.data( 'callboard/practice' )
		);
		const track = await page.evaluate(
			() =>
				window.CALLBOARD.sets.find( ( s ) => s.slug === 'demo-set' )
					?.tracks[ 0 ]?.id
		);
		const body = JSON.stringify( [ { track, opens: 1 } ] );
		const headers = { 'content-type': 'text/plain' };

		const without = await page.request.post( config.url, {
			data: body,
			headers,
		} );
		expect( without.status() ).toBe( 401 );

		const url = new URL( config.url );
		url.searchParams.set( '_wpnonce', config.nonce );
		const withNonce = await page.request.post( url.href, {
			data: body,
			headers,
		} );
		expect( withNonce.status() ).toBe( 200 );
		expect( await withNonce.json() ).toEqual( { saved: 1 } );
	} );
} );
