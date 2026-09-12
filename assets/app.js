/* Callboard front end: renders views from data shipped with the page; the player persists across views. */
( () => {
	const G = window.CALLBOARD || { sets: [], settings: {}, text: {} };
	const S = G.settings || {},
		T = G.text || {};
	const $ = ( id ) => document.getElementById( id );
	const ls = {
		get: ( k ) => {
			try {
				return JSON.parse( localStorage.getItem( k ) );
			} catch {
				return null;
			}
		},
		set: ( k, v ) => {
			try {
				localStorage.setItem( k, JSON.stringify( v ) );
			} catch {}
		},
		del: ( k ) => {
			try {
				localStorage.removeItem( k );
			} catch {}
		},
	};
	// What is left, not what there is: the track list already states the length, and a player is
	// asked "how much longer", never "how long was it".
	const remaining = ( total, at ) =>
		isFinite( total ) && total > 0
			? `-${ fmt( Math.max( 0, total - at ) ) }`
			: '0:00';
	const retrigger = ( el, cls ) => {
		el.classList.remove( cls );
		void el.offsetWidth;
		el.classList.add( cls );
	};
	const fmt = ( s ) =>
		isFinite( s ) && s >= 0
			? `${ Math.floor( s / 60 ) }:${ String(
					Math.floor( s % 60 )
			  ).padStart( 2, '0' ) }`
			: '0:00';
	const tpl = ( s, ...a ) =>
		s.replace( /%(\d)\$s|%s/g, ( m, n ) => a[ n ? n - 1 : 0 ] );
	const reduce = () =>
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const setBy = ( slug ) => G.sets.find( ( s ) => s.slug === slug );

	// ---- The extension API's lower layer. Events are wp.hooks actions named callboard.<event>, and each
	// is also a DOM event named callboard:<event> on document, so a script with no dependencies can listen.
	// Filters are wp.hooks filters named callboard.<point>. Every call site names its hook in full, so
	// the public-surface check reads them. docs/extending.md has the contract; window.callboard is built
	// at the bottom of this file, once everything it reaches is defined.
	const hooks = window.wp?.hooks || null;
	const report = ( id, err ) =>
		// eslint-disable-next-line no-console
		console.error( `[callboard] ${ id }:`, err );
	const doAction = ( name, detail = {} ) => {
		try {
			hooks?.doAction( name, detail );
		} catch ( err ) {
			report( name, err );
		}
		document.dispatchEvent(
			new CustomEvent(
				`callboard:${ name.replace( /^callboard\./, '' ) }`,
				{
					detail,
				}
			)
		);
	};
	const applyFilters = ( name, value, ...args ) => {
		if ( ! hooks?.hasFilter( name ) ) {
			return value;
		}
		try {
			return hooks.applyFilters( name, value, ...args );
		} catch ( err ) {
			report( name, err );
			return value;
		}
	};
	// Extensions read copies. A set or a track handed out by reference is one assignment away from
	// changing what the player itself plays.
	const freeze = ( v ) => {
		if ( v && typeof v === 'object' && ! Object.isFrozen( v ) ) {
			Object.values( v ).forEach( freeze );
			Object.freeze( v );
		}
		return v;
	};
	const snapshot = ( v ) => {
		if ( v === null || v === undefined ) {
			return null;
		}
		try {
			return freeze(
				typeof structuredClone === 'function'
					? structuredClone( v )
					: JSON.parse( JSON.stringify( v ) )
			);
		} catch {
			return null;
		}
	};
	// PHP sends an empty map as []. An extension should never have to check which one it got.
	const asMap = ( v ) => ( v && ! Array.isArray( v ) ? v : {} );
	const owns = ( o, k ) => Object.prototype.hasOwnProperty.call( o, k );
	G.ext = asMap( G.ext );
	G.extensions = asMap( G.extensions );
	G.sets.forEach( ( set ) => {
		set.ext = asMap( set.ext );
		set.tracks.forEach( ( t ) => ( t.ext = asMap( t.ext ) ) );
	} );

	( window.requestIdleCallback || ( ( f ) => setTimeout( f, 1000 ) ) )(
		() => {
			if ( 'serviceWorker' in navigator ) {
				navigator.serviceWorker.register( '/sw.js' ).catch( () => {} );
			}
		}
	);
	// A new version took over underneath this page: say so, quietly, and let a tap bring it in.
	navigator.serviceWorker?.addEventListener( 'message', ( e ) => {
		if (
			e.data?.type !== 'callboard:updated' ||
			e.data.version === G.version
		) {
			return;
		}
		const bar = $( 'update' );
		if ( bar ) {
			bar.hidden = false;
		}
	} );
	document.addEventListener( 'click', ( e ) => {
		if ( e.target.closest( '#update' ) ) {
			location.reload();
		}
	} );
	try {
		if ( navigator.audioSession ) {
			navigator.audioSession.type = 'playback';
		}
	} catch {}
	const isIOS = /iphone|ipad|ipod/i.test( navigator.userAgent );
	const standalone =
		window.matchMedia( '(display-mode: standalone)' ).matches ||
		navigator.standalone === true;
	document.documentElement.classList.toggle( 'is-standalone', standalone );
	// Haptics. iOS has no Vibration API; since iOS 18 a switch control toggled inside a user gesture clicks
	// with a haptic, and a label click forwards to it, so a fresh label and switch are made for every call
	// and thrown away. Everywhere else navigator.vibrate does the same job. Both fire only synchronously
	// inside a tap or a release: never from a timer, and never after an await.
	const haptic = ( ms = 10 ) => {
		if ( isIOS ) {
			try {
				const label = document.createElement( 'label' );
				label.setAttribute( 'aria-hidden', 'true' );
				label.style.display = 'none';
				const sw = document.createElement( 'input' );
				sw.type = 'checkbox';
				sw.setAttribute( 'switch', '' );
				label.appendChild( sw );
				document.head.appendChild( label );
				label.click();
				label.remove();
			} catch {}
			return;
		}
		try {
			navigator.vibrate?.( ms );
		} catch {}
	};
	// iOS Safari only applies :active styles when the page has a touch listener.
	document.addEventListener( 'touchstart', () => {}, { passive: true } );
	// In-app browsers (Instagram, Facebook, TikTok, Snapchat, Messenger) hide Add to Home Screen; Safari has it.
	const inApp =
		/FBAN|FBAV|Instagram|Snapchat|TikTok|musical_ly|Messenger/i.test(
			navigator.userAgent
		) ||
		( isIOS && ! /Safari\//.test( navigator.userAgent ) );
	let installPrompt = null; // Chromium fires this; one tap then installs
	window.addEventListener( 'beforeinstallprompt', ( e ) => {
		e.preventDefault();
		installPrompt = e;
		paintTip();
	} );
	window.addEventListener( 'appinstalled', () => {
		installPrompt = null;
		ls.set( 'callboard:a2hs', 1 );
		paintTip();
	} );

	// ---- Confetti: triple-tap the big title (configured text, optional hearts)
	const burst = () => {
		if ( ! S.confetti ) {
			return;
		}
		const COUNT = 8;
		for ( let k = 0; k < COUNT; k++ ) {
			const el = document.createElement( 'span' );
			el.className = 'tt';
			el.textContent = S.hearts && k % 2 ? '♥' : S.confetti;
			el.setAttribute( 'aria-hidden', 'true' );
			el.style.left = `${ 8 + Math.random() * 80 }vw`;
			el.style.fontSize = `${ 1.2 + Math.random() * 0.9 }rem`;
			el.style.color = `hsl(${ Math.round(
				( k / COUNT ) * 330 + Math.random() * 12
			) } 85% 62%)`;
			document.body.appendChild( el );
			const drift = reduce() ? 0 : ( Math.random() - 0.5 ) * 40,
				tilt = reduce() ? 0 : ( Math.random() - 0.5 ) * 10;
			const rise = -(
				window.innerHeight *
				( 0.55 + Math.random() * 0.3 )
			);
			const anim = el.animate(
				[
					{ transform: 'translate(0, 0) rotate(0deg)', opacity: 0 },
					{ opacity: 0.85, offset: 0.12 },
					{ opacity: 0.85, offset: 0.7 },
					{
						transform: `translate(${ drift }px, ${ rise }px) rotate(${ tilt }deg)`,
						opacity: 0,
					},
				],
				{
					duration: 3200 + Math.random() * 900,
					delay: k * 200 + Math.random() * 200,
					easing: 'cubic-bezier(.15,.5,.25,1)',
					fill: 'forwards',
				}
			);
			anim.onfinish = () => el.remove();
			setTimeout( () => el.remove(), 7000 );
		}
	};
	let taps = [];
	document.addEventListener( 'pointerup', ( e ) => {
		if ( ! e.target.closest( 'h1' ) ) {
			return;
		}
		const now = Date.now();
		taps = taps.filter( ( t ) => now - t < 700 ).concat( now );
		if ( taps.length >= 3 ) {
			taps = [];
			burst();
		}
	} );

	// ---- Persistent player
	const audio = $( 'audio' ),
		deck = $( 'deck' ),
		nowTitle = $( 'now-title' ),
		toggle = $( 'toggle' ),
		seek = $( 'seek' ),
		seekFill = $( 'seek-fill' ),
		cur = $( 'cur' ),
		dur = $( 'dur' );
	const lyricsSheet = $( 'lyrics' ),
		lyricsList = $( 'lyrics-lines' ),
		openLyrics = $( 'open-lyrics' );
	if ( ! audio || ! deck ) {
		return;
	}
	// The lyrics sheet is a <dialog>, so the open attribute is its state.
	const sheetOpen = () => lyricsSheet.hasAttribute( 'open' );

	// ---- Compact / expanded: two states for the deck itself, remembered like everything else the player
	// remembers (ls, above). Compact is the default — a 72px bar with just the title and play/pause; expanded
	// is close to what the deck has always been. Read before load() below, since the class has to be on the
	// element before the first track ever shows the deck.
	function syncOpenLyricsA11y() {
		// The title button doubles as the compact bar's tap target; while compact it expands the deck instead
		// of the lyrics sheet, so its label and aria-controls have to say that rather than whatever the sheet
		// state would otherwise ask for.
		if ( deck.classList.contains( 'is-compact' ) ) {
			openLyrics.setAttribute( 'aria-expanded', 'false' );
			openLyrics.setAttribute( 'aria-controls', 'deck' );
			openLyrics.setAttribute(
				'aria-label',
				openLyrics.dataset.labelExpand
			);
			return;
		}
		openLyrics.setAttribute( 'aria-controls', 'lyrics' );
		openLyrics.setAttribute(
			'aria-expanded',
			sheetOpen() ? 'true' : 'false'
		);
		openLyrics.setAttribute(
			'aria-label',
			sheetOpen()
				? sheetKind === 'notes'
					? T.hide_notes
					: T.hide_lyrics
				: sheetKind === 'notes'
				? T.show_notes
				: sheetKind === 'lyrics'
				? T.show_lyrics
				: T.show_track
		);
		paintSheetPill();
	}
	// The pill exists only where the track has words. Its label is the sheet's own name so the
	// control says what it opens rather than "Lyrics" over a page of director's notes.
	function paintSheetPill() {
		const pill = $( 'sheet-pill' );
		if ( ! pill ) {
			return;
		}
		pill.hidden = ! sheetKind;
		if ( ! sheetKind ) {
			return;
		}
		const open = sheetOpen();
		pill.textContent = sheetKind === 'notes' ? T.notes : T.lyrics;
		pill.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	}
	// Two states and no memory of them. Expanded is Now Playing over the whole screen, and a screen
	// that opens over the track list because of something you tapped yesterday is a screen you have
	// to dismiss before you can use the app. Every media player treats Now Playing as somewhere you
	// go, never somewhere you land.
	function setDeckView( mode ) {
		deck.classList.toggle( 'is-compact', mode !== 'expanded' );
		deck.classList.toggle( 'is-expanded', mode === 'expanded' );
		document.body.classList.toggle( 'now-playing', mode === 'expanded' );
		syncOpenLyricsA11y();
	}
	// Opening Now Playing pushes a history entry at the same URL, so the browser's or system back
	// (Android back, Safari's edge swipe) closes it. See the popstate handler.
	const nowPlayingEntry = () => !! history.state?.nowPlaying;
	function expandDeck( push = true ) {
		if ( deck.classList.contains( 'is-expanded' ) ) {
			return;
		}
		if ( push ) {
			haptic();
			if ( ! nowPlayingEntry() ) {
				history.pushState(
					{ ...( history.state || {} ), nowPlaying: true },
					''
				);
			}
		}
		setDeckView( 'expanded' );
		// the wave and the seek knob draw against a box that had zero width while it was display:none
		requestAnimationFrame( () => {
			drawWave();
			paint( true );
		} );
	}
	function collapseDeck() {
		if ( deck.classList.contains( 'is-compact' ) ) {
			return;
		}
		setDeckView( 'compact' );
	}
	// Close button and Escape: go back through the history entry so it isn't left behind.
	function closeNowPlaying() {
		if ( ! deck.classList.contains( 'is-expanded' ) ) {
			return;
		}
		if ( nowPlayingEntry() ) {
			history.back();
			return;
		}
		collapseDeck();
	}
	let sheetKind = ''; // set by renderSheet(); declared here so syncOpenLyricsA11y() above can read it early
	// After a reload, Now Playing starts closed, so clear the flag on the current entry.
	if ( nowPlayingEntry() ) {
		history.replaceState( { ...history.state, nowPlaying: false }, '' );
	}
	setDeckView( 'compact' );

	let looper = null, // the gapless loop, when one is running (see the A-B loop section)
		looperCtx = null,
		looperBuf = null; // { url, buffer }
	let played = false; // true once this session has played anything; before that the set button reads "Play all"
	let queue = null,
		i = -1,
		seeking = false,
		rows = [],
		cues = [],
		cueEls = [],
		cueIdx = -1,
		lastSec = -1;
	const view = () => document.body.dataset.slug || '';
	const onQueuePage = () => !! queue && view() === queue.slug;
	const key = () => `callboard:${ queue.slug }`;

	let analyser = null,
		eqRaf = 0;
	const ensureAnalyser = () => {
		if ( analyser || isIOS || ! window.AudioContext ) {
			return;
		}
		try {
			const ctx = new AudioContext();
			const src = ctx.createMediaElementSource( audio );
			analyser = ctx.createAnalyser();
			analyser.fftSize = 64;
			analyser.smoothingTimeConstant = 0.7;
			src.connect( analyser );
			analyser.connect( ctx.destination );
			audio.addEventListener( 'play', () =>
				ctx.resume().catch( () => {} )
			);
		} catch {
			analyser = null;
		}
	};
	let eqAnims = [];
	const eqStop = () => {
		eqAnims.forEach( ( a ) => a.cancel() );
		eqAnims = [];
		cancelAnimationFrame( eqRaf );
		eqRaf = 0;
		document.querySelectorAll( '.eq i' ).forEach( ( b ) => {
			b.style.transform = '';
		} );
		glowAnim?.cancel();
		glowAnim = null;
		if ( glow ) {
			coolDown( lastBright );
			lastBright = 0;
		}
	};
	let lastBright = 0;
	// ---- Meter: the light strip on the deck and the bars on the playing row. Sources, in order:
	// a live analyser (not iOS), the track's measured envelope (ten levels a second, from import), or a slow breath.
	const glow = $( 'deck-glow' );
	const envelope = ( t ) => {
		const e = queue?.tracks[ i ]?.levels;
		if ( ! e || t < 0 ) {
			return null;
		}
		const x = t * 10,
			k = Math.min( e.length - 1, Math.floor( x ) ),
			a = +e[ k ] / 9,
			b = +e[ Math.min( e.length - 1, k + 1 ) ] / 9;
		return a + ( b - a ) * ( x - k );
	};
	const glowHot = $( 'deck-glow-hot' ),
		glowHalo = $( 'deck-glow-halo' );
	// A filament's color follows its heat: near-black red when barely lit, through orange, to a pale yellow-white
	// at full current. Four stops, interpolated; the accent sits at the middle so the brand color is the working
	// temperature of the wire.
	const KELVIN = [
		[ 0, [ 74, 16, 0 ] ],
		[ 0.35, [ 190, 58, 12 ] ],
		[ 0.7, [ 255, 106, 46 ] ],
		[ 1, [ 255, 226, 178 ] ],
	];
	const heatColor = ( b ) => {
		let k = 1;
		while ( k < KELVIN.length - 1 && KELVIN[ k ][ 0 ] < b ) {
			k++;
		}
		const [ t0, c0 ] = KELVIN[ k - 1 ],
			[ t1, c1 ] = KELVIN[ k ],
			f = Math.min( 1, Math.max( 0, ( b - t0 ) / ( t1 - t0 ) ) );
		return `rgb(${ c0
			.map( ( v, n ) => Math.round( v + ( c1[ n ] - v ) * f ) )
			.join( ' ' ) })`;
	};
	const paintGlow = ( b ) => {
		// Perceptual, not linear. The curve below was set against audio that sat near full scale
		// almost constantly; speech spends most of its time around a tenth of that and read as
		// unlit. A square root lifts the quiet end into view and leaves the top where it was.
		b = Math.sqrt( b );
		const c = heatColor( b ); // continuous: no steps in the colour, so nothing to read as a flicker
		glow.style.color = c;
		if ( glowHalo ) {
			glowHalo.style.color = c;
		}
		glow.style.opacity = ( 0.25 + 0.75 * b ).toFixed( 3 );
		if ( glowHot ) {
			glowHot.style.opacity = ( 0.6 * b * b * b ).toFixed( 3 ); // the white heart only shows at the peaks
		}
		if ( glowHalo ) {
			glowHalo.style.opacity = ( 0.45 * b * b ).toFixed( 3 );
		}
	};
	// Cut the current and a filament does not go dark; it cools. Pause fades it out over a second and a half.
	let coolRaf = 0;
	const coolDown = ( from ) => {
		cancelAnimationFrame( coolRaf );
		if ( reduce() ) {
			paintGlow( 0 );
			return;
		}
		const t0 = performance.now();
		const step = ( now ) => {
			const k = Math.min( 1, ( now - t0 ) / 1500 );
			paintGlow( from * Math.pow( 1 - k, 2.2 ) ); // fast at first, then the long red tail
			if ( k < 1 ) {
				coolRaf = requestAnimationFrame( step );
			}
		};
		coolRaf = requestAnimationFrame( step );
	};
	let glowAnim = null;
	// The wire's heat, and the level it has learned to call full. Kept out here so that restarting the meter
	// while the audio never stopped (a view swap, a row appearing) carries both over: starting cold would dip
	// the filament on every navigation, and a fresh reference would hold a quiet reading dim for seconds.
	let bright = 0,
		ember = 0,
		ref = 0.5;
	const eqStart = ( row ) => {
		const warm = eqRaf !== 0;
		eqStop();
		if ( ! warm ) {
			bright = 0;
			ember = 0;
			ref = 0.5;
		}
		if ( reduce() ) {
			if ( glow ) {
				paintGlow( 0.5 );
			}
			return; // the static bars still mark the playing row
		}
		const bars = row ? [ ...row.querySelectorAll( '.eq i' ) ] : [];
		const data = analyser
			? new Uint8Array( analyser.frequencyBinCount )
			: null;
		const bands = [
			[ 1, 4 ],
			[ 4, 10 ],
			[ 10, 24 ],
		];
		const hasEnvelope = !! queue?.tracks[ i ]?.levels;
		if ( ! analyser && ! hasEnvelope ) {
			bars.forEach( ( bar, k ) =>
				eqAnims.push(
					bar.animate(
						[
							{ transform: 'scaleY(.35)' },
							{ transform: 'scaleY(1)' },
							{ transform: 'scaleY(.35)' },
						],
						{
							duration: [ 1100, 900, 1300 ][ k ] || 1000,
							delay: -k * 300,
							iterations: Infinity,
							easing: 'ease-in-out',
						}
					)
				)
			);
			if ( glow ) {
				glowAnim = glow.animate(
					[ { opacity: 0.25 }, { opacity: 0.7 }, { opacity: 0.25 } ],
					{
						duration: 2600,
						iterations: Infinity,
						easing: 'ease-in-out',
					}
				);
			}
			return;
		}
		let last = 0;
		cancelAnimationFrame( coolRaf ); // power is back on
		const tick = ( now = 0 ) => {
			const dt = last ? Math.min( 0.1, ( now - last ) / 1000 ) : 0.016;
			last = now;
			let levels;
			if ( analyser ) {
				analyser.getByteFrequencyData( data );
				levels = bands.map( ( [ a, b ] ) => {
					let sum = 0;
					for ( let n = a; n < b; n++ ) {
						sum += data[ n ];
					}
					return Math.min( 1, sum / ( b - a ) / 200 );
				} );
			} else {
				const t = audio.currentTime;
				levels = [
					envelope( t ),
					envelope( t - 0.12 ),
					envelope( t - 0.24 ),
				].map( ( v ) => ( v === null ? 0.3 : v ) );
			}
			bars.forEach( ( bar, k ) => {
				bar.style.transform = `scaleY(${ (
					0.3 +
					0.7 * levels[ k ]
				).toFixed( 2 ) })`;
			} );
			if ( glow ) {
				// a filament: it lights in ~90 ms and cools over ~400 ms, so peaks swell and settle rather than
				// twitch; a second, slower store (~1.4 s) holds the residual heat, so it never goes black
				// between phrases
				const raw =
					levels[ 0 ] * 0.5 + levels[ 1 ] * 0.3 + levels[ 2 ] * 0.2;
				// What counts as fully lit is whatever this material has actually been reaching.
				// The reference rises to a peak at once and forgets it over a few seconds, so a
				// quiet reading and a loud mix each use the whole filament, instead of one pinning
				// it on and the other never lighting it. Both sources above feed this, so it works
				// off the live analyser and off the measured envelope alike.
				ref =
					raw > ref
						? raw
						: ref + ( raw - ref ) * ( 1 - Math.exp( -dt / 6 ) );
				const target = Math.min( 1, raw / Math.max( 0.12, ref ) );
				const tau = target > bright ? 0.09 : 0.4;
				bright += ( target - bright ) * ( 1 - Math.exp( -dt / tau ) );
				ember += ( bright - ember ) * ( 1 - Math.exp( -dt / 1.4 ) );
				lastBright = Math.max( bright, ember * 0.45 );
				paintGlow( Math.min( 1, lastBright ) );
			}
			eqRaf = requestAnimationFrame( tick );
		};
		tick();
	};
	function syncRows() {
		rows = [ ...document.querySelectorAll( '.track' ) ];
		const same = onQueuePage();
		rows.forEach( ( r, k ) => {
			const on = same && k === i;
			r.classList.toggle( 'active', on );
			r.classList.toggle( 'playing', on && ! audio.paused );
			r.setAttribute( 'aria-current', on ? 'true' : 'false' );
		} );
		// The bars belong to a row, so they only move on the set that is playing. The filament belongs to the
		// deck, which outlives every view swap, so it follows the audio on any page: home, another set, or
		// Now Playing opened over either.
		if ( i >= 0 && ! audio.paused ) {
			eqStart( same ? rows[ i ] : null );
		} else {
			eqStop();
		}
		const pa = $( 'play-all' );
		if ( pa ) {
			// the set's transport: starts the set, then mirrors the deck for this set
			const loaded = played && same && i >= 0;
			pa.textContent = ! loaded
				? T.play_all
				: audio.paused
				? T.resume
				: T.pause;
			pa.classList.toggle( 'is-playing', loaded && ! audio.paused );
		}
	}
	function setTitle( text, detail = '' ) {
		const mq = nowTitle.firstElementChild;
		mq.innerHTML = '';
		const a = document.createElement( 'span' );
		a.textContent = text;
		if ( detail ) {
			const d = document.createElement( 'small' );
			d.textContent = detail;
			a.appendChild( d );
		}
		mq.appendChild( a );
		nowTitle.classList.remove( 'marquee' );
		const width = a.getBoundingClientRect().width; // the span is inline; scrollWidth would read 0
		if ( width > nowTitle.clientWidth + 2 ) {
			const b = a.cloneNode( true );
			b.setAttribute( 'aria-hidden', 'true' );
			mq.appendChild( b );
			nowTitle.style.setProperty(
				'--mq-dur',
				`${ Math.max( 8, width / 28 ) }s`
			);
			nowTitle.classList.add( 'marquee' );
		}
		retrigger( nowTitle, 'swap' );
	}

	window.addEventListener( 'resize', () => {
		if ( i >= 0 ) {
			setTitle( queue.tracks[ i ].title );
		}
	} );
	const remember = () => {
		if ( queue && i >= 0 ) {
			ls.set( key(), { i, t: Math.floor( audio.currentTime || 0 ) } );
		}
	};
	const seekKnob = $( 'seek-knob' );
	let seekWidth = 0;
	// Width of the range thumb. The seek input overhangs the line by half a thumb on each side (app.css).
	const seekThumb = () =>
		parseFloat( getComputedStyle( seek ).getPropertyValue( '--thumb' ) ) ||
		0;
	const measureSeek = () => {
		seekWidth =
			seek.clientWidth -
			seekThumb() -
			( seekKnob ? seekKnob.offsetWidth : 0 );
	};
	window.addEventListener( 'resize', measureSeek );
	// Every frame, transforms only. The range input's value is written by paint() once a second: setting it
	// relayouts the slider's thumb and repaints the deck, which at 60 Hz is what made the whole page stutter.
	const setProgress = ( ratio ) => {
		if ( ! seekWidth ) {
			measureSeek();
		}
		// the filament's lit length is also the compact bar's position line — see .deck-glow* in app.css
		deck.style.setProperty( '--progress', ratio );
		seekFill.style.transform = `scaleX(${ ratio })`;
		if ( waveReveal ) {
			const off = ( ( 1 - ratio ) * 100 ).toFixed( 3 );
			waveReveal.style.transform = `translateX(-${ off }%)`;
			wavePlayed.style.transform = `translateX(${ off }%)`;
		}
		if ( seekKnob ) {
			seekKnob.style.transform = `translateX(${ (
				ratio * seekWidth
			).toFixed( 1 ) }px)`;
		}
	};
	// ---- Waveform: where the import measured levels, the seek line becomes the track's shape. Two canvases,
	// base and played, drawn once per track and resize; progress only moves a clip-path on the played copy.
	const waveBase = $( 'wave-base' ),
		waveHover = $( 'wave-hover' ),
		wavePlayed = $( 'wave-played' ),
		waveReveal = $( 'wave-reveal' );
	// The wave is a bitmap sized to its box, and its box changes width between the bar and the full
	// screen — and again on rotate, or when a desktop window is dragged. One observer beats trying to
	// guess the frame on which each of those has settled.
	let waveBox = 0;
	if ( window.ResizeObserver && waveBase?.parentElement ) {
		new ResizeObserver( ( entries ) => {
			const w = Math.round( entries[ 0 ].contentRect.width );
			if ( w && w !== waveBox ) {
				waveBox = w;
				drawWave();
				paint( true );
			}
		} ).observe( waveBase.parentElement );
	}
	function drawWave() {
		if ( ! waveBase || ! wavePlayed ) {
			return;
		}
		const lv = queue?.tracks[ i ]?.levels || '';
		const has = lv.length > 1;
		deck.classList.toggle( 'has-wave', has );
		const w = waveBase.parentElement.clientWidth,
			h = waveBase.clientHeight;
		if ( ! has || ! w || ! h ) {
			return;
		}
		const dpr = window.devicePixelRatio || 1,
			css = getComputedStyle( deck ),
			colors = [
				css.getPropertyValue( '--wave' ).trim(),
				css.getPropertyValue( '--wave-hover' ).trim(),
				css.getPropertyValue( '--accent' ).trim(),
			],
			bar = 2,
			gap = 1,
			n = Math.max( 8, Math.floor( ( w + gap ) / ( bar + gap ) ) );
		[ waveBase, waveHover, wavePlayed ].forEach( ( cv, k ) => {
			if ( ! cv ) {
				return;
			}
			cv.width = Math.round( w * dpr );
			cv.height = Math.round( h * dpr );
			const ctx = cv.getContext( '2d' );
			ctx.scale( dpr, dpr );
			ctx.fillStyle = colors[ k ];
			for ( let b = 0; b < n; b++ ) {
				const from = Math.floor( ( b / n ) * lv.length ),
					to = Math.max(
						from + 1,
						Math.floor( ( ( b + 1 ) / n ) * lv.length )
					);
				let peak = 0;
				for ( let x = from; x < to; x++ ) {
					peak = Math.max( peak, +lv[ x ] || 0 );
				}
				// one bar per slice, centred on the band's middle line
				const full = Math.max( 2, Math.round( ( peak / 9 ) * h ) ),
					x = b * ( bar + gap ),
					y = Math.round( ( h - full ) / 2 );
				if ( ctx.roundRect ) {
					ctx.beginPath();
					ctx.roundRect( x, y, bar, full, 1 );
					ctx.fill();
				} else {
					ctx.fillRect( x, y, bar, full );
				}
			}
		} );
	}
	window.addEventListener( 'resize', drawWave );
	window
		.matchMedia( '(prefers-color-scheme: dark)' )
		.addEventListener( 'change', () => {
			drawWave();
			if ( i >= 0 ) {
				setTitle( queue.tracks[ i ].title );
			}
		} );

	// The seek line follows the audio every frame while it plays (compositor transforms only); nothing trails.
	let progressRaf = 0;
	const follow = () => {
		const d = audio.duration || queue?.tracks[ i ]?.duration;
		if ( ! seeking && d ) {
			setProgress( Math.min( playhead() / d, 1 ) );
		}
		if ( looper && ! audio.paused ) {
			paint(); // the element's clock is not the ear's while the looper runs
		}
		progressRaf = audio.paused ? 0 : requestAnimationFrame( follow );
	};
	function paint( force = false ) {
		const now = playhead(),
			d = audio.duration || queue?.tracks[ i ]?.duration,
			sec = Math.floor( now );
		if ( ! seeking && d && ! progressRaf ) {
			setProgress( Math.min( now / d, 1 ) );
		}
		if ( sec === lastSec && ! force ) {
			return;
		}
		lastSec = sec;
		if ( ! seeking ) {
			seek.value = Math.round( ( d ? now / d : 0 ) * 1000 );
			cur.textContent = fmt( now );
			dur.textContent = remaining( d, now );
			if ( d ) {
				seek.setAttribute(
					'aria-valuetext',
					`${ fmt( now ) } / ${ fmt( d ) }`
				);
			}
		}
		if ( S.confetti && /^\d+$/.test( S.confetti ) ) {
			cur.classList.toggle(
				'is-lucky',
				sec % 60 === Number( S.confetti ) % 60
			);
		}
		syncLyrics( now );
		syncNotes( now );
	}
	let noteShown = null;
	const fmtDate = ( d ) =>
		d
			? new Date( `${ d }T00:00` ).toLocaleDateString( undefined, {
					month: 'short',
					day: 'numeric',
			  } )
			: '';
	function syncNotes( t, force = false ) {
		const notes = queue?.tracks[ i ]?.notes || [];
		const n = notes.find( ( x ) => t >= x.t && t < x.t + 6 ) || null;
		if ( n === noteShown && ! force ) {
			return;
		}
		noteShown = n;
		nowTitle.classList.toggle( 'is-note', !! n );
		if ( n ) {
			setTitle( n.text, fmtDate( n.date ) );
		} else if ( i >= 0 ) {
			setTitle( queue.tracks[ i ].title );
		}
	}
	function positionState() {
		if (
			! ( 'mediaSession' in navigator ) ||
			! navigator.mediaSession.setPositionState
		) {
			return;
		}
		const d = audio.duration;
		if ( ! isFinite( d ) || ! d ) {
			return;
		}
		try {
			navigator.mediaSession.setPositionState( {
				duration: d,
				playbackRate: audio.playbackRate || 1,
				position: Math.min( playhead(), d ),
			} );
		} catch {}
	}
	// Now Playing's artwork and wash. Both come off the set, since a set has one cover; a per-track
	// cover would be a data-model change, not a rendering one.
	function paintCover() {
		const img = $( 'deck-cover' );
		if ( ! img ) {
			return;
		}
		const src = queue?.cover || '';
		if ( src && img.getAttribute( 'src' ) !== src ) {
			img.src = src;
			if ( queue.srcset ) {
				img.srcset = queue.srcset;
			} else {
				img.removeAttribute( 'srcset' );
			}
		} else if ( ! src ) {
			img.removeAttribute( 'src' );
			img.removeAttribute( 'srcset' );
		}
		deck.style.setProperty( '--tint', queue?.tint || 'transparent' );
	}
	function load( n, { play = true, at = 0 } = {} ) {
		if ( ! queue?.tracks.length ) {
			return;
		}
		i = ( n + queue.tracks.length ) % queue.tracks.length;
		const t = queue.tracks[ i ];
		audio.src = t.url;
		if ( at ) {
			audio.currentTime = at;
		}
		deck.hidden = false;
		document.body.classList.add( 'has-deck' );
		noteShown = null;
		nowTitle.classList.remove( 'is-note' );
		setTitle( t.title );
		dur.textContent = remaining( t.duration, 0 );
		lastSec = -1;
		setProgress( 0 );
		clearLoop();
		holdStop();
		paint( true );
		renderSheet( t.id );
		paintMarks( t );
		drawWave();
		syncRows();
		/**
		 * A track loaded into the deck, playing or not. Detail: { set, track, index }.
		 */
		doAction( 'callboard.track', {
			set: queue.slug,
			track: snapshot( t ),
			index: i,
		} );
		renderNowPlayingMeta();
		if ( play ) {
			ensureAnalyser();
			// An extension can hold the start: the count-in taps four beats first. Play pressed while
			// one holds skips the wait (see the toggle below).
			const start = holdStart( {
				set: queue.slug,
				track: snapshot( t ),
				index: i,
				at,
			} );
			morph( start === true ? 'pause' : 'play' );
			if ( start === true ) {
				audio.play().catch( () => {} );
			} else if ( start ) {
				start.then( ( ok ) => ok && audio.play().catch( () => {} ) );
			}
		}
		// The tab strip is the lock screen for a laptop, so it learns the track at the same moment.
		paintTab();
		paintCover();
		const from = $( 'deck-from-set' );
		if ( from ) {
			from.textContent = queue?.name || '';
		}
		if ( 'mediaSession' in navigator ) {
			navigator.mediaSession.metadata = new MediaMetadata( {
				title: t.title,
				// The lock screen wants who made it. A set gathered from one source has no per-track
				// artist, and there the set's own name is the truthful answer.
				artist: t.artist || queue.name,
				album: queue.name,
				artwork: queue.art || [],
			} );
			navigator.mediaSession.setActionHandler( 'previoustrack', prev );
			navigator.mediaSession.setActionHandler( 'nexttrack', () =>
				load( i + 1 )
			);
			navigator.mediaSession.setActionHandler( 'play', () =>
				audio.play().catch( () => {} )
			);
			navigator.mediaSession.setActionHandler( 'pause', () =>
				audio.pause()
			);
			navigator.mediaSession.setActionHandler( 'seekbackward', ( d ) => {
				audio.currentTime = Math.max(
					0,
					audio.currentTime - ( d.seekOffset || 10 )
				);
			} );
			navigator.mediaSession.setActionHandler( 'seekforward', ( d ) => {
				audio.currentTime = Math.min(
					audio.duration || Infinity,
					audio.currentTime + ( d.seekOffset || 10 )
				);
			} );
			try {
				navigator.mediaSession.setActionHandler( 'stop', () => {
					audio.pause();
					audio.currentTime = 0;
				} );
			} catch {}
			try {
				navigator.mediaSession.setActionHandler( 'seekto', ( d ) => {
					audio.currentTime = d.seekTime;
				} );
			} catch {}
		}
		remember();
	}
	function dismissDeck() {
		if ( i < 0 ) {
			return;
		}
		holdStop();
		remember();
		audio.pause();
		audio.removeAttribute( 'src' );
		audio.load();
		i = -1;
		queue = null;
		clearLoop();
		eqStop();
		deck.classList.remove( 'playing' );
		deck.hidden = true;
		document.body.classList.remove( 'has-deck' );
		paintTab();
		if ( sheetOpen() ) {
			hideLyrics();
		}
		syncRows();
		paintTip();
	}
	// Idle and out of the way: a tap that lands on plain page background (not a control, a link, or a track
	// row — those already do their own thing) dismisses a paused deck, the same as Escape below.
	document.addEventListener( 'click', ( e ) => {
		if (
			deck.hidden ||
			! audio.paused ||
			i < 0 ||
			deck.contains( e.target ) ||
			e.target.closest( 'a, button, input, label, .track' )
		) {
			return;
		}
		dismissDeck();
	} );
	// Swipe from the left edge to go back. Only in an iOS Home Screen app, which has no back gesture;
	// `navigator.standalone` is iOS-only. Browsers and Android already have back.
	let edge = null,
		quietBack = false; // set when the swipe already animated, so popstate skips the view transition
	document.addEventListener( 'pointerdown', ( e ) => {
		if (
			navigator.standalone !== true ||
			e.pointerType === 'mouse' ||
			! view() ||
			e.clientX > 24 ||
			deck.classList.contains( 'is-expanded' ) ||
			sheetOpen()
		) {
			return;
		}
		edge = {
			x: e.clientX,
			y: e.clientY,
			id: e.pointerId,
			main: $( 'main' ),
		};
		edge.main.style.transition = 'none';
	} );
	document.addEventListener( 'pointermove', ( e ) => {
		if ( ! edge || e.pointerId !== edge.id ) {
			return;
		}
		const dx = e.clientX - edge.x;
		if ( Math.abs( e.clientY - edge.y ) > 60 ) {
			edge.main.style.transform = '';
			edge = null;
			return;
		}
		if ( dx > 0 ) {
			edge.main.style.transform = `translateX(${ dx.toFixed( 0 ) }px)`;
		}
	} );
	const edgeEnd = ( e ) => {
		if ( ! edge || e.pointerId !== edge.id ) {
			return;
		}
		const main = edge.main,
			dx = e.clientX - edge.x;
		edge = null;
		main.style.transition = '';
		if ( dx > Math.min( 120, window.innerWidth / 3 ) ) {
			haptic(); // inside the pointerup, which counts as an activation
			main.animate(
				[
					{ transform: main.style.transform },
					{ transform: 'translateX(100%)' },
				],
				{ duration: 200, easing: 'cubic-bezier(.2,.8,.2,1)' }
			).onfinish = () => {
				main.style.transform = '';
				// Go back if the previous entry is ours, otherwise go home.
				if ( history.state?.fromApp ) {
					quietBack = true;
					history.back();
				} else {
					go( G.home, true, false ); // the drag was the transition
				}
			};
			return;
		}
		main.animate(
			[ { transform: main.style.transform }, { transform: 'none' } ],
			{
				duration: 200,
				easing: 'cubic-bezier(.34,1.4,.64,1)',
			}
		).onfinish = () => {
			main.style.transform = '';
		};
	};
	document.addEventListener( 'pointerup', edgeEnd );
	document.addEventListener( 'pointercancel', edgeEnd );

	function prev() {
		if ( i < 0 ) {
			return load( 0 );
		}
		if ( audio.currentTime > 3 ) {
			audio.currentTime = 0;
		} else {
			load( i - 1 );
		}
	}
	function startSet( set, n, opts ) {
		if ( queue?.slug !== set.slug ) {
			queue = set;
		}
		load( n, opts );
	}

	$( 'prev' ).addEventListener( 'click', () => {
		haptic();
		prev();
	} );
	$( 'next' ).addEventListener( 'click', () => {
		haptic();
		load( i < 0 ? 0 : i + 1 );
	} );
	toggle.addEventListener( 'click', () => {
		haptic();
		if ( holdStop() ) {
			morph( 'pause' );
			return audio.play().catch( () => {} );
		}
		if ( i >= 0 ) {
			morph( audio.paused ? 'pause' : 'play' ); // answer the tap now; the audio events reconcile
		}
		if ( i < 0 ) {
			return load( 0 );
		}
		return audio.paused ? audio.play().catch( () => {} ) : audio.pause();
	} );

	// ---- Repeat: off, the set (wraps and keeps going), or one track. A running order is not a shuffle, so
	// that is the only other transport mode; the A-B loop beside it is a different feature (a section of one
	// track), wired further down where the rest of that gesture lives.
	const REPEAT_KEY = 'callboard:repeat';
	const repeatBtn = $( 'repeat' );
	let repeatMode = [ 'off', 'set', 'one' ].includes( ls.get( REPEAT_KEY ) )
		? ls.get( REPEAT_KEY )
		: 'off';
	function paintRepeat() {
		if ( ! repeatBtn ) {
			return;
		}
		repeatBtn.dataset.mode = repeatMode;
		repeatBtn.setAttribute(
			'aria-pressed',
			repeatMode === 'off' ? 'false' : 'true'
		);
		repeatBtn.setAttribute(
			'aria-label',
			repeatMode === 'one'
				? repeatBtn.dataset.labelOne
				: repeatMode === 'set'
				? repeatBtn.dataset.labelSet
				: repeatBtn.dataset.labelOff
		);
	}
	paintRepeat();
	repeatBtn?.addEventListener( 'click', () => {
		haptic();
		repeatMode =
			repeatMode === 'off' ? 'set' : repeatMode === 'set' ? 'one' : 'off';
		ls.set( REPEAT_KEY, repeatMode );
		paintRepeat();
	} );

	// ---- Holding the start. Extensions contribute to callboard.beforePlay: each gets the track about to
	// play and a signal, and returns nothing to let it start, false to stop it, or a promise of either. They
	// run one after another in priority order, and the track starts once every one has said yes. Play
	// pressed while one is waiting, another track, or a dismissed deck aborts the signal.
	let starting = null;
	function holdStop() {
		if ( ! starting ) {
			return false;
		}
		const h = starting;
		starting = null;
		h.abort();
		return true;
	}
	function holdStart( context ) {
		holdStop();
		/**
		 * Contributions that may hold the start of a track: [ { id, callback( context, signal ) } ]. Context: { set, track, index, at }.
		 */
		const gates = applyFilters( 'callboard.beforePlay', [], context );
		if ( ! Array.isArray( gates ) || ! gates.length ) {
			return true;
		}
		const ctl = new AbortController();
		let k = 0;
		const step = () => {
			for ( ; k < gates.length; k++ ) {
				const gate = gates[ k ];
				const fn = typeof gate === 'function' ? gate : gate?.callback;
				let out;
				try {
					out = fn?.( context, ctl.signal );
				} catch ( err ) {
					report( gate?.id || 'callboard.beforePlay', err );
					continue;
				}
				if ( out === false ) {
					return false;
				}
				if ( out && typeof out.then === 'function' ) {
					k++;
					return Promise.resolve( out ).then(
						( ok ) =>
							ok !== false && ! ctl.signal.aborted && step(),
						( err ) => {
							report( gate?.id || 'callboard.beforePlay', err );
							return ! ctl.signal.aborted && step();
						}
					);
				}
			}
			return true;
		};
		const result = step();
		if ( result && typeof result.then === 'function' ) {
			starting = ctl;
			result.finally( () => {
				if ( starting === ctl ) {
					starting = null;
				}
			} );
		}
		return result;
	}
	// What the title line shows while an extension holds it (the count, say). Null gives it back.
	let shown = '';
	function display( text, { detail = '', className = '' } = {} ) {
		if ( shown ) {
			nowTitle.classList.remove( ...shown.split( ' ' ) );
			shown = '';
		}
		if ( text === null || text === undefined ) {
			if ( i >= 0 ) {
				setTitle( queue.tracks[ i ].title );
			}
			return;
		}
		shown = String( className )
			.split( /\s+/ )
			.filter( ( c ) => /^[a-z][\w-]*$/i.test( c ) )
			.join( ' ' );
		if ( shown ) {
			nowTitle.classList.add( ...shown.split( ' ' ) );
		}
		setTitle( String( text ), String( detail || '' ) );
	}
	// Play or pause: a state on the button; the stylesheet slides the glyph's points between the two shapes.
	const morph = ( to ) => {
		if ( toggle.dataset.state === to ) {
			return;
		}
		toggle.dataset.state = to;
		toggle.setAttribute(
			'aria-label',
			to === 'pause' ? T.pause : T.resume
		);
	};
	// ---- One player per site. Playing takes a lock; a tab that starts playing steals it and the loser pauses.
	// A new track in this same tab also steals from its own earlier request, so a request only pauses the
	// element when it is still the latest one: that is a theft by another tab, not by ourselves.
	let releaseLock = null,
		lockTicket = 0;
	audio.addEventListener( 'play', () => {
		if ( ! navigator.locks ) {
			return;
		}
		const ticket = ++lockTicket;
		navigator.locks
			.request(
				'callboard:player',
				{ steal: true },
				() => new Promise( ( done ) => ( releaseLock = done ) )
			)
			.catch( () => {
				if ( ticket === lockTicket ) {
					releaseLock = null;
					audio.pause(); // stolen by another tab: it is the player now
				}
			} );
	} );
	audio.addEventListener( 'pause', () => {
		releaseLock?.();
		releaseLock = null;
		looperStop();
	} );
	audio.addEventListener( 'play', () => {
		if ( loop ) {
			looperStart();
		}
	} );
	const playing = () => ( {
		set: queue?.slug || '',
		track: snapshot( queue?.tracks[ i ] ),
		index: i,
		position: audio.currentTime || 0,
	} );
	/**
	 * The element started playing. Detail: { set, track, index, position }.
	 */
	audio.addEventListener( 'play', () =>
		doAction( 'callboard.play', playing() )
	);
	/**
	 * The element paused. Detail: { set, track, index, position }.
	 */
	audio.addEventListener( 'pause', () =>
		doAction( 'callboard.pause', playing() )
	);
	/**
	 * A track played to its end. Detail: { set, track, index, position }.
	 */
	audio.addEventListener( 'ended', () =>
		doAction( 'callboard.ended', playing() )
	);
	// Where a seek started is where the playhead last was: by the time the element says it is
	// seeking, currentTime already reads the destination.
	let seekFrom = 0;
	audio.addEventListener( 'timeupdate', () => {
		if ( ! audio.seeking ) {
			seekFrom = audio.currentTime;
		}
	} );
	audio.addEventListener( 'seeked', () => {
		/**
		 * The playhead moved by a seek. Detail: { from, to } in seconds.
		 */
		doAction( 'callboard.seek', {
			from: seekFrom,
			to: audio.currentTime,
		} );
		seekFrom = audio.currentTime;
	} );
	audio.addEventListener( 'play', () => {
		played = true;
		cancelAnimationFrame( progressRaf );
		follow();
		morph( 'pause' );
		deck.classList.add( 'playing' );
		syncRows();
		positionState();
		paintTab();
	} );
	audio.addEventListener( 'pause', () => {
		morph( 'play' );
		deck.classList.remove( 'playing', 'buffering' );
		syncRows();
		remember();
		positionState();
		paint( true );
		paintTab();
	} );
	audio.addEventListener( 'waiting', () =>
		deck.classList.add( 'buffering' )
	);
	audio.addEventListener( 'playing', () =>
		deck.classList.remove( 'buffering' )
	);
	audio.addEventListener( 'canplay', () =>
		deck.classList.remove( 'buffering' )
	);
	audio.addEventListener( 'ended', () => {
		if ( repeatMode === 'one' ) {
			return load( i, { at: 0 } );
		}
		if ( repeatMode === 'off' && i === queue.tracks.length - 1 ) {
			// The set played through. Forget where we were: a finished set that still says "left off at"
			// the last track is telling you to resume something you just heard the end of.
			ls.del( key() );
			paintHomeResume();
			return; // nothing repeats without being asked to
		}
		load( i + 1 );
	} );
	audio.addEventListener( 'loadedmetadata', () => {
		if ( isFinite( audio.duration ) ) {
			dur.textContent = remaining( audio.duration, audio.currentTime );
		}
		positionState();
	} );
	audio.addEventListener( 'timeupdate', () => {
		if ( audio.currentTime > 0 ) {
			deck.classList.remove( 'buffering' );
		}
		if ( loop && audio.currentTime >= loop.b ) {
			audio.currentTime = loop.a;
		}
		paint();
		if ( ( audio.currentTime | 0 ) % 5 === 0 ) {
			remember();
			positionState();
		}
	} );
	audio.addEventListener( 'seeked', () => {
		paint( true );
		positionState();
	} );
	// ---- Remote playback: AirPlay in Safari, Cast in Chrome. The chip shows while a device is in reach and
	// the picker is the browser's own. Safari has no availability watcher for audio; there the chip stays
	// and the picker says what it finds.
	const remoteBtn = $( 'remote' );
	if ( remoteBtn && audio.remote ) {
		const paintRemote = () => {
			const state = audio.remote.state || 'disconnected';
			remoteBtn.dataset.state = state === 'disconnected' ? '' : state;
			deck.classList.toggle( 'remote', state !== 'disconnected' );
			remoteBtn.setAttribute(
				'aria-label',
				state === 'disconnected' ? T.remote : T.remote_on
			);
		};
		audio.remote
			.watchAvailability( ( ok ) => {
				remoteBtn.classList.toggle( 'is-away', ! ok ); // hides in its slot; the row never shifts
			} )
			.catch( () => {
				remoteBtn.classList.remove( 'is-away' );
			} );
		[ 'connecting', 'connect', 'disconnect' ].forEach( ( ev ) =>
			audio.remote.addEventListener( ev, paintRemote )
		);
		remoteBtn.addEventListener( 'click', () => {
			haptic();
			audio.remote.prompt().catch( () => {} ); // cancelled, or nothing in reach: the picker already said so
		} );
	}

	// Safari restores pages from the back/forward cache with their script state frozen mid-thought. On a
	// restore, the deck reads the element again rather than trusting what it last drew.
	window.addEventListener( 'pageshow', ( e ) => {
		if ( e.persisted ) {
			morph( audio.paused ? 'play' : 'pause' );
			positionState();
		}
	} );

	// The deck's height is a token the page padding and the lyrics sheet read. Measured rather than assumed,
	// so Dynamic Type on iPhone, a landscape inset, or a longer row never leaves the last track under the deck.
	if ( window.ResizeObserver ) {
		new ResizeObserver( () => {
			if ( deck.hidden ) {
				return;
			}
			const h =
				deck.offsetHeight -
				parseFloat( getComputedStyle( deck ).paddingBottom ); // the overscroll run-off and the safe area are not height
			if ( h > 80 ) {
				document.documentElement.style.setProperty(
					'--deck-h',
					`${ Math.round( h ) }px`
				);
			}
		} ).observe( deck );
	}

	// ---- Marks on the seek line: ticks where singing resumes after a rest (from the lyrics), pins for director notes
	const marks = $( 'seek-marks' );
	let ticks = [];
	function paintMarks( t ) {
		if ( ! marks ) {
			return;
		}
		marks.innerHTML = '';
		ticks = [];
		const d = t.duration || 0;
		if ( ! d ) {
			return;
		}
		const cuesFor = ( queue?.lyrics && queue.lyrics[ t.id ] ) || [];
		let prevEnd = -10;
		cuesFor.forEach( ( [ s, e ] ) => {
			if (
				s - prevEnd >= 3 &&
				s > 1.5 &&
				s < d - 1.5 &&
				ticks.length < 40
			) {
				ticks.push( s );
			}
			prevEnd = e;
		} );
		( t.notes || [] ).forEach( ( n ) => ticks.push( n.t ) );
		ticks.forEach( ( at ) => {
			const el = document.createElement( 'i' );
			el.className = 'tick';
			el.style.left = `${ ( ( at / d ) * 100 ).toFixed( 2 ) }%`;
			marks.appendChild( el );
		} );
		( t.notes || [] ).forEach( ( n ) => {
			const el = document.createElement( 'i' );
			el.className = 'pin';
			el.dataset.t = n.t;
			el.style.left = `${ ( ( n.t / d ) * 100 ).toFixed( 2 ) }%`;
			marks.appendChild( el );
		} );
	}
	marks?.addEventListener( 'click', ( e ) => {
		const pin = e.target.closest( '.pin' );
		if ( ! pin ) {
			return;
		}
		audio.currentTime = +pin.dataset.t;
		audio.play().catch( () => {} );
	} );
	const settle = ( t, d ) => {
		const near = ticks.find( ( x ) => Math.abs( x - t ) < d * 0.02 );
		if ( near !== undefined && near !== t ) {
			haptic();
		}
		return near === undefined ? t : near;
	};

	// ---- A-B loop: hold two fingers on the seek line; on a keyboard [ and ] set the ends, \ clears
	let loop = null,
		loopGesture = false;
	// ---- Gapless loop. Resetting currentTime leaves a seam at the join; an AudioBufferSourceNode loops
	// sample-accurately. It runs only while the page is visible and at full speed (Web Audio has no
	// preservesPitch). The element keeps playing muted underneath, so the media session, lock-screen controls
	// and background play are exactly what they were; when the tab hides, the speed changes or the loop
	// clears, the element takes the sound back at the looper's position.
	const canLoopGapless = () =>
		'AudioContext' in window && document.visibilityState === 'visible';
	const playhead = () => {
		if ( ! looper?.src || ! loop ) {
			return audio.currentTime; // no looper yet, or its audio is still being fetched and decoded
		}
		const len = loop.b - loop.a,
			t =
				looper.offset -
				loop.a +
				( looper.ctx.currentTime - looper.startedAt );
		return loop.a + ( ( ( t % len ) + len ) % len );
	};
	async function looperStart() {
		if ( ! loop || looper || ! canLoopGapless() || audio.paused ) {
			return;
		}
		const url = audio.currentSrc || audio.src;
		const ticket = { pending: true };
		looper = ticket;
		try {
			looperCtx = looperCtx || new AudioContext();
			if ( looperCtx.state === 'suspended' ) {
				await looperCtx.resume();
			}
			if ( looperBuf?.url !== url ) {
				const bytes = await ( await fetch( url ) ).arrayBuffer();
				looperBuf = {
					url,
					buffer: await looperCtx.decodeAudioData( bytes ),
				};
			}
		} catch {
			if ( looper === ticket ) {
				looper = null; // no decode here: the element's own loop stands
			}
			return;
		}
		if ( looper !== ticket ) {
			return; // the loop changed while this one was decoding, and a newer start is on its way
		}
		if (
			! loop ||
			audio.paused ||
			! canLoopGapless() ||
			looperCtx.state !== 'running' // autoplay policy kept the context shut: never mute the element for a silent looper
		) {
			looper = null;
			return;
		}
		const src = looperCtx.createBufferSource();
		src.buffer = looperBuf.buffer;
		src.loop = true;
		src.loopStart = loop.a;
		src.loopEnd = Math.min( loop.b, looperBuf.buffer.duration );
		src.connect( analyser || looperCtx.destination );
		const from = Math.min(
			Math.max( audio.currentTime, loop.a ),
			src.loopEnd - 0.05
		);
		src.start( 0, from );
		audio.muted = true;
		looper = {
			ctx: looperCtx,
			src,
			startedAt: looperCtx.currentTime,
			offset: from,
		};
	}
	function looperStop() {
		if ( ! looper ) {
			return;
		}
		const pos = looper.src ? playhead() : null;
		try {
			looper.src?.stop();
		} catch {}
		looper = null;
		audio.muted = false;
		if ( pos !== null && loop ) {
			audio.currentTime = pos; // the element picks up where the ear left off
		}
	}
	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'visible' ) {
			looperStart();
		} else {
			looperStop();
		}
	} );
	const loopBand = $( 'loop-band' ),
		loopChip = $( 'loop' );
	const trackDur = () => audio.duration || queue?.tracks[ i ]?.duration || 0;
	function setLoop( a, b ) {
		const d = trackDur();
		a = Math.max( 0, a );
		b = Math.min( d || b, b );
		if ( ! d || b - a < 1 ) {
			return;
		}
		loop = { a, b };
		haptic();
		loopBand.style.transform = `translateX(${ ( ( a / d ) * 100 ).toFixed(
			2
		) }%) scaleX(${ ( ( b - a ) / d ).toFixed( 4 ) })`;
		loopBand.classList.add( 'on' );
		if ( loopChip ) {
			loopChip.dataset.state = 'on';
			loopChip.setAttribute( 'aria-label', loopChip.dataset.labelOn );
		}
		requestAnimationFrame( () => syncNotes( audio.currentTime, true ) );
		if ( audio.currentTime < a || audio.currentTime > b ) {
			audio.currentTime = a;
		}
		looperStop();
		audio.play().catch( () => {} );
		looperStart();
		keepAwake(); // a loop means the phone is on the stand
		/**
		 * An A-B loop was set ({ a, b } in seconds) or cleared (null).
		 */
		doAction( 'callboard.loop', { a, b } );
	}
	let loopFrom = null;
	function clearLoop() {
		if ( loop ) {
			haptic();
			doAction( 'callboard.loop', null );
		}
		looperStop();
		if ( ! sheetOpen() ) {
			letSleep();
		}
		loop = null;
		loopFrom = null;
		if ( loopBand ) {
			loopBand.classList.remove( 'on' );
			if ( loopChip ) {
				loopChip.dataset.state = '';
				loopChip.setAttribute(
					'aria-label',
					loopChip.dataset.labelOff
				);
			}
			requestAnimationFrame( () => syncNotes( audio.currentTime, true ) );
		}
	}
	// One control, three taps: mark the start, mark the end, clear. Two fingers on the line or [ ] do the same.
	loopChip?.addEventListener( 'click', () => {
		if ( loop ) {
			return clearLoop();
		}
		if ( loopFrom === null ) {
			haptic();
			loopFrom = audio.currentTime;
			if ( loopChip ) {
				loopChip.dataset.state = 'armed';
				loopChip.setAttribute(
					'aria-label',
					loopChip.dataset.labelArmed
				);
			}
			return;
		}
		const a = Math.min( loopFrom, audio.currentTime ),
			b = Math.max( loopFrom, audio.currentTime );
		loopFrom = null;
		if ( b - a < 1 ) {
			return clearLoop(); // the same spot twice: nothing to loop
		}
		setLoop( a, b );
	} );
	const seekWrap = document.querySelector( '.seek-wrap' ),
		pointers = new Map();
	let loopHold = 0;
	seekWrap?.addEventListener(
		'pointerdown',
		( e ) => {
			pointers.set( e.pointerId, e.clientX );
			if ( pointers.size === 2 ) {
				clearTimeout( loopHold );
				loopHold = setTimeout( () => {
					const rect = seek.getBoundingClientRect(),
						half = seekThumb() / 2, // measure against the line, not the wider input
						d = trackDur();
					const r = ( x ) =>
						Math.min(
							1,
							Math.max(
								0,
								( x - rect.left - half ) /
									( rect.width - half * 2 )
							)
						);
					const xs = [ ...pointers.values() ].map( r );
					loopGesture = true;
					seeking = false;
					deck.classList.remove( 'seeking' );
					setLoop( Math.min( ...xs ) * d, Math.max( ...xs ) * d );
				}, 300 );
			}
		},
		true
	);
	const lift = ( e ) => {
		pointers.delete( e.pointerId );
		if ( pointers.size < 2 ) {
			clearTimeout( loopHold );
		}
		if ( ! pointers.size ) {
			setTimeout( () => {
				loopGesture = false;
			}, 50 );
		}
	};
	seekWrap?.addEventListener( 'pointermove', ( e ) => {
		// the hover preview follows the pointer; a CSS variable, so no repaint of the bars
		const r = seekWrap.getBoundingClientRect();
		seekWrap.style.setProperty(
			'--hx',
			`${ Math.min(
				100,
				Math.max( 0, ( ( e.clientX - r.left ) / r.width ) * 100 )
			).toFixed( 2 ) }%`
		);
	} );
	seekWrap?.addEventListener( 'pointerup', lift, true );
	seekWrap?.addEventListener( 'pointercancel', lift, true );

	seek.addEventListener( 'input', () => {
		if ( loopGesture || pointers.size > 1 ) {
			return;
		}
		seeking = true;
		deck.classList.add( 'seeking' );
		const d = audio.duration || queue?.tracks[ i ]?.duration || 0,
			r = seek.value / 1000;
		setProgress( r );
		cur.textContent = fmt( r * d );
		dur.textContent = remaining( d, r * d );
		seek.setAttribute(
			'aria-valuetext',
			`${ fmt( r * d ) } / ${ fmt( d ) }`
		);
	} );
	seek.addEventListener( 'change', () => {
		const d = audio.duration || queue?.tracks[ i ]?.duration;
		if ( loopGesture ) {
			paint( true );
			return;
		}
		if ( d ) {
			audio.currentTime = settle( ( seek.value / 1000 ) * d, d );
		}
		seeking = false;
		deck.classList.remove( 'seeking' );
		paint( true );
	} );
	seek.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) {
			e.preventDefault();
			audio.currentTime += e.key === 'ArrowRight' ? 5 : -5;
		}
	} );
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.target.matches( 'input,select,textarea' ) ) {
			return;
		}
		if ( e.key === ' ' ) {
			e.preventDefault();
			toggle.click();
		} else if ( e.key === 'ArrowRight' && e.shiftKey ) {
			load( i + 1 );
		} else if ( e.key === 'ArrowLeft' && e.shiftKey ) {
			prev();
		} else if ( e.key === 'ArrowRight' ) {
			audio.currentTime += 5;
		} else if ( e.key === 'ArrowLeft' ) {
			audio.currentTime -= 5;
		} else if ( e.key === '[' ) {
			setLoop( audio.currentTime, loop ? loop.b : trackDur() );
		} else if ( e.key === ']' ) {
			setLoop( loop ? loop.a : 0, audio.currentTime );
		} else if ( e.key === '\\' ) {
			clearLoop();
		} else if ( e.key === 'Escape' && sheetOpen() ) {
			hideLyrics();
		} else if (
			e.key === 'Escape' &&
			deck.classList.contains( 'is-expanded' )
		) {
			closeNowPlaying(); // Escape closes lyrics, then Now Playing, then a paused player bar
		} else if ( e.key === 'Escape' && audio.paused && i >= 0 ) {
			dismissDeck();
		}
	} );

	// ---- Lyrics (only where a set has approved lyrics); otherwise the deck title finds the playing track.
	// The cues also live on the media element as a metadata text track, so the browser fires cuechange for
	// them, on time even when the tab is throttled, and a seek lands on the right line without a scan.
	const lyricTrack =
		'VTTCue' in window && audio.addTextTrack
			? audio.addTextTrack( 'metadata', 'Lyrics' )
			: null;
	if ( lyricTrack ) {
		lyricTrack.mode = 'hidden';
		lyricTrack.addEventListener( 'cuechange', () => {
			const active = lyricTrack.activeCues;
			if ( active && active.length ) {
				markCue( Number( active[ active.length - 1 ].id ) );
			}
		} );
	}
	function loadCues() {
		if ( ! lyricTrack ) {
			return;
		}
		Array.from( lyricTrack.cues || [] ).forEach( ( c ) =>
			lyricTrack.removeCue( c )
		);
		// each cue runs until the next begins, so exactly one is active and the last line holds to the end
		cues.forEach( ( [ start, , text ], k ) => {
			const end =
				k + 1 < cues.length
					? cues[ k + 1 ][ 0 ]
					: trackDur() || start + 3600;
			if ( end > start ) {
				const cue = new VTTCue( start, end, text );
				cue.id = String( k );
				lyricTrack.addCue( cue );
			}
		} );
	}
	function renderSheet( id ) {
		cues = ( queue?.lyrics && queue.lyrics[ id ] ) || [];
		const notes = queue?.tracks[ i ]?.notes || [];
		cueIdx = -1;
		lyricsList.innerHTML = '';
		sheetKind = cues.length ? 'lyrics' : notes.length ? 'notes' : '';
		if ( sheetKind ) {
			openLyrics.dataset.sheet =
				sheetKind === 'lyrics' ? T.lyrics_label : T.notes;
		} else {
			delete openLyrics.dataset.sheet;
		}
		// Who the track is by, otherwise the set's name, otherwise the sheet's — never blank, so the
		// deck keeps a constant height. The sheet's name is the last resort now that Now Playing has
		// a pill that says it: two controls a thumb apart both reading "Notes" is one too many.
		openLyrics.dataset.line =
			queue?.tracks[ i ]?.artist ||
			queue?.name ||
			openLyrics.dataset.sheet ||
			'';
		syncOpenLyricsA11y();
		$( 'sheet-label' ).textContent =
			sheetKind === 'notes' ? T.notes_sheet : T.lyrics_sheet;
		const item = ( at, text, detail ) => {
			const li = document.createElement( 'li' );
			if ( detail !== undefined ) {
				const time = document.createElement( 'time' );
				time.textContent = fmt( at );
				li.appendChild( time );
			}
			li.appendChild( document.createTextNode( text ) );
			if ( detail ) {
				const d = document.createElement( 'small' );
				d.textContent = detail;
				li.appendChild( d );
			}
			li.tabIndex = 0;
			li.addEventListener( 'click', () => {
				audio.currentTime = at;
				audio.play().catch( () => {} );
			} );
			lyricsList.appendChild( li );
			return li;
		};
		cueEls = cues.length
			? cues.map( ( [ s, , text ] ) => item( s, text ) )
			: notes.map( ( n ) => item( n.t, n.text, fmtDate( n.date ) ) );
		loadCues();
	}
	function syncLyrics( t ) {
		if ( ! cues.length ) {
			return;
		}
		let n = -1;
		for ( let k = 0; k < cues.length; k++ ) {
			if ( t >= cues[ k ][ 0 ] ) {
				n = k;
			} else {
				break;
			}
		}
		markCue( n );
	}
	function markCue( n ) {
		if ( n === cueIdx ) {
			return;
		}
		cueIdx = n;
		cueEls.forEach( ( el, k ) => {
			el.classList.toggle( 'now', k === n );
			el.classList.toggle( 'past', k < n );
		} );
		if ( n >= 0 && sheetOpen() ) {
			cueEls[ n ].scrollIntoView( {
				block: 'center',
				behavior: 'smooth',
			} );
		}
	}
	let wake = null;
	const keepAwake = async () => {
		try {
			wake = await navigator.wakeLock?.request( 'screen' );
		} catch {}
	};
	const letSleep = () => {
		wake?.release().catch( () => {} );
		wake = null;
	};
	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'visible' && sheetOpen() ) {
			keepAwake();
		}
	} );
	let sheetTimer = 0;
	function showLyrics() {
		keepAwake();
		clearTimeout( sheetTimer );
		lyricsSheet.classList.remove( 'closing' );
		if ( ! sheetOpen() ) {
			if ( lyricsSheet.show ) {
				lyricsSheet.show(); // non-modal, so the player controls stay usable
			} else {
				lyricsSheet.setAttribute( 'open', '' );
			}
		}
		document.body.classList.add( 'sheet-open' );
		syncOpenLyricsA11y();
		if ( cueIdx >= 0 ) {
			cueEls[ cueIdx ].scrollIntoView( { block: 'center' } );
		}
		$( 'close-lyrics' ).focus();
	}
	const closeSheet = () => {
		lyricsSheet.classList.remove( 'closing' );
		if ( lyricsSheet.close ) {
			lyricsSheet.close();
		} else {
			lyricsSheet.removeAttribute( 'open' );
		}
	};
	function hideLyrics() {
		letSleep();
		document.body.classList.remove( 'sheet-open' );
		if ( reduce() || ! sheetOpen() ) {
			closeSheet();
		} else {
			lyricsSheet.classList.add( 'closing' ); // slides away, then leaves the tree
			clearTimeout( sheetTimer );
			sheetTimer = setTimeout( closeSheet, 320 );
		}
		syncOpenLyricsA11y();
		openLyrics.focus();
	}
	openLyrics.addEventListener( 'click', () => {
		if ( i < 0 ) {
			return;
		}
		if ( deck.classList.contains( 'is-compact' ) ) {
			return expandDeck(); // the compact bar's whole job is this tap; the sheet/track logic below is expanded-only
		}
		haptic();
		if ( sheetKind ) {
			return sheetOpen() ? hideLyrics() : showLyrics();
		}
		if ( ! onQueuePage() ) {
			return go( `${ G.home }${ queue.slug }/` );
		}
		rows[ i ]?.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		rows[ i ]?.focus( { preventScroll: true } );
	} );
	// Tidal's "Playing from" is the way back to what you are inside, and with the bar's own tap now
	// opening Now Playing it is the only way back to the set from anywhere else.
	$( 'deck-from' )?.addEventListener( 'click', () => {
		if ( ! queue ) {
			return;
		}
		haptic();
		if ( onQueuePage() ) {
			return closeNowPlaying();
		}
		go( `${ G.home }${ queue.slug }/` ); // go() closes Now Playing and replaces its history entry
	} );
	$( 'sheet-pill' )?.addEventListener( 'click', () => {
		haptic();
		if ( ! sheetOpen() ) {
			showLyrics();
		} else {
			hideLyrics();
		}
	} );
	$( 'deck-down' )?.addEventListener( 'click', () => {
		haptic();
		closeNowPlaying();
	} );
	$( 'close-lyrics' ).addEventListener( 'click', () => {
		haptic();
		hideLyrics();
	} );

	document
		.querySelector( '.skip-link' )
		?.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			const m = $( 'main' );
			m.focus();
			requestAnimationFrame( () => {
				if ( document.activeElement !== m ) {
					m.focus(); // a late layout pass can drop the first attempt
				}
			} );
		} );

	// ---- Delegated clicks: track rows and in-app links
	document.addEventListener( 'click', ( e ) => {
		const r = e.target.closest( '.track' );
		if ( r ) {
			const set = setBy( view() ),
				n = +r.dataset.i;
			if ( ! set ) {
				return;
			}
			haptic();
			if ( onQueuePage() && n === i ) {
				if ( audio.paused ) {
					audio.play().catch( () => {} );
				} else {
					audio.pause();
				}
			} else {
				startSet( set, n );
			}
			return;
		}
		const a = e.target.closest( 'a[href]' );
		if (
			! a ||
			a.target === '_blank' ||
			a.origin !== location.origin ||
			e.metaKey ||
			e.ctrlKey ||
			e.shiftKey
		) {
			return;
		}
		if ( a.hash && a.pathname === location.pathname ) {
			return; // a fragment on this page (the skip link): the browser handles it
		}
		if ( routeOf( a.href ) === null ) {
			return;
		}
		e.preventDefault();
		if ( a.dataset.track ) {
			// a number on the board: open its set and start it
			go( a.href ).then( () => {
				const set = setBy( routeOf( a.href ) );
				if ( set ) {
					startSet( set, +a.dataset.track );
				}
			} );
			return;
		}
		go( a.href );
	} );

	// ---- Install card: one element. iOS gets the two Safari steps, Chromium gets a one-tap prompt,
	// in-app browsers get a way out to Safari. Never shown once installed or dismissed.
	const tip = $( 'a2hs' ),
		tipText = $( 'a2hs-text' ),
		tipGo = $( 'a2hs-go' );
	const tipDefault = tipText ? tipText.innerHTML : '';
	function paintTip() {
		if ( ! tip ) {
			return;
		}
		const wanted =
			S.hint &&
			! standalone &&
			! ls.get( 'callboard:a2hs' ) &&
			( isIOS || installPrompt );
		tip.hidden = ! wanted;
		if ( ! wanted ) {
			return;
		}
		if ( inApp ) {
			tipText.textContent = T.open_safari;
			tipGo.textContent = T.open_safari_go;
			tipGo.hidden = false;
			tipGo.onclick = () => {
				location.href = `x-safari-${ location.href }`;
			};
		} else if ( installPrompt ) {
			tipText.textContent = T.install;
			tipGo.textContent = T.install_go;
			tipGo.hidden = false;
			tipGo.onclick = async () => {
				const p = installPrompt;
				installPrompt = null;
				try {
					await p.prompt();
				} catch {}
				paintTip();
			};
		} else {
			tipText.innerHTML = tipDefault;
			tipGo.hidden = true;
		}
	}
	$( 'a2hs-close' )?.addEventListener( 'click', () => {
		ls.set( 'callboard:a2hs', 1 );
		paintTip();
	} );

	// ---- Online / offline
	const paintNet = () => {
		document.body.classList.toggle( 'is-offline', ! navigator.onLine );
		const b = $( 'offline' );
		if ( b && ! b.classList.contains( 'is-busy' ) ) {
			b.disabled =
				! navigator.onLine && ! b.classList.contains( 'is-done' );
		}
	};
	window.addEventListener( 'online', () => {
		paintNet();
		/**
		 * The browser came back online.
		 */
		doAction( 'callboard.online', {} );
	} );
	window.addEventListener( 'offline', () => {
		paintNet();
		/**
		 * The browser went offline.
		 */
		doAction( 'callboard.offline', {} );
	} );
	paintNet();

	// ---- Notifications (Web Push). The app badge is a contribution point: see paintBadge().
	const urlBase64ToUint8Array = ( b64 ) => {
		const s = ( b64 + '='.repeat( ( 4 - ( b64.length % 4 ) ) % 4 ) )
			.replace( /-/g, '+' )
			.replace( /_/g, '/' );
		const raw = atob( s );
		return Uint8Array.from( [ ...raw ].map( ( c ) => c.charCodeAt( 0 ) ) );
	};
	const toastEl = $( 'toast' );
	let toastTimer = 0;
	function toast( msg ) {
		if ( ! toastEl ) {
			return;
		}
		toastEl.textContent = msg;
		toastEl.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => {
			toastEl.hidden = true;
		}, 4000 );
	}
	async function bindNotify() {
		const btn = $( 'notify' );
		if ( ! btn ) {
			return;
		}
		const iosTab = isIOS && ! standalone; // Safari's tab: push needs the Home Screen app; the bell explains
		if (
			! iosTab &&
			( ! G.push ||
				! ( 'serviceWorker' in navigator ) ||
				! ( 'PushManager' in window ) ||
				! ( 'Notification' in window ) )
		) {
			btn.hidden = true; // no push in this browser at all: the one case the bell leaves
			return;
		}
		const reg = iosTab ? null : await navigator.serviceWorker.ready;
		let sub = reg ? await reg.pushManager.getSubscription() : null;
		const paintBell = () => {
			btn.dataset.state = sub ? 'on' : '';
			btn.setAttribute( 'aria-pressed', sub ? 'true' : 'false' );
			btn.setAttribute( 'aria-label', sub ? T.notify_on : T.notify );
		};
		paintBell();
		btn.onclick = async () => {
			haptic(); // now, while this is still the tap; nothing after the awaits below can
			if ( iosTab ) {
				return toast( T.notify_home );
			}
			if ( Notification.permission === 'denied' ) {
				return toast( T.notify_denied );
			}
			btn.disabled = true;
			try {
				if ( sub ) {
					await fetch( `${ G.push.api }unsubscribe`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { endpoint: sub.endpoint } ),
					} );
					await sub.unsubscribe();
					sub = null;
				} else if (
					( await Notification.requestPermission() ) !== 'granted'
				) {
					toast( T.notify_denied );
				} else {
					sub = await reg.pushManager.subscribe( {
						userVisibleOnly: true,
						applicationServerKey: urlBase64ToUint8Array(
							G.push.key
						),
					} );
					const r = await fetch( `${ G.push.api }subscribe`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( sub.toJSON() ),
					} );
					if ( ! r.ok ) {
						await sub.unsubscribe();
						sub = null;
					} else {
						toast( T.notify_on );
					}
				}
			} catch {}
			btn.disabled = false;
			paintBell();
		};
	}

	// The board's "in 2 days" is rendered by the server and refreshed here, so a copy the worker kept overnight reads right.
	function paintCalls() {
		const rel = window.Intl?.RelativeTimeFormat
			? new Intl.RelativeTimeFormat(
					document.documentElement.lang || 'en',
					{
						numeric: 'auto',
					}
			  )
			: null;
		document.querySelectorAll( '.call-rel[data-when]' ).forEach( ( el ) => {
			const s = ( new Date( el.dataset.when ) - Date.now() ) / 1000;
			if ( ! rel || Math.abs( s ) < 300 ) {
				return; // "now" from the server stands
			}
			const units = [
				[ 86400 * 7, 'week' ],
				[ 86400, 'day' ],
				[ 3600, 'hour' ],
				[ 60, 'minute' ],
			];
			const [ size, unit ] =
				units.find( ( [ n ] ) => Math.abs( s ) >= n ) || units[ 3 ];
			el.textContent = rel.format( Math.round( s / size ), unit );
		} );
	}

	// ---- The tab title. A cast member has the board open behind a rehearsal PDF and a group chat, and
	// the tab strip is the only part of the app they can see. So it carries the track, not the view.
	//
	// Two writers want this string — the router on navigation, the player on every track — so neither
	// sets document.title directly. viewTitle() records what the page alone would say; paintTab()
	// composes the visible line and is the only place that assigns.
	let viewLine = document.title;
	let tabTimer = 0;
	let tabStep = 0;
	const TAB_WINDOW = 28; // roughly what a tab shows before it truncates, with a handful of tabs open
	const TAB_GAP = '   ·   ';
	const TAB_HOLD = 3; // ticks the start is held before it scrolls, like the deck's own marquee

	function viewTitle( set ) {
		viewLine = set ? `${ set.name } · ${ G.site }` : G.site;
		paintTab();
	}

	function nowLine() {
		const t = queue?.tracks[ i ];
		if ( ! t ) {
			return '';
		}
		// No glyph: every browser already marks an audible tab with a speaker, and a second indicator
		// beside it would only spend characters the tab strip does not have.
		return `${ t.title } · ${ queue.name }`;
	}

	function paintTab() {
		const line = nowLine();
		if ( ! line ) {
			stopTabMarquee();
			document.title = viewLine;
			return;
		}
		// Scrolling is for the tab strip alone. While the tab is visible the deck is already marqueeing
		// the same string properly, and a title moving in the corner of the eye is just noise.
		const scroll =
			document.hidden &&
			! audio.paused &&
			line.length > TAB_WINDOW &&
			! matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( ! scroll ) {
			stopTabMarquee();
			document.title = line;
			return;
		}
		startTabMarquee( line );
	}

	function startTabMarquee( line ) {
		const first = line + TAB_GAP;
		if ( tabTimer ) {
			return; // already running; the tick reads the current track itself
		}
		tabStep = 0;
		document.title = first.slice( 0, TAB_WINDOW );
		// One second, deliberately: a hidden tab's timers are clamped to that anyway, so asking for
		// anything faster would only make the speed depend on which browser is throttling.
		tabTimer = setInterval( () => {
			const now = nowLine();
			if ( ! now || audio.paused || ! document.hidden ) {
				paintTab();
				return;
			}
			const text = now + TAB_GAP;
			tabStep += 1;
			const at = Math.max( 0, tabStep - TAB_HOLD ) * 2;
			if ( at >= text.length ) {
				tabStep = 0;
			}
			const from = at % text.length;
			document.title = ( text + text ).slice( from, from + TAB_WINDOW );
		}, 1000 );
	}

	function stopTabMarquee() {
		if ( tabTimer ) {
			clearInterval( tabTimer );
			tabTimer = 0;
		}
	}

	document.addEventListener( 'visibilitychange', paintTab );

	// Home rows: where each set was left, and which one is in the deck. Text inside the meta line, so nothing moves.
	function paintHomeResume() {
		document.querySelectorAll( '.set-resume' ).forEach( ( el ) => {
			const s = setBy( el.dataset.slug ),
				pos = s && ls.get( `callboard:${ s.slug }` ),
				t = pos && s.tracks[ pos.i ];
			el.closest( '.set' )?.classList.toggle(
				'is-now',
				!! queue && queue.slug === s?.slug
			);
			el.textContent =
				t && ( pos.i > 0 || pos.t >= 15 )
					? tpl( T.left_off, t.title )
					: '';
		} );
	}

	// ---- Per-view bindings (first load and after every render)
	let homeOffTimer = 0;
	async function paintHomeOffline() {
		clearTimeout( homeOffTimer );
		if ( ! ( 'caches' in window ) || view() ) {
			return;
		}
		let busy = false;
		for ( const s of G.sets ) {
			const el = document.querySelector(
				`.set-off[data-slug="${ s.slug }"]`
			);
			if ( ! el || ! s.tracks.length ) {
				continue;
			}
			const have = ( await savedSet( s.tracks ) ).size,
				saving = s.tracks.some( ( t ) => dlAborts.has( t.url ) );
			busy = busy || saving;
			const state = saving
				? 'saving'
				: have === s.tracks.length
				? 'saved'
				: have
				? 'partial'
				: '';
			el.dataset.state = state;
			if ( state === 'saved' ) {
				warmPage( s.slug );
			}
			if ( state ) {
				el.style.setProperty(
					'--p',
					( have / s.tracks.length ).toFixed( 3 )
				);
			}
			el.setAttribute(
				'aria-label',
				state === 'saved'
					? T.saved
					: state
					? tpl( T.saving_set, have, s.tracks.length )
					: ''
			);
		}
		if ( busy ) {
			homeOffTimer = setTimeout( paintHomeOffline, 800 );
		}
	}
	function bindView() {
		const set = setBy( view() );
		$( 'topbar-title' ).textContent = set ? set.name : G.site;
		if ( ! set ) {
			bindNotify().catch( () => {} );
			paintHomeOffline().catch( () => {} );
			paintHomeResume();
			paintCalls();
		}
		if ( set?.tracks.length ) {
			if ( ! queue ) {
				queue = set;
				const saved = ls.get( key() );
				load( saved && set.tracks[ saved.i ] ? saved.i : 0, {
					play: false,
					at: saved?.t || 0,
				} );
			}
			$( 'play-all' )?.addEventListener( 'click', () => {
				haptic();
				if ( played && onQueuePage() && i >= 0 ) {
					if ( audio.paused ) {
						audio.play().catch( () => {} );
					} else {
						audio.pause();
					}
				} else {
					startSet( set, 0 );
				}
			} );
			setTimeout( () => bindOffline( set ), 700 );
		}
		bindShare( set );
		paintTip();
		syncRows();
		viewEnter();
	}
	// ---- Share: the system sheet where there is one (iPhone, Android, Windows), the clipboard elsewhere.
	// The link alone is enough; the set's share card rides along as its Open Graph image.
	function bindShare( set ) {
		const btn = $( 'share' );
		if ( ! btn || ! set ) {
			return;
		}
		if ( ! ( navigator.share || navigator.clipboard?.writeText ) ) {
			btn.hidden = true; // nowhere to send a link: the one case the button leaves
			return;
		}
		btn.addEventListener( 'click', () => {
			haptic();
			const url = `${ G.home }${ set.slug }/`;
			if ( navigator.share ) {
				navigator
					.share( {
						title: `${ set.name } · ${ G.site }`,
						text: set.meta,
						url,
					} )
					.catch( () => {} ); // the sheet was dismissed
				return;
			}
			navigator.clipboard
				.writeText( url )
				.then( () => {
					const icon = btn.innerHTML;
					btn.textContent = T.copied;
					btn.classList.add( 'is-done' );
					setTimeout( () => {
						btn.innerHTML = icon;
						btn.classList.remove( 'is-done' );
					}, 1600 );
				} )
				.catch( () => {} );
		} );
	}

	// ---- Offline, per track. Each row has its own control; the set button drives them all.
	const CACHE = 'callboard-audio-v1';
	const dlAborts = new Map();
	const norm = ( u ) => new URL( u, location.href ).href; // cache keys are browser-normalized (percent-encoded)
	const sizeLabel = ( bytes ) =>
		bytes < 1048576
			? `${ Math.max( 1, Math.round( bytes / 1024 ) ) } KB`
			: bytes < 10485760
			? `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`
			: `${ Math.round( bytes / 1048576 ) } MB`;
	const dlButton = ( t ) =>
		document.querySelector( `.dl[data-i="${ t._i }"]` );
	const paintDl = ( t, state, progress = 0 ) => {
		const b = dlButton( t );
		if ( ! b ) {
			return;
		}
		b.dataset.state = state;
		b.style.setProperty( '--p', progress.toFixed( 3 ) );
		b.setAttribute(
			'aria-label',
			tpl(
				state === 'saved'
					? T.saved_track
					: state === 'saving'
					? T.saving_track
					: T.save_track,
				t.title
			)
		);
		b.disabled = state === 'saved'; // a saved mark is information, not a control; removal is deliberate, from the set button
	};
	// A copy counts as saved only if it is the file the set describes now: same URL and, when both are
	// known, the same size. A track replaced under the same name gets saved again instead of playing stale.
	async function savedSet( tracks ) {
		const c = await caches.open( CACHE );
		const have = new Set( ( await c.keys() ).map( ( r ) => r.url ) );
		const saved = new Set();
		for ( const t of tracks ) {
			if ( ! have.has( norm( t.url ) ) ) {
				continue;
			}
			const res = await c.match( norm( t.url ) );
			if ( res?.headers.get( 'X-Callboard-Sideloaded' ) ) {
				saved.add( t.url ); // came off a stick, not the server: a tagged copy is a different size on purpose
				continue;
			}
			const len = Number( res?.headers.get( 'Content-Length' ) ) || 0;
			if ( t.bytes && len && len !== t.bytes ) {
				continue;
			}
			saved.add( t.url );
		}
		return saved;
	}
	// ---- Durable storage. A browser may throw saved audio away to reclaim space, and a set that has
	// quietly evicted itself the night before a show is the worst thing this app can do.
	//
	// persist() is the only lever, and it is worth pulling more than once. Browsers grant it on
	// engagement, on an install to the Home Screen, on a bookmark — none of which have happened the
	// first time somebody taps Save, which is exactly when this used to ask, once, and record that it
	// had asked rather than what the answer was. No current browser shows a prompt for it, so asking
	// again is free, and the answer changes.
	let durableState = null;
	async function durable( { ask = false } = {} ) {
		if ( ! navigator.storage?.persisted ) {
			return null; // no way to know; not the same as "no"
		}
		try {
			if ( durableState !== true ) {
				durableState = await navigator.storage.persisted();
				if ( ! durableState && ask ) {
					durableState =
						( await navigator.storage.persist?.() ) ?? false;
				}
			}
		} catch {
			return null;
		}
		document.body?.classList.toggle( 'is-durable', !! durableState );
		return durableState;
	}

	// How much the browser will still let this origin store. Unknown counts as plenty.
	async function freeSpace() {
		try {
			const e = await navigator.storage?.estimate?.();
			return e && e.quota
				? Math.max( 0, e.quota - ( e.usage || 0 ) )
				: Infinity;
		} catch {
			return Infinity;
		}
	}
	const savedDetail = ( t, state ) => ( {
		set: G.sets.find( ( s ) => s.tracks.includes( t ) )?.slug || '',
		track: snapshot( t ),
		state,
	} );
	async function saveTrack( t, retry = true ) {
		if ( dlAborts.has( t.url ) ) {
			return;
		}
		const ctl = new AbortController();
		dlAborts.set( t.url, ctl );
		paintDl( t, 'saving', 0 );
		durable( { ask: true } );
		try {
			const r = await fetch( t.url, {
				cache: 'no-store',
				signal: ctl.signal,
			} );
			if ( ! r.ok || r.status !== 200 ) {
				throw new Error( r.status );
			}
			const total =
				Number( r.headers.get( 'Content-Length' ) ) || t.bytes || 0;
			const type = r.headers.get( 'Content-Type' ) || 'audio/mpeg';
			const headers = { 'Content-Type': type };
			if ( total ) {
				headers[ 'Content-Length' ] = String( total );
			}
			const c = await caches.open( CACHE );
			if ( r.body && total ) {
				// One branch streams straight into the cache, the other only counts bytes for the ring:
				// the file is never held whole in memory.
				const [ toCache, toCount ] = r.body.tee();
				const count = ( async () => {
					const reader = toCount.getReader();
					let got = 0;
					while ( true ) {
						const { done, value } = await reader.read();
						if ( done ) {
							break;
						}
						got += value.byteLength;
						paintDl( t, 'saving', Math.min( got / total, 0.99 ) );
					}
				} )();
				await Promise.all( [
					c.put(
						norm( t.url ),
						new Response( toCache, { headers } )
					),
					count,
				] );
			} else {
				const body = await r.blob();
				headers[ 'Content-Length' ] = String( body.size );
				await c.put( norm( t.url ), new Response( body, { headers } ) );
			}
			paintDl( t, 'saved', 1 );
			/**
			 * A track was saved for offline. Detail: { set, track, state }, where state is saved (downloaded) or loaded (from a file).
			 */
			doAction( 'callboard.save', savedDetail( t, 'saved' ) );
			return 'saved';
		} catch ( err ) {
			paintDl( t, '', 0 );
			dlAborts.delete( t.url );
			if ( err?.name === 'QuotaExceededError' ) {
				return 'quota'; // no retry will help; the caller says so
			}
			if ( retry && err?.name !== 'AbortError' && navigator.onLine ) {
				await new Promise( ( r ) => setTimeout( r, 1500 ) );
				return saveTrack( t, false ); // one more try; a dropped connection should not leave a hole
			}
		} finally {
			dlAborts.delete( t.url );
		}
	}
	// ---- Filling the cache from a file, for a phone that cannot download 40 MB.
	const fileBase = ( u ) =>
		decodeURIComponent(
			new URL( u, location.href ).pathname.split( '/' ).pop() || ''
		);
	const loose = ( s ) =>
		s
			.toLowerCase()
			.replace( /\.[a-z0-9]+$/, '' )
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
	const unnumbered = ( s ) => loose( s ).replace( /^\d+\s*/, '' );
	// In order: the track's own file name, which is exact for anything out of a .callboard file;
	// then a leading number, which is how a car-format export is named; then the title.
	function matchTrack( file, tracks, taken ) {
		const free = tracks.filter( ( t ) => ! taken.has( t.url ) );
		const name = loose( file.name );
		const byName = free.find(
			( t ) => loose( fileBase( t.url ) ) === name
		);
		if ( byName ) {
			return byName;
		}
		const n = /^0*(\d+)/.exec( file.name );
		const byNumber =
			n && free.find( ( t ) => Number( t.index ) === Number( n[ 1 ] ) );
		if ( byNumber ) {
			return byNumber;
		}
		const bare = unnumbered( file.name );
		return free.find( ( t ) => loose( t.title ) === bare ) || null;
	}
	async function loadTrackFile( t, file ) {
		const c = await caches.open( CACHE );
		await c.put(
			norm( t.url ),
			new Response( file, {
				headers: {
					'Content-Type': file.type || 'audio/mpeg',
					'Content-Length': String( file.size ),
					'X-Callboard-Sideloaded': '1',
				},
			} )
		);
		doAction( 'callboard.save', savedDetail( t, 'loaded' ) );
	}
	async function removeTrack( t ) {
		const c = await caches.open( CACHE );
		await c.delete( norm( t.url ) );
		paintDl( t, '', 0 );
		/**
		 * A track's offline copy was removed. Detail: { set, track, state }.
		 */
		doAction( 'callboard.unsave', savedDetail( t, '' ) );
	}
	function bindOffline( set ) {
		const offBtn = $( 'offline' );
		if ( ! offBtn ) {
			return;
		}
		if ( ! ( 'caches' in window ) || ! ( 'serviceWorker' in navigator ) ) {
			offBtn.hidden = true; // no store to save into: the one case the button leaves
			const noCache = $( 'load-label' );
			if ( noCache ) {
				noCache.hidden = true; // nothing to load into either
			}
			return;
		}
		const tracks = set.tracks.map( ( t, idx ) => ( { ...t, _i: idx } ) );
		const total = sizeLabel(
			tracks.reduce( ( a, t ) => a + ( t.bytes || 0 ), 0 )
		);
		const paintAll = async () => {
			const have = await savedSet( tracks );
			tracks.forEach( ( t ) =>
				paintDl(
					t,
					dlAborts.has( t.url )
						? 'saving'
						: have.has( t.url )
						? 'saved'
						: '',
					have.has( t.url ) ? 1 : 0
				)
			);
			// Every track is here, but the set is only saved once the page it opens from is too. Keep it now if it
			// is missing (a first visit, or a worker update that cleared the old copies) and hold the label until then.
			if (
				have.size === tracks.length &&
				navigator.onLine &&
				! kept.has( set.slug ) &&
				! ( await pageKept( set.slug ) )
			) {
				warmPage( set.slug ).then( () => paintAll() );
			}
			const busy =
				tracks.some( ( t ) => dlAborts.has( t.url ) ) ||
				kept.get( set.slug ) instanceof Promise;
			offBtn.classList.toggle( 'is-busy', busy );
			offBtn.classList.toggle(
				'is-done',
				! busy && have.size === tracks.length
			);
			const rest = sizeLabel(
				tracks
					.filter( ( t ) => ! have.has( t.url ) )
					.reduce( ( a, t ) => a + ( t.bytes || 0 ), 0 )
			);
			offBtn.dataset.some = have.size ? '1' : '';
			// the size sits in its own span so a narrow column can drop it and keep the words
			const label = ( words, size ) => {
				offBtn.textContent = words;
				if ( size ) {
					const s = document.createElement( 'span' );
					s.className = 'size';
					s.textContent = ` · ${ size }`;
					offBtn.append( s );
				}
			};
			if ( busy ) {
				label( tpl( T.saving, have.size, tracks.length ) );
			} else if ( have.size === tracks.length ) {
				label( T.saved );
			} else if ( have.size ) {
				label( tpl( T.save_rest, have.size, tracks.length ), rest );
			} else {
				label( T.save, total );
			}
			offBtn.setAttribute(
				'aria-label',
				have.size === tracks.length && ! busy ? T.saved_hint : ''
			);
			// A title, not text in the button: appended inline it grew the button mid-hover and pushed
			// the row below onto a second line, which is a layout shift caused by looking at something.
			offBtn.title = have.size ? T.saved_hover : '';
			if ( ! offBtn.getAttribute( 'aria-label' ) ) {
				offBtn.removeAttribute( 'aria-label' );
			}
			offBtn.disabled =
				! navigator.onLine && ! busy && have.size !== tracks.length;
		};
		paintAll().catch( () => {} );

		// Filling from a file: the control appears only now, because only now is there a cache to
		// fill. Somebody with the audio on a stick can hand it to a phone that cannot download it.
		const loadInput = $( 'load-files' );
		const loadLabel = $( 'load-label' );
		if ( loadInput && loadLabel ) {
			loadInput.onchange = async () => {
				const files = [ ...( loadInput.files || [] ) ];
				loadInput.value = ''; // so picking the same file twice still fires
				if ( ! files.length ) {
					return;
				}
				durable( { ask: true } );
				const taken = new Set();
				let loaded = 0;
				for ( const file of files ) {
					const t = matchTrack( file, tracks, taken );
					if ( ! t ) {
						continue;
					}
					taken.add( t.url );
					try {
						await loadTrackFile( t, file );
						loaded++;
					} catch {
						// out of space, or a file the browser will not read: the count says so
					}
				}
				await paintAll();
				toast(
					loaded ? tpl( T.loaded, loaded, files.length ) : T.load_none
				);
			};
		}
		// When the whole set is saved, a tap (or Delete) asks to remove it and a second tap confirms.
		// data-confirm is "asking" for 350ms, then "1"; taps while asking are ignored so a double tap
		// can't confirm. The question times out after 4s.
		const arm = () => {
			if ( ! offBtn.dataset.some || offBtn.dataset.confirm ) {
				return;
			}
			offBtn.dataset.confirm = 'asking';
			offBtn.textContent = T.remove_confirm;
			setTimeout( () => {
				if ( offBtn.dataset.confirm === 'asking' ) {
					offBtn.dataset.confirm = '1';
				}
			}, 350 );
			setTimeout( () => {
				if ( offBtn.dataset.confirm ) {
					delete offBtn.dataset.confirm;
					paintAll();
				}
			}, 4000 );
		};
		offBtn.onkeydown = ( e ) => {
			if ( e.key === 'Delete' || e.key === 'Backspace' ) {
				e.preventDefault();
				arm();
			}
		};
		offBtn.onclick = async () => {
			if ( tracks.some( ( t ) => dlAborts.has( t.url ) ) ) {
				haptic();
				dlAborts.forEach( ( ctl ) => ctl.abort() );
				return;
			}
			haptic(); // the tap itself; the completion below comes long after the gesture
			if ( offBtn.dataset.confirm === 'asking' ) {
				return;
			}
			if ( offBtn.dataset.confirm ) {
				delete offBtn.dataset.confirm;
				await Promise.all( tracks.map( removeTrack ) );
				return paintAll();
			}
			const have = await savedSet( tracks );
			if ( have.size === tracks.length ) {
				return arm(); // already saved: ask whether to remove
			}
			const todo = tracks.filter( ( t ) => ! have.has( t.url ) );
			const need = todo.reduce( ( a, t ) => a + ( t.bytes || 0 ), 0 );
			const free = await freeSpace();
			if ( need && free < need * 1.1 ) {
				return noSpace( free );
			}
			let full = false;
			const worker = async () => {
				while ( todo.length ) {
					if ( ( await saveTrack( todo.shift() ) ) === 'quota' ) {
						todo.length = 0;
						full = true;
					}
					await paintAll();
				}
			};
			await Promise.all( [ worker(), worker() ] );
			await warmPage( set.slug ); // the set is saved; its page should open offline too
			if ( full ) {
				return noSpace( await freeSpace() );
			}
			paintAll();
		};
		// The button carries the message for a moment, then goes back to being the button.
		let spaceTimer = 0;
		function noSpace( free ) {
			offBtn.textContent = tpl(
				T.no_space,
				sizeLabel( isFinite( free ) ? free : 0 )
			);
			offBtn.classList.add( 'is-busy' );
			clearTimeout( spaceTimer );
			spaceTimer = setTimeout( () => {
				offBtn.classList.remove( 'is-busy' );
				paintAll();
			}, 3500 );
		}
		document.querySelectorAll( '.dl' ).forEach( ( b ) => {
			b.onclick = async () => {
				haptic();
				const t = tracks[ +b.dataset.i ];
				if ( dlAborts.has( t.url ) ) {
					dlAborts.get( t.url ).abort();
				} else if ( b.dataset.state !== 'saved' ) {
					if ( ( await saveTrack( t ) ) === 'quota' ) {
						return noSpace( await freeSpace() );
					}
				}
				paintAll();
			};
		} );
	}

	// ---- Client-side routing. The server renders every view; on navigation the script fetches the view as an
	// HTML fragment (the same templates, without the shell) and swaps it in under a view transition. One
	// renderer, on the server. The service worker keeps fragments for offline; a saved set's is warmed here.
	// '' = home, 'slug' = a set, null = not ours.
	const homePath = new URL( G.home ).pathname.replace( /\/$/, '' );
	let shownPath = location.pathname; // path of the view currently rendered
	function routeOf( href ) {
		const u = new URL( href, location.href );
		if (
			u.origin !== location.origin ||
			! u.pathname.startsWith( homePath )
		) {
			return null;
		}
		const rest = u.pathname
			.slice( homePath.length )
			.replace( /^\/|\/$/g, '' );
		if ( rest === '' ) {
			return '';
		}
		return setBy( rest ) ? rest : null;
	}
	const fragmentUrl = ( slug ) =>
		`${ G.home }${ slug ? `${ slug }/` : '' }?fragment=1`;
	// slug -> the fragment's HTML once it has arrived, or the promise of it. A touch on a link starts the
	// fetch, so by the time the tap lands the view is usually here. A used copy is refreshed for next time.
	const fragments = new Map();
	function fetchFragment( slug ) {
		const p = fetch( fragmentUrl( slug ), {
			cache: 'no-cache',
			headers: { Accept: 'text/html' },
		} ).then( ( r ) => {
			if ( ! r.ok ) {
				throw new Error( String( r.status ) );
			}
			return r.text();
		} );
		fragments.set( slug, p );
		p.then(
			( html ) => fragments.set( slug, html ),
			() => fragments.delete( slug )
		);
		return p;
	}
	const prefetch = ( slug ) => {
		if ( ! fragments.has( slug ) ) {
			fetchFragment( slug );
		}
	};
	// slug -> true once the worker has kept the set's page this visit, false if it could not, or the promise
	// of one of those while it is on its way
	const kept = new Map();
	function warmPage( slug ) {
		// A saved set has to open offline, from a tap on home (the fragment) or a cold start (the document).
		// The worker fetches and stores both and answers once they are in, so "Saved offline" can wait for it.
		const have = kept.get( slug );
		if ( have === true || have instanceof Promise ) {
			return Promise.resolve( have );
		}
		if ( ! navigator.onLine || ! navigator.serviceWorker ) {
			return Promise.resolve( false );
		}
		const p = ( async () => {
			const reg = await Promise.race( [
				navigator.serviceWorker.ready,
				new Promise( ( r ) => setTimeout( r, 10000 ) ),
			] );
			if ( ! reg?.active ) {
				return false;
			}
			return new Promise( ( resolve ) => {
				const ch = new MessageChannel();
				const timer = setTimeout( () => resolve( false ), 30000 );
				ch.port1.onmessage = ( e ) => {
					clearTimeout( timer );
					resolve( e.data === true );
				};
				reg.active.postMessage(
					{
						type: 'callboard:keep',
						urls: [ fragmentUrl( slug ), `${ G.home }${ slug }/` ],
					},
					[ ch.port2 ]
				);
			} );
		} )()
			.catch( () => false )
			.then( ( ok ) => {
				kept.set( slug, ok );
				return ok;
			} );
		kept.set( slug, p );
		return p;
	}
	// Whether a set's page is already in a cache, from this visit or an earlier one.
	const pageKept = async ( slug ) =>
		!! ( await caches.match( fragmentUrl( slug ) ) ) &&
		!! ( await caches.match( `${ G.home }${ slug }/` ) );
	async function go( url, push = true, animate = true ) {
		const slug = routeOf( url );
		if ( slug === null ) {
			location.href = url;
			return;
		}
		const set = slug ? setBy( slug ) : null;
		const slow = setTimeout(
			() => document.body.classList.add( 'is-loading' ),
			300
		);
		let html;
		try {
			const have = fragments.get( slug );
			if ( typeof have === 'string' ) {
				html = have;
				fetchFragment( slug ); // this copy is used; the next visit gets a fresh one
			} else {
				html = await ( have || fetchFragment( slug ) );
			}
		} catch {
			clearTimeout( slow );
			document.body.classList.remove( 'is-loading' );
			// offline with no copy: a full navigation, which the worker answers from the shell
			if ( push ) {
				location.href = url;
			} else {
				location.reload();
			}
			return;
		}
		clearTimeout( slow );
		document.body.classList.remove( 'is-loading' );
		if ( push ) {
			// fromApp marks entries pushed by this script. Navigating away from Now Playing replaces
			// its entry, so back returns to the page underneath.
			if ( nowPlayingEntry() ) {
				history.replaceState( { fromApp: true }, '', url );
			} else {
				history.pushState( { fromApp: true }, '', url );
			}
			collapseDeck();
		}
		shownPath = new URL( url, location.href ).pathname;

		const apply = () => {
			const t = document.createElement( 'template' );
			t.innerHTML = html;
			const main = t.content.querySelector( 'main' );
			if ( ! main ) {
				location.href = url;
				return;
			}
			viewLeave();
			$( 'main' ).replaceWith( main );
			viewTitle( set );
			document.body.dataset.slug = slug;
			document.body.classList.toggle( 'view-home', ! slug );
			document.body.classList.toggle( 'view-set', !! slug );
			document.body.classList.add( 'swapped' );
			window.scrollTo( 0, 0 );
			if ( sheetOpen() ) {
				hideLyrics();
			}
			bindView();
		};
		document.documentElement.dataset.nav = slug ? 'forward' : 'back';
		if ( animate && document.startViewTransition ) {
			document.startViewTransition( apply ).finished.finally( () => {
				delete document.documentElement.dataset.nav;
			} );
		} else {
			apply();
			delete document.documentElement.dataset.nav;
		}
	}
	window.addEventListener( 'popstate', ( e ) => {
		const animate = ! e.hasUAVisualTransition && ! quietBack;
		quietBack = false;
		// Same path: only Now Playing opens or closes. Different path: navigate.
		if ( e.state?.nowPlaying && location.pathname === shownPath ) {
			if ( i >= 0 ) {
				expandDeck( false );
			}
			return;
		}
		collapseDeck();
		if ( location.pathname !== shownPath ) {
			go( location.href, false, animate );
		}
	} );
	// The first touch on a link starts the fetch; hovering does too. Back to home is prefetched at idle.
	const prefetchLink = ( e ) => {
		const a = e.target.closest?.( 'a[href]' );
		const slug = a && routeOf( a.href );
		if ( slug !== null && slug !== undefined ) {
			prefetch( slug );
		}
	};
	document.addEventListener( 'pointerdown', prefetchLink, { passive: true } );
	document.addEventListener( 'pointerover', prefetchLink, { passive: true } );
	if ( view() ) {
		( window.requestIdleCallback || ( ( f ) => setTimeout( f, 2000 ) ) )(
			() => prefetch( '' )
		);
	}

	// Installed to the Home Screen is the moment iOS actually grants persistence, and the only moment
	// worth asking outside a deliberate save: Firefox puts a prompt behind this, so it is not free
	// everywhere. Reading the state costs nothing, so that happens either way.
	// ---- Extensions: window.callboard. The contract is docs/extending.md; the PHP half is
	// includes/class-extensions.php. An extension registers here with the same id it registered on the
	// server, and PHP decides which ids reach the page, so a feature switched off there cannot come back
	// on here. Callboard's own extensions are the sections after this function, and they can reach
	// nothing but this object.
	const API_VERSION = 1;
	const ID = /^[a-z0-9-]+\/[a-z0-9-]+$/;
	const registry = new Map();
	const commandMap = new Map();
	const warned = new Set();
	let currentView = null;
	const warn = ( msg ) => {
		if ( ! warned.has( msg ) ) {
			warned.add( msg );
			// eslint-disable-next-line no-console
			console.warn( `[callboard] ${ msg }` );
		}
	};
	// The same shape as @wordpress/deprecated, without loading it.
	function deprecated(
		name,
		{ since = '', alternative = '', hint = '' } = {}
	) {
		warn(
			`${ name } is deprecated${
				since ? ` since version ${ since }` : ''
			}.${ alternative ? ` Please use ${ alternative } instead.` : '' }${
				hint ? ` Note: ${ hint }` : ''
			}`
		);
	}
	// Top-level track fields that belong to an extension now. Reading one still works for API v1, and
	// says so where the site runs with SCRIPT_DEBUG.
	if ( G.debug ) {
		const moved = {
			bpm: "track.ext[ 'callboard/count-in' ].bpm",
			quality: "track.ext[ 'callboard/quality' ].quality",
		};
		G.sets.forEach( ( set ) =>
			set.tracks.forEach( ( t ) =>
				Object.entries( moved ).forEach( ( [ field, alternative ] ) => {
					if ( ! ( field in t ) ) {
						return;
					}
					const value = t[ field ];
					Object.defineProperty( t, field, {
						configurable: true,
						enumerable: false,
						get: () => {
							deprecated( `track.${ field }`, {
								since: '2.3.0',
								alternative,
							} );
							return value;
						},
					} );
				} )
			)
		);
	}

	const SLOT_HOOKS = {
		trackBadges: 'callboard.slot.trackBadges',
		trackMeta: 'callboard.slot.trackMeta',
		nowPlayingMeta: 'callboard.slot.nowPlayingMeta',
	};
	const TONES = [ 'default', 'accent', 'muted' ];
	// An item is text, never markup: built with textContent, classes checked one at a time.
	function itemEl( item ) {
		if (
			! item ||
			item.text === undefined ||
			item.text === null ||
			String( item.text ) === ''
		) {
			return null;
		}
		const el = document.createElement( 'span' );
		el.className = [ 'cb-item' ]
			.concat(
				String( item.className || '' )
					.split( /\s+/ )
					.filter( ( c ) => /^[a-z][\w-]*$/i.test( c ) )
			)
			.join( ' ' );
		if ( item.extension ) {
			el.dataset.extension = item.extension;
		}
		el.dataset.client = '';
		if ( TONES.includes( item.tone ) && item.tone !== 'default' ) {
			el.dataset.tone = item.tone;
		}
		if ( item.title ) {
			el.title = String( item.title );
		}
		if ( item.label ) {
			el.setAttribute( 'aria-label', String( item.label ) );
		}
		el.textContent = String( item.text );
		return el;
	}
	const viewInfo = () => ( {
		slug: view(),
		set: snapshot( setBy( view() ) ),
		main: $( 'main' ),
	} );
	// Client items on the rows: badges before the length, metadata in the second line.
	function renderRowSlots() {
		document
			.querySelectorAll( '#tracks [data-client]' )
			.forEach( ( el ) => el.remove() );
		const set = setBy( view() );
		if (
			! set ||
			! (
				hooks?.hasFilter( SLOT_HOOKS.trackBadges ) ||
				hooks?.hasFilter( SLOT_HOOKS.trackMeta )
			)
		) {
			return;
		}
		const info = viewInfo();
		document.querySelectorAll( '#tracks .track' ).forEach( ( row ) => {
			const t = set.tracks[ +row.dataset.i ];
			if ( ! t ) {
				return;
			}
			const copy = snapshot( t );
			/**
			 * Items for a track row, before its length: [ { text, label, title, tone, className } ]. Args: track, view.
			 */
			const badges = applyFilters(
				'callboard.slot.trackBadges',
				[],
				copy,
				info
			)
				.map( itemEl )
				.filter( Boolean );
			const len = row.querySelector( '.len' );
			if ( len && badges.length ) {
				const time = [ ...len.childNodes ].find(
					( n ) => n.nodeType === Node.TEXT_NODE
				);
				badges.forEach( ( el ) =>
					len.insertBefore( el, time || null )
				);
			}
			/**
			 * Items for a track row's second line, beside the artist. Args: track, view.
			 */
			const meta = applyFilters(
				'callboard.slot.trackMeta',
				[],
				copy,
				info
			)
				.map( itemEl )
				.filter( Boolean );
			if ( meta.length ) {
				let by = row.querySelector( '.by' );
				if ( ! by ) {
					by = document.createElement( 'span' );
					by.className = 'by';
					by.dataset.client = '';
					len?.before( by );
				}
				meta.forEach( ( el ) => by.appendChild( el ) );
			}
		} );
	}
	function renderNowPlayingMeta() {
		const box = $( 'deck-meta' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		const t = queue && i >= 0 ? queue.tracks[ i ] : null;
		if ( t ) {
			/**
			 * Items for Now Playing, between the elapsed and remaining times. Args: track, window.callboard.
			 */
			applyFilters(
				'callboard.slot.nowPlayingMeta',
				[],
				snapshot( t ),
				callboard
			)
				.map( itemEl )
				.filter( Boolean )
				.forEach( ( el ) => box.appendChild( el ) );
		}
		box.hidden = ! box.childElementCount;
	}
	// The Home Screen badge is the sum of what extensions contribute. With no contributors the page
	// leaves the badge alone; with contributors, zero clears it.
	async function paintBadge() {
		if (
			! ( 'setAppBadge' in navigator ) ||
			! hooks?.hasFilter( 'callboard.badge' )
		) {
			return;
		}
		/**
		 * Contributions to the Home Screen badge: numbers, or promises of them, summed.
		 */
		const list = applyFilters( 'callboard.badge', [], callboard );
		if ( ! Array.isArray( list ) || ! list.length ) {
			return;
		}
		const values = await Promise.all(
			list.map( ( v ) => Promise.resolve( v ).catch( () => 0 ) )
		);
		const n = values.reduce(
			( sum, v ) =>
				sum +
				( Number.isFinite( +v ) && +v > 0 ? Math.floor( +v ) : 0 ),
			0
		);
		( n ? navigator.setAppBadge( n ) : navigator.clearAppBadge() ).catch(
			() => {}
		);
	}
	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'visible' ) {
			paintBadge();
		}
	} );
	function invalidate( ...points ) {
		const all = [ 'trackBadges', 'trackMeta', 'nowPlayingMeta', 'badge' ];
		const which = points.length ? points : all;
		if (
			which.includes( 'trackBadges' ) ||
			which.includes( 'trackMeta' )
		) {
			renderRowSlots();
		}
		if ( which.includes( 'nowPlayingMeta' ) ) {
			renderNowPlayingMeta();
		}
		if ( which.includes( 'badge' ) ) {
			paintBadge();
		}
		which
			.filter( ( p ) => ! all.includes( p ) )
			.forEach( ( p ) =>
				warn( `invalidate(): there is no point called "${ p }".` )
			);
	}

	// One extension's view: its own signal, so unregistering one aborts only its listeners.
	function initOne( ext, v ) {
		ext.view?.abort();
		ext.view = new AbortController();
		try {
			ext.args.init?.( { ...v, signal: ext.view.signal } );
		} catch ( err ) {
			report( ext.id, err );
		}
	}
	function teardownOne( ext, v ) {
		if ( ! ext.view ) {
			return;
		}
		try {
			ext.args.teardown?.( { ...v, signal: ext.view.signal } );
		} catch ( err ) {
			report( ext.id, err );
		}
		ext.view.abort();
		ext.view = null;
	}
	function viewEnter() {
		currentView = viewInfo();
		registry.forEach( ( ext ) => initOne( ext, currentView ) );
		renderRowSlots();
		/**
		 * A view is on screen and every extension has run init for it. Detail: { view: home or set, set: slug }.
		 */
		doAction( 'callboard.view', {
			view: currentView.slug ? 'set' : 'home',
			set: currentView.slug,
		} );
	}
	function viewLeave() {
		if ( ! currentView ) {
			return;
		}
		/**
		 * The view is about to be replaced; extensions' teardown runs next. Detail: { view, set }.
		 */
		doAction( 'callboard.viewTeardown', {
			view: currentView.slug ? 'set' : 'home',
			set: currentView.slug,
		} );
		registry.forEach( ( ext ) => teardownOne( ext, currentView ) );
		currentView = null;
	}

	const point = ( value, priority ) => {
		if ( typeof value === 'function' ) {
			return { callback: value, priority };
		}
		if ( value && typeof value.callback === 'function' ) {
			return {
				callback: value.callback,
				priority: Number.isFinite( value.priority )
					? value.priority
					: priority,
			};
		}
		return null;
	};
	function registerExtension( id, args = {} ) {
		if ( ! ID.test( id ) ) {
			warn(
				`"${ id }" is not an extension id: "namespace/name", lowercase.`
			);
			return false;
		}
		if ( ! owns( G.extensions, id ) ) {
			warn(
				`${ id } is not active on this page. Register it with callboard_register_extension() in PHP first; a disabled extension stays off here too.`
			);
			return false;
		}
		if ( registry.has( id ) ) {
			warn( `${ id } is already registered.` );
			return false;
		}
		if ( ( args.apiVersion ?? 1 ) !== API_VERSION ) {
			warn(
				`${ id } was written for API version ${ args.apiVersion }; this page runs version ${ API_VERSION }.`
			);
			return false;
		}
		if ( ! hooks ) {
			warn( `wp.hooks is not loaded, so ${ id } cannot run.` );
			return false;
		}
		const priority = Number.isFinite( args.priority ) ? args.priority : 10;
		const ext = { id, args, hooks: new Set(), commands: [], view: null };
		const guard =
			( fn, fallback ) =>
			( ...a ) => {
				try {
					return fn( ...a );
				} catch ( err ) {
					report( id, err );
					return fallback( ...a );
				}
			};
		const addFilter = ( hook, value, wrap ) => {
			const p = point( value, priority );
			if ( ! p ) {
				warn(
					`${ id } gave ${ hook } something that is not a function.`
				);
				return;
			}
			hooks.addFilter( hook, id, wrap( p.callback ), p.priority );
			ext.hooks.add( hook );
		};
		Object.entries( args.slots || {} ).forEach( ( [ name, value ] ) => {
			if ( ! SLOT_HOOKS[ name ] ) {
				warn(
					`${ id } asks for a slot called "${ name }", which does not exist.`
				);
				return;
			}
			addFilter( SLOT_HOOKS[ name ], value, ( cb ) =>
				guard(
					( items, ...a ) =>
						items.concat(
							[].concat( cb( ...a ) || [] ).map( ( item ) => ( {
								...item,
								extension: id,
							} ) )
						),
					( items ) => items
				)
			);
		} );
		if ( args.badge ) {
			addFilter( 'callboard.badge', args.badge, ( cb ) =>
				guard(
					( list, app ) => list.concat( [ cb( app ) ] ),
					( list ) => list
				)
			);
		}
		if ( args.beforePlay ) {
			addFilter(
				'callboard.beforePlay',
				args.beforePlay,
				( cb ) => ( gates ) => gates.concat( [ { id, callback: cb } ] )
			);
		}
		Object.entries( args.events || {} ).forEach( ( [ name, value ] ) => {
			const p = point( value, priority );
			const hook = name.includes( '.' ) ? name : `callboard.${ name }`;
			if ( ! p ) {
				warn(
					`${ id } gave ${ hook } something that is not a function.`
				);
				return;
			}
			hooks.addAction(
				hook,
				id,
				guard( p.callback, () => {} ),
				p.priority
			);
			ext.hooks.add( hook );
		} );
		registry.set( id, ext );
		Object.entries( args.commands || {} ).forEach( ( [ name, fn ] ) => {
			if ( registerCommand( `${ id }/${ name }`, fn ) ) {
				ext.commands.push( `${ id }/${ name }` );
			}
		} );
		try {
			args.setup?.( callboard );
		} catch ( err ) {
			report( id, err );
		}
		if ( currentView ) {
			initOne( ext, currentView );
		}
		invalidate();
		return true;
	}
	function unregisterExtension( id ) {
		const ext = registry.get( id );
		if ( ! ext ) {
			warn( `${ id } is not registered.` );
			return false;
		}
		if ( currentView ) {
			teardownOne( ext, currentView );
		}
		ext.hooks.forEach( ( hook ) => {
			hooks.removeFilter( hook, id );
			hooks.removeAction( hook, id );
		} );
		ext.commands.forEach( ( c ) => commandMap.delete( c ) );
		registry.delete( id );
		document
			.querySelectorAll( `[data-client][data-extension="${ id }"]` )
			.forEach( ( el ) => el.remove() );
		invalidate();
		return true;
	}
	function registerCommand( name, fn ) {
		const owner = name.split( '/' ).slice( 0, 2 ).join( '/' );
		if (
			! /^[a-z0-9-]+\/[a-z0-9-]+\/[a-zA-Z0-9-]+$/.test( name ) ||
			! registry.has( owner ) ||
			typeof fn !== 'function'
		) {
			warn(
				`Command "${ name }" needs a name of "namespace/name/command", a function, and a registered extension that owns it.`
			);
			return false;
		}
		commandMap.set( name, fn );
		return true;
	}
	function run( name, ...args ) {
		const fn = commandMap.get( name );
		if ( ! fn ) {
			warn( `There is no command called "${ name }".` );
			return undefined;
		}
		try {
			return fn( ...args );
		} catch ( err ) {
			report( name, err );
			return undefined;
		}
	}
	function emit( name, detail ) {
		const owner = name.split( '.' ).slice( 0, 2 ).join( '/' );
		if (
			! /^[a-z0-9-]+\.[a-z0-9-]+\.[a-zA-Z0-9]+$/.test( name ) ||
			! registry.has( owner )
		) {
			warn(
				`Event "${ name }" needs a name of "namespace.name.event" and a registered extension that owns it.`
			);
			return false;
		}
		doAction( name, detail );
		return true;
	}

	const commands = Object.freeze( {
		play() {
			if ( holdStop() ) {
				morph( 'pause' );
				audio.play().catch( () => {} );
				return true;
			}
			if ( i >= 0 ) {
				audio.play().catch( () => {} );
				return true;
			}
			const set = queue || setBy( view() );
			if ( ! set?.tracks.length ) {
				return false;
			}
			startSet( set, 0 );
			return true;
		},
		pause() {
			holdStop();
			audio.pause();
			return true;
		},
		seek( seconds ) {
			if ( i < 0 || ! Number.isFinite( +seconds ) ) {
				return false;
			}
			audio.currentTime = Math.max(
				0,
				Math.min( +seconds, trackDur() || +seconds )
			);
			return true;
		},
		next() {
			if ( ! queue ) {
				return false;
			}
			load( i < 0 ? 0 : i + 1 );
			return true;
		},
		prev() {
			if ( ! queue ) {
				return false;
			}
			prev();
			return true;
		},
		// Open a set and start one of its tracks, the way a number on the board does.
		async goTo( slug, index = 0, { at = 0, play = true } = {} ) {
			const set = setBy( slug );
			if ( ! set?.tracks[ index ] ) {
				return false;
			}
			if ( view() !== slug ) {
				await go( `${ G.home }${ slug }/` );
			}
			startSet( set, index, { at, play } );
			return true;
		},
		display,
	} );

	const state = Object.freeze(
		Object.defineProperties(
			{},
			{
				view: { enumerable: true, get: () => view() },
				set: { enumerable: true, get: () => snapshot( queue ) },
				track: {
					enumerable: true,
					get: () =>
						snapshot( queue && i >= 0 ? queue.tracks[ i ] : null ),
				},
				index: { enumerable: true, get: () => i },
				position: {
					enumerable: true,
					get: () => ( i >= 0 ? playhead() : 0 ),
				},
				duration: {
					enumerable: true,
					get: () => ( i >= 0 ? trackDur() : 0 ),
				},
				paused: { enumerable: true, get: () => audio.paused },
				loop: {
					enumerable: true,
					get: () =>
						loop ? Object.freeze( { a: loop.a, b: loop.b } ) : null,
				},
				online: { enumerable: true, get: () => navigator.onLine },
			}
		)
	);

	const callboard = Object.freeze( {
		apiVersion: API_VERSION,
		version: G.version,
		hooks,
		state,
		commands,
		registerExtension,
		unregisterExtension,
		extensions: () => [ ...registry.keys() ],
		isActive: ( id ) => owns( G.extensions, id ),
		data: ( id ) => snapshot( G.ext[ id ] ),
		registerCommand,
		run,
		emit,
		invalidate,
		deprecated,
	} );
	/**
	 * The extension API: registry, state, commands, and the hooks underneath. See docs/extending.md.
	 */
	window.callboard = callboard;

	durable( { ask: standalone } );
	bindView();
	// Ready once every deferred script has run, extensions included, so an extension that loads after
	// this file still hears it. A script that arrives later than that registers late, which is fine.
	let readied = false;
	const ready = () => {
		if ( readied ) {
			return;
		}
		readied = true;
		/**
		 * The page and every deferred script, extensions included, have run. Detail: { view, set }.
		 */
		doAction( 'callboard.ready', {
			view: view() ? 'set' : 'home',
			set: view(),
		} );
		paintBadge();
	};
	if ( document.readyState === 'complete' ) {
		ready();
	} else {
		document.addEventListener( 'DOMContentLoaded', ready, { once: true } );
		window.addEventListener( 'load', ready, { once: true } );
	}
} )();

