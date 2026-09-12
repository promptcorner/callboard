/**
 * The gapless A-B loop: while a loop is set and the page is visible, a looping Web Audio buffer plays the
 * loop and the audio element keeps running muted underneath.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wav, fulfillWithRanges } = require( './utils/wav' );

// The site's service worker would answer audio requests itself, where page.route() can't see them.
test.use( { serviceWorkers: 'block' } );

test( 'setting both ends of a loop plays it from a looping buffer, and hiding the page hands back to the audio element', async ( {
	page,
} ) => {
	const tone = wav( { seconds: 10 } );
	await page.route( /\.mp3(\?|$)/, async ( route ) => {
		// The looper downloads the track with fetch() before decoding it. Slow that download down so the
		// second end of the loop is always set while the first download is still running.
		if ( route.request().resourceType() === 'fetch' ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
		}
		return fulfillWithRanges( route, tone );
	} );
	await page.addInitScript( () => {
		// Record the loop settings of every buffer source when it starts.
		window.sources = [];
		const createBufferSource = AudioContext.prototype.createBufferSource;
		AudioContext.prototype.createBufferSource = function recordSources() {
			const source = createBufferSource.call( this );
			const start = source.start.bind( source );
			source.start = ( ...args ) => {
				window.sources.push( {
					loop: source.loop,
					loopStart: source.loopStart,
					loopEnd: source.loopEnd,
				} );
				return start( ...args );
			};
			return source;
		};
	} );
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );

	await page.goto( '/demo-set/' );
	await page.locator( '.track' ).first().click();
	await page.locator( '#open-lyrics' ).click(); // open Now Playing
	const audio = page.locator( '#audio' );
	await expect
		.poll( () => audio.evaluate( ( a ) => ! a.paused && a.duration ) )
		.toBeCloseTo( 10, 0 );

	await audio.evaluate( ( a ) => ( a.currentTime = 2 ) );
	await page.keyboard.press( '[' );
	await audio.evaluate( ( a ) => ( a.currentTime = 6 ) );
	await page.keyboard.press( ']' );

	await expect
		.poll( () => page.evaluate( () => window.sources.at( -1 ) ) )
		.toMatchObject( { loop: true } );
	const source = await page.evaluate( () => window.sources.at( -1 ) );
	expect( source.loopStart ).toBeCloseTo( 2, 1 );
	expect( source.loopEnd ).toBeCloseTo( 6, 1 );
	// The element keeps playing silently underneath, for the lock screen and background play.
	await expect.poll( () => audio.evaluate( ( a ) => a.muted ) ).toBe( true );

	await page.evaluate( () => {
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'hidden',
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	} );
	await expect.poll( () => audio.evaluate( ( a ) => a.muted ) ).toBe( false );
	const at = await audio.evaluate( ( a ) => a.currentTime );
	expect( at ).toBeGreaterThanOrEqual( 2 );
	expect( at ).toBeLessThanOrEqual( 6 );
	// Reading the position while the looper was still loading used to throw on every frame.
	expect( errors ).toEqual( [] );
} );
