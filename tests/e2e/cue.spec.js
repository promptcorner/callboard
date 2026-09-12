/**
 * Shared cue (callboard/cue): a director with Lead on opens a track, and a phone with Follow on opens the
 * same one. The director is the signed-in admin; the follower is a second, signed-out browser context.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const base = new URL( process.env.WP_BASE_URL || 'http://localhost:8889' );
const isCue = ( url ) =>
	new URL( url ).pathname.endsWith( '/callboard/v1/cue/' );

const openNowPlaying = async ( page ) => {
	await page.locator( '#open-lyrics' ).click();
	await expect( page.locator( '#deck' ) ).toHaveClass( /is-expanded/ );
};

const state = ( page ) =>
	page.evaluate( () => ( {
		view: window.callboard.state.view,
		set: window.callboard.state.set?.slug,
		index: window.callboard.state.index,
		paused: window.callboard.state.paused,
		expanded: document
			.getElementById( 'deck' )
			.classList.contains( 'is-expanded' ),
	} ) );

// Resolves once the page has sent the cue for this track.
const cueSent = ( page, track ) =>
	page.waitForResponse(
		( res ) =>
			isCue( res.url() ) &&
			res.request().method() === 'POST' &&
			res.request().postDataJSON()?.track === track &&
			res.ok()
	);

// The director: signed in, on demo-set with the first track loaded and Lead on.
const lead = async ( page ) => {
	await page.goto( '/demo-set/' );
	await page.locator( '.track' ).first().click();
	await openNowPlaying( page );
	const sent = cueSent( page, 0 );
	await page.locator( '#cue-lead' ).click();
	await expect( page.locator( '#cue-lead' ) ).toHaveAttribute(
		'aria-pressed',
		'true'
	);
	await sent;
	await page.locator( '#deck-down' ).click();
	await expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );
};

const signedOut = ( browser ) =>
	browser.newContext( {
		baseURL: base.href,
		storageState: { cookies: [], origins: [] },
	} );

// What the page does when the tab goes to the background, or comes back.
const setVisibility = ( page, value ) =>
	page.evaluate( ( v ) => {
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => v,
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	}, value );

test.describe( 'Shared cue', () => {
	test( 'a follower opens the track the director opens, Back still works, and Follow off stops it', async ( {
		page,
		browser,
	} ) => {
		await lead( page );

		const context = await signedOut( browser );
		const follower = await context.newPage();
		try {
			await follower.goto( '/demo-set/' );
			await expect( follower.locator( '#cue-lead' ) ).toHaveCount( 0 );
			await follower.locator( '.track' ).nth( 4 ).click();
			await openNowPlaying( follower );
			const follow = follower.locator( '#cue-follow' );
			await expect( follow ).toBeVisible();
			await follow.click();
			await expect( follow ).toHaveAttribute( 'aria-pressed', 'true' );
			// Turning Follow on opens the director's current track.
			await expect
				.poll( () => state( follower ) )
				.toMatchObject( {
					set: 'demo-set',
					index: 0,
					paused: true,
				} );

			// Now Playing open on home, still following.
			await follower.locator( '#deck-down' ).click();
			await expect( follower.locator( '#deck' ) ).toHaveClass(
				/is-compact/
			);
			await follower.locator( 'a.back' ).click();
			await expect( follower.locator( 'body' ) ).toHaveAttribute(
				'data-slug',
				''
			);
			await openNowPlaying( follower );

			// The director opens track 3.
			const sent = cueSent( page, 2 );
			await page.locator( '.track' ).nth( 2 ).click();
			await sent;
			expect( ( await state( page ) ).index ).toBe( 2 ); // the director's own cue does not move the director
			await expect( page.locator( '#cue-follow' ) ).toBeHidden();

			await expect
				.poll( () => state( follower ), { timeout: 5000 } )
				.toEqual( {
					view: 'demo-set',
					set: 'demo-set',
					index: 2,
					paused: true,
					expanded: false,
				} );
			await expect( follower ).toHaveURL( /\/demo-set\/$/ );

			// Back returns to home, where the follower was, with Now Playing closed.
			await follower.goBack();
			await expect( follower.locator( 'body' ) ).toHaveAttribute(
				'data-slug',
				''
			);
			await expect( follower ).toHaveURL( base.href );
			expect( ( await state( follower ) ).expanded ).toBe( false );

			// Follow off: the director moves again and the follower stays, without asking.
			await openNowPlaying( follower );
			await follow.click();
			await expect( follow ).toHaveAttribute( 'aria-pressed', 'false' );
			const reads = [];
			follower.on( 'request', ( req ) => {
				if ( isCue( req.url() ) ) {
					reads.push( req.url() );
				}
			} );
			const moved = cueSent( page, 1 );
			await page.locator( '.track' ).nth( 1 ).click();
			await moved;
			// Proving nothing happens takes time: two and a half poll intervals.
			await follower.waitForTimeout( 5000 );
			expect( reads ).toEqual( [] );
			expect( await state( follower ) ).toMatchObject( {
				view: '',
				index: 2,
			} );
		} finally {
			await context.close();
		}
	} );

	test( 'a hidden page makes no requests while following', async ( {
		page,
		browser,
	} ) => {
		await lead( page );

		const context = await signedOut( browser );
		const follower = await context.newPage();
		try {
			const reads = [];
			follower.on( 'request', ( req ) => {
				if ( isCue( req.url() ) ) {
					reads.push( Date.now() );
				}
			} );
			await follower.goto( '/demo-set/' );
			await follower.locator( '.track' ).first().click();
			await openNowPlaying( follower );
			await follower.locator( '#cue-follow' ).click();
			// Following reads the cue every two seconds.
			const started = reads.length;
			await expect
				.poll( () => reads.length, { timeout: 5000 } )
				.toBeGreaterThanOrEqual( started + 2 );

			await setVisibility( follower, 'hidden' );
			await follower.waitForTimeout( 500 ); // lets a read that was already on its way land
			const hidden = reads.length;
			// Proving nothing is sent takes time: two and a half poll intervals.
			await follower.waitForTimeout( 5000 );
			expect( reads ).toHaveLength( hidden );

			// Visible again, it reads straight away.
			await setVisibility( follower, 'visible' );
			await expect
				.poll( () => reads.length, { timeout: 1000 } )
				.toBeGreaterThan( hidden );
		} finally {
			await context.close();
		}
	} );

	test( 'switched off by id, there are no buttons and no requests', async ( {
		page,
	} ) => {
		await page.context().addCookies( [
			{
				name: 'callboard_example_disable',
				value: 'callboard/cue',
				domain: base.hostname,
				path: '/',
			},
		] );
		const reads = [];
		page.on( 'request', ( req ) => {
			if ( isCue( req.url() ) ) {
				reads.push( req.url() );
			}
		} );
		try {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await openNowPlaying( page );
			await expect(
				page.locator( '#cue-lead, #cue-follow' )
			).toHaveCount( 0 );
			expect(
				await page.evaluate( () => ( {
					active: window.callboard.isActive( 'callboard/cue' ),
					data: window.callboard.data( 'callboard/cue' ),
				} ) )
			).toEqual( { active: false, data: null } );
			await page.waitForTimeout( 2500 ); // eslint-disable-line playwright/no-wait-for-timeout -- longer than one poll interval, to show nothing is sent
			expect( reads ).toEqual( [] );
		} finally {
			await page.context().clearCookies( {
				name: 'callboard_example_disable',
			} );
		}
	} );

	test( 'the nonce is built per request, and a site that requires sign-in needs it', async ( {
		page,
		admin,
		browser,
	} ) => {
		await page.goto( '/' );
		const config = await page.evaluate( () =>
			window.callboard.data( 'callboard/cue' )
		);
		expect( config.canLead ).toBe( true );
		expect( config.nonce ).toBeTruthy();
		// App data is not cached with the playlists.
		expect(
			await page.evaluate(
				( nonce ) =>
					JSON.stringify( window.CALLBOARD.sets ).includes( nonce ),
				config.nonce
			)
		).toBe( false );

		const saveSignIn = async ( on ) => {
			await admin.visitAdminPage(
				'edit.php',
				'post_type=callboard_set&page=callboard-settings'
			);
			await page.locator( '#callboard-require_signin' ).setChecked( on );
			await page.locator( '#submit' ).click();
			await expect(
				page
					.locator(
						'#setting-error-settings_updated, .notice-success'
					)
					.first()
			).toBeVisible();
		};

		const context = await signedOut( browser );
		await saveSignIn( true );
		try {
			expect( ( await context.request.get( config.api ) ).status() ).toBe(
				401
			);
			// Signed in without the nonce, WordPress treats the request as signed out.
			expect( ( await page.request.get( config.api ) ).status() ).toBe(
				401
			);
			const read = await page.request.get( config.api, {
				headers: { 'X-WP-Nonce': config.nonce },
			} );
			expect( read.status() ).toBe( 200 );
			expect( Object.keys( await read.json() ) ).toEqual( [
				'seq',
				'set',
				'track',
				'position',
				'at',
			] );
		} finally {
			await context.close();
			await saveSignIn( false );
		}
	} );
} );