// ---- callboard/count-in. A track with a tempo, played from the top, taps four beats first: a soft
// click and the count on the title. Built on window.callboard alone, through beforePlay.
( () => {
	const cb = window.callboard;
	if ( ! cb ) {
		return;
	}
	let ctx = null;
	const click = ( first ) => {
		try {
			ctx =
				ctx ||
				new ( window.AudioContext || window.webkitAudioContext )();
			const o = ctx.createOscillator(),
				g = ctx.createGain(),
				at = ctx.currentTime;
			o.frequency.value = first ? 1320 : 880;
			g.gain.setValueAtTime( 0.0001, at );
			g.gain.exponentialRampToValueAtTime( 0.18, at + 0.006 );
			g.gain.exponentialRampToValueAtTime( 0.0001, at + 0.07 );
			o.connect( g ).connect( ctx.destination );
			o.start( at );
			o.stop( at + 0.08 );
		} catch {}
	};
	/**
	 * Count-in, as an extension: holds the start of a track through beforePlay.
	 */
	cb.registerExtension( 'callboard/count-in', {
		version: '1.0.0',
		apiVersion: 1,
		beforePlay( { track, at }, signal ) {
			const bpm = track?.ext?.[ 'callboard/count-in' ]?.bpm;
			if ( ! cb.data( 'callboard/count-in' )?.enabled || ! bpm || at ) {
				return;
			}
			const deck = document.getElementById( 'deck' );
			const beat = 60000 / bpm;
			return new Promise( ( resolve ) => {
				const timers = [];
				const done = ( ok ) => {
					timers.forEach( clearTimeout );
					deck?.classList.remove( 'counting' );
					cb.commands.display( null );
					resolve( ok );
				};
				signal.addEventListener( 'abort', () => done( false ), {
					once: true,
				} );
				deck?.classList.add( 'counting' );
				for ( let k = 1; k <= 4; k++ ) {
					timers.push(
						setTimeout(
							() => {
								// the count accumulates: 1, 1 2, 1 2 3, 1 2 3 4
								cb.commands.display(
									Array.from(
										{ length: k },
										( _, n ) => n + 1
									).join( '   ' ),
									{ className: 'is-count' }
								);
								click( k === 1 ); // the beat is audible only: a timer is not a gesture, so no haptic can ride on it
							},
							( k - 1 ) * beat
						)
					);
				}
				timers.push( setTimeout( () => done( true ), 4 * beat ) );
			} );
		},
	} );
} )();

