<?php
$is_wholesale = is_page( 'wholesale' );
$eyebrow = $is_wholesale ? __( 'Wholesale', 'doughboss-final' ) : __( 'Partnerships', 'doughboss-final' );
$headline = $is_wholesale ? __( 'Bring Dough Boss to your customers.', 'doughboss-final' ) : __( 'Grow with a bakery people remember.', 'doughboss-final' );
$lede = $is_wholesale ? __( 'Talk to us about wholesale supply, product fit and operational requirements.', 'doughboss-final' ) : __( 'Learn about the brand, operating model and what a Dough Boss partnership could look like.', 'doughboss-final' );
?>
<section class="dbf-page-hero"><img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'menu/zaatar.webp' ) ); ?>" alt=""><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow"><?php echo esc_html( $eyebrow ); ?></p><h1 class="dbf-display"><?php echo esc_html( $headline ); ?></h1><p class="dbf-lede"><?php echo esc_html( $lede ); ?></p></div></section>
<section class="dbf-page-content"><div class="dbf-wrap dbf-partner-grid"><aside class="dbf-partner-aside"><p class="dbf-eyebrow">Start a conversation</p><h2 class="dbf-heading">Let's talk.</h2><p>Tell us who you are, what you are exploring and the best way to contact you.</p><a class="dbf-button" href="mailto:orders@doughboss.com.au">Contact Dough Boss</a></aside><article class="dbf-prose"><?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?></article></div></section>
