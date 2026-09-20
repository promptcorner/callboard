/**
 * Front end: home, a set, the player, in-place navigation. Runs on desktop and an iPhone viewport.
 */
const fs = require( 'node:fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// The deck opens compact by default (title, artist, play/pause) and remembers the last view; a few tests
// here reach into the wave/times/chips row, which compact hides, so they ask for the expanded view first.
const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();

test.describe( 'Front end', () => {
	test( 'home lists sets with counts and the footer note', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		await expect( page ).toHaveTitle( /./ );
		// Not a total count: a machine can have its own local-only sets alongside the fixtures.
		await expect(
			page.locator( 'a.set', { hasText: 'Compositions' } )
		).toHaveCount( 1 );
		await expect(
			page.locator( 'a.set', { hasText: 'Empty Set' } )
		).toHaveCount( 1 );
		const card = page.locator( 'a.set', { hasText: 'Compositions' } );
		await expect( card ).toBeVisible();
		await expect( card.locator( '.set-name' ) ).toHaveText(
			'Compositions'
		);
		await expect( card.locator( '.set-meta' ) ).toContainText(
			'10 tracks'
		);
		await expect( page.locator( 'footer.colophon' ) ).toContainText(
			'For rehearsal use only.'
		);
		await expect( page.locator( '#deck' ) ).toBeHidden();
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'a set scrolls and keeps the deck pinned', async ( { page } ) => {
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await page.locator( '.track' ).nth( 9 ).click();
		await expect( page.locator( '#deck' ) ).toBeVisible();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Everyday Adventures'
		);
		await page.locator( '.track' ).nth( 9 ).scrollIntoViewIfNeeded();
		const deckBox = await page.locator( '#deck' ).boundingBox();
		const viewport = page.viewportSize();
		expect( deckBox.y + deckBox.height ).toBeGreaterThanOrEqual(
			viewport.height - 130
		); // pinned to the bottom edge
	} );

	test( 'a director note link opens its track at the note time', async ( {
		page,
	} ) => {
		// The demo set's third track has a note at 0:04, "Softer here".
		await page.goto( '/demo-set/?track=2&at=4' );
		await expect(
			page.locator( '.track[aria-current="true"]' )
		).toContainText( 'Trombone Detritus' );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Softer here'
		);
		await expect
			.poll( () =>
				page
					.locator( '#audio' )
					.evaluate( ( audio ) => audio.currentTime )
			)
			.toBeGreaterThanOrEqual( 4 );
		// The parameters are dropped, so reloading does not jump back to the note.
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
	} );

	test( 'a set page without a note link still opens where the listener left off', async ( {
		page,
	} ) => {
		await page.addInitScript( () =>
			localStorage.setItem(
				'callboard:demo-set',
				JSON.stringify( { i: 3, t: 20 } )
			)
		);
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track' ).nth( 3 ) ).toHaveAttribute(
			'aria-current',
			'true'
		);
		await expect
			.poll( () =>
				page
					.locator( '#audio' )
					.evaluate( ( audio ) => audio.currentTime )
			)
			.toBeGreaterThanOrEqual( 20 );
	} );

	test( 'loading a set from files fills the offline copies without the network', async ( {
		page,
	} ) => {
		const audio = fs.readFileSync(
			'tests/fixtures/callboard/demo-set/01 - Intensities in Ten Cities [intensities].mp3'
		);
		const file = ( name ) => ( {
			name,
			mimeType: 'audio/mpeg',
			buffer: audio,
		} );

		await page.goto( '/demo-set/' );
		await page.waitForTimeout( 900 );
		// The control appears only once the script knows there is a cache to fill.
		await expect( page.locator( '#load-label' ) ).toBeVisible();

		// One file per matching rule: the track's own name, a car export's leading number, the title.
		await page
			.locator( '#load-files' )
			.setInputFiles( [
				file( '01 - Intensities in Ten Cities [intensities].mp3' ),
				file( '02 Anything At All.mp3' ),
				file( 'Trombone Detritus.mp3' ),
				file( 'nothing-in-this-set.mp3' ),
			] );
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			3,
			{ timeout: 15000 }
		);
		await expect( page.locator( '#toast' ) ).toContainText(
			/Loaded 3 of 4/
		);

		// A copy off a stick is a different size from the server's and must not count as stale.
		await page.reload();
		await page.waitForTimeout( 1200 );
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			3
		);

		// A file matching nothing is said so, not guessed at.
		await page
			.locator( '#load-files' )
			.setInputFiles( [ file( 'still-not-in-this-set.mp3' ) ] );
		await expect( page.locator( '#toast' ) ).toContainText(
			/Nothing matched/
		);
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			3
		);
	} );

	test( 'saving a set offline marks every track, including slashed titles', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.waitForTimeout( 900 );
		await page.locator( '#offline' ).click();
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			10,
			{
				timeout: 30000,
			}
		);
		await expect( page.locator( '#offline' ) ).toContainText(
			/Saved offline/
		);
		await page.reload();
		await page.waitForTimeout( 1200 );
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			10
		);
		await page.locator( '.back' ).click(); // home shows the same mark on the set
		await expect(
			page.locator( '.set-off[data-slug="demo-set"]' )
		).toHaveAttribute( 'data-state', 'saved' );
		await page.goBack();
		// The set came back as a swapped-in view, so wait for its button to be bound before tapping it.
		await expect( page.locator( '#offline' ) ).toHaveAttribute(
			'data-some',
			'1'
		);
		await page.locator( '#offline' ).click(); // a tap on a saved set asks first
		await expect( page.locator( '#offline' ) ).toContainText( /Tap again/ );
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			10
		);
		await expect( page.locator( '#offline' ) ).toHaveAttribute(
			'data-confirm',
			'1'
		); // ready for the answer
		await page.locator( '#offline' ).click();
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			0
		);
	} );

	test( 'saving stops with a message when the browser has no room', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			navigator.storage.estimate = () =>
				Promise.resolve( { quota: 1024 * 1024, usage: 1024 * 1000 } ); // 24 KB free
		} );
		await page.goto( '/demo-set/' );
		await page.waitForTimeout( 900 );
		await page.locator( '#offline' ).click();
		await expect( page.locator( '#offline' ) ).toHaveText(
			/Not enough space, 24 KB free/
		);
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			0
		);
		await expect( page.locator( '#offline' ) ).toContainText(
			/Save offline/,
			{ timeout: 5000 }
		); // the button comes back
	} );

	test( 'saving asks the browser for persistent storage', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.__persistCalls = { persisted: 0, persist: 0 };
			const storage = navigator.storage || {};
			storage.persisted = async () => {
				window.__persistCalls.persisted++;
				return false;
			};
			storage.persist = async () => {
				window.__persistCalls.persist++;
				return true;
			};
			if ( ! navigator.storage ) {
				Object.defineProperty( navigator, 'storage', {
					configurable: true,
					value: storage,
				} );
			}
		} );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '#offline[data-some]' ) ).toBeAttached( {
			timeout: 15000,
		} );
		const before = await page.evaluate( () => window.__persistCalls );
		expect( before.persist ).toBe( 0 );
		expect( before.persisted ).toBeGreaterThan( 0 );
		await page.locator( '#offline' ).click();
		await page.waitForFunction( () => window.__persistCalls.persist > 0 );
		expect(
			await page.evaluate( () => window.__persistCalls.persist )
		).toBeGreaterThan( 0 );
	} );

	test( 'a saved copy whose size no longer matches is not counted as saved', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.waitForTimeout( 900 );
		await page.locator( '#offline' ).click(); // per-track controls are hidden until hover on touch, so save the set
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			10,
			{ timeout: 30000 }
		);
		await page.evaluate( async () => {
			// stand in for a track replaced on the server: same URL, a different file
			const url = document.getElementById( 'audio' ).src;
			const c = await caches.open( 'callboard-audio-v1' );
			const keys = await c.keys();
			await c.put(
				keys[ 0 ],
				new Response( 'x', {
					headers: {
						'Content-Type': 'audio/mpeg',
						'Content-Length': '1',
					},
				} )
			);
			return url;
		} );
		await page.reload();
		await page.waitForTimeout( 1200 );
		await expect( page.locator( '.dl[data-state="saved"]' ) ).toHaveCount(
			9
		); // the replaced one is offered again
		await expect( page.locator( '#offline' ) ).toContainText( /9 of 10/ );
		await page.evaluate( async () => {
			for ( const k of await (
				await caches.open( 'callboard-audio-v1' )
			).keys() ) {
				await ( await caches.open( 'callboard-audio-v1' ) ).delete( k );
			}
		} );
	} );

	test( 'cached audio answers byte-range requests', async ( { page } ) => {
		await page.goto( '/demo-set/' );
		await page
			.waitForFunction( () => navigator.serviceWorker?.controller, null, {
				timeout: 15000,
			} )
			.catch( async () => {
				await page.reload();
				await page.waitForFunction(
					() => navigator.serviceWorker?.controller,
					null,
					{ timeout: 15000 }
				);
			} );
		await page.locator( '.track' ).first().click();
		const partial = await page.evaluate( async () => {
			const url = document.getElementById( 'audio' ).src;
			const c = await caches.open( 'callboard-audio-v1' );
			const full = await fetch( url, { cache: 'no-store' } );
			await c.put( url, full.clone() );
			const res = await fetch( url, {
				headers: { Range: 'bytes=10-29' },
			} );
			return {
				status: res.status,
				range: res.headers.get( 'Content-Range' ),
				length: Number( res.headers.get( 'Content-Length' ) ) || 0,
				acceptRanges: res.headers.get( 'Accept-Ranges' ),
				bytes: ( await res.arrayBuffer() ).byteLength,
			};
		} );
		expect( partial.status ).toBe( 206 );
		expect( partial.range ).toMatch( /^bytes 10-29\/\d+$/ );
		expect( partial.length ).toBe( 20 );
		expect( partial.acceptRanges ).toBe( 'bytes' );
		expect( partial.bytes ).toBe( 20 );
	} );

	test( 'a set shows its tracks, credits and only WordPress admin chrome', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await expect( page.locator( 'h1' ) ).toHaveText(
			'Compositions'
		);
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await expect(
			page.locator( '.track' ).first().locator( '.title' )
		).toHaveText( 'Intensities in Ten Cities' );
		await expect( page.locator( 'footer.colophon' ) ).toContainText(
			'Audio by Airmen of Note, United States Air Force Band'
		);
		await expect(
			page.locator( 'footer.colophon a' ).first()
		).toHaveAttribute( 'rel', /noreferrer/ );
		await expect( page.locator( 'link[rel=stylesheet]' ) ).toHaveCount( 2 );
		await expect( page.locator( '#dashicons-css' ) ).toHaveCount( 1 );
		await expect( page.locator( '#admin-bar-css' ) ).toHaveCount( 1 );
	} );

	// #106: the save mark sat in the gutter outside the row, and a long title pushed the rest of the row
	// off the edge of a phone.
	test( 'a long title stays inside its row, and so do the duration and the save mark', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		const row = page.locator( '.tracks li' ).first();
		await row
			.locator( '.title' )
			.evaluate(
				( el ) =>
					( el.textContent =
						'A number whose title runs on well past the width of any phone held upright' )
			);
		const inside = async ( part ) => {
			const r = await row.boundingBox();
			const b = await row.locator( part ).boundingBox();
			return b.x >= r.x - 0.5 && b.x + b.width <= r.x + r.width + 0.5;
		};
		await expect.poll( () => inside( '.len' ) ).toBe( true );
		await expect.poll( () => inside( '.dl' ) ).toBe( true );
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= window.innerWidth
			)
		).toBe( true ); // nothing pushes the page sideways
	} );

	// #50: on a 375px phone the header wrapped Share onto a line of its own. The size is what gives way.
	test( 'on a small phone the set header keeps its actions on one line', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 375, height: 667 } );
		await page.goto( '/demo-set/' );
		await expect( page.locator( '#share' ) ).toBeVisible();
		await expect
			.poll( () =>
				page
					.locator( '#play-all, #offline, #share' )
					.evaluateAll(
						( els ) =>
							new Set(
								els.map( ( e ) =>
									Math.round( e.getBoundingClientRect().top )
								)
							).size
					)
			)
			.toBe( 1 );
		await expect( page.locator( '#offline' ) ).toContainText(
			'Save offline'
		);
	} );

	test( 'tapping a track loads it into the persistent player', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 1 ).click();
		const audio = page.locator( '#audio' );
		await expect( audio ).toHaveAttribute( 'src', /underground/ );
		await expect( page.locator( '.track' ).nth( 1 ) ).toHaveClass(
			/active/
		);
		await expect( page.locator( '.track' ).nth( 1 ) ).toHaveAttribute(
			'aria-current',
			'true'
		);
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Underground'
		);
		await expect( page.locator( '#deck' ) ).toBeVisible();
	} );

	// 63ea838: the title's width was read from an inline span's scrollWidth, which is always 0, so no title
	// ever scrolled. Its test went with the long fixture set in #94; this lengthens a real title instead.
	test( 'a long title scrolls in the deck instead of being cut off', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			let data;
			Object.defineProperty( window, 'CALLBOARD', {
				configurable: true,
				get: () => data,
				set: ( value ) => {
					const first = value?.sets?.find(
						( s ) => s.slug === 'demo-set'
					)?.tracks?.[ 0 ];
					if ( first ) {
						first.title =
							'Intensities in Ten Cities, read straight through with every line of every one of them and nothing left out';
					}
					data = value;
				},
			} );
		} );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expect( page.locator( '#now-title' ) ).toHaveClass( /marquee/ );
		await expect( page.locator( '#now-title .mq > span' ) ).toHaveCount(
			2
		); // the second copy makes the loop seamless
	} );

	// #15: play() rejects whenever the browser refuses — a file it cannot decode, a source that is
	// gone, a gesture it does not count — and every tap left that rejection unhandled on the page.
	test( 'play controls raise no page errors when the browser refuses to play', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.__unhandled = [];
			window.addEventListener( 'unhandledrejection', ( e ) =>
				window.__unhandled.push( String( e.reason ) )
			);
			window.__refusals = 0;
			HTMLMediaElement.prototype.play = function refuse() {
				window.__refusals++;
				return Promise.reject(
					new DOMException(
						'no supported source',
						'NotSupportedError'
					)
				);
			};
		} );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expect( page.locator( '.track' ).first() ).toHaveClass(
			/active/
		);
		await page.locator( '#toggle' ).click();
		await page.locator( '.track' ).first().click(); // the current row plays and pauses it
		await page.locator( '#play-all' ).click(); // Play all is the transport by now
		await expect
			.poll( () => page.evaluate( () => window.__refusals ) )
			.toBeGreaterThanOrEqual( 4 ); // the load, the play key, the row, and Play all each asked
		await page.evaluate( () => new Promise( ( r ) => setTimeout( r ) ) ); // rejections report at the end of a task
		expect( await page.evaluate( () => window.__unhandled ) ).toEqual( [] );
	} );

	test( "Play all becomes the set's transport once it is playing", async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		const btn = page.locator( '#play-all' );
		await expect( btn ).toHaveText( 'Play all' );
		await btn.click();
		await expect( btn ).not.toHaveText( 'Play all' );
		await page.evaluate( () => document.getElementById( 'audio' ).pause() );
		await expect( btn ).toHaveText( 'Play' );
		await expect( page.locator( '.track' ).first() ).toHaveClass(
			/active/
		);
	} );

	test( 'ticks and note pins mark the seek line; a pin jumps there', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click(); // the annotated track
		await expandDeck( page ); // the seek line and its marks are expanded-only now
		await expect( page.locator( '#seek-marks .pin' ) ).toHaveCount( 2 ); // one per director's note
		await page.locator( '#seek-marks .pin' ).first().click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Softer here'
		);
		await expect( page.locator( '#now-title' ) ).toContainText( 'Sep 1' );
	} );

	test( 'an A-B loop from the keyboard shows the band and clears', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await page.evaluate( () => {
			document.getElementById( 'audio' ).currentTime = 2;
		} );
		await page.keyboard.press( '[' );
		await page.evaluate( () => {
			document.getElementById( 'audio' ).currentTime = 6;
		} );
		await page.keyboard.press( ']' );
		await expect( page.locator( '#loop-band' ) ).toHaveClass( /on/ );
		await page.keyboard.press( '\\' );
		await expect( page.locator( '#loop-band' ) ).not.toHaveClass( /on/ );
	} );

	test( 'the deck draws the waveform and keeps one height with or without lyrics', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click(); // levels and a note
		await expandDeck( page ); // the wave only draws in the expanded view
		await expect( page.locator( '#deck' ) ).toHaveClass( /has-wave/ );
		expect(
			await page.locator( '#wave-base' ).evaluate( ( c ) => c.width )
		).toBeGreaterThan( 0 );
		const withLyrics = ( await page.locator( '#deck' ).boundingBox() )
			.height;
		await page.locator( '#seek' ).evaluate( ( el ) => {
			// drag to the middle: headless Chromium cannot decode the mp3, so drive the control, not the media
			el.value = 500;
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await expect( page.locator( '#wave-reveal' ) ).toHaveAttribute(
			'style',
			/translateX\(-50(\.0+)?%\)/
		); // the reveal window slides to the middle; the canvas inside slides back the same amount
		await expect( page.locator( '#wave-played' ) ).toHaveAttribute(
			'style',
			/translateX\(50(\.0+)?%\)/
		);
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click(); // levels, no lyrics or notes
		await expandDeck( page ); // compare like with like: Now Playing in both cases
		const without = ( await page.locator( '#deck' ).boundingBox() ).height;
		expect( Math.abs( withLyrics - without ) ).toBeLessThan( 1 );
	} );

	// #66: each bar used to stand on a line two-thirds down with a faint reflection hanging below it.
	test( 'the waveform is bars alone, centred on the band', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect( page.locator( '#deck' ) ).toHaveClass( /has-wave/ );
		// Reads the pixels: for every column with paint in it, the gap above the bar and the gap below it
		// match, to within a device pixel of rounding.
		const shape = () =>
			page.locator( '#wave-base' ).evaluate( ( c ) => {
				const { width: w, height: h } = c;
				const px = c.getContext( '2d' ).getImageData( 0, 0, w, h ).data;
				let bars = 0,
					skew = 0;
				for ( let x = 0; x < w; x++ ) {
					let top = -1,
						bottom = -1;
					for ( let y = 0; y < h; y++ ) {
						if ( px[ ( y * w + x ) * 4 + 3 ] ) {
							top = top < 0 ? y : top;
							bottom = y;
						}
					}
					if ( top >= 0 ) {
						bars++;
						skew = Math.max(
							skew,
							Math.abs( top - ( h - 1 - bottom ) )
						);
					}
				}
				return { bars, skew, dpr: Math.ceil( w / c.clientWidth ) };
			} );
		await expect
			.poll( async () => ( await shape() ).bars )
			.toBeGreaterThan( 0 );
		const { skew, dpr } = await shape();
		expect( skew ).toBeLessThanOrEqual( dpr );
	} );

	// #112: the wave ran twenty pixels past the times and the transport on both sides, and "kHz" and
	// "kbps" were shouted in capitals.
	test( 'Now Playing keeps one column and spells its units as written', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect( page.locator( '#deck' ) ).toHaveClass( /has-wave/ );
		const edges = async ( sel ) => {
			const b = await page.locator( sel ).boundingBox();
			return [ b.x, b.x + b.width ];
		};
		await expect
			.poll( async () => {
				const [ waveLeft, waveRight ] = await edges( '#wave-base' );
				const [ curLeft ] = await edges( '#cur' );
				const [ , durRight ] = await edges( '#dur' );
				const [ ctlLeft, ctlRight ] = await edges( '.deck-controls' );
				return Math.max(
					Math.abs( waveLeft - curLeft ),
					Math.abs( waveRight - durRight ),
					Math.abs( waveLeft - ctlLeft ),
					Math.abs( waveRight - ctlRight )
				);
			} )
			.toBeLessThanOrEqual( 1 );
		// innerText, not textContent: a text-transform shows up in what is drawn, not in the DOM.
		const quality = page.locator( '.quality-pill' );
		await expect( quality ).toContainText( /\bkbps\b/, {
			useInnerText: true,
		} );
		await expect( quality ).toContainText( /kHz/, { useInnerText: true } );
	} );

	test( 'a track with a tempo counts in before it plays, once the setting is on', async ( {
		page,
		admin,
	} ) => {
		const settings = async ( on ) => {
			await admin.visitAdminPage(
				'edit.php',
				'post_type=callboard_set&page=callboard-settings'
			);
			if ( on ) {
				await page.check( '#callboard-count_in' );
			} else {
				await page.uncheck( '#callboard-count_in' );
			}
			await page.click( '#submit' );
			await expect(
				page
					.locator(
						'#setting-error-settings_updated, .notice-success'
					)
					.first()
			).toBeVisible();
		};
		await page.goto( '/demo-set/' );
		await expect( page.locator( '.track .bpm' ) ).toHaveText( '♩ 96' );
		await page.locator( '.track' ).nth( 2 ).click(); // 96 BPM: off by default, it just plays
		// Read once, straight after the tap (#29). A count starts inside the click and runs for four beats,
		// so a retrying not.toHaveClass simply waited it out and passed.
		expect(
			await page
				.locator( '#deck' )
				.evaluate( ( d ) => d.classList.contains( 'counting' ) )
		).toBe( false );
		try {
			await settings( true );
			await page.goto( '/demo-set/' );
			await page.evaluate( () => localStorage.clear() ); // forget the position, or the same row just toggles play
			await page.reload();
			await page.locator( '.track' ).nth( 2 ).click();
			await expect( page.locator( '#deck' ) ).toHaveClass( /counting/ );
			await expect( page.locator( '#now-title' ) ).toHaveText(
				/^1(\s+[2-4])*$/
			);
			await expect( page.locator( '#deck' ) ).not.toHaveClass(
				/counting/,
				{
					timeout: 4000,
				}
			);
			await expect( page.locator( '#now-title' ) ).toContainText(
				'Trombone Detritus'
			);
		} finally {
			await settings( false ); // back off for the other tests, whether this one passed or not
		}
	} );

	test( 'the deck offers AirPlay or Cast only while a device is in reach', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			// stand in for a speaker on the network: Chromium's own remote never finds one in CI
			if ( ! ( 'remote' in HTMLMediaElement.prototype ) ) {
				return;
			}
			window.__remote = { prompted: 0 };
			Object.defineProperty( HTMLMediaElement.prototype, 'remote', {
				get() {
					return {
						state: 'disconnected',
						watchAvailability: ( cb ) => {
							cb( true );
							return Promise.resolve( 1 );
						},
						cancelWatchAvailability: () => Promise.resolve(),
						prompt: () => {
							window.__remote.prompted++;
							return Promise.resolve();
						},
						addEventListener: () => {},
					};
				},
			} );
		} );
		await page.goto( '/demo-set/' );
		test.skip(
			! ( await page.evaluate(
				() => 'remote' in HTMLMediaElement.prototype
			) ),
			'no Remote Playback API in this browser'
		);
		await page.locator( '.track' ).first().click();
		await expandDeck( page ); // the cast chip lives on the (now expanded-only) time row
		const chip = page.locator( '#remote' );
		await expect( chip ).toBeVisible();
		await expect( chip ).toHaveAttribute(
			'aria-label',
			'Play on another device'
		);
		await chip.click();
		expect( await page.evaluate( () => window.__remote.prompted ) ).toBe(
			1
		);
	} );

	test( 'Share hands the set link to the system sheet', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.__shared = [];
			navigator.share = ( d ) => {
				window.__shared.push( d );
				return Promise.resolve();
			};
		} );
		await page.goto( '/demo-set/' );
		const btn = page.locator( '#share' );
		await expect( btn ).toBeVisible();
		await btn.click();
		const shared = await page.evaluate( () => window.__shared );
		expect( shared ).toHaveLength( 1 );
		expect( shared[ 0 ].url ).toMatch( /\/demo-set\/$/ );
		expect( shared[ 0 ].title ).toContain( 'Compositions' );
		expect( shared[ 0 ].text ).toContain( '10 tracks' );
	} );

	test( 'without a share sheet, Share copies the link', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			Object.defineProperty( navigator, 'share', {
				value: undefined,
				configurable: true,
			} );
			window.__copied = '';
			navigator.clipboard.writeText = ( t ) => {
				window.__copied = t;
				return Promise.resolve();
			};
		} );
		await page.goto( '/demo-set/' );
		const btn = page.locator( '#share' );
		await expect( btn ).toHaveAttribute( 'aria-label', /Share a link/ );
		await btn.click();
		await expect( btn ).toHaveText( 'Link copied' );
		expect( await page.evaluate( () => window.__copied ) ).toMatch(
			/\/demo-set\/$/
		);
		await expect( btn.locator( 'svg' ) ).toHaveCount( 1, {
			timeout: 3000,
		} ); // the icon is back
	} );

	test( 'an empty set says so on home and on its page', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		await expect(
			page
				.locator( 'a.set', { hasText: 'Empty Set' } )
				.locator( '.set-meta' )
		).toHaveText( 'No audio yet' );
		await page.goto( '/empty-set/' );
		await expect( page.locator( '.note' ) ).toContainText(
			'No audio in this set yet'
		);
		await expect( page.locator( '#play-all' ) ).toHaveCount( 0 );
	} );

	test( 'navigation swaps in the fragment the server renders', async ( {
		page,
		request,
	} ) => {
		const frag = await request.get( '/demo-set/?fragment=1' );
		expect( frag.ok() ).toBeTruthy();
		const body = await frag.text();
		expect( body.trim().startsWith( '<main' ) ).toBeTruthy();
		expect( body ).not.toContain( '<html' );
		expect( body ).toContain( 'class="tracks"' );
		expect(
			await ( await request.get( '/?fragment=1' ) ).text()
		).toContain( 'class="sets"' );
		expect( ( await request.get( '/nope/?fragment=1' ) ).status() ).toBe(
			404
		);
		await page.goto( '/' );
		const [ res ] = await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'fragment=1' ) ),
			page.locator( 'a.set', { hasText: 'Compositions' } ).click(),
		] );
		expect( res.ok() ).toBeTruthy();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( 'h1' ) ).toHaveText(
			'Compositions'
		);
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await expect( page.locator( '.colophon' ) ).toContainText( 'Audio by' ); // the footer came with it
	} );

	test( 'All sets swaps views in place and keeps the player', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await page.locator( 'a.back' ).click();
		await expect( page ).toHaveURL( /\/$/ );
		await expect( page.locator( 'a.set' ).first() ).toBeVisible();
		await expect( page.locator( '#deck' ) ).toBeVisible();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Intensities in Ten Cities'
		);
		await page.goBack();
		await expect( page.locator( 'h1' ) ).toHaveText(
			'Compositions'
		);
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Intensities in Ten Cities'
		); // no reload
	} );

	test( 'unknown paths fall back to home with a note and a 404 status', async ( {
		page,
	} ) => {
		const response = await page.goto( '/nope/' );
		expect( response.status() ).toBe( 404 );
		await expect( page.locator( '.note-404' ) ).toContainText(
			"isn't here"
		);
	} );
} );

