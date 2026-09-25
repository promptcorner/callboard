/* eslint-disable no-console -- command-line script. */
/**
 * Regenerates the wordpress.org screenshots in .wordpress-org/.
 *
 * Captures pages from a running wp-env site, places them in scripts/wporg/template.html (device
 * frame and headline), and saves each result at 2400x1500. .wordpress-org/screenshots.json lists
 * each screenshot's page, headline, and readme.txt caption.
 *
 * While it runs, the script uses WP-CLI to publish a sample call, set the site title, and switch
 * every set except the demo set to draft. It reverts all three when it finishes.
 *
 * Usage: start wp-env, then run `node scripts/wporg-screenshots.js [baseURL]`.
 * Without a URL it uses the wp-env tests site.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const ROOT = path.join( __dirname, '..' );
const OUT = path.join( ROOT, '.wordpress-org' );
const SHOTS = require( path.join( OUT, 'screenshots.json' ) );
const SITE_NAME = 'Rehearsal Tracks';
const PHONE = { width: 390, height: 844 };

/**
 * Returns the wp-env tests site port, read from .wp-env.json and .wp-env.override.json.
 */
const testsPort = () => {
	let port = 8889;
	for ( const name of [ '.wp-env.json', '.wp-env.override.json' ] ) {
		try {
			const json = JSON.parse(
				fs.readFileSync( path.join( ROOT, name ), 'utf8' )
			);
			port = typeof json.testsPort === 'number' ? json.testsPort : port;
		} catch {}
	}
	return port;
};

const BASE = process.argv[ 2 ] || `http://localhost:${ testsPort() }`;
// Run WP-CLI against the same site that is being captured.
const CLI =
	new URL( BASE ).port === String( testsPort() ) ? 'tests-cli' : 'cli';

const wp = ( php ) =>
	execFileSync( 'npx', [ 'wp-env', 'run', CLI, 'wp', 'eval', php ], {
		cwd: ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();

const dataUri = ( file, type ) =>
	`data:${ type };base64,${ fs.readFileSync( file ).toString( 'base64' ) }`;

// Passes a string to PHP base64-encoded, so quotes and dollar signs in it are not parsed as code.
const php64 = ( value ) =>
	`base64_decode( '${ Buffer.from( String( value ) ).toString( 'base64' ) }' )`;

/**
 * Publishes a call for tomorrow at 19:00 with the demo set's first three tracks, sets the site title,
 * and switches other published sets to draft.
 *
 * @return {{name: string, call: number, others: number[]}} What tearDown() needs to revert it.
 */
const setUp = () =>
	JSON.parse(
		wp( `
			$name = get_option( 'blogname' );
			update_option( 'blogname', ${ php64( SITE_NAME ) } );
			$set = get_page_by_path( 'demo-set', OBJECT, 'callboard_set' );
			$others = get_posts( array( 'post_type' => 'callboard_set', 'post_status' => 'publish', 'posts_per_page' => -1, 'exclude' => array( $set->ID ), 'fields' => 'ids' ) );
			foreach ( $others as $other ) {
				wp_update_post( array( 'ID' => $other, 'post_status' => 'draft' ) );
			}
			$tracks = get_posts( array( 'post_type' => 'attachment', 'post_parent' => $set->ID, 'post_mime_type' => 'audio', 'orderby' => 'menu_order', 'order' => 'ASC', 'posts_per_page' => 3, 'fields' => 'ids' ) );
			$call = wp_insert_post( array( 'post_type' => 'callboard_call', 'post_status' => 'publish', 'post_title' => 'Act I run', 'post_content' => 'Warm-up at 6:45. Bring scripts and a pencil; we are setting the Act I transitions.' ) );
			update_post_meta( $call, '_callboard_when', wp_date( 'Y-m-d', strtotime( '+1 day' ) ) . ' 19:00' );
			update_post_meta( $call, '_callboard_where', 'Room 204' );
			update_post_meta( $call, '_callboard_numbers', $tracks );
			echo wp_json_encode( array( 'name' => $name, 'call' => $call, 'others' => $others ) );
		` )
	);

/**
 * Deletes the sample call, republishes the other sets, and restores the site title.
 *
 * @param {{name: string, call: number, others: number[]}} state Returned by setUp().
 */
const tearDown = ( state ) =>
	wp( `
		wp_delete_post( ${ Number( state.call ) }, true );
		foreach ( array( ${ state.others.map( Number ).join( ', ' ) } ) as $other ) {
			wp_update_post( array( 'ID' => $other, 'post_status' => 'publish' ) );
		}
		update_option( 'blogname', ${ php64( state.name ) } );
	` );

/**
 * Captures a page at iPhone size and returns it as a PNG data URI.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {'light'|'dark'}                     scheme  Color scheme.
 * @param {Function}                           visit   Navigates the page to what should be captured.
 */
const phone = async ( browser, scheme, visit ) => {
	const ctx = await browser.newContext( {
		viewport: PHONE,
		deviceScaleFactor: 3,
		colorScheme: scheme,
		isMobile: true,
		hasTouch: true,
	} );
	const page = await ctx.newPage();
	await visit( page );
	await page.waitForTimeout( 1200 ); // wait for entrance animations to finish
	// Stop the scrolling track title at its start position.
	await page.evaluate( () => {
		document.querySelectorAll( '.deck-title .mq' ).forEach( ( el ) => {
			el.style.animation = 'none';
			el.style.transform = 'none';
		} );
	} );
	const png = await page.screenshot();
	await ctx.close();
	return `data:image/png;base64,${ png.toString( 'base64' ) }`;
};

const home = async ( page ) => {
	await page.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );
	await page.locator( '.board .call' ).first().waitFor();
};

const playing = async ( page ) => {
	await page.goto( `${ BASE }/demo-set/`, { waitUntil: 'networkidle' } );
	await page.locator( '.track' ).nth( 1 ).click(); // the player bar appears once a track is loaded
	await page.locator( '#deck' ).waitFor();
	await page.waitForTimeout( 2500 ); // play a few seconds so the progress shows
};

/**
 * Capture functions, keyed by the `view` field in screenshots.json.
 */
const CAPTURES = {
	home: async ( browser ) => [
		await phone( browser, 'dark', home ),
		await phone( browser, 'light', home ),
	],
	set: async ( browser ) => [ await phone( browser, 'dark', playing ) ],
	'now-playing': async ( browser ) => [
		await phone( browser, 'dark', async ( page ) => {
			await playing( page );
			await page.locator( '#deck-open' ).click(); // tapping the player bar opens the full-screen player
			await page
				.locator( '#deck.is-expanded' )
				.waitFor( { state: 'visible' } );
		} ),
	],
	'call-editor': async ( browser, state ) => {
		const ctx = await browser.newContext( {
			// Tall enough to include the call details meta box below the editor.
			viewport: { width: 1440, height: 1400 },
			deviceScaleFactor: 2,
		} );
		const page = await ctx.newPage();
		await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'networkidle' } );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForLoadState( 'networkidle' );
		await page.goto(
			`${ BASE }/wp-admin/post.php?post=${ state.call }&action=edit`,
			{ waitUntil: 'networkidle' }
		);
		// Expand the demo set's track checkboxes if they are collapsed.
		const numbers = page.locator( '#callboard-call details' ).first();
		if ( ! ( await numbers.evaluate( ( el ) => el.open ) ) ) {
			await numbers.locator( 'summary' ).click();
		}
		await page.waitForTimeout( 800 );
		const png = await page.screenshot();
		await ctx.close();
		return [ `data:image/png;base64,${ png.toString( 'base64' ) }` ];
	},
};

