/**
 * Installable app: manifest, service worker, head tags, link previews.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'PWA and previews', () => {
	test( 'manifest.json is written to the site root and names the site', async ( {
		request,
	} ) => {
		const res = await request.get( '/manifest.json' );
		expect( res.ok() ).toBeTruthy();
		const manifest = await res.json();
		expect( manifest.display ).toBe( 'standalone' );
		expect( manifest.start_url ).toBe( '/' );
		expect( manifest.icons.length ).toBeGreaterThanOrEqual( 2 );
		expect( manifest.id ).toBe( '/' );
		// Not shortcuts[ 0 ]: a local-only set can sort ahead of the fixtures on this machine.
		expect( manifest.shortcuts.map( ( s ) => s.name ) ).toContain(
			'Shakespeare’s Sonnets'
		);
		expect( manifest.launch_handler.client_mode ).toBe(
			'navigate-existing'
		);
		expect( manifest.short_name.length ).toBeLessThanOrEqual( 12 );
	} );

	test( 'service worker is served from the root with push handlers', async ( {
		request,
	} ) => {
		const res = await request.get( '/sw.js' );
		expect( res.ok() ).toBeTruthy();
		const body = await res.text();
		expect( body ).toMatch( /addEventListener\(\s*'push'/ );
		expect( body ).toMatch( /addEventListener\(\s*'fetch'/ );
		expect( body ).toContain( 'navigationPreload' );
		expect( body ).toMatch( /const ASSETS = \[.*\/manifest\.json.*\]/ ); // shell precache, versioned
		expect( body ).toContain( 'd.notification' ); // declarative Web Push payloads
		expect( body ).toMatch(
			/addEventListener\(\s*'pushsubscriptionchange'/
		);
		expect( body ).toMatch(
			/const PUSH_API = '[^']*callboard\/v1\/push\/'/
		); // the worker knows where to re-register
	} );

	test( 'head carries app meta and Open Graph tags per view', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await expect( page.locator( 'link[rel=manifest]' ) ).toHaveAttribute(
			'href',
			/manifest\.json$/
		);
		await expect(
			page.locator( 'link[rel=apple-touch-icon]' )
		).toHaveCount( 1 );
		expect(
			await page.locator( 'link[rel=apple-touch-startup-image]' ).count()
		).toBeGreaterThanOrEqual( 10 );
		await expect(
			page.locator( 'meta[property="og:title"]' )
		).toHaveAttribute( 'content', 'Shakespeare’s Sonnets' );
		await expect(
			page.locator( 'meta[property="og:image"]' )
		).toHaveAttribute( 'content', /\.png/ );
		await expect( page.locator( 'meta[name=viewport]' ) ).toHaveAttribute(
			'content',
			/viewport-fit=cover/
		);
	} );

	test( 'a saved set opens and plays with the network off', async ( {
		page,
		context,
	} ) => {
		await page.goto( '/demo-set/' );
		// the worker registers at idle and controls the page after a reload
		await page
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
		// The save controls bind a beat after the view does and only then read the cache; the button
		// wears data-some once it has, which is the first moment a tap on it means anything.
		await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
			timeout: 15000,
		} );
		await page.locator( '#offline' ).click();
		await expect( page.locator( '#offline' ) ).toContainText(
			/Saved offline/,
			{ timeout: 30000 }
		);
		// "Saved offline" waits for the worker to keep the set's page, so the copy is already here the
		// moment the label says so. Checked through evaluate, which awaits: waitForFunction takes a
		// returned promise itself as truthy.
		expect(
			await page.evaluate( async () =>
				Boolean( await caches.match( '/demo-set/?fragment=1' ) )
			)
		).toBe( true );

		await context.setOffline( true );
		await page.goto( '/' ); // home, from the precached shell
		const set = page.locator( 'a.set', {
			hasText: 'Shakespeare’s Sonnets',
		} );
		await expect( set ).toBeVisible();
		await set.click(); // the set, from its cached fragment
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await page.locator( '.track' ).first().click();
		await page.waitForFunction(
			() => {
				const a = document.getElementById( 'audio' );
				return a.error || a.readyState >= 1;
			},
			null,
			{ timeout: 15000 }
		);
		const audio = await page.evaluate( () => {
			const a = document.getElementById( 'audio' );
			return {
				error: a.error ? a.error.code : null,
				readyState: a.readyState,
			};
		} );
		expect( audio.error ).toBeNull();
		expect( audio.readyState ).toBeGreaterThanOrEqual( 1 ); // metadata arrived from the cache
		await context.setOffline( false );

		// Remove the copies: a tap on "Saved offline" asks, the next tap removes. The set page was swapped
		// in, so wait for the button to be bound first.
		await expect( page.locator( '#offline' ) ).toHaveAttribute(
			'data-some',
			'1',
			{ timeout: 15000 }
		);
		// A double tap only asks; the second tap does not confirm.
		await page.locator( '#offline' ).dblclick();
		await expect( page.locator( '#offline' ) ).toContainText( /Tap again/ );
		await expect(
			page.locator( '.dl[data-state="saved"]' )
		).not.toHaveCount( 0 );
		// Confirm once the button accepts it (data-confirm="1").
		await expect( page.locator( '#offline' ) ).toHaveAttribute(
			'data-confirm',
			'1',
			{ timeout: 15000 }
		);
		await page.locator( '#offline' ).click();
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			0
		);
	} );

	test( 'a set that says it is saved opens with no signal straight after', async ( {
		page,
		context,
	} ) => {
		// A server slow to render the set's page, which is most shared hosting. The save used to say
		// "Saved offline" while the page it opens from was still on its way, and the moment the signal
		// went the page was lost with it.
		await context.route( /\/demo-set\/\?fragment=1/, async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
			await route.continue().catch( () => {} );
		} );
		// A first visit, straight from a link: the worker takes over this page without it being fetched
		// again, so nothing has stored the document either.
		await page.goto( '/demo-set/' );
		await page.waitForFunction(
			() => navigator.serviceWorker?.controller,
			null,
			{ timeout: 15000 }
		);
		await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
			timeout: 15000,
		} );
		await page.locator( '#offline' ).click();
		await expect( page.locator( '#offline' ) ).toContainText(
			/Saved offline/,
			{ timeout: 30000 }
		);
		await context.setOffline( true ); // the moment it says so

		await page.goto( '/' );
		await page
			.locator( 'a.set', { hasText: 'Shakespeare’s Sonnets' } )
			.click();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );

		await page.goto( '/demo-set/' ); // a cold start on the set itself
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await context.setOffline( false );
	} );
} );