// The filament is the deck's top edge, lit by the audio. It kept going dark whenever the page was anywhere
// but the playing set, and nothing here looked at it, so it came back more than once. These tests watch it
// the way a person does: is it there, is it lit, does it move, and does it stay that way through every way
// of getting around.
//
// A playing element is faked rather than decoded. The rest of the suite already assumes headless Chromium
// cannot decode the audio, and a meter test that depends on whether this machine happens to have the codec
// would pass here and fail in Actions, or the other way round. The fake keeps a clock, so currentTime moves
// and the track's measured levels drive the wire. Removing AudioContext sends every browser down that same
// path, which is the one an iPhone takes anyway.
const fakePlayback = ( page ) =>
	page.addInitScript( () => {
		window.AudioContext = undefined;
		window.webkitAudioContext = undefined;
		const proto = HTMLMediaElement.prototype;
		const clocks = new WeakMap();
		const now = () => performance.now() / 1000;
		const clock = ( el ) => {
			if ( ! clocks.has( el ) ) {
				clocks.set( el, { playing: false, base: 0, at: now() } );
			}
			return clocks.get( el );
		};
		const read = ( el ) => {
			const c = clock( el );
			return c.playing ? c.base + now() - c.at : c.base;
		};
		Object.defineProperty( proto, 'paused', {
			configurable: true,
			get() {
				return ! clock( this ).playing;
			},
		} );
		Object.defineProperty( proto, 'currentTime', {
			configurable: true,
			get() {
				return read( this );
			},
			set( v ) {
				const c = clock( this );
				c.base = +v || 0;
				c.at = now();
			},
		} );
		// The flag flips at once and the event follows as a task, the order the browser uses.
		proto.play = function play() {
			const c = clock( this );
			if ( ! c.playing ) {
				c.base = read( this );
				c.at = now();
				c.playing = true;
				setTimeout( () => this.dispatchEvent( new Event( 'play' ) ) );
			}
			return Promise.resolve();
		};
		proto.pause = function pause() {
			const c = clock( this );
			if ( c.playing ) {
				c.base = read( this );
				c.playing = false;
				setTimeout( () => this.dispatchEvent( new Event( 'pause' ) ) );
			}
		};
	} );

