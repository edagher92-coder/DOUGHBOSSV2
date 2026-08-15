'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const badge = read('includes/class-doughboss-staff-badge.php');
const clock = read('includes/class-doughboss-timeclock.php');
const scope = read('includes/class-doughboss-staff-scope.php');
const activator = read('includes/class-doughboss-activator.php');
const core = read('includes/class-doughboss.php');
const kioskJs = read('public/js/doughboss-staff-badge.js');
const uninstall = read('uninstall.php');

test('QR kiosk boots from the plugin and never creates a WordPress login', () => {
	assert.match(core, /class-doughboss-staff-badge\.php/);
	assert.match(core, /new DoughBoss_Staff_Badge\(\)/);
	assert.match(badge, /admin_post_nopriv_doughboss_staff_badge_pin/);
	assert.match(badge, /admin_post_nopriv_doughboss_staff_badge_action/);
	assert.doesNotMatch(badge, /wp_set_auth_cookie|wp_signon|wp_set_current_user/);
});

test('badge bearer and PIN secrets are hashed, short lived and revocable', () => {
	assert.match(badge, /hash\(\s*'sha256',\s*\$token\s*\)/);
	assert.match(badge, /wp_hash_password\(\s*\$pin\s*\)/);
	assert.match(badge, /wp_check_password\(\s*\$pin/);
	assert.match(badge, /const SESSION_TTL\s*=\s*120/);
	assert.match(badge, /const MAX_PIN_ATTEMPTS\s*=\s*5/);
	assert.match(badge, /const LOCK_TTL\s*=\s*900/);
	assert.match(badge, /ATTEMPT_PREFIX/);
	assert.match(badge, /get_transient\(\s*self::attempt_key/);
	assert.match(badge, /set_transient\(\s*self::attempt_key/);
	assert.match(badge, /status\s*=\s*%s[\s\S]*?'revoked'/);
	assert.doesNotMatch(activator, /\btoken\s+varchar|\bpin\s+varchar/i);
	assert.match(activator, /token_hash\s+char\(64\)/);
	assert.match(activator, /pin_hash\s+varchar\(255\)/);
	assert.match(activator, /UNIQUE KEY\s+user_active_guard\s*\(user_id,active_guard\)/);
});

test('kiosk cookie and transitions fail closed', () => {
	assert.match(badge, /'secure'\s*=>\s*is_ssl\(\)/);
	assert.match(badge, /'httponly'\s*=>\s*true/);
	assert.match(badge, /'samesite'\s*=>\s*'Strict'/);
	assert.match(badge, /verify_session_nonce/);
	assert.match(badge, /DoughBoss_Timeclock::storage_ready\(\)/);
	assert.match(badge, /destroy_session\(\)[\s\S]*?redirect_clock/);
	assert.match(clock, /SELECT GET_LOCK\(%s,\s*5\)/);
});

test('scanner accepts only the same-site staff-clock badge URL', () => {
	assert.match(badge, /REQUEST_URI[\s\S]*?home_url\(\s*'\/staff-clock\/'\s*\)/);
	assert.match(kioskJs, /url\.origin\s*!==\s*window\.location\.origin/);
	assert.match(kioskJs, /url\.pathname\.replace\([^\n]+\)\s*!==\s*'\/staff-clock'/);
	assert.match(kioskJs, /url\.searchParams\.get\(\s*'staff_badge'\s*\)/);
	assert.doesNotMatch(kioskJs, /fetch\(|XMLHttpRequest|https?:\/\//);
});

test('recorded breaks are serialized and are the only worked-time deduction', () => {
	assert.match(activator, /CREATE TABLE \{\$staff_breaks\}[\s\S]*?UNIQUE KEY\s+shift_open_guard\s*\(shift_id,open_guard\)/);
	assert.match(badge, /function start_break/);
	assert.match(badge, /function end_break/);
	assert.match(badge, /break_end_utc\s*=\s*%s,\s*open_guard\s*=\s*NULL/);
	assert.match(clock, /worked_minutes[\s\S]*?break_minutes/);
	assert.match(clock, /Return only stored break minutes; no automatic or assumed deduction exists/);
});

test('roster decisions and late minutes are immutable shift snapshots', () => {
	assert.match(scope, /const ROSTER_META\s*=\s*'doughboss_staff_roster'/);
	assert.match(scope, /function roster_snapshot/);
	for (const field of ['scheduled_start_local', 'late_grace_minutes', 'late_minutes']) {
		assert.match(activator, new RegExp('\\b' + field + '\\b'));
		assert.match(clock, new RegExp("'" + field + "'\\s*=>"));
	}
	assert.match(scope, /Leave a day blank when no roster is set; no lateness is assumed/);
});

test('badge issue and revoke forms use protected manager handlers', () => {
	assert.match(badge, /action="<\?php echo esc_url\( admin_url\( 'admin-post\.php' \) \); \?>"/);
	assert.match(badge, /check_admin_referer\(\s*'doughboss_issue_staff_badge'/);
	assert.match(badge, /check_admin_referer\(\s*'doughboss_revoke_staff_badge_'/);
	assert.match(badge, /manage_doughboss/);
	assert.match(badge, /public\/vendor\/qrcode-generator\/qrcode\.js/);
});

test('uninstall removes badge, break, roster and temporary session data', () => {
	assert.match(uninstall, /doughboss_staff_breaks/);
	assert.match(uninstall, /doughboss_staff_badges/);
	assert.match(uninstall, /doughboss_staff_roster/);
	assert.match(uninstall, /doughboss_staff_badge_session_/);
	assert.match(uninstall, /doughboss_staff_badge_locked_/);
	assert.match(uninstall, /doughboss_staff_badge_attempts_/);
});
