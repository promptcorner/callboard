/**
 * Admin: settings, set editing, import queue, notices.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Some tests change shared data (settings, the demo set's first track, the import queue, the board).
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
// The track's details panel starts open when the track already has a tempo or a note, so only click it
// when it is closed.
const openTrackDetails = async ( track ) => {
	if (
		! ( await track.locator( 'details' ).evaluate( ( el ) => el.open ) )
	) {
		await track.locator( 'summary' ).click();
	}
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
const deleteCalls = async ( admin, page, title ) => {
	await admin.visitAdminPage( 'edit.php', 'post_type=callboard_call' );
	await deleteRows( page.locator( '#the-list tr', { hasText: title } ) );
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

	test( 'a track keeps its tempo and dated director notes', async ( {
		admin,
		page,
	} ) => {
		undoAfter( async () => {
			const first = await openDemoSet( admin, page );
			await openTrackDetails( first );
			await first.locator( 'input[type=number]' ).fill( '' );
			await first.locator( 'textarea' ).fill( '' );
			await saveSet( page );
		} );
		const first = await openDemoSet( admin, page );
		await openTrackDetails( first );
		await first.locator( 'input[type=number]' ).fill( '100' );
		await first.locator( 'textarea' ).fill( '0:03 Softer here' );
		await saveSet( page );
		const again = page.locator( '#callboard-track-list li' ).first();
		await expect( again.locator( 'input[type=number]' ) ).toHaveValue(
			'100'
		);
		await expect( again.locator( 'textarea' ) ).toHaveValue(
			'0:03 Softer here'
		);
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expect( page.locator( '#seek-marks .pin' ) ).toHaveCount( 1 );
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

	test( 'the notices page shows subscriber count and a send form', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'post_type=callboard_set&page=callboard-notices'
		);
		const count = page.locator( '.wrap p' ).first();
		await expect( count ).toContainText( /subscribed/ );
		await expect( page.locator( '#callboard-nbody' ) ).toBeVisible();
		// Send is enabled exactly when someone is subscribed. A developer's own browser may well be, so
		// the test reads the count rather than assuming zero.
		const n = parseInt(
			( await count.textContent() ).match( /\d+/ )[ 0 ],
			10
		);
		if ( n ) {
			await expect( page.locator( '#submit' ) ).toBeEnabled();
		} else {
			await expect( page.locator( '#submit' ) ).toBeDisabled();
		}
	} );

	test( 'a posted call opens the board, and its number starts the track', async ( {
		admin,
		page,
	} ) => {
		// Also deletes a draft left behind if Publish did not go through.
		undoAfter( () => deleteCalls( admin, page, 'Act II sitzprobe' ) );
		await deleteCalls( admin, page, 'Act II sitzprobe' ); // left over from a run that was stopped
		await admin.visitAdminPage(
			'post-new.php',
			'post_type=callboard_call'
		);
		await page.fill( '#title', 'Act II sitzprobe' );
		await page.click( '#content-html' ); // the code tab: the visual editor hides the textarea
		await page.fill( '#content', 'Orchestra joins us. Be warmed up.' );
		const when = new Date( Date.now() + 3 * 86400 * 1000 );
		when.setHours( 19, 0, 0, 0 );
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		await page.fill(
			'#callboard-when',
			`${ when.getFullYear() }-${ pad( when.getMonth() + 1 ) }-${ pad(
				when.getDate()
			) }T19:00`
		);
		await page.fill( '#callboard-where', 'Pit' );
		const demo = page.locator( '.callboard-numbers details', {
			hasText: 'Compositions',
		} );
		await demo.locator( 'summary' ).click();
		await demo.locator( 'input[type=checkbox]' ).nth( 2 ).check(); // Trombone Detritus
		// When the title field of a new post loses focus, WordPress starts an autosave 200ms later and
		// ignores clicks on Publish until that save finishes. Wait for "Draft saved" before clicking;
		// checking that the button is enabled is not enough, because the save may not have started yet (#131).
		await expect( page.locator( '.autosave-message' ) ).toHaveText(
			/Draft saved/
		);
		await expect( page.locator( '#publish' ) ).not.toHaveClass(
			/disabled/
		);
		await page.click( '#publish' );
		// Wait for the edit screen's HTML, not its load event. The load event also waits for outside
		// requests such as the admin bar avatar, and a slow one made this test time out.
		await page.waitForURL( /post\.php\?post=\d+&action=edit&message=/, {
			waitUntil: 'domcontentloaded',
		} );
		await expect( page.locator( '#callboard-where' ) ).toHaveValue( 'Pit' );

		await page.goto( '/' );
		const call = page.locator( '.call', { hasText: 'Act II sitzprobe' } );
		await expect( call ).toBeVisible();
		await expect( call.locator( '.call-rel' ) ).toContainText( /in|days/ );
		await expect( call.locator( '.call-where' ) ).toHaveText( 'Pit' );
		// #51: the board is a list ruled like the others, not a stack of cards.
		await expect( call ).toHaveCSS(
			'background-color',
			'rgba(0, 0, 0, 0)'
		);
		await expect( call ).toHaveCSS( 'border-radius', '0px' );
		await expect( call.locator( '.call-numbers a' ) ).toHaveText(
			'Trombone Detritus'
		);
		await call.locator( '.call-numbers a' ).click();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Trombone Detritus'
		);

		await admin.visitAdminPage( 'edit.php', 'post_type=callboard_call' );
		await expect(
			page
				.locator( '#the-list tr', { hasText: 'Act II sitzprobe' } )
				.first()
				.locator( '.column-callboard_when' )
		).toContainText( /\(in / );
	} );
} );
