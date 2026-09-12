/**
 * Browser APIs the player depends on, where nothing on screen would show that they stopped working:
 * Web Audio for the count-in, the Screen Wake Lock, and ResizeObserver for the waveform. The gapless
 * loop has its own spec, gapless-loop.spec.js. See #119.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();

test.describe( 'Player browser APIs', () => {
	test( 'count-in plays four clicks at the track tempo, then starts the track', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			// Turn count-in on for this page only, instead of changing the site setting.
			let data;
			Object.defineProperty( window, 'CALLBOARD', {
				configurable: true,
				get: () => data,
				set: ( value ) => {
					data = value;
					const ext = data?.ext?.[ 'callboard/count-in' ];
					if ( ext ) {
						ext.enabled = true;
					}
				},
			} );
			// Record every oscillator start (the clicks) and every play() call, with the time it happened.
			window.clicks = [];
			window.plays = [];
			const createOscillator = AudioContext.prototype.createOscillator;
			AudioContext.prototype.createOscillator = function recordClicks() {
				const osc = createOscillator.call( this );
				const start = osc.start.bind( osc );
				osc.start = ( ...args ) => {
					window.clicks.push( {
						at: performance.now(),
						hz: osc.frequency.value,
					} );
					return start( ...args );
				};
				return osc;
			};
			const play = HTMLMediaElement.prototype.play;
			HTMLMediaElement.prototype.play = function recordPlay() {
				window.plays.push( performance.now() );
				return play.call( this ).catch( () => {} );
			};
		} );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track .bpm' ) ).toHaveText( '♩ 96' );
		await page.locator( '.track' ).nth( 2 ).click(); // the 96 BPM track

		await expect
			.poll( () => page.evaluate( () => window.clicks.length ) )
			.toBe( 4 );
		const beat = 60000 / 96;
		// Wait for the track to start after the last click.
		await expect
			.poll( () =>
				page.evaluate( () =>
					window.plays.some( ( t ) => t > window.clicks[ 3 ].at )
				)
			)
			.toBe( true );
		const { clicks, plays } = await page.evaluate( () => ( {
			clicks: window.clicks,
			plays: window.plays,
		} ) );

		// The first click is higher, like a metronome's downbeat.
		expect( clicks.map( ( c ) => c.hz ) ).toEqual( [
			1320, 880, 880, 880,
		] );
		// About one beat apart. A busy machine can fire any of the timers late, so allow some slack.
		for ( let k = 1; k < 4; k++ ) {
			const gap = clicks[ k ].at - clicks[ k - 1 ].at;
			expect( gap ).toBeGreaterThan( beat - 200 );
			expect( gap ).toBeLessThan( beat + 300 );
		}
		// Nothing plays during the count, and the track starts about a beat after the last click.
		const first = clicks[ 0 ].at,
			last = clicks[ 3 ].at;
		expect( plays.filter( ( t ) => t > first && t < last ) ).toEqual( [] );
		expect( plays.some( ( t ) => t > last + beat - 200 ) ).toBe( true );
	} );

	test( 'the screen stays awake while a loop is set or the lyrics sheet is open', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			// A fake wake lock that records whether the page is currently holding it.
			window.wakeLock = { requests: 0, held: false };
			Object.defineProperty( navigator, 'wakeLock', {
				configurable: true,
				value: {
					request: async () => {
						window.wakeLock.requests++;
						window.wakeLock.held = true;
						return {
							release: async () => {
								window.wakeLock.held = false;
							},
						};
					},
				},
			} );
		} );
		const held = () => page.evaluate( () => window.wakeLock.held );

		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click(); // has a director's note, so it has a sheet
		await expandDeck( page );
		expect( await held() ).toBe( false );

		await page.locator( '#open-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeVisible();
		await expect.poll( held ).toBe( true );
		await page.locator( '#close-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeHidden();
		await expect.poll( held ).toBe( false );

		await page.keyboard.press( '[' ); // set a loop from the current position to the end
		await expect( page.locator( '#loop-band' ) ).toHaveClass( /on/ );
		await expect.poll( held ).toBe( true );
		await page.keyboard.press( '\\' ); // clear the loop
		await expect( page.locator( '#loop-band' ) ).not.toHaveClass( /on/ );
		await expect.poll( held ).toBe( false );
	} );

	test( 'the waveform redraws when its box changes width, even without a window resize', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click(); // has loudness levels, so it has a waveform
		await expandDeck( page );
		await expect( page.locator( '#deck' ) ).toHaveClass( /has-wave/ );

		// The canvas is drawn for its box at the screen's pixel density.
		const sizes = () =>
			page.locator( '#wave-base' ).evaluate( ( canvas ) => ( {
				canvas: canvas.width,
				box: Math.round(
					canvas.parentElement.clientWidth * window.devicePixelRatio
				),
			} ) );
		await expect
			.poll( async () => {
				const s = await sizes();
				return s.canvas > 0 && s.canvas === s.box;
			} )
			.toBe( true );
		const before = ( await sizes() ).canvas;

		// The window's resize event also redraws the wave, so change the box alone. ResizeObserver is
		// what notices this, the same way it notices the deck switching between the bar and full screen.
		await page
			.locator( '#wave-base' )
			.evaluate(
				( canvas ) => ( canvas.parentElement.style.width = '300px' )
			);
		await expect
			.poll( async () => {
				const s = await sizes();
				return s.canvas !== before && s.canvas === s.box;
			} )
			.toBe( true );
	} );
} );