const wire = ( page ) => page.locator( '#deck-glow' );
const glowOpacity = ( page ) =>
	wire( page ).evaluate( ( g ) => +getComputedStyle( g ).opacity );
// Half a second of frames: how bright it got, and how much it moved.
const glowSpread = ( page ) =>
	wire( page ).evaluate(
		( g ) =>
			new Promise( ( resolve ) => {
				const seen = [];
				const t0 = performance.now();
				const step = () => {
					seen.push( +getComputedStyle( g ).opacity );
					if ( performance.now() - t0 < 500 ) {
						requestAnimationFrame( step );
					} else {
						resolve( Math.max( ...seen ) - Math.min( ...seen ) );
					}
				};
				requestAnimationFrame( step );
			} )
	);
// There, a full-width line inside the window, and brighter than a wire with no current in it.
const expectLit = async ( page ) => {
	await expect( wire( page ) ).toBeVisible();
	const box = await wire( page ).boundingBox();
	const viewport = page.viewportSize();
	expect( box.y ).toBeGreaterThanOrEqual( 0 );
	expect( box.y ).toBeLessThan( viewport.height );
	expect( box.width ).toBeGreaterThan( viewport.width * 0.9 );
	await expect.poll( () => glowOpacity( page ) ).toBeGreaterThan( 0.4 );
};
const expectBurning = async ( page ) => {
	await expectLit( page );
	await expect.poll( () => glowSpread( page ) ).toBeGreaterThan( 0.02 );
};

