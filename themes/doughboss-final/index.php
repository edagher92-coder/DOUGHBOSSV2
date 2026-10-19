<?php get_header(); ?>
<section class="dbf-page-content"><div class="dbf-wrap dbf-prose">
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?><article <?php post_class(); ?>><h1><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1><?php the_excerpt(); ?></article><?php endwhile; the_posts_pagination(); else : ?><p><?php esc_html_e( 'Nothing found.', 'doughboss-final' ); ?></p><?php endif; ?>
</div></section>
<?php get_footer(); ?>
