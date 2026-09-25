/**
 * Browser APIs the player depends on, where nothing on screen would show that they stopped working:
 * the Screen Wake Lock and ResizeObserver for the waveform. The gapless loop has its own spec,
 * gapless-loop.spec.js. See #119.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();

test.describe( 'Player browser APIs', () => {
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
		await page.locator( '.track' ).nth( 2 ).click(); // has lyrics, so it has a sheet
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
