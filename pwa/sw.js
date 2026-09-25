/* Callboard service worker: app shell cache + optional user-saved audio (served with Range support). */
const VERSION = '__VERSION__';
const APP = '__APP_VERSION__'; // the plugin version the pages compare against
const PLUGIN = '__PLUGIN_PATH__';
const HOME = '__HOME__'; // the site's home path, `/` or a network site's `/choir/`
const MANIFEST = '__MANIFEST__';
const ASSETS = __ASSETS__; // eslint-disable-line no-undef -- written by PHP: shell files, versioned the way the page requests them
const SITE = '__SITE__'; // the site ID on a multisite network, whose sites can share one origin and its caches; empty on a single site
const SHELL = `callboard-shell-${ SITE ? SITE + '-' : '' }${ VERSION }`;
const AUDIO = 'callboard-audio-v1';
// The shell files by path, so a page asking for a newer ?ver= than this worker was written with still
// reaches the copy it kept. The home page is left out: there the query string is the view.
const ASSET_PATHS = ASSETS.map(
	( a ) => new URL( a, location.origin ).pathname
).filter( ( p ) => p !== HOME );

self.addEventListener( 'install', ( e ) => {
	// One file at a time: addAll keeps nothing when any file fails, and one extension script that 404s
	// (its plugin gone, a bad src) would leave the app no home page and no script to open offline with.
	e.waitUntil(
		caches
			.open( SHELL )
			.then( ( c ) =>
				Promise.allSettled( ASSETS.map( ( u ) => c.add( u ) ) )
			)
			.catch( () => {} )
	);
	self.skipWaiting();
} );
self.addEventListener( 'activate', ( e ) =>
	e.waitUntil(
		( async () => {
			for ( const k of await caches.keys() ) {
				const others = SITE && ! k.startsWith( `callboard-shell-${ SITE }-` ); // another network site's caches
				if ( k !== SHELL && k !== AUDIO && ! others ) {
					await caches.delete( k );
				}
			}
			if ( self.registration.navigationPreload ) {
				await self.registration.navigationPreload.enable(); // the page request races the worker boot
			}
			await self.clients.claim();
			// Notifications were removed in 3.0. Drop a subscription left from before, since nothing will send to it now.
			const sub = self.registration.pushManager
				? await self.registration.pushManager.getSubscription().catch( () => null )
				: null;
			if ( sub ) {
				await sub.unsubscribe().catch( () => {} );
			}
			for ( const c of await self.clients.matchAll( {
				type: 'window',
			} ) ) {
				c.postMessage( { type: 'callboard:updated', version: APP } );
			}
		} )()
	)
);

/* A saved set has to open with no signal, so the page asks the worker to keep the set's page: the fragment a tap
   swaps in and the whole document a cold start asks for. The answer comes back once both are stored, inside
   waitUntil, so the page can hold "Saved offline" until it is true. */
self.addEventListener( 'message', ( e ) => {
	if ( e.data?.type !== 'callboard:keep' || ! e.ports[ 0 ] ) {
		return;
	}
	e.waitUntil(
		( async () => {
			const cache = await caches.open( SHELL );
			const kept = await Promise.all(
				e.data.urls.map( async ( u ) => {
					try {
						const res = await fetch( u, { cache: 'no-cache' } );
						if ( res.ok ) {
							await cache.put( u, res );
							return true;
						}
					} catch {
						// no signal: a copy kept earlier still counts
					}
					return !! ( await cache.match( u ) );
				} )
			);
			e.ports[ 0 ].postMessage( kept.every( Boolean ) );
		} )()
	);
} );

