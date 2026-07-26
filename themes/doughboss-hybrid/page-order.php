<?php get_header(); ?>
<div class="db-shell"><header><p class="db-kicker"><?php esc_html_e( 'Order online', 'doughboss-hybrid' ); ?></p><h1 class="db-page-title"><?php esc_html_e( 'Build your order.', 'doughboss-hybrid' ); ?></h1></header>
<?php echo doughboss_hybrid_plugin_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<section class="db-section db-card"><?php echo do_shortcode( '[doughboss_shop_picker]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
<section class="db-section"><?php echo do_shortcode( '[doughboss_menu]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo do_shortcode( '[doughboss_builder]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
<section class="db-section db-card"><?php echo do_shortcode( '[doughboss_cart]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section></div>
<?php get_footer(); ?>
