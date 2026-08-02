'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const seeder = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-menu-seeder.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'admin', 'class-doughboss-admin.php'), 'utf8');

function ok(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

ok(
  seeder.includes("'pies-dough-boss-pie' => 'Chicken Pie'") &&
    seeder.includes("'pies-spinach-pie'    => 'Spinach & Cheese'"),
  'unmarked legacy pie titles must be matched and renamed in place'
);
ok(
  seeder.indexOf('$legacy_title') < seeder.indexOf("'title'            => $name"),
  'legacy title lookup must run before the canonical-title fallback'
);
ok(admin.includes('Drinks (34 items, with prices'), 'admin menu count copy must match the 34-item canonical menu');

console.log('menu seeder legacy title contract passed');
