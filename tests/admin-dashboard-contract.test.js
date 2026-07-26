'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');

function read(file) {
	return fs.readFileSync(path.join(root, file), 'utf8');
}

var admin = read('admin/class-doughboss-admin.php');
var reports = read('includes/class-doughboss-reports.php');

test('operations dashboard is a separate manager-only page', function () {
	assert.match(admin, /'doughboss-dashboard'/);
	assert.match(admin, /function render_dashboard_page\(\)/);
	assert.match(admin, /current_user_can\( \$this->cap\(\) \)/);
});

test('dashboard sources payment, kitchen, POSPal and catering facts from durable records', function () {
	assert.match(admin, /DoughBoss_Reports::payment_attempt_statuses/);
	assert.match(admin, /DoughBoss_Reports::kitchen_timing/);
	assert.match(admin, /DoughBoss_Reports::pospal_sync_snapshot/);
	assert.match(admin, /DoughBoss_Reports::catering_pipeline/);
	assert.match(reports, /function payment_attempt_statuses/);
	assert.match(reports, /function kitchen_timing/);
	assert.match(reports, /function pospal_sync_snapshot/);
	assert.match(reports, /function catering_pipeline/);
});

test('dashboard does not claim a remote POSPal check or invent missing figures', function () {
	assert.match(admin, /this page never calls payment gateways or POSPal/);
	assert.match(admin, /'Not connected'/);
	assert.match(admin, /'No data'/);
	assert.match(admin, /does not claim remote till reachability/);
});
