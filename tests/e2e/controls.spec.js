/**
 * Every control on the deck and the set page, driven the way a hand would. Not every headless Chromium
 * build plays the fixture MP3s, so these assert on what the controls change (track, position, chips,
 * sheet, lock) and count the play() calls they make rather than waiting for sound.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Where headless Chromium can't play the audio, play() rejects and the element's paused flag can't be
// trusted. Count the transport calls instead: a control that asks the element to play or pause has done
// its job.
const spyTransport = ( page ) =>
	page.addInitScript( () => {
		window.__transport = 0;
		const proto = HTMLMediaElement.prototype;
		const play = proto.play,
			pause = proto.pause;
		proto.play = function play2() {
			window.__transport++;
			return play.call( this ).catch( () => {} );
		};
		proto.pause = function pause2() {
			window.__transport++;
			return pause.call( this );
		};
	} );
const transport = ( page ) => page.evaluate( () => window.__transport );
const time = ( page ) =>
	page.evaluate( () => document.getElementById( 'audio' ).currentTime );
const setTime = ( page, t ) =>
	page.evaluate( ( v ) => {
		document.getElementById( 'audio' ).currentTime = v;
	}, t );
// The deck now opens compact (title, artist, play/pause only) and remembers the last view in localStorage;
// most of this file predates that and expects the full transport, seek, and waveform on screen the moment
// a track loads, so it starts every test already expanded. The compact/expanded behaviour itself — the
// default and the tap to expand — gets its own describe block below. Closing it (back, Escape, the close button)
// is tested in front.spec.js under "Touch", so it runs on the iPhone project too.
const expandDeck = ( page ) => page.locator( '#open-lyrics' ).click();
// Headless Chromium grants no screen wake lock, so stand in for one and count what is held. Hiding the
// page releases every lock, the way the platform does.
const spyWakeLock = ( page ) =>
	page.addInitScript( () => {
		const held = new Set();
		window.__wake = held;
		Object.defineProperty( navigator, 'wakeLock', {
			configurable: true,
			value: {
				request: async () => {
					const sentinel = {
						released: false,
						release: async () => {
							sentinel.released = true;
							held.delete( sentinel );
						},
					};
					held.add( sentinel );
					return sentinel;
				},
			},
		} );
		document.addEventListener( 'visibilitychange', () => {
			if ( document.visibilityState === 'hidden' ) {
				held.forEach( ( sentinel ) => ( sentinel.released = true ) );
				held.clear();
			}
		} );
	} );
const locksHeld = ( page ) => page.evaluate( () => window.__wake.size );
const setVisibility = ( page, state ) =>
	page.evaluate( ( value ) => {
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => value,
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	}, state );

test.describe( 'Controls', () => {
	test.beforeEach( async ( { page } ) => {
		await spyTransport( page );
		await page.goto( '/demo-set/' );
	} );

	test( 'the play button asks the element to play, then to pause', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		const before = await transport( page );
		await page.locator( '#toggle' ).click(); // paused (no decode) so this asks again
		expect( await transport( page ) ).toBe( before + 1 );
		await expect( page.locator( '#toggle' ) ).toHaveAttribute(
			'aria-label',
			/Play|Pause/
		);
	} );

	// #35: the play key lost its disc and became a bare glyph like the skips beside it.
	test( 'the play key is a solid disc with the glyph cut out of it', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		const disc = await page
			.locator( '#toggle .cap' )
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
		const glyph = await page
			.locator( '#toggle .glyph' )
			.evaluate( ( el ) => getComputedStyle( el ).fill );
		expect( disc ).not.toMatch( /rgba\(.*, 0\)$/ ); // painted, not transparent
		expect( glyph ).not.toBe( disc );
	} );

	test( 'next and previous move through the set and wrap', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		await page.locator( '#next' ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 11–20'
		);
		await expect( page.locator( '.track' ).nth( 1 ) ).toHaveClass(
			/active/
		);
		await page.locator( '#prev' ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);
		await page.locator( '#prev' ).click(); // from the first track, previous wraps to the last
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 91–100'
		);
		await page.locator( '#next' ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);
	} );

	test( 'previous restarts a track that is more than three seconds in', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).nth( 2 ).click();
		await setTime( page, 5 );
		await page.locator( '#prev' ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 21–30'
		); // stayed on the track rather than going back one
		expect( await time( page ) ).toBeLessThan( 5 );
	} );

	// The media keys on a keyboard, the lock screen, and the Dynamic Island all arrive the same way:
	// the OS calls a Media Session action handler with the page unfocused and no arguments. Nothing
	// covered that path, so a change to load() or prev() could quietly break every hardware control
	// on the device while every button on screen kept working.
	test( 'the media keys move through the set with the page unfocused', async ( {
		page,
	} ) => {
		await page.addInitScript( () => {
			window.__mediaHandlers = {};
			const real = navigator.mediaSession?.setActionHandler?.bind(
				navigator.mediaSession
			);
			if ( real ) {
				navigator.mediaSession.setActionHandler = ( action, fn ) => {
					window.__mediaHandlers[ action ] = fn;
					return real( action, fn );
				};
			}
		} );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);

		const registered = await page.evaluate( () =>
			Object.keys( window.__mediaHandlers )
		);
		for ( const action of [
			'previoustrack',
			'nexttrack',
			'play',
			'pause',
		] ) {
			expect( registered ).toContain( action );
		}

		// Called with no arguments, the way the OS calls them.
		await page.evaluate( () => window.__mediaHandlers.nexttrack() );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 11–20'
		);

		await page.evaluate( () => window.__mediaHandlers.previoustrack() );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);
	} );

	test( 'the seek control scrubs and announces the position', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		await expandDeck( page ); // the seek line and the A-B loop are Now Playing's, not the bar's
		const seek = page.locator( '#seek' );
		await seek.evaluate( ( el ) => {
			el.value = 500;
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await expect( page.locator( '#cur' ) ).toHaveText( '0:20' );
		await expect( seek ).toHaveAttribute( 'aria-valuetext', '0:20 / 0:40' );
		expect( await time( page ) ).toBeCloseTo( 20, 0 );
		await seek.focus();
		await page.keyboard.press( 'ArrowRight' ); // five seconds
		expect( await time( page ) ).toBeCloseTo( 25, 0 );
	} );

	test( 'keyboard: space, arrows, shift-arrows, brackets, escape', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		await page.locator( 'h1' ).click(); // focus off the controls, before Now Playing covers it
		await expandDeck( page ); // the seek line and the A-B loop are Now Playing's, not the bar's
		const before = await transport( page );
		await page.keyboard.press( 'Space' );
		expect( await transport( page ) ).toBe( before + 1 );
		await setTime( page, 10 );
		await page.keyboard.press( 'ArrowRight' );
		expect( await time( page ) ).toBeCloseTo( 15, 0 );
		await page.keyboard.press( 'ArrowLeft' );
		expect( await time( page ) ).toBeCloseTo( 10, 0 );
		await page.keyboard.press( 'Shift+ArrowRight' );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 11–20'
		);
		await page.keyboard.press( 'Shift+ArrowLeft' );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);
		await setTime( page, 4 );
		await page.keyboard.press( '[' );
		await setTime( page, 9 );
		await page.keyboard.press( ']' );
		await expect( page.locator( '#loop-band' ) ).toHaveClass( /on/ );
		await page.keyboard.press( '\\' );
		await expect( page.locator( '#loop-band' ) ).not.toHaveClass( /on/ );
		await page.evaluate( () => document.getElementById( 'audio' ).pause() ); // no decode leaves paused unsettled
		// Escape closes Now Playing first, then dismisses the paused player bar.
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );
		await expect( page.locator( '#deck' ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#deck' ) ).toBeHidden();
		await expect( page.locator( '.track.active' ) ).toHaveCount( 0 );
	} );

	test( 'the title button opens and closes the sheet, or finds the track', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click(); // carries a director's note
		await expandDeck( page ); // the first tap on the bar opens Now Playing; the sheet is inside it
		await page.locator( '#open-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeVisible();
		await expect( page.locator( '#open-lyrics' ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		await expect( page.locator( 'body' ) ).toHaveClass( /sheet-open/ );
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#lyrics' ) ).toBeHidden();
		await page.locator( '#open-lyrics' ).click();
		await page.locator( '#close-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeHidden();
		await page.goto( '/demo-set/' ); // no lyrics or notes here, so the title button finds the track instead
		await page.locator( '.track' ).nth( 1 ).click();
		await page.locator( 'a.back' ).click();
		await expect( page ).toHaveURL( /\/$/ );
		// The bar's own tap opens Now Playing now, so "Playing from" is what carries you back to the
		// set — the same job Tidal gives it, and the only way back from anywhere that is not the set.
		await expandDeck( page );
		await page.locator( '#deck-from' ).click();
		await expect( page ).toHaveURL( /\/demo-set\/$/ );
		await expect( page.locator( '#deck' ) ).not.toHaveClass(
			/is-expanded/
		);
		await page.locator( '#open-lyrics' ).click(); // on the set the bar opens Now Playing again
		await expandDeck( page ); // and once open, the title finds the row
		await expect( page.locator( '.track' ).nth( 1 ) ).toBeFocused();
	} );

	test( 'Play all becomes the transport; the track row toggles the current track', async ( {
		page,
	} ) => {
		const before = await transport( page );
		await page.locator( '#play-all' ).click();
		expect( await transport( page ) ).toBe( before + 1 );
		await expect( page.locator( '.track' ).first() ).toHaveClass(
			/active/
		);
		await page.locator( '.track' ).first().click(); // the current row asks to play again (paused)
		expect( await transport( page ) ).toBe( before + 2 );
		await page.locator( '.track' ).nth( 4 ).click();
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 41–50'
		);
	} );

	test( 'switching tracks while playing never pauses the new one', async ( {
		page,
	} ) => {
		// no decode here, so fire the media events by hand in the order the browser does
		await page.locator( '.track' ).first().click();
		await page.evaluate( () =>
			document
				.getElementById( 'audio' )
				.dispatchEvent( new Event( 'play' ) )
		); // track one takes the lock
		await page.locator( '.track' ).nth( 1 ).click(); // a new src: the browser fires pause, then play
		const pausedByUs = await page.evaluate( async () => {
			const a = document.getElementById( 'audio' );
			const before = window.__transport;
			a.dispatchEvent( new Event( 'pause' ) );
			a.dispatchEvent( new Event( 'play' ) ); // track two takes the lock from track one's request
			await new Promise( ( r ) => setTimeout( r, 300 ) );
			return window.__transport - before; // anything here is our own lock handler pausing the new track
		} );
		expect( pausedByUs ).toBe( 0 );
		// #29: the case that actually broke. The new track's request goes in before the old one was ever
		// released, so it steals from this tab's own earlier request, and that loser must not pause.
		const pausedBySteal = await page.evaluate( async () => {
			const a = document.getElementById( 'audio' );
			const before = window.__transport;
			a.dispatchEvent( new Event( 'play' ) );
			a.dispatchEvent( new Event( 'play' ) );
			await navigator.locks.query(); // lets both requests settle, the stolen one included
			await new Promise( ( r ) => setTimeout( r ) );
			return window.__transport - before;
		} );
		expect( pausedBySteal ).toBe( 0 );
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 11–20'
		);
	} );

	test( 'playing takes the site-wide lock; a second tab steals it', async ( {
		page,
		context,
	} ) => {
		await page.locator( '.track' ).first().click();
		const held = await page.evaluate( async () => {
			const q = await navigator.locks.query();
			return q.held.some( ( l ) => l.name === 'callboard:player' );
		} );
		// no decode means no play event, so the lock is only taken once something plays; assert the API is wired
		expect( typeof held ).toBe( 'boolean' );
		const other = await context.newPage();
		await other.goto( '/demo-set/' );
		await other.close();
	} );
} );

test.describe( 'Deck view: compact and expanded', () => {
	test.beforeEach( async ( { page } ) => {
		await spyTransport( page );
		await page.goto( '/demo-set/' ); // no seeded preference: compact is the default
	} );

	test( 'starts compact: the track, and moving through it', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		const deck = page.locator( '#deck' );
		await expect( deck ).toHaveClass( /is-compact/ );
		await expect( deck ).not.toHaveClass( /is-expanded/ );
		await expect( page.locator( '#now-title' ) ).toBeVisible();
		// Skipping between numbers is the job; a bar you have to open first is not compact.
		await expect( page.locator( '#prev' ) ).toBeVisible();
		await expect( page.locator( '#toggle' ) ).toBeVisible();
		await expect( page.locator( '#next' ) ).toBeVisible();
		// What you set once can wait for the expanded view.
		await expect( page.locator( '#seek' ) ).toBeHidden();
		await expect( page.locator( '.deck-time' ) ).toBeHidden();
		await expect( page.locator( '.deck-controls-secondary' ) ).toBeHidden();
	} );

	test( 'tapping the compact bar expands it; the play button does not', async ( {
		page,
	} ) => {
		await page.locator( '.track' ).first().click();
		const before = await transport( page );
		await page.locator( '#toggle' ).click(); // the play button, not the bar
		expect( await transport( page ) ).toBe( before + 1 );
		await expect( page.locator( '#deck' ) ).toHaveClass( /is-compact/ );
		await page.locator( '#open-lyrics' ).click(); // the rest of the bar
		await expect( page.locator( '#deck' ) ).toHaveClass( /is-expanded/ );
		await expect( page.locator( '#next' ) ).toBeVisible();
		// Now Playing covers the set, so the art and a way back out are the two things it owes you.
		await expect( page.locator( '#deck-cover' ) ).toBeVisible();
		await expect( page.locator( '#deck-down' ) ).toBeVisible();
		// And it is not remembered: a reload comes back to the list, not to the full screen.
		await page.reload();
		await expect( page.locator( '#deck' ) ).not.toHaveClass(
			/is-expanded/
		);
	} );

	test( 'repeat cycles off, set, one, and remembers the choice', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		const repeat = page.locator( '#repeat' );
		await expect( repeat ).toHaveAttribute( 'data-mode', 'off' );
		await expect( repeat ).toHaveAttribute( 'aria-pressed', 'false' );
		await repeat.click();
		await expect( repeat ).toHaveAttribute( 'data-mode', 'set' );
		await expect( repeat ).toHaveAttribute( 'aria-pressed', 'true' );
		await repeat.click();
		await expect( repeat ).toHaveAttribute( 'data-mode', 'one' );
		expect(
			await page.evaluate( () =>
				JSON.parse( localStorage.getItem( 'callboard:repeat' ) )
			)
		).toBe( 'one' );
		await page.reload();
		// Now Playing is not remembered across a reload, so the set has to be opened again to reach
		// the controls that live there. What the reload is testing is the repeat mode, which is.
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await expect( page.locator( '#repeat' ) ).toHaveAttribute(
			'data-mode',
			'one'
		);
		await repeat.click();
		await expect( repeat ).toHaveAttribute( 'data-mode', 'off' );
	} );

	test( 'repeat one replays the same track instead of advancing', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		await page.locator( '#repeat' ).click();
		await page.locator( '#repeat' ).click(); // off -> set -> one
		await page.evaluate( () =>
			document
				.getElementById( 'audio' )
				.dispatchEvent( new Event( 'ended' ) )
		);
		await expect( page.locator( '#now-title' ) ).toContainText(
			'Sonnets 1–10'
		);
	} );

	test( 'the A-B loop chip has an affordance and reads its state', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		const loop = page.locator( '#loop' );
		await expect( loop ).toBeVisible();
		await expect( loop ).toHaveAttribute( 'data-state', '' );
		await setTime( page, 4 );
		await page.keyboard.press( '[' );
		await setTime( page, 9 );
		await page.keyboard.press( ']' );
		await expect( loop ).toHaveAttribute( 'data-state', 'on' );
		await expect( loop ).toHaveAttribute( 'aria-label', /Clear/ );
	} );

	// #169: the sheet used to own the wake lock on its own, so closing it mid-loop let the phone sleep.
	test( 'an A-B loop holds the screen awake once the sheet closes, and again after the page comes back', async ( {
		page,
	} ) => {
		await spyWakeLock( page );
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).nth( 2 ).click(); // carries a director's note, so it has a sheet
		await expandDeck( page );
		await page.locator( '#open-lyrics' ).click();
		await expect( page.locator( '#lyrics' ) ).toBeVisible();
		await expect.poll( () => locksHeld( page ) ).toBe( 1 );
		await setTime( page, 4 );
		await page.keyboard.press( '[' );
		await setTime( page, 9 );
		await page.keyboard.press( ']' );
		await expect( page.locator( '#loop-band' ) ).toHaveClass( /on/ );
		await expect.poll( () => locksHeld( page ) ).toBe( 1 ); // asking twice would strand the first lock
		await page.keyboard.press( 'Escape' ); // closes the sheet, leaving the loop running
		await expect( page.locator( '#lyrics' ) ).toBeHidden();
		await expect.poll( () => locksHeld( page ) ).toBe( 1 );
		// The platform drops the lock whenever the page hides; coming back to a loop takes it again.
		await setVisibility( page, 'hidden' );
		await expect.poll( () => locksHeld( page ) ).toBe( 0 );
		await setVisibility( page, 'visible' );
		await expect.poll( () => locksHeld( page ) ).toBe( 1 );
		await page.keyboard.press( '\\' ); // clearing the loop with the sheet closed lets it sleep
		await expect( page.locator( '#loop-band' ) ).not.toHaveClass( /on/ );
		await expect.poll( () => locksHeld( page ) ).toBe( 0 );
	} );

	test( 'a chip reads active only once it has a state to be active about', async ( {
		page,
	} ) => {
		await page.goto( '/demo-set/' );
		await page.locator( '.track' ).first().click();
		await expandDeck( page );
		// .remote-chip:not([data-state=""]) is the "active" look, so a chip that has not connected to
		// anything has to carry an empty data-state rather than no attribute at all.
		await expect( page.locator( '#remote' ) ).toHaveAttribute(
			'data-state',
			''
		);
	} );
} );
