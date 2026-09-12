/**
 * A generated WAV file for tests that need audio the browser can decode everywhere.
 *
 * The fixture tracks are MP3. Serving a short WAV in their place with page.route() means a test does not
 * depend on which codecs a given headless Chromium build has.
 */

/**
 * A mono 16-bit PCM sine tone.
 *
 * @param {Object} [options]
 * @param {number} [options.seconds] Length.
 * @param {number} [options.rate]    Sample rate.
 * @param {number} [options.hz]      Tone frequency.
 * @return {Buffer} The whole file, header included.
 */
const wav = ( { seconds = 10, rate = 8000, hz = 440 } = {} ) => {
	const samples = Math.round( seconds * rate );
	const data = samples * 2;
	const buf = Buffer.alloc( 44 + data );
	buf.write( 'RIFF', 0 );
	buf.writeUInt32LE( 36 + data, 4 );
	buf.write( 'WAVE', 8 );
	buf.write( 'fmt ', 12 );
	buf.writeUInt32LE( 16, 16 ); // fmt chunk size
	buf.writeUInt16LE( 1, 20 ); // PCM
	buf.writeUInt16LE( 1, 22 ); // mono
	buf.writeUInt32LE( rate, 24 );
	buf.writeUInt32LE( rate * 2, 28 ); // bytes per second
	buf.writeUInt16LE( 2, 32 ); // bytes per frame
	buf.writeUInt16LE( 16, 34 ); // bits per sample
	buf.write( 'data', 36 );
	buf.writeUInt32LE( data, 40 );
	for ( let n = 0; n < samples; n++ ) {
		const v = Math.sin( ( 2 * Math.PI * hz * n ) / rate ) * 0.3 * 32767;
		buf.writeInt16LE( Math.round( v ), 44 + n * 2 );
	}
	return buf;
};

/**
 * Answer a route with a file, honouring a Range header the way a web server does, so the audio
 * element can seek.
 *
 * @param {import('@playwright/test').Route} route
 * @param {Buffer}                           body
 * @param {string}                           [contentType]
 */
const fulfillWithRanges = ( route, body, contentType = 'audio/wav' ) => {
	const range = route.request().headers().range;
	const match = range && /bytes=(\d*)-(\d*)/.exec( range );
	if ( ! match ) {
		return route.fulfill( {
			status: 200,
			contentType,
			headers: { 'Accept-Ranges': 'bytes' },
			body,
		} );
	}
	const start = match[ 1 ]
		? +match[ 1 ]
		: Math.max( 0, body.length - +match[ 2 ] );
	const end = match[ 1 ] && match[ 2 ] ? +match[ 2 ] : body.length - 1;
	return route.fulfill( {
		status: 206,
		contentType,
		headers: {
			'Accept-Ranges': 'bytes',
			'Content-Range': `bytes ${ start }-${ end }/${ body.length }`,
		},
		body: body.subarray( start, end + 1 ),
	} );
};

module.exports = { wav, fulfillWithRanges };
