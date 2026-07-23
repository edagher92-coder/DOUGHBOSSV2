'use strict';

/*
 * Static release contracts for the GitHub Pages demo's crawlable surfaces.
 * These assertions deliberately avoid a browser or network dependency.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const demo = path.join(root, 'demo');
const origin = 'https://edagher92-coder.github.io/DOUGHBOSSV2';
const socialImage = `${origin}/assets/social/doughboss-social-card.jpg`;
const pages = {
	home: { file: 'index.html', canonical: `${origin}/` },
	menu: { file: 'menu.html', canonical: `${origin}/menu.html` },
	catering: { file: 'catering.html', canonical: `${origin}/catering.html` },
	franchise: { file: 'franchise.html', canonical: `${origin}/franchise.html` },
};

function read(relativePath) {
	return fs.readFileSync(path.join(demo, relativePath), 'utf8');
}

function attribute(html, tag, name, value, attributeName) {
	const escaped = value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const match = html.match(new RegExp(`<${tag}\\b[^>]*\\b${name}=["']${escaped}["'][^>]*\\b${attributeName}=["']([^"']+)["'][^>]*>`, 'i'))
		|| html.match(new RegExp(`<${tag}\\b[^>]*\\b${attributeName}=["']([^"']+)["'][^>]*\\b${name}=["']${escaped}["'][^>]*>`, 'i'));
	return match && match[1];
}

function jpegDimensions(file) {
	const bytes = fs.readFileSync(file);
	assert.strictEqual(bytes.readUInt16BE(0), 0xffd8, 'social card must be a JPEG');
	let offset = 2;
	while (offset < bytes.length) {
		assert.strictEqual(bytes[offset], 0xff, 'invalid JPEG marker');
		const marker = bytes[offset + 1];
		offset += 2;
		if (marker === 0xd9 || marker === 0xda) break;
		const length = bytes.readUInt16BE(offset);
		if (marker >= 0xc0 && marker <= 0xc3) {
			return { width: bytes.readUInt16BE(offset + 5), height: bytes.readUInt16BE(offset + 3) };
		}
		offset += length;
	}
	throw new Error('JPEG dimensions not found');
}

const home = read(pages.home.file);
for (const [name, page] of Object.entries(pages)) {
	const html = read(page.file);
	assert.strictEqual(attribute(html, 'link', 'rel', 'canonical', 'href'), page.canonical, `${name} has its absolute canonical URL`);
	assert.strictEqual(attribute(html, 'meta', 'name', 'robots', 'content'), 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1', `${name} is indexable`);
	assert.strictEqual(attribute(html, 'meta', 'property', 'og:url', 'content'), page.canonical, `${name} Open Graph URL follows canonical`);
	assert.strictEqual(attribute(html, 'meta', 'property', 'og:type', 'content'), 'website', `${name} declares website Open Graph type`);
	assert.strictEqual(attribute(html, 'meta', 'property', 'og:image', 'content'), socialImage, `${name} uses the shared social image`);
	assert.strictEqual(attribute(html, 'meta', 'property', 'og:image:width', 'content'), '1200', `${name} declares social image width`);
	assert.strictEqual(attribute(html, 'meta', 'property', 'og:image:height', 'content'), '630', `${name} declares social image height`);
	assert.strictEqual(attribute(html, 'meta', 'name', 'twitter:card', 'content'), 'summary_large_image', `${name} uses a large Twitter card`);
	assert.strictEqual(attribute(html, 'meta', 'name', 'twitter:image', 'content'), socialImage, `${name} Twitter image matches Open Graph`);
}

assert.deepStrictEqual(jpegDimensions(path.join(demo, 'assets/social/doughboss-social-card.jpg')), { width: 1200, height: 630 }, 'social card matches declared dimensions');

const jsonLdBlocks = [...home.matchAll(/<script\b[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)];
assert.ok(jsonLdBlocks.length > 0, 'home includes JSON-LD');
const jsonLd = jsonLdBlocks.map((block) => JSON.parse(block[1].trim()));
assert.ok(jsonLd.some((schema) => schema['@context'] === 'https://schema.org' && Array.isArray(schema['@graph'])), 'home JSON-LD has a Schema.org graph');
assert.ok(jsonLd.flatMap((schema) => schema['@graph'] || []).some((node) => node['@type'] === 'Organization' && node.url === pages.home.canonical), 'home JSON-LD identifies the organization');

const robots = read('robots.txt');
const sitemap = read('sitemap.xml');
for (const page of Object.values(pages)) {
	assert.ok(sitemap.includes(`<loc>${page.canonical}</loc>`), `sitemap contains ${page.file}`);
}
assert.ok(robots.includes(`Sitemap: ${origin}/sitemap.xml`), 'robots advertises the deployed sitemap');
for (const adminPage of ['owner.html', 'staff.html', 'backend.html', 'kitchen.html']) {
	assert.match(read(adminPage), /<meta\s+name=["']robots["']\s+content=["']noindex,nofollow["']/i, `${adminPage} has noindex,nofollow`);
	assert.ok(!robots.includes(`Disallow: /${adminPage}`), `robots lets crawlers read ${adminPage}'s noindex`);
	assert.ok(!sitemap.includes(`>${origin}/${adminPage}<`), `${adminPage} is absent from sitemap`);
}

const marketing = home.match(/window\.DoughBossMarketingConfig=({[\s\S]*?});<\/script>/);
assert.ok(marketing, 'home declares a marketing configuration');
assert.match(marketing[1], /(?:^|[,\s{])enabled\s*:\s*false(?:[,\s}])/i, 'demo marketing is disabled by default');
assert.match(home, /<script\s+src=["']marketing\.js(?:\?[^"']*)?["']><\/script>/i, 'home loads the local marketing controller');

console.log('SEO contract: metadata, structured data, crawl policy, and marketing default passed.');
