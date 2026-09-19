/* eslint-disable no-console -- a command-line script; it talks. */
/* Fixture audio for the demo set: Compositions by the Airmen of Note.

   The United States Air Force publishes the album as public-domain music. Wikimedia Commons marks
   the composition, performance, and recording of every source used here as a U.S. Government work.
   This script downloads Commons' copies, creates compact 40-second excerpts for the repository and
   retains the source and rights evidence in manifest.json.

   Run: node tests/fixtures/jazz.js (needs ffmpeg, ffprobe and a network connection). */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const CLIP = 40;
const ROOT = path.join( __dirname, 'callboard' );
const ALBUM = 'Compositions';
const PERFORMER = 'Airmen of Note, United States Air Force Band';
const SOURCE =
	'https://www.music.af.mil/Multimedia/Music/Public-Domain-Music/';
const LICENSE =
	'https://commons.wikimedia.org/wiki/Template:PD-USGov-Military-Air_Force';
const TRACKS = [
	[ 'intensities', 'Intensities in Ten Cities', 'Alan Baylock', 'd/d3' ],
	[ 'underground', 'Underground', 'Tyler Kuebler', '8/88' ],
	[ 'detritus', 'Trombone Detritus', 'Ben Patterson', '7/79' ],
	[ 'new-day', "It's a Brand New Day", 'Steve Erickson', 'd/df' ],
	[ 'stole-back', 'Stole Back My Soul', 'Alan Baylock', '8/85' ],
	[ 'sunk-in-dars', 'Sunk in Dars', 'Tyler Kuebler', '5/5a' ],
	[ 'little-dreamer', 'Little Dreamer, Big Dreams', 'Ben Patterson', 'a/ad' ],
	[ 'convergence', 'Convergence', 'Jeff Martin', 'e/e9' ],
	[ 'hickory', 'Hickory and Twine', 'Alan Baylock', 'b/bb' ],
	[ 'everyday', 'Everyday Adventures', 'Ben Patterson', '1/11' ],
];

const sh = ( cmd, args ) =>
	execFileSync( cmd, args, {
		stdio: [ 'ignore', 'pipe', 'inherit' ],
		maxBuffer: 1 << 28,
	} );

const commonsName = ( title ) =>
	`${ title } - Airmen of Note - United States Air Force Band.mp3`;
const commonsPage = ( title ) =>
	`https://commons.wikimedia.org/wiki/File:${ encodeURIComponent(
		commonsName( title ).replaceAll( ' ', '_' )
	) }`;
const commonsFile = ( title, bucket ) =>
	`https://upload.wikimedia.org/wikipedia/commons/${ bucket }/${ encodeURIComponent(
		commonsName( title ).replaceAll( ' ', '_' )
	) }`;

/* Same envelope the plugin measures on import, so the waveform is right without a second pass. */
function levels( file ) {
	const pcm = sh( 'ffmpeg', [
		'-v',
		'error',
		'-i',
		file,
		'-ac',
		'1',
		'-ar',
		'1000',
		'-f',
		'u8',
		'-',
	] );
	const n = Math.floor( pcm.length / 100 );
	const values = [];
	let peak = 1;
	for ( let k = 0; k < n; k++ ) {
		let sum = 0;
		for ( let j = 0; j < 100; j++ ) {
			sum += Math.abs( pcm[ k * 100 + j ] - 128 );
		}
		values[ k ] = sum / 100;
		peak = Math.max( peak, values[ k ] );
	}
	return values
		.map( ( value ) =>
			Math.min( 9, Math.round( 9 * Math.sqrt( value / peak ) ) )
		)
		.join( '' );
}

const configuredCache = process.env.CALLBOARD_JAZZ_CACHE;
const tmp = configuredCache
	? path.resolve( configuredCache )
	: fs.mkdtempSync( path.join( os.tmpdir(), 'callboard-jazz-' ) );
fs.mkdirSync( tmp, { recursive: true } );
const dir = path.join( ROOT, 'demo-set' );
fs.mkdirSync( dir, { recursive: true } );
for ( const file of fs.readdirSync( dir ) ) {
	if ( /\.(mp3|json)$/.test( file ) ) {
		fs.unlinkSync( path.join( dir, file ) );
	}
}

const tracks = [];
const envelopes = {};
TRACKS.forEach( ( [ id, title, composer, bucket ], index ) => {
	const number = String( index + 1 ).padStart( 2, '0' );
	const source = path.join( tmp, `${ number }.mp3` );
	const file = `${ number } - ${ title } [${ id }].mp3`;
	const output = path.join( dir, file );

	console.log( `${ number }  Downloading ${ title }` );
	if ( ! fs.existsSync( source ) ) {
		sh( 'curl', [
			'-fLsS',
			'--retry',
			'5',
			'--retry-all-errors',
			'--retry-delay',
			'5',
			'--user-agent',
			'Callboard fixture builder/1.0',
			commonsFile( title, bucket ),
			'-o',
			source,
		] );
	}
	sh( 'ffmpeg', [
		'-v',
		'error',
		'-y',
		'-ss',
		'5',
		'-t',
		String( CLIP ),
		'-i',
		source,
		'-af',
		`afade=t=in:d=0.4,afade=t=out:st=${
			CLIP - 1.2
		}:d=1.2,loudnorm=I=-18:TP=-1.5:LRA=11`,
		'-ar',
		'44100',
		'-b:a',
		'96k',
		'-id3v2_version',
		'3',
		'-metadata',
		`title=${ title }`,
		'-metadata',
		`artist=${ PERFORMER }`,
		'-metadata',
		`composer=${ composer }`,
		'-metadata',
		`album=${ ALBUM }`,
		'-metadata',
		'copyright=Public domain — U.S. Government work',
		output,
	] );

	const duration = Math.round(
		parseFloat(
			sh( 'ffprobe', [
				'-v',
				'error',
				'-show_entries',
				'format=duration',
				'-of',
				'csv=p=0',
				output,
			] ).toString()
		)
	);
	envelopes[ id ] = levels( output );
	tracks.push( {
		index: index + 1,
		id,
		title,
		file,
		duration,
		url: commonsPage( title ),
		uploader: PERFORMER,
		uploader_url: SOURCE,
		composer,
		license: 'Public domain — U.S. Government work',
		license_url: LICENSE,
	} );
} );

const write = ( file, data, pretty ) =>
	fs.writeFileSync(
		file,
		( pretty
			? JSON.stringify( data, null, '\t' )
			: JSON.stringify( data ) ) + '\n'
	);
write(
	path.join( dir, 'manifest.json' ),
	{
		name: ALBUM,
		slug: 'demo-set',
		order: -1,
		playlist_url: SOURCE,
		curator: PERFORMER,
		curator_url: SOURCE,
		license: 'Public domain — U.S. Government work',
		license_url: LICENSE,
		tracks,
	},
	true
);
write( path.join( dir, 'levels.json' ), envelopes );
write( path.join( dir, 'notes.json' ), {
	detritus: [
		{ t: 4, text: 'Softer here', date: '2026-09-01' },
		{ t: 9, text: 'Lean into the turn', date: '2026-09-04' },
	],
} );
write( path.join( dir, 'tempo.json' ), { detritus: 96 } );
if ( ! configuredCache ) {
	fs.rmSync( tmp, { recursive: true, force: true } );
}
console.log(
	`\n${ tracks.length } tracks into ${ path.relative( process.cwd(), dir ) }`
);