test.describe( 'The filament', () => {
	test.beforeEach( async ( { page } ) => {
		await fakePlayback( page );
	} );

	// The suite runs with reduced motion, where the wire does not move but should still be lit while the
	// audio plays. That is the setting a lot of phones have on, so it gets the navigation check too.
	test( 'with reduced motion it stays lit on every view while a track plays', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expectLit( page );
		await page.locator( 'a.back' ).click();
		await expect( page ).toHaveURL( /\/$/ );
		await expect( page.locator( 'a.set' ).first() ).toBeVisible();
		await expectLit( page );
		await page.goBack();
		await expect( page.locator( 'h1' ) ).toHaveText(
			'Compositions'
		);
		await expectLit( page );
	} );

	test.describe( 'with motion', () => {
		test.use( {
			contextOptions: {
				reducedMotion: 'no-preference',
				strictSelectors: true,
			},
		} );

		test( 'it burns with the audio on home, another set, and back again', async ( {
			page,
		} ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expectBurning( page );

			// All sets: the fragment swap, inside a view transition.
			await page.locator( 'a.back' ).click();
			await expect( page ).toHaveURL( /\/$/ );
			await expect( page.locator( 'a.set' ).first() ).toBeVisible();
			await expectBurning( page );

			// The back gesture, then forward again: history, not a tap.
			await page.goBack();
			await expect( page.locator( 'h1' ) ).toHaveText(
				'Compositions'
			);
			await expectBurning( page );
			await page.goForward();
			await expect( page.locator( 'a.set' ).first() ).toBeVisible();
			await expectBurning( page );

			// A set that is not the one playing.
			await page.locator( 'a.set', { hasText: 'Empty Set' } ).click();
			await expect( page.locator( '.note' ) ).toContainText(
				'No audio in this set yet'
			);
			await expectBurning( page );

			// And into the playing set from its card.
			await page.goBack();
			await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
			await expect( page.locator( '.track' ) ).toHaveCount( 10 );
			await expectBurning( page );
		} );

		test( 'Now Playing opened and closed over home keeps it burning', async ( {
			page,
		} ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await page.locator( 'a.back' ).click();
			await expect( page.locator( 'a.set' ).first() ).toBeVisible();
			await page.locator( '#open-lyrics' ).click();
			await expect( page.locator( '#deck' ) ).toHaveClass(
				/is-expanded/
			);
			await expectBurning( page );
			await page.locator( '#deck-down' ).click();
			await expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );
			await expectBurning( page );
		} );

		test( 'pause cools the wire instead of switching it off', async ( {
			page,
		} ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await page.locator( 'a.back' ).click();
			await expect( page.locator( 'a.set' ).first() ).toBeVisible();
			await expectBurning( page );
			// Pause and watch the next few frames in the page itself, so no round trip sits between the
			// press and the first reading.
			const { before, after } = await wire( page ).evaluate(
				( g ) =>
					new Promise( ( resolve ) => {
						const read = () => +getComputedStyle( g ).opacity;
						const was = read();
						document.getElementById( 'toggle' ).click();
						const seen = [];
						const t0 = performance.now();
						const step = () => {
							seen.push( read() );
							if ( performance.now() - t0 < 400 ) {
								requestAnimationFrame( step );
							} else {
								resolve( { before: was, after: seen } );
							}
						};
						requestAnimationFrame( step );
					} )
			);
			expect( before ).toBeGreaterThan( 0.4 );
			expect( after[ 0 ] ).toBeGreaterThan( 0.3 ); // still warm the moment the current is cut
			expect( after.at( -1 ) ).toBeLessThan( after[ 0 ] ); // and on its way down
			// The tail is long by design, so wait for it to finish rather than guessing when it has.
			await expect.poll( () => glowSpread( page ) ).toBeLessThan( 0.005 );
			expect( await glowOpacity( page ) ).toBeLessThan( 0.3 );
			await expect( wire( page ) ).toBeVisible(); // cooled, not gone
		} );

		// Every view swap restarts the meter. It used to restart cold, which dipped the wire on each
		// navigation and held a quiet recording dim for seconds while it relearned what full meant. A
		// steady level makes the dip measurable: warm, the wire should not notice the swap at all.
		test( 'a view swap does not dip the wire', async ( { page } ) => {
			await page.addInitScript( () => {
				let data;
				Object.defineProperty( window, 'CALLBOARD', {
					configurable: true,
					get: () => data,
					set( v ) {
						for ( const s of v?.sets || [] ) {
							for ( const t of s.tracks || [] ) {
								if ( t.levels ) {
									t.levels = '6'.repeat( t.levels.length );
								}
							}
						}
						data = v;
					},
				} );
			} );
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expect
				.poll( () => glowOpacity( page ) )
				.toBeGreaterThan( 0.95 );
			// Every frame from the tap until half a second after home has landed.
			const lowest = await wire( page ).evaluate(
				( g ) =>
					new Promise( ( resolve ) => {
						let low = 1,
							landed = 0;
						const t0 = performance.now();
						const step = ( now ) => {
							low = Math.min(
								low,
								+getComputedStyle( g ).opacity
							);
							if ( ! landed && ! document.body.dataset.slug ) {
								landed = now;
							}
							if (
								( landed && now - landed > 500 ) ||
								now - t0 > 8000
							) {
								resolve( landed ? low : -1 );
							} else {
								requestAnimationFrame( step );
							}
						};
						requestAnimationFrame( step );
						document.querySelector( 'a.back' ).click();
					} )
			);
			await expect( page ).toHaveURL( /\/$/ );
			expect( lowest ).toBeGreaterThan( 0.9 );
		} );
	} );
} );

