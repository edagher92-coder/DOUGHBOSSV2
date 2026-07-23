'use strict';

var fs = require('fs');
var path = require('path');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var order = fs.readFileSync(path.join(root, 'demo', 'menu-order.js'), 'utf8');
var profile = fs.readFileSync(path.join(root, 'demo', 'config', 'profiles', 'doughboss-revesby-launch.js'), 'utf8');
var index = fs.readFileSync(path.join(root, 'demo', 'index.html'), 'utf8');
var franchise = fs.readFileSync(path.join(root, 'demo', 'franchise.html'), 'utf8');

assert.match(profile, /orderingHours:\s*\{/);
assert.match(profile, /display:\s*'Daily 6:30am–2:30pm'/);
assert.match(order, /function orderingHours\(location\)/);
assert.match(order, /timeZone:\s*timezone/);
assert.match(order, /Intl\.DateTimeFormat\('en-AU'/);
assert.match(order, /afterHours = !storeStatus\(window\.DBDemo\.getLocation\(locationId\)\)\.open/);
assert.match(order, /if \(afterHours\) \{ renderDone\(\); return; \}/);
assert.match(order, /Send preorder request/);
assert.match(order, /No payment now/);
assert.match(order, /Awaiting morning review/);
assert.match(order, /No payment has been taken/);
assert.doesNotMatch(order, /contacting me about this order and occasional offers/i);
assert.doesNotMatch(order, /please order during opening hours/i);
assert.doesNotMatch(index, /0400 000 000/);
assert.doesNotMatch(franchise, /halal Lebanese bakery/i);
assert.doesNotMatch(franchise, /Lebanese bakery/i);

console.log('After-hours preorder and inclusive contact contracts passed.');
