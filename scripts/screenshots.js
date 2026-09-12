/* eslint-disable no-console -- a command-line script; it talks. */
/* Retake the landing-page screenshots from the running wp-env tests site.
 *
 * Run wp-env first, then:
 *   node scripts/screenshots.js           every screenshot
 *   node scripts/screenshots.js demo      only the live demo previews
 *   node scripts/screenshots.js demo http://localhost:8889
 *
 * The phone shots are 390x664 at device pixel ratio 2 (780x1328). The live demo previews are 390x844
 * at ratio 2 (780x1688), the size of the phone frame on the page.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const ROOT = path.join( __dirname, '..' );
const OUT = path.join( ROOT, 'site' );
const PHONE = { width: 390, height: 664 };
const DEMO_PHONE = { width: 390, height: 844 };

// Same site settings as the demo blueprint, with each example's changes on top.
// Keep in step with blueprint.json and PRESETS in site/index.html.
const DEMO_SETTINGS = {
	tagline: 'Rehearsal tracks',
	footer_note: 'For rehearsal use only.',
	badge: '★',
	confetti: '22',
	hearts: true,
	show_hint: false,
	offline: true,
	push: false,
	notify_new_sets: false,
	notify_calls: false,
};
const DEMO_PRESETS = [
	{
		file: 'demo-spring-show.png',
		name: 'Spring Show',
		accent: '',
		badge: '★',
		confetti: '',
		hearts: false,
	},
	{
		file: 'demo-chamber-choir.png',
		name: 'Chamber Choir',
		accent: '#3b82f6',
		badge: '♪',
		confetti: '',
		hearts: false,
	},
	{
		file: 'demo-maya-sings.png',
		name: 'Maya Sings',
		accent: '#e0457b',
		badge: '♥',
		confetti: '22',
		hearts: true,
	},
];

/**
 * The tests site's port, read the way wp-env and playwright.config.js read it.
 */
function testsPort() {
	let port = 8889;
	for ( const name of [ '.wp-env.json', '.wp-env.override.json' ] ) {
		try {
			const json = JSON.parse(
				fs.readFileSync( path.join( ROOT, name ), 'utf8' )
			);
			if ( typeof json.testsPort === 'number' ) {
				port = json.testsPort;
			}
		} catch {}
	}
	return port;
}

const args = process.argv.slice( 2 );
const ONLY_DEMO = args.includes( 'demo' );
const BASE =
	args.find( ( a ) => /^https?:\/\//.test( a ) ) ||
	`http://localhost:${ testsPort() }`;

/**
 * Run PHP on the tests site with WP-CLI. The input is passed as base64 JSON so no quoting can break it.
 *
 * @param {string} code  PHP that reads `$in` and may echo JSON.
 * @param {Object} input Data for `$in`.
 * @return {*} The decoded JSON the code echoed, or null.
 */
function wp( code, input = {} ) {
	const b64 = Buffer.from( JSON.stringify( input ) ).toString( 'base64' );
	const php = `$in = json_decode( base64_decode( '${ b64 }' ), true ); ${ code }`;
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', 'eval', php ],
		{
			cwd: ROOT,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'inherit' ],
		}
	);
	const line = out.trim().split( '\n' ).pop();
	try {
		return JSON.parse( line );
	} catch {
		return null;
	}
}

// Show only what the demo has: the demo playlist and one upcoming call. Other playlists and calls are
// set to draft directly in the database, so no save hooks or notifications run, and restored after.
const DEMO_SETUP = `
global $wpdb;
$state = array( 'blogname' => get_option( 'blogname' ), 'settings' => get_option( 'callboard_settings' ), 'hidden' => array(), 'call' => 0 );
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( ( post_type = 'callboard_set' AND post_name <> 'demo-set' ) OR post_type = 'callboard_call' )" );
foreach ( $ids as $id ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $id ) );
	clean_post_cache( $id );
	$state['hidden'][] = (int) $id;
}
update_option( 'callboard_settings', array_merge( (array) get_option( 'callboard_settings' ), $in['settings'] ) );
$call = wp_insert_post( array( 'post_type' => 'callboard_call', 'post_status' => 'publish', 'post_title' => 'Act I run', 'post_content' => 'Warm-up at 6:45. Bring scripts and a pencil; we are setting the Act I transitions.' ) );
update_post_meta( $call, '_callboard_when', wp_date( 'Y-m-d', strtotime( '+2 days' ) ) . ' 19:00' );
update_post_meta( $call, '_callboard_where', 'Room 204' );
$tracks = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'audio', 'post_parent' => get_page_by_path( 'demo-set', OBJECT, 'callboard_set' )->ID, 'posts_per_page' => 2, 'orderby' => 'menu_order', 'order' => 'ASC', 'fields' => 'ids' ) );
update_post_meta( $call, '_callboard_numbers', $tracks );
$state['call'] = (int) $call;
Callboard\\Sets::flush();
echo wp_json_encode( $state );
`;

