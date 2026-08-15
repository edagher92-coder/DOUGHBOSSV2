'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const rest = read('includes/class-doughboss-rest-controller.php');
const order = read('includes/class-doughboss-order.php');
const catering = read('includes/class-doughboss-catering.php');
const portals = read('includes/class-doughboss-portals.php');
const board = read('public/js/doughboss-orderboard.js');
const css = read('public/css/doughboss-portals.css');

test('the protected summary reports exact location-scoped Make, Pass and Catering counts only', () => {
	assert.match(rest, /'\/admin\/board-summary'/);
	assert.match(rest, /'permission_callback'\s*=> array\( \$this, 'verify_board_access' \)/);
	assert.match(rest, /public function admin_board_summary/);
	assert.match(rest, /DoughBoss_Staff_Scope::effective_location_id/);
	assert.match(rest, /DoughBoss_Order::kitchen_workload_counts/);
	assert.match(rest, /DoughBoss_Catering::production_queue_count/);
	assert.match(rest, /Cache-Control', 'no-store, private/);
	const summary = rest.slice(rest.indexOf('public function admin_board_summary'), rest.indexOf('public function admin_update_status'));
	assert.doesNotMatch(summary, /customer_(?:name|phone)|address|notes/);

	const orderSummary = order.slice(order.indexOf('public static function kitchen_workload_counts'), order.indexOf('public static function preorder_requests'));
	assert.match(orderSummary, /'pending', 'confirmed', 'preparing', 'baking'/);
	assert.match(orderSummary, /'ready', 'out_for_delivery'/);
	assert.match(orderSummary, /order_source = 'preorder_request'/);
	assert.doesNotMatch(orderSummary, /LIMIT/);
	assert.match(catering, /public static function production_queue_count/);
	assert.match(catering, /self::STATUS_DEPOSIT/);
	assert.match(catering, /self::STATUS_PAID/);
});

test('all three one-screen navigation links receive safe live counts and stale-state handling', () => {
	assert.match(portals, /data-db-board-summary-link/);
	assert.match(portals, /data-db-board-count/);
	assert.match(portals, /aria-current="page"/);
	assert.match(board, /'\/admin\/board-summary'/);
	assert.match(board, /var summaryEpoch = 0/);
	assert.match(board, /new window\.AbortController/);
	assert.match(board, /value > 99 \? '99\+'/);
	assert.match(board, /last known count is stale/);
	assert.match(board, /summaryTimer = setInterval\(loadSummary, POLL_FAST\)/);
	assert.match(board, /loadSummary\(\);/);
	assert.match(css, /\.db-portal-live-count/);
	assert.match(css, /\.db-portal-mode--stale/);
	assert.match(css, /:focus-visible/);
	assert.match(css, /min-height: 52px/);
});
