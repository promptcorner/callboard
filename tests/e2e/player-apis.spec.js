/**
 * Browser APIs the player depends on, where nothing on screen would show that they stopped working:
 * ResizeObserver for the waveform. See #119.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const expandDeck = ( page ) => page.locator( '#deck-open' ).click();

test.describe( 'Player browser APIs', () => {
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