const DEMO_PRESET = `
update_option( 'blogname', $in['name'] );
update_option( 'callboard_settings', array_merge( (array) get_option( 'callboard_settings' ), array( 'accent' => $in['accent'], 'badge' => $in['badge'], 'confetti' => $in['confetti'], 'hearts' => (bool) $in['hearts'] ) ) );
Callboard\\Sets::flush();
`;

const DEMO_RESTORE = `
global $wpdb;
if ( $in['call'] ) {
	wp_delete_post( $in['call'], true );
}
foreach ( $in['hidden'] as $id ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $id ) );
	clean_post_cache( $id );
}
update_option( 'blogname', $in['blogname'] );
update_option( 'callboard_settings', $in['settings'] );
Callboard\\Sets::flush();
`;

( async () => {
	const browser = await chromium.launch();

	const phone = async ( name, scheme, visit, size = PHONE ) => {
		const ctx = await browser.newContext( {
			viewport: size,
			deviceScaleFactor: 2,
			colorScheme: scheme,
			isMobile: true,
			hasTouch: true,
		} );
		const page = await ctx.newPage();
		await visit( page );
		await page.waitForTimeout( 1200 ); // let the entrance animations settle
		// A scrolling title caught mid-scroll looks like a bug in a screenshot. Reset it to the start.
		await page.evaluate( () => {
			document.querySelectorAll( '.deck-title .mq' ).forEach( ( el ) => {
				el.style.animation = 'none';
				el.style.transform = 'none';
			} );
		} );
		await page.screenshot( { path: path.join( OUT, name ) } );
		await ctx.close();
		console.log(
			`${ name }  ${ size.width * 2 }x${ size.height * 2 }  ${ scheme }`
		);
	};

	// The live demo previews: the home page as the demo blueprint sets it up, once per example.
	const state = wp( DEMO_SETUP, { settings: DEMO_SETTINGS } );
	if ( ! state ) {
		throw new Error(
			'Could not set up the demo content on the tests site.'
		);
	}
	try {
		for ( const preset of DEMO_PRESETS ) {
			wp( DEMO_PRESET, preset );
			await phone(
				preset.file,
				'dark',
				async ( p ) => {
					await p.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );
				},
				DEMO_PHONE
			);
		}
	} finally {
		wp( DEMO_RESTORE, state );
	}

	if ( ONLY_DEMO ) {
		await browser.close();
		return;
	}

	await phone( 'home-light.png', 'light', async ( p ) => {
		await p.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );
	} );
	await phone( 'set-light.png', 'light', async ( p ) => {
		await p.goto( `${ BASE }/demo-set/`, { waitUntil: 'networkidle' } );
	} );
	await phone( 'set-dark.png', 'dark', async ( p ) => {
		await p.goto( `${ BASE }/demo-set/`, { waitUntil: 'networkidle' } );
		await p.locator( '.track' ).first().click(); // the player bar only shows once a track is loaded
		await p.waitForTimeout( 900 );
	} );

	// The admin shot: a call open in the editor, at the desktop size the page uses.
	const desk = await browser.newContext( {
		viewport: { width: 1280, height: 960 },
		deviceScaleFactor: 2,
	} );
	const page = await desk.newPage();
	await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'networkidle' } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
	await page.goto( `${ BASE }/wp-admin/edit.php?post_type=callboard_call`, {
		waitUntil: 'networkidle',
	} );
	const row = page.locator( '.wp-list-table tbody a.row-title' ).first();
	if ( await row.count() ) {
		await row.click();
		await page.waitForLoadState( 'networkidle' );
		await page.waitForTimeout( 1500 );
		await page.screenshot( { path: path.join( OUT, 'admin-call.png' ) } );
		console.log( 'admin-call.png  2560x1920' );
	} else {
		console.log( 'admin-call.png  SKIPPED: no call posted' );
	}
	await desk.close();
	await browser.close();
} )();
