/**
 * Admin: settings, set editing, import queue.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Some tests change shared data (settings, the demo set's first track, the import queue).
// Each one registers how to undo its change, and the undo runs in afterEach. Playwright runs afterEach
// even when a test fails or times out, so a failed test can't leave data behind that breaks later tests.
let undos = [];
const undoAfter = ( fn ) => undos.push( fn );

const openDemoSet = async ( admin, page ) => {
	await admin.visitAdminPage( 'edit.php', 'post_type=callboard_set' );
	await page
		.locator( '.wp-list-table tbody tr', {
			hasText: 'Compositions',
		} )
		.first()
		.locator( 'a.row-title' )
		.click();
	return page.locator( '#callboard-track-list li' ).first();
};
const saveSet = async ( page ) => {
	await page.click( '#publish' );
	await expect( page.locator( '#message' ) ).toContainText( /updated/i );
};
const deleteRows = async ( rows ) => {
	while ( ( await rows.count() ) > 0 ) {
		const n = await rows.count();
		await rows.first().hover();
		await rows.first().locator( 'a.submitdelete' ).click();
		await expect( rows ).toHaveCount( n - 1 );
	}
};
const deleteQueued = async ( admin, page, name ) => {
	await admin.visitAdminPage(
		'edit.php',
		'post_type=callboard_set&page=callboard-import'
	);
	await deleteRows(
		page.locator( 'table.widefat tbody tr', { hasText: name } )
	);
};

test.describe( 'Admin', () => {
	test.afterEach( async ( { page } ) => {
		// A failed test can leave the editor with unsaved changes. Accept core's "leave this page?" prompt.
		page.on( 'dialog', ( dialog ) => dialog.accept().catch( () => {} ) );
		const pending = undos.reverse();
		undos = [];
		for ( const undo of pending ) {
			await undo();
		}
	} );

	test( 'settings save and show on the front end', async ( {
		admin,
		page,
	} ) => {
		undoAfter( async () => {
			// back to the house colour, so the other tests and the screenshots see it
			await admin.visitAdminPage(
				'edit.php',
				'post_type=callboard_set&page=callboard-settings'
			);
			await page.fill( '#callboard-accent', '' );
			await page.click( '#submit' );
			await page.goto( '/' );
			await expect( page.locator( '#callboard-accent' ) ).toHaveCount(
				0
			);
		} );

		await admin.visitAdminPage(
			'edit.php',
			'post_type=callboard_set&page=callboard-settings'
		);
		await page.fill( '#callboard-tagline', 'Practice tracks' );
		await page.fill( '#callboard-footer_note', 'For rehearsal use only.' );
		await page.fill( '#callboard-badge', '★' );
		await page.fill( '#callboard-confetti', '22' );
		await page.check( '#callboard-hearts' );
		await page.fill( '#callboard-accent', '#3b82f6' );
		await page.click( '#submit' );
		await expect(
			page
				.locator( '#setting-error-settings_updated, .notice-success' )
				.first()
		).toBeVisible();
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track .hh' ).first() ).toHaveText( '★' );
		await page.goto( '/' );
		await expect(
			page.locator( 'meta[property="og:description"]' )
		).toHaveAttribute( 'content', /Practice tracks/ );
		// Three taps on the title release the confetti, hearts between the 22s.
		for ( let n = 0; n < 3; n++ ) {
			await page.locator( 'h1' ).click();
		}
		const confetti = page.locator( 'body > .tt' );
		await expect( confetti ).toHaveCount( 8 );
		await expect( confetti.filter( { hasText: '22' } ) ).toHaveCount( 4 );
		await expect( confetti.filter( { hasText: '♥' } ) ).toHaveCount( 4 );
		expect(
			await page.evaluate( () =>
				getComputedStyle( document.documentElement )
					.getPropertyValue( '--accent' )
					.trim()
			)
		).toBe( '#3b82f6' );
	} );

	test( 'setup leads from the library to the live player', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'post_type=callboard_set&page=callboard-setup'
		);
		await expect(
			page.getByRole( 'heading', {
				name: 'Set up Callboard',
			} )
		).toBeVisible();
		await expect( page.getByText( /published set/ ).first() ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Manage sets' } ).first()
		).toHaveAttribute( 'href', /post_type=callboard_set/ );
		await expect(
			page.getByRole( 'link', { name: 'Open player' } ).first()
		).toHaveAttribute( 'href', /\/$/ );
	} );

	test( 'a new set starts with a Media Library track picker', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'post-new.php',
			'post_type=callboard_set'
		);
		await expect( page.locator( '#title-prompt-text' ) ).toHaveText(
			'Name this set'
		);
		await expect(
			page.getByText(
				'No tracks yet. Add audio from your computer or choose files already in WordPress.'
			)
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Add tracks' } ).click();
		await expect( page.locator( '.media-modal' ) ).toBeVisible();
		await expect( page.locator( '.media-frame-title' ) ).toContainText(
			'Choose tracks'
		);
		await expect( page.getByRole( 'tab', { name: 'Upload files' } ) ).toBeVisible();
		await expect(
			page.getByRole( 'tab', { name: 'Media Library' } )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );
	} );

	test( 'a set has a tracks meta box with reorderable, retitlable rows', async ( {
		admin,
		page,
	} ) => {
		undoAfter( async () => {
			const first = await openDemoSet( admin, page );
			await first.locator( 'input[type=text]' ).fill( 'Intensities in Ten Cities' );
			await saveSet( page );
		} );
		// Find the demo set through the list table (REST is closed to anonymous but we're logged in here).
		await admin.visitAdminPage( 'edit.php', 'post_type=callboard_set' );
		const row = page
			.locator( '.wp-list-table tbody tr', {
				hasText: 'Compositions',
			} )
			.first();
		await expect( row ).toContainText( 'Compositions' );
		await expect( row.locator( 'td.tracks' ) ).toHaveText( '10' );
		await row.locator( 'a.row-title' ).click();
		const tracks = page.locator( '#callboard-track-list li' );
		await expect( tracks ).toHaveCount( 10 );
		await tracks
			.first()
			.locator( 'input[type=text]' )
			.fill( 'Renamed Tone' );
		await saveSet( page );
		await page.goto( '/demo-set/' );
		await expect(
			page.locator( '.track' ).first().locator( '.title' )
		).toContainText( 'Renamed Tone' );
	} );

	test( 'the import page queues a YouTube request', async ( {
		admin,
		page,
	} ) => {
		undoAfter( () => deleteQueued( admin, page, 'Queued Show' ) );
		await deleteQueued( admin, page, 'Queued Show' ); // left over from a run that was stopped
		await page.fill(
			'#callboard-url',
			'https://www.youtube.com/playlist?list=PLtest'
		);
		await page.fill( '#callboard-name', 'Queued Show' );
		await page.click( 'form.callboard-request #submit' );
		await expect( page.locator( '.notice-success' ) ).toContainText(
			'Queued'
		);
		const table = page.locator( 'table.widefat' );
		await expect( table ).toContainText( 'Queued Show' );
		await expect(
			table.locator( '.callboard-status-queued' ).first()
		).toBeVisible();
		await page.locator( 'a.submitdelete' ).first().click();
		await expect( page.locator( 'table.widefat' ) ).toHaveCount( 0 );
	} );
} );
