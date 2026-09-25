/**
 * Set files on a phone: a saved set written out as a .callboard file, and a .callboard file read back
 * into the offline copy, which is how a set gets from one phone to another on a USB stick.
 */
const fs = require( 'node:fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { readZip } = require( './utils/zip' );

const FIXTURE = 'tests/fixtures/callboard/demo-set';

// Save the whole demo set offline, the way a person would before writing it out.
const saveSet = async ( page ) => {
	await page.goto( '/demo-set/' );
	// The save controls bind a beat after the view does; data-some says they have.
	await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
		timeout: 15000,
	} );
	await page.locator( '#offline' ).click();
	await expect( page.locator( '#offline' ) ).toContainText( /Saved offline/, {
		timeout: 60000,
	} );
};

test.describe( 'Set files', () => {
	test( 'a saved set writes a set file that another phone opens with the network off', async ( {
		page,
		browser,
	}, testInfo ) => {
		const button = page.getByRole( 'button', { name: 'Save set file' } );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
			timeout: 15000,
		} );
		// Written from the offline copy, so only offered once the whole set is saved.
		await expect( button ).toBeHidden();
		await saveSet( page );
		await expect( button ).toBeVisible();

		// Headless Chromium has no share sheet, so the file downloads, as it does on Android.
		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			button.click(),
		] );
		expect( download.suggestedFilename() ).toBe( 'demo-set.callboard' );
		const path = await download.path();
		const zip = readZip( fs.readFileSync( path ) );

		// The format Exporter writes and wp-admin imports.
		const manifest = JSON.parse( zip.get( 'manifest.json' ) );
		expect( manifest ).toMatchObject( {
			version: 1,
			name: 'Compositions',
			slug: 'demo-set',
		} );
		expect( manifest.tracks ).toHaveLength( 10 );
		expect( manifest.tracks[ 0 ] ).toMatchObject( {
			index: 1,
			id: 'intensities',
			title: 'Intensities in Ten Cities',
			file: '01 - Intensities in Ten Cities [intensities].mp3',
		} );
		for ( const t of manifest.tracks ) {
			expect(
				zip
					.get( t.file )
					.equals( fs.readFileSync( `${ FIXTURE }/${ t.file }` ) ),
				`${ t.file } is the saved audio, byte for byte`
			).toBe( true );
		}
		expect(
			Object.keys( JSON.parse( zip.get( 'levels.json' ) ) )
		).toContain( 'intensities' );
		expect( zip.has( 'notes.json' ) ).toBe( false );

		// Another phone, with nothing saved and no signal.
		const other = await browser.newContext( {
			baseURL: testInfo.project.use.baseURL,
			...( testInfo.project.use.hasTouch
				? { hasTouch: true, isMobile: true }
				: {} ),
		} );
		try {
			const phone = await other.newPage();
			await phone.goto( '/' );
			await expect( phone.locator( '#open-set-label' ) ).toBeVisible();
			await expect(
				phone.locator( '.set-off[data-slug="demo-set"]' )
			).toHaveAttribute( 'data-state', '' );
			await other.setOffline( true );
			await phone.locator( '#open-set' ).setInputFiles( path );
			await expect( phone.locator( '#toast' ) ).toContainText(
				'Loaded 10 of 10 tracks into Compositions',
				{ timeout: 30000 }
			);
			await expect(
				phone.locator( '.set-off[data-slug="demo-set"]' )
			).toHaveAttribute( 'data-state', 'saved' );
		} finally {
			await other.close();
		}
	} );

	test( 'a set file exported from wp-admin opens on a phone', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', 'post_type=callboard_set' );
		const href = await page
			.locator( 'tr', { hasText: 'Compositions' } )
			.getByRole( 'link', { name: 'Export', exact: true } )
			.getAttribute( 'href' );
		const exported = await ( await page.request.get( href ) ).body();
		// ZipArchive deflates its entries, so this reads compressed audio, where the phone's own file is stored.
		expect( readZip( exported ).has( 'manifest.json' ) ).toBe( true );

		await page.goto( '/' );
		await expect( page.locator( '#open-set-label' ) ).toBeVisible();
		await page.locator( '#open-set' ).setInputFiles( {
			name: 'demo-set.callboard',
			mimeType: 'application/zip',
			buffer: exported,
		} );
		await expect( page.locator( '#toast' ) ).toContainText(
			'Loaded 10 of 10 tracks into Compositions',
			{ timeout: 30000 }
		);
		await expect(
			page.locator( '.set-off[data-slug="demo-set"]' )
		).toHaveAttribute( 'data-state', 'saved' );
	} );

	test( 'a file that is not a set says so', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		await expect( page.locator( '#open-set-label' ) ).toBeVisible();
		await page.locator( '#open-set' ).setInputFiles( {
			name: 'notes.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from( 'not a set' ),
		} );
		await expect( page.locator( '#toast' ) ).toContainText(
			'That file is not a Callboard set'
		);
	} );
} );
