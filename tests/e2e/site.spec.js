/**
 * The landing page's live demo section (site/index.html).
 *
 * The page is static, so these tests serve site/ from a local server the way the Pages workflow
 * publishes it. WordPress Playground is replaced with a stub module, so nothing here needs the
 * network or a running WordPress.
 */
const fs = require( 'fs' );
const http = require( 'http' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const ROOT = path.join( __dirname, '..', '..' );
const PLAYGROUND_CLIENT = 'https://playground.wordpress.net/client/index.js';

// Stands in for Playground's client. Starting waits until the test calls window.finishDemo().
const STUB_CLIENT = `
export async function startPlaygroundWeb( { blueprint } ) {
	window.demoBlueprint = blueprint;
	await new Promise( ( resolve ) => ( window.finishDemo = resolve ) );
	return {
		isReady: async () => {},
		run: async ( { code } ) => ( window.demoRuns = [ ...( window.demoRuns || [] ), code ] ),
		goTo: async () => {},
	};
}`;

let server;
let base;

test.beforeAll( async () => {
	server = http.createServer( ( req, res ) => {
		const url = decodeURIComponent( req.url.split( '?' )[ 0 ] );
		const file =
			url === '/blueprint.json'
				? path.join( ROOT, 'blueprint.json' )
				: path.join( ROOT, 'site', url === '/' ? 'index.html' : url );
		if ( ! file.startsWith( ROOT ) || ! fs.existsSync( file ) ) {
			res.writeHead( 404 );
			res.end();
			return;
		}
		let body = fs.readFileSync( file );
		if ( file.endsWith( '.html' ) ) {
			body = body.toString().replaceAll( '{{pages_url}}', base );
		}
		const type = {
			'.html': 'text/html',
			'.png': 'image/png',
			'.json': 'application/json',
		};
		res.writeHead( 200, {
			'content-type':
				type[ path.extname( file ) ] || 'application/octet-stream',
		} );
		res.end( body );
	} );
	await new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', resolve )
	);
	base = `http://127.0.0.1:${ server.address().port }/`;
} );

// Never reach the real Playground from a test.
test.beforeEach( async ( { context } ) => {
	await context.route( 'https://playground.wordpress.net/**', ( route ) =>
		route.abort()
	);
} );

test.afterAll( () => server?.close() );

const stubPlayground = ( page ) =>
	page.route( PLAYGROUND_CLIENT, ( route ) =>
		route.fulfill( {
			contentType: 'text/javascript',
			headers: { 'access-control-allow-origin': '*' },
			body: STUB_CLIENT,
		} )
	);

const openDemo = async ( page ) => {
	await page.goto( base );
	await page.locator( '.demo' ).scrollIntoViewIfNeeded();
};

