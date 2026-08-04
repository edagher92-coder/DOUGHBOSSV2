<?php get_header(); ?>
<?php while ( have_posts() ) : the_post(); ?>
	<section class="dbf-page-hero"><img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'home-manoush-category-v5.webp' ) ); ?>" width="1254" height="1254" alt=""><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow">Dough Boss</p><h1 class="dbf-display"><?php the_title(); ?></h1></div></section>
	<section class="dbf-page-content"><article class="dbf-wrap dbf-prose"><?php the_content(); ?></article></section>
<?php endwhile; ?>
<?php get_footer(); ?>