( async () => {
	const template = fs
		.readFileSync( path.join( __dirname, 'wporg', 'template.html' ), 'utf8' )
		// Replacer functions, so "$" sequences in the data are not treated as replacement patterns.
		.replace( '__FONT_BOLD__', () =>
			dataUri(
				path.join( ROOT, 'assets/fonts/Poppins-Bold.ttf' ),
				'font/ttf'
			)
		)
		.replace( '__FONT_SEMIBOLD__', () =>
			dataUri(
				path.join( ROOT, 'assets/fonts/Poppins-SemiBold.ttf' ),
				'font/ttf'
			)
		);

	const state = setUp();
	let browser;
	try {
		browser = await chromium.launch();
		for ( const shot of SHOTS ) {
			const capture = CAPTURES[ shot.view ];
			if ( ! capture ) {
				throw new Error( `No capture for the view "${ shot.view }"` );
			}
			const images = await capture( browser, state );
			let layout = images.length === 2 ? 'two' : 'one';
			if ( shot.view === 'call-editor' ) {
				layout = 'wide';
			}
			const ctx = await browser.newContext( {
				viewport: { width: 1200, height: 750 },
				deviceScaleFactor: 2,
			} );
			const page = await ctx.newPage();
			const errors = [];
			page.on( 'pageerror', ( error ) => errors.push( error ) );
			await page.setContent(
				template.replace(
					'window.SHOT = __SHOT__;',
					() =>
						'window.SHOT = ' +
						// Escape "<" so no string in the JSON can close the <script> tag.
						JSON.stringify( { ...shot, layout, images } ).replace(
							/</g,
							'\\u003c'
						) +
						';'
				),
				{ waitUntil: 'load' }
			);
			await page.evaluate( () => document.fonts.ready );
			// Fail instead of saving an empty background if the template's script did not run.
			const headline = await page.locator( '#h' ).textContent();
			if ( errors.length || headline !== shot.headline ) {
				throw new Error(
					`${ shot.file }: the template did not render. ${ errors.join( ' ' ) }`
				);
			}
			await page.screenshot( { path: path.join( OUT, shot.file ) } );
			await ctx.close();
			console.log( `${ shot.file }  2400x1500  ${ shot.view }` );
		}
	} finally {
		await browser?.close();
		tearDown( state );
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