// Touch controls and closing Now Playing (#125). In this file so they also run on the iPhone project.
const isExpanded = ( page ) =>
	expect( page.locator( '#deck' ) ).toHaveClass( /is-expanded/ );
const isCompact = ( page ) =>
	expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );

// Returns the controls whose touch area is smaller than 44px. Probes 21px out from each control's centre
// with elementFromPoint, so an invisible ::after touch area counts even when the visible box is smaller.
// A probe that lands on a neighbouring control whose centre is just as close (two note pins 44px apart)
// is not a miss.
const touchAreaUnder44 = ( page, selector ) =>
	page.evaluate( ( sel ) => {
		const short = [],
			all = [ ...document.querySelectorAll( sel ) ];
		const centre = ( n ) => {
			const b = n.getBoundingClientRect();
			return [ b.left + b.width / 2, b.top + b.height / 2 ];
		};
		for ( const el of all ) {
			if (
				! el.checkVisibility( { visibilityProperty: true } ) ||
				! el.getClientRects().length
			) {
				continue;
			}
			el.scrollIntoView( { block: 'center', inline: 'center' } );
			const r = el.getBoundingClientRect(),
				x = r.left + r.width / 2,
				y = r.top + r.height / 2;
			const misses = [
				[ -21, 0 ],
				[ 21, 0 ],
				[ 0, -21 ],
				[ 0, 21 ],
			].filter( ( [ dx, dy ] ) => {
				const px = x + dx,
					py = y + dy,
					hit = document.elementFromPoint( px, py );
				if ( hit && el.contains( hit ) ) {
					return false;
				}
				const other = hit && all.find( ( o ) => o.contains( hit ) );
				if ( ! other ) {
					return true;
				}
				// Allow 3px for the later element winning at the midpoint.
				const [ ox, oy ] = centre( other );
				return Math.hypot( ox - px, oy - py ) > 24;
			} );
			if ( misses.length ) {
				short.push(
					`${ el.id || el.className } (${ Math.round(
						r.width
					) }x${ Math.round( r.height ) })`
				);
			}
		}
		return short;
	}, selector );

