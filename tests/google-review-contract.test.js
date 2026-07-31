'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (...parts) => fs.readFileSync(path.join(root, ...parts), 'utf8');
const home = read('demo', 'index.html');
const demoOrder = read('demo', 'menu-order.js');
const settings = read('includes', 'class-doughboss-settings.php');
const assets = read('includes', 'class-doughboss-assets.php');
const seo = read('includes', 'class-doughboss-seo.php');
const storefront = read('public', 'js', 'doughboss.js');
const catering = read('public', 'js', 'doughboss-catering.js');
const admin = read('admin', 'class-doughboss-admin.php');

assert.match(home, /class="review-invite"/, 'homepage exposes a Google review invitation');
assert.match(home, /Leave a Google review/, 'homepage has a clear review action');
assert.ok(home.indexOf('Follow @doughboss') < home.indexOf('Leave a Google review'), 'homepage prioritises Instagram above the review action');
assert.match(home, /"hasMap":"https:\/\/www\.google\.com\/maps\//, 'demo local-business schema links to Google Maps');
assert.doesNotMatch(home, /aggregateRating|reviewRating/, 'demo does not publish self-serving rating markup');

assert.match(demoOrder, /\(afterHours \? '' : '<a href="' \+ GOOGLE_REVIEW_URL/, 'after-hours preorder requests do not receive a premature Google review prompt');
assert.match(demoOrder, /Leave a Google review/, 'completed demo orders invite genuine reviews');

assert.match(settings, /sanitize_google_review_url/, 'WordPress validates the review destination');
assert.match(settings, /google\\\.\(com\|com\\\.au\)/, 'WordPress only accepts supported Google domains');
assert.match(admin, /google_review_url/, 'owner can replace or disable the review link');
assert.match(assets, /'googleReviewUrl'\s*=>\s*DoughBoss_Settings::google_review_url\(\)/, 'validated link reaches public scripts');
assert.match(storefront, /DATA\.googleReviewUrl/, 'successful WordPress orders render the invitation');
assert.match(storefront, /data-doughboss-engagement.*social_engagement/s, 'WordPress order success labels Instagram engagement');
assert.match(catering, /Follow @doughboss/, 'catering success prioritises Instagram');
assert.match(catering, /data-doughboss-engagement.*review_engagement/s, 'catering review clicks are labelled for first-party measurement');
assert.match(seo, /'hasMap'/, 'WordPress local-business schema exposes map destinations');
assert.doesNotMatch(seo, /aggregateRating|reviewRating/, 'WordPress does not claim a first-party Google rating');

console.log('Google review contract: ethical prompts, owner configuration, URL validation, and local SEO linkage passed.');
