'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const plugin = read('doughboss.php');
const core = read('includes/class-doughboss.php');
const portals = read('includes/class-doughboss-portals.php');
const clock = read('includes/class-doughboss-timeclock.php');
const scope = read('includes/class-doughboss-staff-scope.php');
const staffExperience = read('includes/class-doughboss-staff-experience.php');
const activator = read('includes/class-doughboss-activator.php');
const migrations = read('includes/class-doughboss-migrations.php');
const uninstall = read('uninstall.php');
const clockCss = read('public/css/doughboss-timeclock.css');

test('release 2.36.0 boots the staff clock on database schema 1.20.0', () => {
	assert.match(plugin, /Version:\s+2\.36\.0/);
	assert.match(plugin, /DOUGHBOSS_VERSION',\s*'2\.36\.0'/);
	assert.match(plugin, /DOUGHBOSS_DB_VERSION',\s*'1\.20\.0'/);
	assert.match(core, /class-doughboss-timeclock\.php/);
	assert.match(core, /new DoughBoss_Timeclock\(\)/);
	assert.match(migrations, /'1\.20\.0'\s*=>\s*'upgrade_to_1_20_0'/);
	assert.match(migrations, /function upgrade_to_1_20_0\s*\(/);
});

test('staff clock is a hidden standalone no-cache portal, not a public menu page', () => {
	assert.match(portals, /add_rewrite_rule\(\s*'\^staff-clock\/\?\$'/);
	assert.match(portals, /array\(\s*'kitchen',\s*'catering-kitchen',\s*'staff-clock',\s*'management'\s*\)/);
	assert.match(portals, /render_staff_clock\s*\(/);
	assert.match(portals, /DoughBoss_Timeclock::render_portal\(\)/);
	assert.doesNotMatch(portals, /is_user_logged_in\(\)\s*&&\s*!\s*DoughBoss_Timeclock::can_clock\(\)/);
	assert.ok(
		portals.indexOf('! DoughBoss_Timeclock::storage_ready()') < portals.indexOf("$this->render_head( __( 'Staff Clock"),
		'schema-failure logout must occur before the portal emits HTML'
	);
	assert.match(portals, /nocache_headers\(\)/);
	assert.match(portals, /X-Robots-Tag:\s*noindex, nofollow, noarchive/);
	assert.match(portals, /X-Frame-Options:\s*DENY/);
	assert.ok(
		portals.indexOf('$this->portal_headers();') < portals.indexOf("'staff-clock' !== $portal"),
		'private/no-cache headers must be sent before any portal authentication branch'
	);
	assert.match(portals, /doughboss-timeclock\.css/);
	assert.match(clockCss, /min-height:\s*(?:5[2-9]|[6-9]\d|\d{3,})px/);
});

test('every clock mutation is bound to an individual account, capability and nonce', () => {
	assert.match(clock, /const CAPABILITY\s*=\s*'clock_doughboss_staff'/);
	assert.match(clock, /admin_post_doughboss_clock_in/);
	assert.match(clock, /admin_post_doughboss_clock_out/);
	assert.match(clock, /admin_post_doughboss_correct_shift/);
	assert.match(clock, /is_user_logged_in\(\)\s*&&\s*current_user_can\(\s*self::CAPABILITY\s*\)\s*&&\s*self::storage_ready\(\)/);
	assert.match(clock, /function storage_ready\s*\([\s\S]*?doughboss_db_version[\s\S]*?DoughBoss_Activator::timeclock_storage_ready\(\)/);
	assert.match(clock, /function verify_action\s*\([\s\S]*?check_admin_referer\(\s*\$action\s*\)/);
	assert.match(clock, /function verify_action\s*\([\s\S]*?storage_ready\(\)[\s\S]*?redirect_back\(\s*'unavailable'\s*\)/);
	assert.match(clock, /handle_clock_in\s*\([\s\S]*?verify_action\(\s*'doughboss_clock_in'\s*\)/);
	assert.match(clock, /handle_clock_out\s*\([\s\S]*?verify_action\(\s*'doughboss_clock_out'\s*\)/);
	assert.match(clock, /handle_correction\s*\([\s\S]*?verify_manager_access\(\)/);
	assert.match(clock, /function verify_manager_access\s*\([\s\S]*?current_user_can\(\s*'manage_doughboss'\s*\)[\s\S]*?storage_ready\(\)/);
	assert.match(clock, /handle_correction\s*\([\s\S]*?check_admin_referer\(\s*'doughboss_correct_shift_'\s*\.\s*\$shift_id\s*\)/);
});

test('shop check-in fails closed and managers explicitly choose in multi-shop mode', () => {
	assert.match(scope, /function assigned_location_id\s*\(/);
	assert.match(scope, /DoughBoss_Locations::single_location_id\(\)/);
	assert.match(scope, /doughboss_staff_location_required/);
	assert.match(scope, /needs an active shop assignment before it can check in/);
	assert.match(clock, /\$manager\s*&&\s*count\(\s*\$locations\s*\)\s*>\s*1/);
	assert.match(clock, /<select[^>]+name="location_id"[^>]+required/);
	assert.match(clock, /<option value="">[\s\S]*?Choose a shop/);
	assert.match(clock, /resolve_clock_in_location\s*\([\s\S]*?DoughBoss_Locations::get\(\s*\$location_id\s*\)/);
	assert.match(clock, /\$location\s*&&\s*1\s*===\s*\(int\)\s*\$location->is_active/);
	assert.match(clock, /Choose an active DoughBoss shop/);
});

test('a shift preserves immutable staff and location evidence at clock-in', () => {
	for (const field of ['staff_name', 'staff_login', 'location_id', 'location_name', 'timezone_snapshot', 'clock_in_utc']) {
		assert.match(clock, new RegExp("'" + field + "'\\s*=>"), `${field} must be snapshotted on the shift`);
		assert.match(activator, new RegExp('\\b' + field + '\\b'), `${field} must exist in the durable schema`);
	}
	assert.match(clock, /wp_get_current_user\(\)/);
	assert.match(clock, /\$user->display_name/);
	assert.match(clock, /\$user->user_login/);
	assert.match(clock, /\(string\)\s*\$location->name/);
	assert.match(clock, /valid_timezone_name\(\s*isset\(\s*\$location->timezone\s*\)/);
	assert.match(clock, /'timezone_snapshot'\s*=>\s*\$timezone/);
	assert.doesNotMatch(clock, /SELECT\s+s\.\*,\s*u\.display_name,\s*u\.user_login\s+FROM/i);
});

test('clock-in and clock-out are serialized and verify database outcomes', () => {
	assert.match(clock, /SELECT GET_LOCK\(%s,\s*5\)/);
	assert.match(clock, /finally\s*\{[\s\S]*?SELECT RELEASE_LOCK\(%s\)/);
	assert.match(clock, /open_shift\(\s*\$user_id\s*\)/);
	assert.match(clock, /\$wpdb->insert\([\s\S]*?if\s*\(\s*false\s*===\s*\$ok[\s\S]*?return\s+'error'/);
	assert.match(clock, /clock_out_utc IS NULL/);
	assert.match(clock, /return\s+1\s*===\s*\$ok\s*\?\s*'out'\s*:\s*'error'/);
	assert.match(activator, /open_guard\s+tinyint\(1\)\s+unsigned\s+NULL\s+DEFAULT\s+1/);
	assert.match(activator, /UNIQUE KEY\s+user_open_guard\s*\(user_id,open_guard\)/);
});

test('every valid shared-kiosk action signs the employee out, including failures', () => {
	assert.match(clock, /redirect_back\(\s*'location'\s*\)/);
	assert.match(clock, /redirect_back\(\s*\$status\s*\)/);
	assert.match(clock, /function redirect_back\s*\(\s*\$status\s*\)\s*\{\s*wp_logout\(\)/);
	assert.doesNotMatch(clock, /redirect_back\([^\n]+false\s*\)/);
	assert.match(clock, /Use your own staff account/);
	assert.match(clock, /safely signed out for the next team member/);
});

test('clock-only staff role has no kitchen or management authority', () => {
	assert.match(activator, /add_role\(\s*'doughboss_staff'/);
	assert.match(activator, /'clock_doughboss_staff'\s*=>\s*true/);
	assert.match(staffExperience, /in_array\(\s*'doughboss_staff'[\s\S]*?home_url\(\s*'\/staff-clock\/'\s*\)/);
	const roleBlock = activator.match(/add_role\(\s*'doughboss_staff'[\s\S]*?\n\s*\);/);
	assert.ok(roleBlock, 'clock-only role must be created');
	assert.doesNotMatch(roleBlock[0], /manage_doughboss(?:_kds)?/);
});

test('schema readiness covers shift and audit tables before migration advances', () => {
	assert.match(activator, /doughboss_staff_shifts/);
	assert.match(activator, /doughboss_staff_shift_events/);
	assert.match(activator, /CREATE TABLE \{\$staff_shifts\}[\s\S]*?ENGINE=InnoDB/);
	assert.match(activator, /CREATE TABLE \{\$staff_events\}[\s\S]*?ENGINE=InnoDB/);
	assert.match(activator, /function timeclock_storage_ready\s*\(/);
	assert.match(activator, /timeclock_storage_ready\(\)/);
	for (const field of ['shift_id', 'event_type', 'actor_user_id', 'reason', 'before_json', 'after_json', 'occurred_at_utc']) {
		assert.match(activator, new RegExp('\\b' + field + '\\b'), `${field} must be part of the audit schema/readiness contract`);
	}
	assert.match(migrations, /upgrade_to_1_20_0\s*\([\s\S]*?timeclock_storage_ready\(\)/);
	assert.match(migrations, /WHERE s\.location_name = ''/);
	assert.doesNotMatch(migrations, /SET s\.location_name[\s\S]{0,300}s\.timezone_snapshot[\s\S]{0,1000}UPDATE \{\$shifts\} s LEFT JOIN \{\$locations\}/);
});

test('timesheet CSV neutralizes spreadsheet formulas in every text cell', () => {
	assert.match(clock, /function csv_cell\s*\(/);
	assert.match(clock, /\[=\+\\?-@\]/);
	assert.match(clock, /fputcsv\([\s\S]*?csv_cell/);
	for (const field of ['staff_name', 'staff_login', 'location_name']) {
		assert.match(clock, new RegExp('csv_cell\\(\\s*\\$row->' + field + '\\s*\\)'), `${field} must be neutralized before export`);
	}
});

test('manager corrections and forced closes are reasoned, atomic and audited', () => {
	assert.match(clock, /admin_post_doughboss_correct_shift/);
	assert.match(clock, /function handle_correction\s*\(/);
	assert.match(clock, /sanitize_textarea_field\(/);
	assert.match(clock, /reason[^\n]{0,100}(?:required|empty)/i);
	assert.match(clock, /START TRANSACTION/);
	assert.match(clock, /COMMIT/);
	assert.match(clock, /ROLLBACK/);
	assert.match(clock, /doughboss_staff_shift_events/);
	assert.match(clock, /'shift_id'\s*=>/);
	assert.match(clock, /'event_type'\s*=>[\s\S]*?manager_closed/);
	assert.match(clock, /\$actor_id\s*=\s*get_current_user_id\(\)/);
	assert.match(clock, /'actor_user_id'\s*=>\s*\$actor_id/);
	assert.match(clock, /'reason'\s*=>\s*\$reason/);
	assert.match(clock, /'before_json'\s*=>/);
	assert.match(clock, /'after_json'\s*=>/);
	assert.match(clock, /'occurred_at_utc'\s*=>/);
	assert.match(clock, /\$event\s*=\s*\$wpdb->insert\([\s\S]*?false\s*===\s*\$event[\s\S]*?ROLLBACK/);
});

test('uninstall removes attendance data, capability and clock-only role', () => {
	assert.match(uninstall, /doughboss_staff_shift_events/);
	assert.match(uninstall, /doughboss_staff_shifts/);
	assert.ok(
		uninstall.indexOf('doughboss_staff_shift_events') < uninstall.indexOf('doughboss_staff_shifts'),
		'audit child table should be dropped before shifts'
	);
	assert.match(uninstall, /remove_cap\(\s*'clock_doughboss_staff'\s*\)/);
	assert.match(uninstall, /remove_role\(\s*'doughboss_staff'\s*\)/);
	assert.match(uninstall, /'\[doughboss_staff_clock\]'\s*===\s*trim/);
	assert.match(uninstall, /DELETE FROM \{\$wpdb->usermeta\} WHERE meta_key = %s[\s\S]*?doughboss_location_id/);
});

test('attendance records the selected shop without browser GPS or IP collection', () => {
	const attendanceSurface = [clock, clockCss, scope].join('\n');
	assert.doesNotMatch(attendanceSurface, /navigator\.geolocation|getCurrentPosition|watchPosition/i);
	assert.doesNotMatch(attendanceSurface, /REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_CLIENT_IP|ip_address/i);
	assert.doesNotMatch(activator.match(/CREATE TABLE \{\$staff_shifts\}[\s\S]*?ENGINE=InnoDB/)?.[0] || '', /latitude|longitude|gps|ip_address/i);
	assert.match(portals, /Permissions-Policy:\s*camera=\(\), microphone=\(\), geolocation=\(\)/);
});
