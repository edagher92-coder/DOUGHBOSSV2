'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const root = path.resolve( __dirname, '..' );
const brandDir = path.join( root, 'docs', 'brand' );
const targetUrl = 'https://doughboss.com.au/student-vouchers/';

test( 'standalone student-voucher QR assets are high-resolution and use the canonical claim URL', () => {
	const svg = fs.readFileSync( path.join( brandDir, 'doughboss-student-voucher-qr.svg' ), 'utf8' );
	const gif = fs.readFileSync( path.join( brandDir, 'doughboss-student-voucher-qr.gif' ) );

	assert.match( svg, /width="1080" height="1080"/ );
	assert.ok( svg.includes( targetUrl ) );
	assert.match( svg, /shape-rendering="crispEdges"/ );
	assert.equal( gif.subarray( 0, 6 ).toString( 'ascii' ), 'GIF87a' );
	assert.equal( gif.readUInt16LE( 6 ), 1080 );
	assert.equal( gif.readUInt16LE( 8 ), 1080 );
} );

test( 'print-ready poster carries the correct offer terms and raster dimensions', () => {
	const svg = fs.readFileSync( path.join( brandDir, 'doughboss-student-voucher-qr-poster-a4.svg' ), 'utf8' );
	const png = fs.readFileSync( path.join( brandDir, 'doughboss-student-voucher-qr-poster-a4.png' ) );

	assert.match( svg, /width="1240" height="1754"/ );
	assert.ok( svg.includes( 'One voucher per eligible student email. Daily allocation applies.' ) );
	assert.ok( svg.includes( targetUrl ) );
	assert.deepEqual( Array.from( png.subarray( 0, 8 ) ), [ 137, 80, 78, 71, 13, 10, 26, 10 ] );
	assert.equal( png.readUInt32BE( 16 ), 1240 );
	assert.equal( png.readUInt32BE( 20 ), 1754 );
} );

test( 'generator keeps a four-module quiet zone and high error correction', () => {
	const source = fs.readFileSync( path.join( root, 'tools', 'generate-student-voucher-qr.js' ), 'utf8' );

	assert.ok( source.includes( "const targetUrl = 'https://doughboss.com.au/student-vouchers/';" ) );
	assert.ok( source.includes( "const qr = qrcode( 0, 'H' );" ) );
	assert.ok( source.includes( 'const quietModules = 4;' ) );
} );