// Vertical offset of Now Playing from its transform, in px.
const deckOffset = ( page ) =>
	page
		.locator( '#deck' )
		.evaluate( ( deck ) =>
			Math.round(
				new DOMMatrix( getComputedStyle( deck ).transform ).m42
			)
		);

const grabberCentre = async ( page ) => {
	const box = await page.locator( '#deck-down' ).boundingBox();
	return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
};

// Drags straight down with real input through the DevTools protocol: touch on the iPhone project, the mouse
// on desktop. Each move is stamped `ms` after the last (8ms when `ms` is 0, a flick), so the speed the page
// measures does not depend on how fast this machine sends events. `during` runs before the finger lifts,
// and the lift is stamped with the real time, so a pause there reads as holding still.
const drag = async (
	page,
	testInfo,
	{ x, y },
	dy,
	{ steps = 10, ms = 0, during } = {}
) => {
	const touch = testInfo.project.use.hasTouch;
	const cdp = await page.context().newCDPSession( page );
	const start = Date.now() / 1000,
		gap = ( ms || 8 ) / 1000;
	const send = ( phase, py, timestamp ) =>
		touch
			? cdp.send( 'Input.dispatchTouchEvent', {
					type: [ 'touchStart', 'touchMove', 'touchEnd' ][ phase ],
					touchPoints: phase === 2 ? [] : [ { x, y: py } ],
					timestamp,
			  } )
			: cdp.send( 'Input.dispatchMouseEvent', {
					type: [ 'mousePressed', 'mouseMoved', 'mouseReleased' ][
						phase
					],
					x,
					y: py,
					button: 'left',
					buttons: phase === 2 ? 0 : 1,
					clickCount: 1,
					timestamp,
			  } );
	if ( ! touch ) {
		await page.mouse.move( x, y );
	}
	await send( 0, y, start );
	for ( let step = 1; step <= steps; step++ ) {
		await send( 1, y + ( dy * step ) / steps, start + step * gap );
		if ( ms ) {
			await page.waitForTimeout( ms ); // eslint-disable-line playwright/no-wait-for-timeout -- the drag's speed is what is under test.
		}
	}
	let end = start + ( steps + 1 ) * gap;
	if ( during ) {
		await during();
		end = Math.max( end, Date.now() / 1000 );
	}
	await send( 2, y + dy, end );
	await cdp.detach();
};

