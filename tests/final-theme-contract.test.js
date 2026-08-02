const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'themes', 'doughboss-final');
const required = [
  'style.css', 'functions.php', 'header.php', 'footer.php', 'front-page.php',
  'page.php', 'page-order.php', 'page-menu.php', 'page-track-order.php',
  'page-catering.php', 'page-about-us.php', 'page-locations.php',
  'page-franchising.php', 'page-wholesale.php', 'single.php', 'archive.php',
  'search.php', '404.php', 'assets/theme.js', 'template-parts/locations.php'
];

let failed = false;
function check(value, message) {
  if (!value) { failed = true; console.error(`FAIL: ${message}`); }
  else console.log(`PASS: ${message}`);
}

required.forEach((file) => check(fs.existsSync(path.join(root, file)), `${file} exists`));
const source = required
  .filter((file) => fs.existsSync(path.join(root, file)))
  .map((file) => fs.readFileSync(path.join(root, file), 'utf8'))
  .join('\n');

[
  'doughboss_manoush_hero', 'doughboss_ordering_status', 'doughboss_shop_picker',
  'doughboss_menu', 'doughboss_builder', 'doughboss_cart',
  'doughboss_order_tracking', 'doughboss_catering'
].forEach((shortcode) => check(source.includes(shortcode), `${shortcode} is delegated to the plugin`));

check(source.includes('doughboss_final_ordering_open()'), 'theme gates checkout UI on the server-owned ordering setting');
check(source.includes("orders@doughboss.com.au"), 'approved orders email is present');
check(source.includes("catering@doughboss.com.au"), 'approved catering email is present');
check(source.includes('prefers-reduced-motion'), 'reduced-motion behaviour is included');
check(source.includes('data-dbf-menu-toggle'), 'responsive navigation is included');
check(!source.includes('sk_live_') && !source.includes('sk_test_') && !source.includes('whsec_'), 'theme contains no payment secrets');
check(!source.includes('orders & payments are simulated'), 'production theme contains no demo simulation ribbon');

process.exitCode = failed ? 1 : 0;
