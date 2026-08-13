­r‡^Ñf¥–Ø¦{M¬yÊ'vÃ®¶›­'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const plugin = read('doughboss.php');
const activator = read('includes/class-doughboss-activator.php');
const migration = read('includes/class-doughboss-migrations.php');
const occupancy = read('includes/class-doughboss-table-occupancy.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const board = read('public/js/doughboss-orderboard.js');
const css = read('public/css/doughboss-orderboard.css');
const tableQr = read('includes/class-doughboss-table-qr.php');

test('table occupancy is a separate additive 2.37.0 schema concern, never a customer QR-ordering gate', () => {
	assert.match(plugin, /DOUGHBOSS_VERSION', '2\.37\.0'/);
	assert.match(plugin, /DOUGHBOSS_DB_VERSION', '1\.21\.0'/);
	assert.match(activator, /manual_reserved_until datetime NULL DEFAULT NULL/);
	assert.match(activator, /manual_released_at datetime NULL DEFAULT NULL/);
	assert.match(activator, /manual_release_order_id bigint\(20\) unsigned NOT NULL DEFAULT 0/);
	assert.match(activator, /reservation_version bigint\(20\) unsigned NOT NULL DEFAULT 1/);
	assert.match(activator, /doughboss_table_reservation_events/);
	assert.match(activator, /KEY location_manual_reservation \(location_id,manual_reserved_until\)/);
	assert.match(activator, /public static function table_occupancy_storage_ready/);
	assert.match(migration, /'1\.21\.0' => 'upgrade_to_1_21_0'/);
	assert.match(migration, /DoughBoss_Activator::table_occupancy_storage_ready/);

	const qrReady = activator.slice(activator.indexOf('public static function table_qr_storage_ready'), activator.indexOf('public static function table_occupancy_storage_ready'));
	assert.doesNotMatch(qrReady, /manual_reserved_until|manual_released_at|table_reservation_events/);
});

test('effective QR order holds are durable, fifteen minutes, renewable and release-aware', () => {
	assert.match(occupancy, /const HOLD_SECONDS = 900/);
	assert.match(occupancy, /newest\.order_type = 'dine_in'/);
	assert.match(occupancy, /newest\.order_source = 'table_qr'/);
	assert.match(occupancy, /newest\.status <> 'cancelled'/);
	assert.match(occupancy, /newest\.created_at > COALESCE\(t\.manual_released_at/);
	assert.match(occupancy, /newest\.id > t\.manual_release_order_id/);
	assert.match(occupancy, /newest\.created_at > DATE_SUB\(UTC_TIMESTAMP\(\), INTERVAL 15 MINUTE\)/);
	assert.match(occupancy, /ORDER BY newest\.created_at DESC, newest\.id DESC/);
	assert.match(occupancy, /\+ self::HOLD_SECONDS/);
	assert.match(occupancy, /manual_released_at.*=> \$now/s);
	assert.match(occupancy, /SELECT id FROM \{\$orders\} WHERE table_id = %d AND order_type = 'dine_in' AND order_source = 'table_qr'/);
	assert.doesNotMatch(occupancy, /add_action\(\s*'doughboss_order_created'/);
});

test('staff table actions are authenticated, location-scoped, nonce-protected and concurrency-safe', () => {
	assert.match(rest, /'\/admin\/table-occupancy'/);
	assert.match(rest, /'\/admin\/table\/\(\?P<id>\\d\+\)\/reservation'/);
	assert.match(rest, /'permission_callback'\s*=> array\( \$this, 'verify_board_access' \)/);
	assert.match(rest, /'permission_callback'\s*=> array\( \$this, 'verify_board_mutation' \)/);
	assert.match(rest, /public function verify_board_mutation/);
	assert.match(rest, /return \$this->verify_nonce\( \$request \)/);
	assert.match(rest, /DoughBoss_Staff_Scope::effective_location_id/);
	assert.match(occupancy, /SELECT \* FROM \{\$tables\} WHERE id = %d LIMIT 1 FOR UPDATE/);
	assert.match(occupancy, /SELECT location_id FROM \{\$tables\} WHERE id = %d LIMIT 1/);
	assert.match(occupancy, /SELECT table_id, event_type FROM \{\$events\} WHERE event_key = %s LIMIT 1/);
	assert.match(occupancy, /\$expected_version !== \(int\) \$row->reservation_version/);
	assert.match(activator, /UNIQUE KEY event_key \(event_key\)/);
});

test('table API and display are PII-free and never expose a QR bearer/session value', () => {
	const list = occupancy.slice(occupancy.indexOf('public static function list_tables'), occupancy.indexOf('public static function get_table'));
	assert.doesNotMatch(list, /token_hash|session_hash|cart_token_hash|customer_name|customer_phone|customer_email|address|notes/);
	const shape = occupancy.slice(occupancy.indexOf('private static function shape_table'));
	assert.doesNotMatch(shape, /token_hash|session_hash|cart_token_hash|customer_name|customer_phone|customer_email|address|notes/);
	assert.match(rest, /Cache-Control', 'no-store, private/);
	assert.match(tableQr, /t\.zone AS table_zone/);
	assert.match(tableQr, /'table_zone'\s*=> \$row->table_zone/);
	assert.match(rest, /'zone'\s*=> \$context\['table_zone'\]/);
});

test('Make and Pass receive a touch-first live table strip while Catering stays dedicated', () => {
	assert.match(board, /function loadTableOccupancy/);
	assert.match(board, /'\/admin\/table-occupancy'/);
	assert.match(board, /function updateTableReservation/);
	assert.match(board, /Reserve 15 min/);
	assert.match(board, /SCREEN_MODE === 'catering'/);
	assert.match(board, /expected_version: table\.reservation_version/);
	assert.match(board, /tableEventKey/);
	assert.match(css, /\.db-table-occupancy-grid/);
	assert.match(css, /\.db-table-card-action/);
	assert.match(css, /min-height: 52px/);
	assert.match(css, /grid-template-columns: repeat\(6, minmax\(0, 1fr\)\)/);
});
