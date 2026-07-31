<?php
/**
 * Fallback template for DoughBoss Hybrid.
 *
 * WordPress requires an index template even when the named page and front-page
 * templates provide the normal DoughBoss customer experience.
 *
 * @package DoughBoss_Hybrid
 */

get_header();
?>

<main id="main-content" class="db-shell site-main">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : ?>
			<?php the_post(); ?>
			<article <?php post_class( 'db-fallback-page' ); ?>>
				<h1><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing has been published here yet.', 'doughboss-hybrid' ); ?></p>
	<?php endif; ?>
</main>

<?php
get_footer();
