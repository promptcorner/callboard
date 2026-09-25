/**
 * Accessibility: axe-core (WCAG 2.x A/AA) on every surface, in both color schemes, plus keyboard operation.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const audit = async ( page ) =>
	new AxeBuilder( { page } )
		.exclude( '#wpadminbar' )
		.withTags( [
			'wcag2a',
			'wcag2aa',
			'wcag21a',
			'wcag21aa',
			'wcag22aa',
			'best-practice',
		] )
		.analyze();

const report = ( results ) =>
	results.violations
		.map(
			( v ) =>
				`${ v.id } (${ v.impact }): ${ v.help } — ${ v.nodes
					.map( ( n ) => n.target.join( ' ' ) )
					.join( ', ' ) }`
		)
		.join( '\n' );

// The deck opens compact by default and remembers the last view in localStorage; the repeat control, the
// A-B loop chip, the quality pill, and the cast/share chips only exist in the expanded view, so the axe
// pass below covers both rather than only ever seeing the compact bar.
const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();

for ( const scheme of [ 'light', 'dark' ] ) {
	test.describe( `axe, ${ scheme }`, () => {
		test.use( { colorScheme: scheme } );

		test( 'home', async ( { page } ) => {
			await page.goto( '/' );
			const results = await audit( page );
			expect( report( results ) ).toBe( '' );
		} );

		test( 'a set, with a track playing, compact', async ( { page } ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).nth( 2 ).click();
			const results = await audit( page );
			expect( report( results ) ).toBe( '' );
		} );

		test( 'a set, with a track playing, expanded', async ( { page } ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).nth( 2 ).click();
			await expandDeck( page );
			const results = await audit( page );
			expect( report( results ) ).toBe( '' );
		} );
	} );
}

test.describe( 'axe, admin', () => {
	for ( const [ name, query ] of [
		[ 'setup', 'post_type=callboard_set&page=callboard-setup' ],
		[ 'settings', 'post_type=callboard_set&page=callboard-settings' ],
		[ 'import', 'post_type=callboard_set&page=callboard-import' ],
	] ) {
		test( name, async ( { admin, page } ) => {
			await admin.visitAdminPage( 'edit.php', query );
			const results = await new AxeBuilder( { page } )
				.include( '#wpbody-content' )
				.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa' ] )
				.analyze();
			expect( report( results ) ).toBe( '' );
		} );
	}

	test( 'new set editor', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'post-new.php',
			'post_type=callboard_set'
		);
		const results = await new AxeBuilder( { page } )
			.include( '#wpbody-content' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa' ] )
			.analyze();
		expect( report( results ) ).toBe( '' );
	} );
} );

test( 'the whole player works from the keyboard', async ( { page } ) => {
	await page.goto( '/demo-set/' );
	await page.locator( '.skip-link' ).focus();
	await expect( page.locator( '.skip-link' ) ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#main' ) ).toBeFocused();
	await page.locator( '.track' ).first().focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#deck' ) ).toBeVisible();
	await expect( page.locator( '.track' ).first() ).toHaveAttribute(
		'aria-current',
		'true'
	);
	await expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );
	await page.locator( '#open-lyrics' ).focus();
	await page.keyboard.press( 'Enter' ); // the compact bar's tap-to-expand target, reachable by keyboard too
	await expect( page.locator( '#deck' ) ).toHaveClass( /is-expanded/ );
	await page.locator( '#next' ).focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#now-title' ) ).toContainText(
		'Underground'
	);
	await page.locator( '#seek' ).focus();
	await page.keyboard.press( 'ArrowRight' );
	await expect( page.locator( '#seek' ) ).toHaveAttribute(
		'aria-valuetext',
		/\d:\d\d \/ \d:\d\d/
	);
	await page.locator( '#toggle' ).focus();
	await page.keyboard.press( 'Space' );
	await expect( page.locator( '#toggle' ) ).toHaveAttribute(
		'aria-label',
		/Play|Pause/
	);
} );