// ---- callboard/quality. What the copy is, in Now Playing: the attachment's own bit depth and sample
// rate when the server knows them, otherwise an average bitrate from the size and length every track has.
( () => {
	const cb = window.callboard;
	if ( ! cb ) {
		return;
	}
	const pattern = ( s, ...a ) =>
		String( s || '' ).replace( /%(\d)\$s/g, ( m, n ) => a[ n - 1 ] );
	/**
	 * Quality, as an extension: a nowPlayingMeta item.
	 */
	cb.registerExtension( 'callboard/quality', {
		version: '1.0.0',
		apiVersion: 1,
		slots: {
			nowPlayingMeta( track ) {
				const known = track.ext?.[ 'callboard/quality' ]?.quality;
				if ( known ) {
					return [ { text: known, className: 'quality-pill' } ];
				}
				const kbps =
					track.bytes && track.duration
						? Math.round(
								( track.bytes * 8 ) / track.duration / 1000
						  )
						: 0;
				if ( ! kbps ) {
					return [];
				}
				const ext = ( /\.([a-z0-9]+)(?:\?.*)?$/i.exec( track.url ) ||
					[] )[ 1 ];
				return [
					{
						text: pattern(
							cb.data( 'callboard/quality' )?.format,
							( ext || '' ).toUpperCase(),
							kbps
						),
						className: 'quality-pill',
					},
				];
			},
		},
	} );
} )();

