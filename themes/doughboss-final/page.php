<?php get_header(); ?>
<?php while ( have_posts() ) : the_post(); ?>
	<section class="dbf-page-hero"><?php echo doughboss_final_asset_image( 'menu/real-v1/zaatar-cheese.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow">Dough Boss</p><h1 class="dbf-display"><?php the_title(); ?></h1></div></section>
	<section class="dbf-page-content"><article class="dbf-wrap dbf-prose"><?php the_content(); ?></article></section>
<?php endwhile; ?>
<?php get_footer(); ?>