self.addEventListener( 'fetch', ( e ) => {
	const req = e.request;
	if ( req.method !== 'GET' ) {
		return;
	}
	const url = new URL( req.url );
	if ( url.origin !== location.origin ) {
		return;
	}
	if ( /\.(mp3|m4a|aac|ogg|opus|wav|flac)$/i.test( url.pathname ) ) {
		return e.respondWith( audio( req, url ) );
	}
	const fragment = url.searchParams.has( 'fragment' ); // a view without the shell, fetched by the page on navigation
	if ( req.mode === 'navigate' || fragment ) {
		if ( ! fragment && url.pathname.includes( '/wp-' ) ) {
			// wp-admin and wp-login are not ours, but with navigation preload on the browser has already sent
			// the request; hand that response over rather than let a second request follow the first, which
			// on an options.php redirect loses the "Settings saved" notice.
			return e.respondWith(
				( async () => ( await e.preloadResponse ) || fetch( req ) )()
			);
		}
		return e.respondWith( page( req, e, fragment ) );
	}
	// The shell list reaches beyond the plugin folder: core's hooks script and extension assets live
	// wherever WordPress or their own plugin put them, and an installed app has to open without them
	// fetching.
	if (
		url.pathname.startsWith( PLUGIN ) ||
		url.pathname + url.search === MANIFEST ||
		ASSETS.includes( url.pathname + url.search ) ||
		ASSET_PATHS.includes( url.pathname )
	) {
		return e.respondWith( staleWhileRevalidate( req, url ) );
	}
} );

async function audio( req, url ) {
	const cache = await caches.open( AUDIO );
	const hit = await cache.match( url.href, { ignoreVary: true } );
	if ( ! hit ) {
		return fetch( req );
	}
	const range = req.headers.get( 'range' );
	if ( ! range ) {
		return hit;
	}
	const blob = await hit.blob(); // disk-backed; slicing is cheap and streams
	const size = blob.size;
	const m = /bytes=(\d*)-(\d*)/.exec( range ) || [];
	let start = m[ 1 ] ? +m[ 1 ] : 0;
	let end = m[ 2 ] ? Math.min( +m[ 2 ], size - 1 ) : size - 1;
	if ( ! m[ 1 ] && m[ 2 ] ) {
		start = Math.max( size - +m[ 2 ], 0 );
		end = size - 1;
	}
	if ( start > end || start >= size ) {
		return new Response( null, {
			status: 416,
			headers: { 'Content-Range': `bytes */${ size }` },
		} );
	}
	return new Response( blob.slice( start, end + 1 ), {
		status: 206,
		headers: {
			'Content-Type': hit.headers.get( 'Content-Type' ) || 'audio/mpeg',
			'Content-Range': `bytes ${ start }-${ end }/${ size }`,
			'Content-Length': String( end - start + 1 ),
			'Accept-Ranges': 'bytes',
		},
	} );
}
async function page( req, e, fragment ) {
	const cache = await caches.open( SHELL );
	try {
		const res = ( await e.preloadResponse ) || ( await fetch( req ) );
		if ( res.ok ) {
			e.waitUntil( cache.put( req.url, res.clone() ) ); // the copy is what opens offline; don't let the worker stop before it lands
		}
		return res;
	} catch {
		// a fragment with no copy fails, and the page falls back to a full navigation, which lands here again
		// as a document and gets the shell's home
		return (
			( await cache.match( req.url ) ) ||
			( ! fragment && ( await cache.match( HOME ) ) ) ||
			new Response( 'You are offline.', {
				status: 503,
				headers: { 'Content-Type': 'text/plain' },
			} )
		);
	}
}
async function staleWhileRevalidate( req, url ) {
	const cache = await caches.open( SHELL );
	const cached = await cache.match( req.url );
	const network = fetch( req )
		.then( ( res ) => {
			if ( res.ok ) {
				cache.put( req.url, res.clone() );
			}
			return res;
		} )
		.catch( () => null );
	return (
		cached ||
		( await network ) ||
		// No signal, and no copy at this ?ver=: a plugin updated without the worker being rewritten.
		// The copy kept at the old version runs; nothing at all would leave its extension off.
		( url.pathname !== HOME &&
			( await cache.match( req.url, { ignoreSearch: true } ) ) ) ||
		new Response( '', { status: 504 } )
	);
}
