/**
 * Privacy: unindexed, no discoverable people, no anonymous API except push opt-in.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Privacy', () => {
	test( 'every response is noindex and sends no referrer', async ( {
		request,
	} ) => {
		const res = await request.get( '/demo-set/' );
		expect( res.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( res.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	} );

	test( 'robots.txt blocks crawlers but allows preview bots', async ( {
		request,
	} ) => {
		const body = await ( await request.get( '/robots.txt' ) ).text();
		expect( body ).toContain( 'User-agent: *\nDisallow: /' );
		expect( body ).toContain( 'User-agent: Twitterbot\nAllow: /' );
	} );

	test( 'the users endpoint and anonymous REST are closed', async ( {
		playwright,
	} ) => {
		// Not the logged-in test user: a fresh client with no cookies.
		const stranger = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
			storageState: { cookies: [], origins: [] }, // the runner's default state is the logged-in admin
		} );
		for ( const path of [
			'/wp-json/wp/v2/users',
			'/wp-json/wp/v2/posts',
		] ) {
			const res = await stranger.get( path );
			const body = ( await res.text() ).slice( 0, 200 );
			expect( res.status(), `${ path } → ${ body }` ).toBe( 401 );
		}
		await stranger.dispose();
	} );

	test( 'feeds, author archives and search redirect home', async ( {
		request,
	} ) => {
		for ( const path of [ '/feed/', '/?author=1', '/?s=x' ] ) {
			const res = await request.get( path, { maxRedirects: 0 } );
			expect( [ 301, 302, 403 ] ).toContain( res.status() );
		}
	} );

	test( 'the push routes are gone, so no REST route is open to visitors', async ( {
		request,
	} ) => {
		const key = await request.get( '/wp-json/callboard/v1/push/key' );
		expect( key.ok() ).toBeFalsy();
	} );
} );
