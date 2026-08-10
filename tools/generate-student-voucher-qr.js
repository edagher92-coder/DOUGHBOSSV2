#!/usr/bin/env node
'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const qrcode = require( '../public/vendor/qrcode-generator/qrcode.js' );

const targetUrl = 'https://doughboss.com.au/student-vouchers/';
const outputDir = path.resolve( __dirname, '../docs/brand' );
const qr = qrcode( 0, 'H' );
qr.addData( targetUrl );
qr.make();

const moduleCount = qr.getModuleCount();
const quietModules = 4;

function qrRects( x, y, cell ) {
	const rects = [];
	for ( let row = 0; row < moduleCount; row += 1 ) {
		for ( let column = 0; column < moduleCount; column += 1 ) {
			if ( qr.isDark( row, column ) ) {
				rects.push( `<rect x="${x + ( column + quietModules ) * cell}" y="${y + ( row + quietModules ) * cell}" width="${cell}" height="${cell}"/>` );
			}
		}
	}
	return rects.join( '' );
}

const standaloneCell = 24;
const standaloneSize = ( moduleCount + quietModules * 2 ) * standaloneCell;
const standaloneSvg = [
	'<?xml version="1.0" encoding="UTF-8"?>',
	`<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="title desc" width="${standaloneSize}" height="${standaloneSize}" viewBox="0 0 ${standaloneSize} ${standaloneSize}" shape-rendering="crispEdges">`,
	'<title id="title">Dough Boss student voucher QR code</title>',
	`<desc id="desc">Scan to open ${targetUrl}</desc>`,
	'<rect width="100%" height="100%" fill="#fff"/>',
	`<g fill="#000">${qrRects( 0, 0, standaloneCell )}</g>`,
	'</svg>',
	'',
].join( '\n' );

const posterWidth = 1240;
const posterHeight = 1754;
const posterCell = 20;
const posterQrSize = ( moduleCount + quietModules * 2 ) * posterCell;
const posterQrX = Math.round( ( posterWidth - posterQrSize ) / 2 );
const posterQrY = 515;
const posterSvg = [
	'<?xml version="1.0" encoding="UTF-8"?>',
	`<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="title desc" width="${posterWidth}" height="${posterHeight}" viewBox="0 0 ${posterWidth} ${posterHeight}">`,
	'<title id="title">Dough Boss five dollar student voucher</title>',
	`<desc id="desc">A print-ready poster with a QR code linking to ${targetUrl}</desc>`,
	'<rect width="1240" height="1754" fill="#0d0b0a"/>',
	'<circle cx="1135" cy="120" r="260" fill="#e8271d" opacity=".92"/>',
	'<circle cx="80" cy="1660" r="300" fill="#e8271d" opacity=".18"/>',
	'<rect x="70" y="64" width="440" height="100" rx="4" fill="none" stroke="#fff" stroke-width="5"/>',
	'<text x="290" y="129" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="36" font-weight="800" letter-spacing="7" text-anchor="middle">DOUGH BOSS.</text>',
	'<text x="620" y="260" fill="#f4eee5" font-family="Arial,Helvetica,sans-serif" font-size="54" font-weight="700" letter-spacing="8" text-anchor="middle">STUDENT OFFER</text>',
	'<text x="620" y="390" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="112" font-weight="900" letter-spacing="2" text-anchor="middle">GET $5 OFF</text>',
	'<text x="620" y="465" fill="#d8cec2" font-family="Arial,Helvetica,sans-serif" font-size="34" text-anchor="middle">Scan to claim with your eligible student email</text>',
	`<rect x="${posterQrX}" y="${posterQrY}" width="${posterQrSize}" height="${posterQrSize}" rx="34" fill="#fff"/>`,
	`<g fill="#000" shape-rendering="crispEdges">${qrRects( posterQrX, posterQrY, posterCell )}</g>`,
	'<text x="620" y="1490" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="46" font-weight="800" text-anchor="middle">SCAN • CLAIM • SHOW AT THE TILL</text>',
	'<text x="620" y="1562" fill="#d8cec2" font-family="Arial,Helvetica,sans-serif" font-size="28" text-anchor="middle">One voucher per eligible student email. Daily allocation applies.</text>',
	'<text x="620" y="1624" fill="#d8cec2" font-family="Arial,Helvetica,sans-serif" font-size="28" text-anchor="middle">Single use • No cash value • Terms apply</text>',
	'<text x="620" y="1702" fill="#f4eee5" font-family="Arial,Helvetica,sans-serif" font-size="32" font-weight="700" letter-spacing="2" text-anchor="middle">doughboss.com.au/student-vouchers/</text>',
	'</svg>',
	'',
].join( '\n' );

const gifData = qr.createDataURL( 24, quietModules * 24 );
const gifBase64 = gifData.replace( /^data:image\/gif;base64,/, '' );

fs.writeFileSync( path.join( outputDir, 'doughboss-student-voucher-qr.svg' ), standaloneSvg );
fs.writeFileSync( path.join( outputDir, 'doughboss-student-voucher-qr.gif' ), Buffer.from( gifBase64, 'base64' ) );
fs.writeFileSync( path.join( outputDir, 'doughboss-student-voucher-qr-poster-a4.svg' ), posterSvg );

console.log( JSON.stringify( {
	targetUrl,
	moduleCount,
	standaloneSize,
	files: [
		'docs/brand/doughboss-student-voucher-qr.svg',
		'docs/brand/doughboss-student-voucher-qr.gif',
		'docs/brand/doughboss-student-voucher-qr-poster-a4.svg',
	],
} ) );