test.describe( 'Landing page live demo', () => {
	test( 'the preview is not cropped', async ( { page } ) => {
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await openDemo( page );
			const still = page.locator( '#demo-still' );
			await expect
				.poll( () => still.evaluate( ( img ) => img.naturalWidth ) )
				.toBeGreaterThan( 0 );
			const { box, natural } = await still.evaluate( ( img ) => ( {
				box:
					img.getBoundingClientRect().width /
					img.getBoundingClientRect().height,
				natural: img.naturalWidth / img.naturalHeight,
			} ) );
			expect( Math.abs( box - natural ) / natural ).toBeLessThan( 0.01 );
		}
	} );

	test( 'each example changes the preview', async ( { page } ) => {
		await openDemo( page );
		const still = page.locator( '#demo-still' );
		for ( const [ name, file ] of [
			[ 'Chamber Choir', 'demo-chamber-choir.png' ],
			[ 'Maya Sings', 'demo-maya-sings.png' ],
			[ 'Spring Show', 'demo-spring-show.png' ],
		] ) {
			const chip = page.getByRole( 'button', { name } );
			await chip.click();
			await expect( chip ).toHaveAttribute( 'aria-pressed', 'true' );
			await expect( still ).toHaveAttribute( 'src', new RegExp( file ) );
			await expect( still ).toHaveAttribute( 'alt', new RegExp( name ) );
			await expect
				.poll( () =>
					still.evaluate(
						( img ) => img.complete && img.naturalWidth
					)
				)
				.toBeGreaterThan( 0 );
		}
		await expect(
			page.getByRole( 'button', { pressed: true } )
		).toHaveCount( 1 );
	} );

	test( 'the new-tab link opens Playground with the chosen example', async ( {
		page,
	} ) => {
		await openDemo( page );
		await page.getByRole( 'button', { name: 'Maya Sings' } ).click();
		const link = page.locator( '#demo-start' );
		await expect( link ).toHaveAttribute( 'target', '_blank' );
		await expect( link ).toHaveAttribute(
			'href',
			/^https:\/\/playground\.wordpress\.net\/\?mode=seamless#/
		);
		const blueprint = await link.evaluate( ( a ) =>
			JSON.parse(
				new TextDecoder().decode(
					Uint8Array.from( atob( a.hash.slice( 1 ) ), ( c ) =>
						c.charCodeAt( 0 )
					)
				)
			)
		);
		expect( blueprint.steps.at( -1 ).code ).toContain( 'Maya Sings' );
	} );

	test( 'starting shows progress, then the live site in the frame', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		await stubPlayground( page );
		await openDemo( page );
		const link = page.getByRole( 'link', { name: 'Start the live demo' } );
		await link.click();

		const status = page.getByRole( 'status' );
		await expect( status ).toHaveText( /Starting/ );
		await expect( link ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( page.locator( '#demo-still' ) ).toBeVisible();

		await page.waitForFunction(
			() => typeof window.finishDemo === 'function'
		);
		await page.evaluate( () => window.finishDemo() );

		await expect( page.locator( '#demo' ) ).toBeVisible();
		await expect( page.locator( '#demo-still' ) ).toBeHidden();
		await expect( status ).toHaveText( /running/ );
		await expect( page.locator( '#demo-start' ) ).toBeFocused();
		await expect( page.locator( '#demo-start' ) ).toHaveText(
			'Open the demo in a new tab'
		);

		await page.getByRole( 'button', { name: 'Chamber Choir' } ).click();
		await expect( status ).toHaveText( /showing Chamber Choir/ );
		expect(
			await page.evaluate( () => window.demoRuns.at( -1 ) )
		).toContain( 'Chamber Choir' );
	} );

	test( 'a demo that cannot start offers a new tab', async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.route( PLAYGROUND_CLIENT, ( route ) =>
			route.fulfill( { status: 500, body: '' } )
		);
		await openDemo( page );
		await page.getByRole( 'link', { name: 'Start the live demo' } ).click();

		await expect( page.getByRole( 'status' ) ).toHaveText(
			/couldn’t start/
		);
		const fallback = page.getByRole( 'link', {
			name: 'Open the demo in a new tab',
		} );
		await expect( fallback ).toBeVisible();
		await expect( fallback ).toHaveAttribute( 'target', '_blank' );
		await expect( fallback ).toHaveAttribute(
			'href',
			/^https:\/\/playground\.wordpress\.net\//
		);
		await expect( page.locator( '#demo-still' ) ).toBeVisible();
		await expect( page.locator( '#demo' ) ).toBeHidden();
	} );

	test( 'on a phone the demo opens in a new tab instead of the frame', async ( {
		page,
		context,
	} ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await stubPlayground( page );
		await openDemo( page );
		const link = page.getByRole( 'link', {
			name: 'Open the live demo in a new tab',
		} );
		const popup = context.waitForEvent( 'page' );
		await link.click();
		await popup;
		await expect( page.locator( '#demo' ) ).toBeHidden();
		await expect( page.locator( '#demo-still' ) ).toBeVisible();
	} );
} );