// ---- callboard/badging. A notification puts a number on the Home Screen icon; opening the app is
// reading what it was for. This contributes zero, and contributions that add up to zero clear the badge.
( () => {
	/**
	 * Badging, as an extension: a badge contributor of zero.
	 */
	window.callboard?.registerExtension( 'callboard/badging', {
		version: '1.0.0',
		apiVersion: 1,
		badge: () => 0,
	} );
} )();

// ---- callboard/practice. Anonymous practice counts per track: times opened, loops set and seconds played.
// The page sends them to the site in batches. Runs only when the "Count practice" setting is on.
( () => {
	const cb = window.callboard;
	if ( ! cb?.isActive( 'callboard/practice' ) || ! navigator.sendBeacon ) {
		return;
	}
	const counts = new Map(); // track id -> { opens, loops, seconds }
	let playing = null; // { id, since } while a track plays
	// An open is the first play of a track after it loads. The page can load a track before this runs,
	// and resuming after a pause is not a new open.
	let opened = null;
	const entry = ( id ) => {
		if ( ! counts.has( id ) ) {
			counts.set( id, { opens: 0, loops: 0, seconds: 0 } );
		}
		return counts.get( id );
	};
	// Add the time played so far to the playing track, and keep timing it.
	const tick = () => {
		if ( playing ) {
			const now = performance.now();
			entry( playing.id ).seconds += ( now - playing.since ) / 1000;
			playing.since = now;
		}
	};
	const stop = () => {
		tick();
		playing = null;
	};
	const send = () => {
		tick();
		const config = cb.data( 'callboard/practice' );
		const body = [ ...counts ]
			.map( ( [ track, c ] ) => ( {
				track,
				opens: c.opens,
				loops: c.loops,
				seconds: Math.round( c.seconds ),
			} ) )
			.filter( ( c ) => c.opens || c.loops || c.seconds );
		if ( ! body.length || ! config?.url ) {
			return;
		}
		const url = new URL( config.url, window.location.href );
		if ( config.nonce ) {
			url.searchParams.set( '_wpnonce', config.nonce ); // sendBeacon cannot set headers
		}
		// Sent as text/plain, which a beacon can send without a CORS preflight in every browser.
		const queued = navigator.sendBeacon(
			url.href,
			new Blob( [ JSON.stringify( body ) ], { type: 'text/plain' } )
		);
		if ( queued ) {
			counts.clear();
		}
	};
	/**
	 * Practice counts, as an extension: listens to player events and sends totals.
	 */
	cb.registerExtension( 'callboard/practice', {
		version: '1.0.0',
		apiVersion: 1,
		events: {
			track() {
				stop();
				opened = null;
			},
			loop( range ) {
				const track = cb.state.track;
				if ( range && track?.id ) {
					entry( track.id ).loops++;
				}
			},
			play( { track } ) {
				stop();
				if ( track?.id ) {
					if ( opened !== track.id ) {
						entry( track.id ).opens++;
						opened = track.id;
					}
					playing = { id: track.id, since: performance.now() };
				}
			},
			pause: stop,
			ended: stop,
		},
		setup() {
			document.addEventListener( 'visibilitychange', () => {
				if ( document.visibilityState === 'hidden' ) {
					send();
				}
			} );
			window.addEventListener( 'pagehide', send );
			setInterval( send, 5 * 60 * 1000 );
		},
	} );
} )();
