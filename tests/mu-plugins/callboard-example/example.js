/**
 * The example extension's script: window.callboard and nothing else. See callboard-example-extension.php.
 */
( () => {
	const cb = window.callboard;
	if ( ! cb ) {
		return;
	}
	const body = document.body.dataset;
	const count = ( key ) =>
		( body[ key ] = String( +( body[ key ] || 0 ) + 1 ) );

	cb.registerExtension( 'example/demo', {
		version: '1.0.0',
		apiVersion: 1,
		setup() {
			count( 'exampleSetups' );
			// The button this extension put in the transport slot from PHP.
			document
				.querySelector( '.example-transport' )
				?.addEventListener( 'click', () =>
					count( 'exampleTransportClicks' )
				);
		},
		init( view ) {
			count( 'exampleInits' );
			view.main.dataset.exampleView = view.slug || 'home';
			// A listener bound to the view's signal is gone once the view is.
			document.addEventListener(
				'example:ping',
				() => count( 'examplePings' ),
				{
					signal: view.signal,
				}
			);
		},
		teardown() {
			count( 'exampleTeardowns' );
		},
		slots: {
			trackMeta: ( track ) => [
				{
					text: `${ track.ext[ 'example/demo' ].seconds }s <img src=x onerror="window.__exampleXss=true">`,
					className: 'example-meta',
					title: 'Seconds, from track data',
				},
			],
		},
		events: {
			track: ( { index } ) => ( body.exampleTrack = String( index ) ),
		},
		commands: {
			shout: ( word ) => {
				body.exampleShout = word;
				return String( word ).toUpperCase();
			},
			// Fires this extension's own event.
			ping: ( word ) => cb.emit( 'example.demo.pinged', { word } ),
		},
		badge: () => 3,
	} );

	cb.registerExtension( 'example/late', {
		version: '1.0.0',
		apiVersion: 1,
		events: {
			'example.demo.pinged': ( { word } ) =>
				( body.examplePinged = word ),
		},
	} );

	// Registered in PHP only when the test replaces callboard/quality; refused here otherwise.
	if ( cb.isActive( 'example/quality' ) ) {
		cb.registerExtension( 'example/quality', {
			version: '1.0.0',
			apiVersion: 1,
			slots: {
				nowPlayingMeta: ( track ) => [
					{
						text: `Example · ${ track.title }`,
						className: 'example-quality',
					},
				],
			},
		} );
	}
} )();
