# Brief for the Chrome agent: apply three Dough Boss quick fixes on the live site

Paste everything below the line into the agent that is logged into the doughboss.com.au WordPress admin.
Elie has said go on these three fixes only. Each was tested (14/14 on a local WordPress, 10/10 against the live pages in a local browser).

---

You are logged into the WordPress admin for doughboss.com.au. Apply exactly three WPCode snippets, in the order below, then verify and report. Do nothing else.

## Rules (read first)
- Touch only WPCode > Code Snippets, and only to ADD the three new snippets below. Do not edit, deactivate or delete any existing snippet (existing hotfixes are numbered #533 to #537).
- Do not update plugins, themes or WordPress. Do not change any setting, page, menu, user or product.
- Do not copy, read out or screenshot passwords, tokens, API keys or payment details.
- If anything differs from this brief (no WPCode, a different admin layout, a warning, an error, a white screen), STOP and tell Elie exactly what you saw. Do not improvise a workaround.
- Save each snippet as ACTIVE only after you have pasted the code exactly. After each one, load the home page in a new tab and confirm the site still loads normally before moving on.
- Undo for any fix: open the snippet in WPCode, switch it off, Save, then purge any cache.

## Step 1: Quick fix 1 (CSS). Removes the black strip on the right of inner-page heroes
WPCode > Code Snippets > Add Snippet > "Add Your Custom Code (New Snippet)" > Use snippet.
Title: `Quick fix 1 - hero strip`. Code type: CSS Snippet. Insert location: Site Wide Header. Paste:

```css
.dbf-page-hero .dbf-page-hero-bg {
	max-width: none;
}
```

Activate, Save.

## Step 2: Quick fix 2 (JavaScript). Gives menu card photos accessible names
Add Snippet > "Add Your Custom Code (New Snippet)". Title: `Quick fix 2 - menu photo labels`. Code type: JavaScript Snippet. Insert location: Site Wide Footer. Paste:

```js
(function () {
	'use strict';

	function urlOf(node) {
		var bg = node.style && node.style.backgroundImage ? node.style.backgroundImage : '';
		var m = /url\(\s*["']?([^"')]+)["']?\s*\)/i.exec(bg);
		return m ? m[1] : '';
	}

	function label() {
		var nodes = document.querySelectorAll('.db-card-img');
		var counts = {};
		var i;
		for (i = 0; i < nodes.length; i++) {
			var u = urlOf(nodes[i]);
			if (u) { counts[u] = (counts[u] || 0) + 1; }
		}
		for (i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			var url = urlOf(node);
			if (!url || (node.className || '').indexOf('db-card-img--placeholder') !== -1) { continue; }
			var card = node.parentNode;
			var h = card && card.querySelector ? card.querySelector('.db-card-body h2, .db-card-body h3, .db-card-body h4') : null;
			var name = h ? String(h.textContent || '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '') : '';
			if (counts[url] > 1) {
				node.removeAttribute('role');
				node.removeAttribute('aria-label');
				node.setAttribute('aria-hidden', 'true');
			} else if (name) {
				node.removeAttribute('aria-hidden');
				node.setAttribute('role', 'img');
				node.setAttribute('aria-label', name + ', photo');
			}
		}
	}

	function start() {
		var roots = document.querySelectorAll('[data-doughboss-menu]');
		if (!roots.length) { return; }
		label();
		if (typeof MutationObserver === 'undefined') { return; }
		var pending = false;
		var obs = new MutationObserver(function () {
			if (pending) { return; }
			pending = true;
			window.setTimeout(function () { pending = false; label(); }, 50);
		});
		for (var i = 0; i < roots.length; i++) {
			obs.observe(roots[i], { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}());
```

Activate, Save.

## Step 3: Quick fix 3 (PHP). Keeps the staff pages out of search. Do this one LAST
Add Snippet > "Add Your Custom Code (New Snippet)". Title: `Quick fix 3 - staff pages noindex`. Code type: PHP Snippet. Insert location: Run Everywhere. Do NOT paste an opening `<?php` line. Paste:

```php
if ( ! function_exists( 'dbqf_staff_page_slugs' ) ) {
	/** Slugs of the pages that should never appear in search results. */
	function dbqf_staff_page_slugs() {
		return array( 'kitchen', 'track-order' );
	}
}

add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}
		$ids = array();
		foreach ( dbqf_staff_page_slugs() as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$ids[] = (int) $page->ID;
			}
		}
		if ( $ids ) {
			$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
		}
		return $args;
	},
	10,
	2
);

add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( is_page( dbqf_staff_page_slugs() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
		}
		return $robots;
	}
);

add_filter(
	'wp_headers',
	function ( $headers ) {
		if ( function_exists( 'is_page' ) && is_page( dbqf_staff_page_slugs() ) ) {
			$headers['X-Robots-Tag'] = 'noindex, nofollow';
		}
		return $headers;
	}
);
```

Activate, Save. If WPCode reports a PHP error or deactivates the snippet by itself, leave it off and report the exact message. Then confirm the home page and `/menu/` still load.

## Step 4: Purge caches
If a page cache or CDN plugin is active (for example WP Rocket, LiteSpeed, W3 Total Cache, or a host cache), purge it. Tell Elie which one you used.

## Step 5: Verify and report (read-only)
1. Fix 1: open `/order/`, `/locations/`, `/about-us/`, `/franchising/` on desktop width and a phone-width window. The hero photo should reach the right edge with no black strip.
2. Fix 2: on `/menu/`, inspect a card photo. It should have `role="img"` and an `aria-label` ending in ", photo". The drink cards that share one photo should have `aria-hidden="true"` instead. (Expected on today's menu: roughly 29 labelled and 14 hidden of 43.)
3. Fix 3: open `/wp-sitemap-posts-page-1.xml`. `/kitchen/` and `/track-order/` should be gone and the customer pages (for example `/order/`, `/menu/`, `/locations/`) should still be listed. In the Network panel for `/track-order/`, the response header `x-robots-tag` should read `noindex, nofollow`. (The host runs ModSecurity and a page cache, so a stale sitemap or missing header means the cache was not purged.)
4. Report back to Elie in plain words: for each of the three fixes, applied yes/no, verified yes/no, and anything unexpected. Include the cache plugin you purged. Do not claim a check passed unless you saw it.
