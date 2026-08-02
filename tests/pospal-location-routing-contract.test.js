'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const pospalOrders = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-pospal-orders.php'), 'utf8');

test('POSPal order push defaults to the order location mapping before legacy store 1', function () {
	assert.match(pospalOrders, /DoughBoss_Locations::get\(\s*\(int\) \$order->location_id\s*\)/);
	assert.match(pospalOrders, /\$location->pospal_store_index/);
	assert.match(pospalOrders, /apply_filters\(\s*'doughboss_pospal_order_store_index',\s*\$default_store_index/);
});
