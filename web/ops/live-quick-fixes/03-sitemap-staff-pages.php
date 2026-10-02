<?php
/**
 * Dough Boss quick fix 3: keep the staff pages out of search results.
 *
 * Problem: /kitchen/ (staff kitchen board) and /track-order/ (order tracking) are in the public
 * WordPress sitemap, and /track-order/ is served as "index,follow" under the homepage title.
 *
 * This (a) removes both pages from the core sitemap, (b) adds noindex, nofollow to their robots
 * meta, and (c) sends an X-Robots-Tag: noindex, nofollow header, which wins over any other plugin's
 * conflicting robots tag. It changes nothing else, writes nothing, and only reads page slugs.
 *
 * Where to put it: WPCode > Add Snippet > "Add Your Custom Code", type PHP Snippet,
 * "Run Everywhere" (it must run on front-end requests and sitemap requests).
 * Undo: deactivate the snippet. Search engines recrawl on their own schedule, so removal from the
 * index is not instant.
 * Do not paste the opening "<?php" line if WPCode adds one itself.
 */

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
