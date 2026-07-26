const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'themes', 'doughboss-hybrid');
const files = ['style.css', 'index.php', 'functions.php', 'header.php', 'footer.php', 'front-page.php', 'page-order.php', 'page-track-order.php', 'page-catering.php', 'README.md'];
let failed = false;
function check(condition, message) { if (!condition) { failed = true; console.error(`FAIL: ${message}`); } else { console.log(`PASS: ${message}`); } }

files.forEach((file) => check(fs.existsSync(path.join(root, file)), `${file} exists`));
const source = files.reduce((all, file) => all + fs.readFileSync(path.join(root, file), 'utf8'), '');
['doughboss_manoush_hero', 'doughboss_shop_picker', 'doughboss_menu', 'doughboss_builder', 'doughboss_cart', 'doughboss_order_tracking', 'doughboss_catering'].forEach((shortcode) => check(source.includes(`[${shortcode}]`), `${shortcode} is wired through the plugin`));
check(source.includes("add_filter( 'doughboss_load_assets'"), 'template-rendered storefront assets are enabled');
check(!source.includes('http://') && !source.includes('https://fonts.'), 'theme contains no remote font or insecure asset dependency');
check(source.includes('prefers-reduced-motion') && source.includes('skip-link'), 'motion and keyboard accessibility are covered');
process.exitCode = failed ? 1 : 0;
