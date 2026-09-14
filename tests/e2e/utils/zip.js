/**
 * Reads a zip the way an importer would, checking every entry's CRC, so a test can look inside a set file.
 */
const zlib = require( 'node:zlib' );

/**
 * The entries of a zip.
 *
 * @param {Buffer} buf The whole file.
 * @return {Map<string, Buffer>} Entry name to its bytes. Throws when an entry's CRC doesn't match.
 */
const readZip = ( buf ) => {
	const end = buf.lastIndexOf( Buffer.from( [ 0x50, 0x4b, 0x05, 0x06 ] ) );
	if ( end < 0 ) {
		throw new Error( 'not a zip' );
	}
	const count = buf.readUInt16LE( end + 10 );
	let p = buf.readUInt32LE( end + 16 );
	const out = new Map();
	for ( let n = 0; n < count; n++ ) {
		if ( buf.readUInt32LE( p ) !== 0x02014b50 ) {
			throw new Error( 'bad central directory' );
		}
		const method = buf.readUInt16LE( p + 10 ),
			crc = buf.readUInt32LE( p + 16 ),
			packed = buf.readUInt32LE( p + 20 ),
			nameLen = buf.readUInt16LE( p + 28 ),
			extraLen = buf.readUInt16LE( p + 30 ),
			commentLen = buf.readUInt16LE( p + 32 ),
			at = buf.readUInt32LE( p + 42 );
		const name = buf.toString( 'utf8', p + 46, p + 46 + nameLen );
		const start =
			at + 30 + buf.readUInt16LE( at + 26 ) + buf.readUInt16LE( at + 28 );
		const raw = buf.subarray( start, start + packed );
		const data = method === 8 ? zlib.inflateRawSync( raw ) : raw;
		if ( zlib.crc32( data ) !== crc ) {
			throw new Error( `CRC mismatch in ${ name }` );
		}
		out.set( name, data );
		p += 46 + nameLen + extraLen + commentLen;
	}
	return out;
};

module.exports = { readZip };
