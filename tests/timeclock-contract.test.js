/*
 * Structural guardrails for the staff-clock slice. The behaviour is exercised
 * in WordPress at runtime; these checks keep the security and schema anchors
 * from being accidentally removed during future visual work.
 */
const fs = require('fs');
const path = require('path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('staff clock uses authenticated individual accounts and nonce-protected actions', () => {
  const clock = read('includes/class-doughboss-timeclock.php');
  assert.match(clock, /is_user_logged_in\(\)/);
  assert.match(clock, /current_user_can\( 'clock_doughboss_staff' \)/);
  assert.match(clock, /check_admin_referer\( \$action \)/);
  assert.match(clock, /doughboss_clock_in/);
  assert.match(clock, /doughboss_clock_out/);
});

test('staff clock schema is durable, scoped, and supports one active-shift lookup', () => {
  const activator = read('includes/class-doughboss-activator.php');
  const clock = read('includes/class-doughboss-timeclock.php');
  assert.match(activator, /doughboss_staff_shifts/);
  assert.match(activator, /ENGINE=InnoDB/);
  assert.match(activator, /KEY user_open \(user_id,clock_out_utc\)/);
  assert.match(clock, /clock_out_utc IS NULL/);
  assert.match(clock, /GET_LOCK/);
});

test('staff clock portal is a private noindex route and has a manager timesheet', () => {
  const clock = read('includes/class-doughboss-timeclock.php');
  const activator = read('includes/class-doughboss-activator.php');
  const admin = read('admin/class-doughboss-admin.php');
  assert.match(activator, /staff-clock/);
  assert.match(clock, /noindex,nofollow/);
  assert.match(admin, /doughboss-timeclock/);
  assert.match(clock, /Staff timesheet/);
});
