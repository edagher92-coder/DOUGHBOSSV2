'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const seeder = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-menu-seeder.php'), 'utf8');
const migrations = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-migrations.php'), 'utf8');
const admin = fs.readFileSync(path.join(root, 'admin', 'class-doughboss-admin.php'), 'utf8');

function ok(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

ok(
  seeder.includes("'pies-dough-boss-pie' => 'Chicken Pie'") &&
    seeder.includes("'pies-spinach-pie'    => 'Spinach & Cheese'") &&
    seeder.includes("'pizza-sujuk-special' => 'Dough Boss Special'"),
  'unmarked legacy menu titles must be matched and renamed in place'
);
ok(
  seeder.includes("'pizza-sujuk-special' => 'pizza-dough-boss-special'"),
  'Sujuk Special must reuse the existing Dough Boss Special seeded record'
);
ok(
  migrations.includes("'1.18.0' => 'upgrade_to_1_18_0'") &&
    migrations.includes("'post_title'   => 'Sujuk Special'") &&
    migrations.includes("update_post_meta( $post_id, '_doughboss_seed_key', 'pizza-sujuk-special' )"),
  'plugin upgrades must rename the one legacy seeded pizza automatically while retaining its post ID'
);
ok(
  seeder.indexOf('$legacy_title') < seeder.indexOf("'title'            => $name"),
  'legacy title lookup must run before the canonical-title fallback'
);
ok(admin.includes('Drinks (34 items, with prices'), 'admin menu count copy must match the 34-item canonical menu');

console.log('menu seeder legacy title contract passed');
