const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

const qr = read('includes/class-doughboss-table-qr.php');
const admin = read('admin/class-doughboss-admin.php');
const activator = read('includes/class-doughboss-activator.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const order = read('includes/class-doughboss-order.php');
const staffScope = read('includes/class-doughboss-staff-scope.php');
const board = read('public/js/doughboss-orderboard.js');
const boardCss = read('public/css/doughboss-orderboard.css');
const guide = read('docs/DoughBoss-Revesby-Scan-To-Order-Launch-2026-08-02.md');
const plugin = read('includes/class-doughboss.php');
const catering = read('includes/class-doughboss-catering.php');

test('Revesby QR pack is manager-only, nonce protected and printable/exportable', () => {
  assert.match(admin, /current_user_can\( self::CAP \)/);
  assert.match(admin, /check_admin_referer\( 'doughboss_table_qr' \)/);
  assert.match(admin, /'create_pack' === \$action/);
  assert.match(admin, /Create Revesby QR pack/);
  assert.match(admin, /Print \/ save QR pack as PDF/);
  assert.match(admin, /Download SVG/);
});

test('bulk QR creation validates the whole pack and cleans up a failed partial request', () => {
  assert.match(qr, /public static function create_pack/);
  assert.match(qr, /count\( \$labels \) > 50/);
  assert.match(qr, /doughboss_table_pack_duplicate/);
  assert.match(qr, /SELECT label FROM \{\$tables\} WHERE location_id = %d/);
  assert.match(qr, /self::rollback_pack\( \$issued \)/);
  assert.match(qr, /private static function rollback_pack/);
});

test('table scan authority is opaque, cart-bound, expiring and rechecked on checkout paths', () => {
  assert.match(qr, /hash\( 'sha256', \(string\) \$raw \)/);
  assert.match(qr, /const SESSION_TTL = 28800/);
  assert.match(qr, /'cart_token_hash' => hash\( 'sha256', \$cart_token \)/);
  assert.match(qr, /t\.current_qr_code_id = q\.id/);
  assert.match(qr, /s\.expires_at > %s/);
  assert.match(qr, /l\.is_active = 1/);
  assert.match(rest, /\$location_id = \$table_context \? \(int\) \$table_context\['location_id'\]/);
  assert.match(rest, /'order_source'\s*=> \$table_context \? 'table_qr' : 'web'/);
});

test('staff roles preserve least privilege and management separation', () => {
  assert.match(activator, /'doughboss_kitchen'/);
  assert.match(activator, /'manage_doughboss_kds'\s*=> true/);
  assert.match(activator, /'doughboss_manager'/);
  assert.match(activator, /'manage_doughboss'\s*=> true/);
  assert.doesNotMatch(activator, /'doughboss_kitchen'[\s\S]{0,350}'manage_doughboss'\s*=> true/);
});

test('KDS access is server-scoped to an assigned active shop', () => {
  assert.match(staffScope, /const LOCATION_META = 'doughboss_location_id'/);
  assert.match(staffScope, /DoughBoss_Locations::single_location_id\(\)/);
  assert.match(staffScope, /doughboss_staff_location_required/);
  assert.match(staffScope, /public static function effective_location_id/);
  assert.match(staffScope, /public static function can_access_order/);
  assert.match(staffScope, /\(int\) \$order->location_id !== \(int\) \$scope/);
  assert.match(rest, /DoughBoss_Staff_Scope::effective_location_id/);
  assert.match(rest, /DoughBoss_Staff_Scope::can_access_order/);
  assert.match(admin, /DoughBoss_Staff_Scope::current_location_id/);
  assert.match(plugin, /class-doughboss-staff-scope\.php/);
  assert.match(plugin, /DoughBoss_Staff_Scope::init\(\)/);
});

test('KDS exposes operational truth and prevents stale or offline mutations', () => {
  assert.match(board, /var loadEpoch = 0/);
  assert.match(board, /requestEpoch !== loadEpoch/);
  assert.match(board, /window\.addEventListener\('offline'/);
  assert.match(board, /window\.addEventListener\('online'/);
  assert.match(board, /expected_version: o\.version/);
  assert.match(board, /var inFlight = \{\}/);
  assert.match(order, /doughboss_stale_order/);
});

test('KDS presents payment, service, table, modifiers and allergy exceptions', () => {
  assert.match(board, /Table service/);
  assert.match(board, /TABLE ' \+ tableLabel/);
  assert.match(board, /'table_qr': 'Table QR'/);
  assert.match(board, /db-card-item-toppings/);
  assert.match(board, /Allergy \/ dietary note/);
  assert.match(board, /Payment pending/);
  assert.match(board, /Payment failed - manager check/);
  assert.match(board, /confirmUnpaid/);
  assert.match(boardCss, /\.db-payment-pending/);
  assert.match(boardCss, /\.db-payment-failed/);
});

test('KDS includes audible and visible alerts plus polling fallback', () => {
  assert.match(board, /AudioContext/);
  assert.match(board, /db-alerting/);
  assert.match(board, /Sound is OFF/);
  assert.match(board, /var POLL_FAST/);
  assert.match(board, /var POLL_SAFETY/);
  assert.match(board, /new EventSource/);
  assert.match(board, /sseHealthy = false/);
});

test('compact catering display reads committed jobs from a protected shop-scoped feed', () => {
  assert.match(rest, /'\/admin\/catering-board'/);
  assert.match(rest, /'permission_callback'\s*=> array\( \$this, 'verify_board_access' \)/);
  assert.match(rest, /public function admin_catering_board/);
  assert.match(rest, /DoughBoss_Staff_Scope::effective_location_id/);
  assert.match(rest, /DoughBoss_Catering::production_queue/);
  assert.match(catering, /public static function production_queue/);
  assert.match(catering, /self::STATUS_DEPOSIT/);
  assert.match(catering, /self::STATUS_CONFIRMED/);
  assert.match(catering, /self::STATUS_BALANCE_DUE/);
  assert.match(catering, /self::STATUS_PAID/);
  assert.match(board, /'\/admin\/catering-board'/);
  assert.match(board, /function renderCatering/);
  assert.match(board, /Production display · lifecycle changes stay in Catering Enquiries/);
  assert.doesNotMatch(board, /orders\.filter\(isCateringOrder\)/);
});

test('checkout-to-kitchen event loop is durable, versioned and notification-aware', () => {
  assert.match(order, /START TRANSACTION/);
  assert.match(order, /checkout_key/);
  assert.match(order, /payment_intent_id/);
  assert.match(order, /doughboss_order_created/);
  assert.match(order, /doughboss_order_status_changed/);
  assert.match(rest, /'tracking_url' => DoughBoss_Settings::tracking_page_url/);
  assert.match(rest, /if \( ! \$replayed \)/);
});

test('launch guide keeps production disabled until physical and provider acceptance', () => {
  assert.match(guide, /Keep \*\*Accept orders\*\* and \*\*Accept card payments\*\* off/);
  assert.match(guide, /iPhone\/Safari and Android\/Chrome/);
  assert.match(guide, /exactly one payment, one authoritative order, one KDS card/);
  assert.match(guide, /POSPal mapping and the physical thermal printer/);
  assert.match(guide, /peak-hour rehearsal/);
});
