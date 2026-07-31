<?php get_header(); ?>
<div class="db-shell"><header><p class="db-kicker"><?php esc_html_e( 'Catering', 'doughboss-hybrid' ); ?></p><h1 class="db-page-title"><?php esc_html_e( 'Feed the whole table.', 'doughboss-hybrid' ); ?></h1></header><section class="db-section db-card"><?php echo do_shortcode( '[doughboss_catering]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section></div>
<?php get_footer(); ?>
