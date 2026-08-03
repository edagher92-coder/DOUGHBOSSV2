const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const themeStyle = read('themes/doughboss-final/style.css');
const themeScript = read('themes/doughboss-final/assets/theme.js');
const header = read('themes/doughboss-final/header.php');
const order = read('themes/doughboss-final/page-order.php');
const home = read('themes/doughboss-final/front-page.php');
const catering = read('themes/doughboss-final/page-catering.php');
const locations = read('themes/doughboss-final/template-parts/locations.php');
const vouchers = read('themes/doughboss-final/page-vouchers.php');
const themeFunctions = read('themes/doughboss-final/functions.php');
const footer = read('themes/doughboss-final/footer.php');
const voucherClient = read('public/js/doughboss-voucher.js');
const trackAlias = read('themes/doughboss-final/page-track.php');
const shortcodes = read('includes/class-doughboss-shortcodes.php');
const storefront = read('public/js/doughboss.js');
const hero = read('public/js/doughboss-manoush-hero.js');
const heroStyle = read('public/css/doughboss-manoush-hero.css');
const board = read('admin/class-doughboss-admin.php');
const boardClient = read('public/js/doughboss-orderboard.js');
const boardStyle = read('public/css/doughboss-orderboard.css');
const partnerPage = read('themes/doughboss-final/template-parts/partner-page.php');

let failed = false;
function check(value, message) {
  if (!value) { failed = true; console.error(`FAIL: ${message}`); }
  else console.log(`PASS: ${message}`);
}

check(header.includes('data-dbf-menu-close'), 'mobile navigation has an explicit close control');
check(themeScript.includes("event.key === 'Tab'") && themeScript.includes('focusableMenuItems'), 'mobile navigation traps keyboard focus');
check(themeScript.includes("event.key === 'Escape'") && themeScript.includes('closeMenu(true)'), 'mobile navigation closes with Escape and restores focus');
check(themeStyle.includes('visibility: hidden') && themeStyle.includes('pointer-events: none'), 'closed off-canvas navigation is not interactable');
check(themeStyle.includes('100dvh') && themeStyle.includes('overflow-y: auto'), 'mobile navigation fits short touch screens');
check(/html\s*\{[^}]*overflow-x:\s*clip/s.test(themeStyle), 'off-canvas navigation cannot create horizontal page scrolling');
check(themeStyle.includes('@media (prefers-reduced-motion: reduce)'), 'theme honours reduced motion');
check(hero.includes('Math.abs(centre)') && hero.includes("window.addEventListener('scroll'"), 'hero blowout follows scroll in both directions');
check(hero.includes("window.addEventListener('resize'"), 'hero recalculates its scene responsively');
check(['hero-meat-manoush-v4.webp', 'hero-folded-zaatar-v4.webp', 'hero-cheese-manoush-v4.webp', 'hero-spinach-fatayer-v4.webp', 'hero-chicken-wrap-v4.webp'].every((asset) => shortcodes.includes(asset) && fs.existsSync(path.join(root, 'public/images', asset))), 'hero uses five present, distinct production food assets');
check(heroStyle.includes('@keyframes db-mh-smoke') && heroStyle.includes('drop-shadow'), 'hero adds premium atmospheric depth while retaining reduced-motion handling');

check(home.includes('dbf-sr-only') && catering.includes('dbf-sr-only'), 'visual hero pages retain a clear page-level heading');
check(catering.includes('href="#catering-enquiry"') && catering.includes('id="catering-enquiry"'), 'catering intro moves directly to one canonical contact experience');
check(order.includes('dbf-page-hero--order') && order.includes('Checkout coming soon'), 'order page matches the premium demo direction while clearly marking checkout unavailable');
check(order.includes('dbf-order-intro') && order.includes('dbf-order-readiness'), 'location and availability share a collision-safe responsive order introduction');
check(themeStyle.includes('.dbf-order-intro') && themeStyle.includes('grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr)'), 'desktop order panels use one non-overlapping grid');
check(themeStyle.includes('body.doughboss-order-page .dbf-order-intro .db-ordering-status') && themeStyle.includes('margin: 0 !important'), 'final theme neutralises the plugin legacy negative status margin so availability never overlaps the hero or location card');
check(order.includes("if ( doughboss_final_ordering_open() )") && order.includes('[doughboss_shop_picker]'), 'shop selection and checkout controls remain gated by server-owned ordering state');
check(order.includes('[doughboss_menu]'), 'browseable menu remains available while checkout is paused');
check(trackAlias.includes("page-track-order.php"), 'existing /track/ page slug resolves to the live tracking experience');
check(locations.match(/rel="noopener noreferrer"/g)?.length === 3, 'all external map links use safe new-tab relationships');
check(vouchers.includes('.edu or .edu.au') && vouchers.includes('doughboss_voucher_claim'), 'voucher page explains education-email eligibility and delegates allocation to the plugin');
check(themeFunctions.includes("home_url( '/vouchers/' )") && footer.includes("home_url( '/vouchers/' )"), 'student vouchers are discoverable in fallback navigation and the footer');
check(themeFunctions.includes("home_url( '/track-order/' )") && footer.includes("home_url( '/track-order/' )"), 'order tracking is discoverable in fallback navigation and the footer');
check(shortcodes.includes('aria-pressed="false"') && shortcodes.includes('aria-atomic="true"') && voucherClient.includes("setAttribute( 'aria-pressed', 'true' )"), 'voucher selection and results expose their state to assistive technology');
check(themeScript.includes("data-dbf-scroll-state") && !themeScript.includes('observer.unobserve'), 'theme reveals reverse cleanly when scrolling up and down');
check(!/stone-baked/i.test(home + footer) && /oven-baked/i.test(home + footer), 'public homepage and footer use the approved oven-baked wording consistently');
check(partnerPage.includes('dbf-partner-grid--single') && themeStyle.includes('.dbf-page-content--partner') && themeStyle.includes('@media (max-width: 560px)'), 'empty partnership content and narrow footer columns collapse without overflow or a large blank gap');

check((shortcodes.match(/class="db-loading" role="status" aria-live="polite"/g) || []).length === 4, 'storefront loading states are announced');
check(shortcodes.includes('aria-label="<?php esc_attr_e( \'Mobile number\''), 'voucher phone field has an accessible name');
check((storefront.match(/class: 'db-error', role: 'alert'/g) || []).length >= 4, 'storefront load failures are announced as alerts');
check(board.includes('db-board-loading" role="status" aria-live="polite"'), 'kitchen-board loading state is announced');
check(boardClient.includes("['make', 'pass', 'catering']") && boardClient.includes("'/admin/catering-board'") && boardClient.includes('renderCatering'), 'order board exposes a dedicated catering production presentation');
check(boardStyle.includes('min-width: 1500px') && boardStyle.includes('doughboss-board--screen-catering'), 'order board includes targeted FHD kitchen and compact catering display layouts');

process.exitCode = failed ? 1 : 0;
