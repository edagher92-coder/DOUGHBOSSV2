<?php get_header(); ?>
<div class="db-shell"><header><p class="db-kicker"><?php esc_html_e( 'Order updates', 'doughboss-hybrid' ); ?></p><h1 class="db-page-title"><?php esc_html_e( 'Track your order.', 'doughboss-hybrid' ); ?></h1></header><section class="db-section db-card"><?php echo do_shortcode( '[doughboss_order_tracking]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section></div>
<?php get_footer(); ?>