test.describe( 'Touch', () => {
	test( 'browser back closes Now Playing and keeps the set page and scroll position', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 6 ).scrollIntoViewIfNeeded();
		await page.locator( '.track' ).nth( 6 ).click();
		const scrolled = await page.evaluate( () => window.scrollY );
		expect( scrolled ).toBeGreaterThan( 0 );
		await expandDeck( page );
		await isExpanded( page );
		await page.goBack();
		await isCompact( page );
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await expect
			.poll( () => page.evaluate( () => window.scrollY ) )
			.toBeCloseTo( scrolled, -1 );
		// Forward opens it again.
		await page.goForward();
		await isExpanded( page );
	} );

	test( 'the close button and Escape close Now Playing without leaving a history entry', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await page.locator( '.track' ).first().click();

		await expandDeck( page );
		await isExpanded( page );
		await page.locator( '#deck-down' ).click();
		await isCompact( page );

		await expandDeck( page );
		await isExpanded( page );
		await page.keyboard.press( 'Escape' );
		await isCompact( page );
		await expect( page.locator( '#deck' ) ).toBeVisible(); // Escape closed Now Playing, not the player bar

		// If a history entry was left behind, this back would stay on the set page.
		await page.goBack();
		await expect( page ).toHaveURL( /\/$/ );
		await expect( page.locator( 'a.set' ).first() ).toBeVisible();
	} );

	test( 'the close button is a grabber bar with a 44px touch area', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await isExpanded( page );
		const grabber = page.getByRole( 'button', { name: 'Close player' } );
		await expect( grabber ).toBeVisible();
		await expect( grabber.locator( 'svg' ) ).toHaveCount( 0 );
		const pill = await grabber.evaluate( ( button ) => {
			const bar = button.firstElementChild,
				r = bar.getBoundingClientRect();
			return {
				width: r.width,
				height: r.height,
				radius: parseFloat(
					getComputedStyle( bar ).borderTopLeftRadius
				),
				centre: r.left + r.width / 2,
				top: r.top,
			};
		} );
		expect( pill.width ).toBeGreaterThanOrEqual( 32 );
		expect( pill.width ).toBeLessThanOrEqual( 40 );
		expect( pill.height ).toBeGreaterThanOrEqual( 4 );
		expect( pill.height ).toBeLessThanOrEqual( 6 );
		expect( pill.radius ).toBeGreaterThanOrEqual( pill.height / 2 );
		expect( pill.centre ).toBeCloseTo( page.viewportSize().width / 2, 0 );
		const adminBar = await page.locator( '#wpadminbar' ).boundingBox();
		expect( pill.top ).toBeLessThan( adminBar.y + adminBar.height + 40 );
		const box = await grabber.boundingBox();
		expect( box.width ).toBeGreaterThanOrEqual( 44 );
		expect( box.height ).toBeGreaterThanOrEqual( 44 );
		expect( await touchAreaUnder44( page, '#deck-down' ) ).toEqual( [] );
	} );

	// The default config runs with reduced motion, where the screen does not follow the finger.
	test.describe( 'with motion', () => {
		test.use( {
			contextOptions: {
				reducedMotion: 'no-preference',
				strictSelectors: true,
			},
		} );

		test( 'a long drag down closes Now Playing without leaving a history entry', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/' );
			await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
			await expect( page ).toHaveURL( /\/demo-set\/$/ );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			const height = page.viewportSize().height;
			const dy = Math.round( height * 0.6 );
			await drag( page, testInfo, await grabberCentre( page ), dy, {
				steps: 12,
				ms: 30,
				during: async () => {
					// The screen follows the finger one to one.
					await expect
						.poll( () => deckOffset( page ) )
						.toBeGreaterThan( dy - 3 );
				},
			} );
			await isCompact( page );
			await expect.poll( () => deckOffset( page ) ).toBe( 0 );
			expect(
				await page.evaluate( () => !! history.state?.nowPlaying )
			).toBe( false );
			// If a history entry was left behind, this back would stay on the set page.
			await page.goBack();
			await expect( page ).toHaveURL( /\/$/ );
			await expect( page.locator( 'a.set' ).first() ).toBeVisible();
		} );

		test( 'a short slow drag springs back', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			const dy = Math.round( page.viewportSize().height * 0.15 );
			await drag( page, testInfo, await grabberCentre( page ), dy, {
				steps: 10,
				ms: 60,
				during: async () => {
					await expect
						.poll( () => deckOffset( page ) )
						.toBeGreaterThan( dy - 3 );
				},
			} );
			await expect.poll( () => deckOffset( page ) ).toBe( 0 );
			await isExpanded( page );
			expect(
				await page.evaluate( () => !! history.state?.nowPlaying )
			).toBe( true );
		} );

		test( 'a fast flick closes Now Playing', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			// Shorter than the distance that closes on its own, so only the speed can close it.
			const dy = Math.round( page.viewportSize().height * 0.15 );
			await drag( page, testInfo, await grabberCentre( page ), dy, {
				steps: 3,
			} );
			await isCompact( page );
			await expect( page ).toHaveURL( /\/demo-set\/$/ );
		} );

		test( 'a drag that starts on the seek bar does not move or close Now Playing', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			const seek = await page.locator( '#seek' ).boundingBox();
			const dy = Math.round( page.viewportSize().height * 0.4 );
			const start = {
				x: seek.x + seek.width / 2,
				y: seek.y + seek.height / 2,
			};
			let moved = null;
			await drag( page, testInfo, start, dy, {
				steps: 8,
				ms: 20,
				during: async () => ( moved = await deckOffset( page ) ),
			} );
			expect( moved ).toBe( 0 );
			await isExpanded( page );
			// The same drag from the grabber does close it.
			await drag( page, testInfo, await grabberCentre( page ), dy, {
				steps: 8,
				ms: 20,
			} );
			await isCompact( page );
		} );

		test( 'Now Playing moves with the finger on the same move, not a frame later', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			const start = await grabberCentre( page );
			// Read where the screen is as each move arrives, after the deck has handled it.
			await page.evaluate( () => {
				const deck = document.getElementById( 'deck' );
				window.cbMoves = [];
				document.addEventListener( 'pointermove', ( e ) =>
					window.cbMoves.push( [
						e.clientY,
						new DOMMatrix( getComputedStyle( deck ).transform )
							.m42,
					] )
				);
			} );
			const dy = Math.round( page.viewportSize().height * 0.15 );
			await drag( page, testInfo, start, dy, {
				steps: 6,
				ms: 40,
				during: async () => {
					const moves = await page.evaluate( () => window.cbMoves );
					expect( moves.length ).toBeGreaterThan( 3 );
					for ( const [ y, offset ] of moves.slice( 1 ) ) {
						expect( offset ).toBeCloseTo( y - start.y, 0 );
					}
				},
			} );
		} );

		test( 'a drag close becomes the player bar as it reaches it, without stopping there', async ( {
			page,
		}, testInfo ) => {
			await page.goto( '/demo-set/' );
			await page.locator( '.track' ).first().click();
			await expandDeck( page );
			await isExpanded( page );
			// One sample per frame of whether Now Playing is still up and where it is.
			await page.evaluate( () => {
				const deck = document.getElementById( 'deck' );
				window.cbFrames = [];
				const tick = () => {
					window.cbFrames.push( [
						deck.classList.contains( 'is-expanded' ),
						new DOMMatrix( getComputedStyle( deck ).transform )
							.m42,
					] );
					if ( window.cbFrames.length < 900 ) {
						requestAnimationFrame( tick );
					}
				};
				requestAnimationFrame( tick );
			} );
			await drag(
				page,
				testInfo,
				await grabberCentre( page ),
				Math.round( page.viewportSize().height * 0.4 ),
				{ steps: 8, ms: 20 }
			);
			await isCompact( page );
			const frames = await page.evaluate( () => window.cbFrames );
			const open = frames.filter( ( [ expanded ] ) => expanded );
			const lowest = Math.max( ...open.map( ( [ , y ] ) => y ) );
			// Frames where Now Playing sat at the bottom, at or within 2px of where it stopped. Waiting for the
			// history back to close it left it there for 7 frames or more.
			const parked = open.filter( ( [ , y ] ) => y >= lowest - 2 );
			expect( parked.length ).toBeLessThanOrEqual( 4 );
			expect( lowest ).toBeGreaterThan(
				page.viewportSize().height * 0.6
			);
		} );
	} );

	test( 'the page under Now Playing does not scroll', async ( {
		page,
	}, testInfo ) => {
		test.skip(
			! testInfo.project.use.hasTouch,
			'a finger panning Now Playing is what scrolled the page under it'
		);
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		const cdp = await page.context().newCDPSession( page );
		// A finger moving 150px up from a point, as raw touch events.
		const pan = async ( locator, dx = null ) => {
			const box = await locator.boundingBox();
			const x = box.x + ( dx ?? box.width / 2 ),
				y = box.y + box.height / 2;
			const touch = ( type, py ) =>
				cdp.send( 'Input.dispatchTouchEvent', {
					type,
					touchPoints: type === 'touchEnd' ? [] : [ { x, y: py } ],
				} );
			await touch( 'touchStart', y );
			for ( let step = 1; step <= 10; step++ ) {
				await touch( 'touchMove', y - 15 * step );
			}
			await touch( 'touchEnd' );
		};
		const scrollY = () => page.evaluate( () => window.scrollY );
		const twoFrames = () =>
			page.evaluate(
				() =>
					new Promise( ( resolve ) =>
						requestAnimationFrame( () =>
							requestAnimationFrame( resolve )
						)
					)
			);
		const rootScrolls = () =>
			page.evaluate(
				() =>
					getComputedStyle( document.documentElement ).overflowY !==
					'hidden'
			);

		// The same pan on the first track row scrolls the page while Now Playing is closed. The first row,
		// because the Add to Home Screen hint can cover the lower ones.
		await pan( page.locator( '.track' ).first() );
		const canPan = await expect
			.poll( scrollY, { timeout: 3000 } )
			.toBeGreaterThan( 0 )
			.then( () => true )
			.catch( () => false );
		expect( await rootScrolls() ).toBe( true );
		// A pan keeps scrolling for a moment after the finger lifts. Start from where it stops.
		await expect
			.poll( async () => {
				const at = await scrollY();
				await twoFrames();
				return ( await scrollY() ) - at;
			} )
			.toBe( 0 );
		const before = await scrollY();

		await expandDeck( page );
		await isExpanded( page );
		// Where the browser can't pan the page at all, the root refusing to scroll is the check that is left.
		expect( await rootScrolls() ).toBe( false );
		if ( canPan ) {
			// On the transport row, which Now Playing itself doesn't use for dragging.
			await pan( page.locator( '.deck-transport' ), 10 );
			await twoFrames();
			expect( await scrollY() ).toBe( before );
		} else {
			testInfo.annotations.push( {
				type: 'note',
				description: 'this browser did not scroll from touch events',
			} );
		}
		await cdp.detach();
	} );

	test( 'the player bar is sized like Tidal’s, and Now Playing uses the same glyphs a size up', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await isCompact( page );
		const size = ( selector ) =>
			page.locator( selector ).evaluate( ( el ) => {
				const r = el.getBoundingClientRect();
				return [ Math.round( r.width ), Math.round( r.height ) ];
			} );
		// The play glyph's path is 16 units tall in a 36-unit box; the skip glyphs' are 14 in 24.
		const glyph = async ( selector, units, box ) =>
			Math.round( ( ( await size( selector ) )[ 1 ] * units ) / box );
		const bar = await page
			.locator( '#deck' )
			.evaluate(
				( deck ) =>
					deck.offsetHeight -
					parseFloat( getComputedStyle( deck ).paddingBottom )
			);
		expect( bar ).toBeGreaterThanOrEqual( 84 );
		expect( await size( '#toggle' ) ).toEqual( [ 48, 48 ] );
		expect( await glyph( '#toggle .pp', 16, 36 ) ).toBe( 26 );
		const disc = () =>
			page
				.locator( '#toggle .cap' )
				.evaluate( ( cap ) => getComputedStyle( cap ).backgroundColor );
		expect( await disc() ).toBe( 'rgba(0, 0, 0, 0)' ); // a bare glyph, no disc
		expect( await size( '#next' ) ).toEqual( [ 48, 48 ] );
		expect( await glyph( '#next svg', 14, 24 ) ).toBe( 18 );
		const narrow = page.viewportSize().width <= 520;
		await expect( page.locator( '#prev' ) ).toBeVisible( {
			visible: ! narrow,
		} );
		// The title's tap target ends before the controls begin.
		const open = await page.locator( '#open-lyrics' ).boundingBox();
		const first = await page
			.locator( narrow ? '#toggle' : '#prev' )
			.boundingBox();
		expect( open.x + open.width ).toBeLessThanOrEqual( first.x );

		await expandDeck( page );
		await isExpanded( page );
		// The same bare glyphs, a size up.
		expect( await size( '#toggle' ) ).toEqual( [ 64, 64 ] );
		expect( await glyph( '#toggle .pp', 16, 36 ) ).toBe( 32 );
		expect( await disc() ).toBe( 'rgba(0, 0, 0, 0)' );
		expect( await size( '#prev' ) ).toEqual( [ 56, 56 ] );
		expect( await glyph( '#prev svg', 14, 24 ) ).toBe( 21 );
		expect( await size( '#loop' ) ).toEqual( [ 48, 48 ] );
		expect( await size( '#repeat svg' ) ).toEqual( [ 24, 24 ] );
	} );

	test( 'the last track sits just above the player bar, and the bar has no position line', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await isCompact( page );
		await page.evaluate( () =>
			window.scrollTo( 0, document.documentElement.scrollHeight )
		);
		const gap = () =>
			page.evaluate( () => {
				const main = document.querySelector( '.app' );
				const last = [ ...main.querySelectorAll( '*' ) ]
					.filter( ( el ) => el.getClientRects().length )
					.reduce(
						( low, el ) =>
							Math.max( low, el.getBoundingClientRect().bottom ),
						0
					);
				return (
					document.getElementById( 'deck' ).getBoundingClientRect()
						.top - last
				);
			} );
		await expect.poll( gap ).toBeGreaterThanOrEqual( 0 );
		expect( await gap() ).toBeLessThanOrEqual( 64 );
		expect(
			await page
				.locator( '#deck' )
				.evaluate(
					( deck ) => getComputedStyle( deck, '::before' ).content
				)
		).toBe( 'none' );
		// The bar's height is what the page padding, toasts and the update prompt sit on. Opening Now Playing
		// used to measure the whole screen into it.
		const token = () =>
			page.evaluate( () =>
				getComputedStyle( document.documentElement ).getPropertyValue(
					'--deck-h'
				)
			);
		const closed = await token();
		expect( parseFloat( closed ) ).toBeLessThan( 120 );
		await expandDeck( page );
		await isExpanded( page );
		// Two frames: a ResizeObserver reports during the frame after the resize.
		await page.evaluate(
			() =>
				new Promise( ( resolve ) =>
					requestAnimationFrame( () => requestAnimationFrame( resolve ) )
				)
		);
		expect( await token() ).toBe( closed );
	} );

	test( 'Enter and Space on the grabber close Now Playing', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await page.locator( '.track' ).first().click();
		const grabber = page.getByRole( 'button', { name: 'Close player' } );
		for ( const key of [ 'Enter', 'Space' ] ) {
			await expandDeck( page );
			await isExpanded( page );
			await grabber.focus();
			await page.keyboard.press( key );
			await isCompact( page );
		}
		await page.goBack();
		await expect( page ).toHaveURL( /\/$/ );
	} );

	test( 'with reduced motion, a long drag closes Now Playing without moving it', async ( {
		page,
	}, testInfo ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } ); // the default here, but this test depends on it
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await isExpanded( page );
		const dy = Math.round( page.viewportSize().height * 0.6 );
		let moved = null;
		await drag( page, testInfo, await grabberCentre( page ), dy, {
			steps: 12,
			ms: 30,
			during: async () => ( moved = await deckOffset( page ) ),
		} );
		expect( moved ).toBe( 0 );
		await isCompact( page );
	} );

	test( 'player and track list controls have touch areas of at least 44px', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click(); // has a note, so a note pin and the lyrics button show
		expect(
			await touchAreaUnder44(
				page,
				'a.back, .actions button, .track, .dl, #deck button, #deck input'
			)
		).toEqual( [] );
		await expandDeck( page );
		await isExpanded( page );
		expect(
			await touchAreaUnder44(
				page,
				'#deck button, #deck input, .seek-marks .pin'
			)
		).toEqual( [] );
	} );

	test( 'tapping the waveform seeks to the tapped position', async ( {
		page,
	}, testInfo ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 1 ).click(); // no lyrics or notes, so no marks to snap to
		await expandDeck( page );
		await isExpanded( page );
		await page.evaluate( () => {
			const seek = document.getElementById( 'seek' );
			seek.addEventListener(
				'input',
				() => ( window.__seekTo = +seek.value )
			);
		} );
		const wave = await page.locator( '.seek-wrap' ).boundingBox();
		for ( const at of [ 0.25, 0.75 ] ) {
			const x = wave.x + wave.width * at,
				y = wave.y + wave.height / 2;
			if ( testInfo.project.use.hasTouch ) {
				await page.touchscreen.tap( x, y );
			} else {
				await page.mouse.click( x, y );
			}
			// The range is 0 to 1000 across the waveform; allow 5 either way.
			await expect
				.poll( () => page.evaluate( () => window.__seekTo ) )
				.toBeGreaterThanOrEqual( at * 1000 - 5 );
			expect(
				await page.evaluate( () => window.__seekTo )
			).toBeLessThanOrEqual( at * 1000 + 5 );
		}
	} );

	test( 'the lyrics sheet is a non-modal dialog', async ( { page } ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click();
		await expandDeck( page );
		await page.locator( '#sheet-pill' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeVisible();
		expect(
			await page
				.locator( '#lyrics' )
				.evaluate(
					( el ) =>
						el instanceof HTMLDialogElement &&
						el.open &&
						! el.matches( ':modal' )
				)
		).toBe( true );
		await page.locator( '#next' ).click(); // the player controls still work while it is open
		await expect( page.locator( '#now-title' ) ).toContainText(
			"It's a Brand New Day"
		);
		await page.locator( '#wpadminbar' ).evaluate( ( el ) => {
			el.style.pointerEvents = 'none';
		} );
		await page.locator( '#close-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeHidden();
	} );

	test( 'in an iOS Home Screen app, swiping from the left edge goes back', async ( {
		page,
	} ) => {
		// iOS Home Screen apps have no back gesture, so the app provides one there only.
		await page.addInitScript( () =>
			Object.defineProperty( navigator, 'standalone', { value: true } )
		);
		await page.goto( '/' );
		await page.locator( 'a.set', { hasText: 'Compositions' } ).click();
		await expect( page.locator( '.track' ) ).toHaveCount( 10 );
		await page.evaluate( () => {
			const fire = ( type, clientX ) =>
				document.body.dispatchEvent(
					new PointerEvent( type, {
						clientX,
						clientY: 300,
						pointerId: 9,
						pointerType: 'touch',
						isPrimary: true,
						bubbles: true,
					} )
				);
			fire( 'pointerdown', 8 );
			fire( 'pointermove', 220 );
			fire( 'pointerup', 220 );
		} );
		await expect( page ).toHaveURL( /\/$/ );
		await expect( page.locator( 'a.set' ).first() ).toBeVisible();
		// It went back in history rather than pushing home, so forward returns to the set.
		await page.goForward();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
	} );
} );
