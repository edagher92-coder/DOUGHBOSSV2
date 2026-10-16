<?php
/** Members hub: put useful actions before decorative imagery. */
get_header();
?>
<?php while ( have_posts() ) : the_post(); ?>
	<section class="dbf-page-hero dbf-page-hero--members" aria-labelledby="dbf-members-title">
		<div class="dbf-wrap dbf-page-hero-inner">
			<p class="dbf-eyebrow"><?php esc_html_e( 'Your Dough Boss', 'doughboss-final' ); ?></p>
			<h1 id="dbf-members-title" class="dbf-display"><?php the_title(); ?></h1>
		</div>
	</section>
	<section class="dbf-page-content"><article class="dbf-wrap dbf-prose"><?php the_content(); ?></article></section>
<?php endwhile; ?>
<?php get_footer(); ?>
