'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

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
	assert.doesNotMatch(badge, /Use staff account instead|wp_login_url/);
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
	assert.match(badge, /DoughBoss_Timeclock::with_user_lock\([\s\S]*?self::active_badge/);
	assert.match(badge, /if \( 'verified' !== \$verification \)/);
	assert.match(badge, /status\s*=\s*%s[\s\S]*?'revoked'/);
	assert.doesNotMatch(activator, /\btoken\s+varchar|\bpin\s+varchar/i);
	assert.match(activator, /token_hash\s+char\(64\)/);
	assert.match(badge, /pattern="\[0-9\]\{6,8\}" minlength="6" maxlength="8"/);
	assert.match(badge, /preg_match\( '\/\^\\d\{6,8\}\$\/', \$pin \)/);
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

test('scanner accepts only same-site staff-clock badges and posts fragment bearers', () => {
	assert.match(badge, /REQUEST_URI[\s\S]*?home_url\(\s*'\/staff-clock\/'\s*\)/);
	assert.match(badge, /admin_post_nopriv_doughboss_staff_badge_scan/);
	assert.match(badge, /handle_badge_scan\(\)[\s\S]*?REQUEST_METHOD[\s\S]*?begin_badge_session/);
	assert.match(badge, /home_url\( '\/staff-clock\/' \) \. '#staff-badge='/);
	assert.match(badge, /data-badge-scan-action=/);
	assert.match(kioskJs, /url\.origin\s*!==\s*window\.location\.origin/);
	assert.match(kioskJs, /url\.pathname\.replace\([^\n]+\)\s*!==\s*'\/staff-clock'/);
	assert.match(kioskJs, /\^#staff-badge=\(\[A-Za-z0-9_-\]\{40,120\}\)\$/);
	assert.match(kioskJs, /url\.searchParams\.get\(\s*'staff_badge'\s*\)/);
	assert.match(kioskJs, /doughboss_staff_badge_scan/);
	assert.match(kioskJs, /form\.method\s*=\s*'post'/);
	assert.match(kioskJs, /window\.history\.replaceState/);
	assert.match(badge, /data-badge-scan-action=[\s\S]*?<\/main>\s*<script src="<\?php echo esc_url\( DOUGHBOSS_PLUGIN_URL \. 'public\/js\/doughboss-staff-badge\.js/);
	assert.doesNotMatch(kioskJs, /window\.location\.assign/);
	assert.doesNotMatch(kioskJs, /fetch\(|XMLHttpRequest|https?:\/\//);
});

test('a new fragment badge replaces an existing kiosk PIN or action session', () => {
	let posts = 0;
	let scrubs = 0;
	const listeners = {};
	const location = {
		origin: 'https://doughboss.test',
		pathname: '/staff-clock/',
		search: '',
		hash: '#staff-badge=' + 'A'.repeat(43),
		get href() { return this.origin + this.pathname + this.search + this.hash; },
	};
	const document = {
		body: { appendChild() {} },
		querySelector: () => ({ getAttribute: () => 'https://doughboss.test/wp-admin/admin-post.php' }),
		querySelectorAll: () => [],
		getElementById: () => null,
		createElement: (tag) => tag === 'form'
			? { appendChild() {}, submit() { posts += 1; } }
			: {},
	};
	const window = {
		location,
		history: { replaceState() { scrubs += 1; location.hash = ''; } },
		addEventListener: (name, callback) => { listeners[name] = callback; },
		setTimeout() {},
	};
	vm.runInNewContext(kioskJs, { URL, String, document, window });
	assert.equal(posts, 1);
	assert.equal(scrubs, 1);
	location.hash = '#staff-badge=' + 'B'.repeat(43);
	listeners.hashchange();
	assert.equal(posts, 2);
	assert.equal(scrubs, 2);
});

test('attendance badge flow requires an explicit active staff shop assignment', () => {
	assert.match(scope, /function attendance_location_id/);
	const attendanceMethod = scope.match(/function attendance_location_id[\s\S]*?\n\t}/)?.[0] || '';
	assert.match(attendanceMethod, /get_user_meta\( \$user_id, self::LOCATION_META, true \)/);
	assert.match(attendanceMethod, /DoughBoss_Locations::is_valid/);
	assert.doesNotMatch(attendanceMethod, /single_location_id/);
	assert.match(badge, /DoughBoss_Staff_Scope::attendance_location_id\( \$user_id \)/);
	assert.match(badge, /DoughBoss_Staff_Scope::attendance_location_id\( \$user->ID \)/);
});

test('bearer exchanges and one-time badge print responses cannot be cached or leaked as referrers', () => {
	assert.match(badge, /function send_bearer_response_headers/);
	assert.match(badge, /nocache_headers\(\)/);
	assert.match(badge, /Cache-Control: no-store, private/);
	assert.match(badge, /Referrer-Policy: no-referrer/);
	assert.match(badge, /capture_badge_scan\(\)[\s\S]*?send_bearer_response_headers\(\)/);
	assert.match(badge, /handle_issue_badge\(\)[\s\S]*?send_bearer_response_headers\(\)/);
});

test('revocation fails visibly unless exactly one active badge changes state', () => {
	assert.match(badge, /\$revoked\s*=\s*\$wpdb->query/);
	assert.match(badge, /if \( false === \$revoked \)[\s\S]*?Badge revocation failed/);
	assert.match(badge, /if \( 1 !== \$revoked \)/);
	assert.match(badge, /Badge not revoked/);
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
