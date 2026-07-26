<?php get_header(); ?>
<div class="db-shell">
	<section class="db-hero" aria-labelledby="doughboss-home-title">
		<p class="db-kicker"><?php esc_html_e( 'Freshly baked in Sydney', 'doughboss-hybrid' ); ?></p>
		<h1 id="doughboss-home-title"><?php esc_html_e( 'Your manoush moment starts here.', 'doughboss-hybrid' ); ?></h1>
		<p class="db-lede"><?php esc_html_e( 'Choose your shop, build your order and follow it from the oven to your hands.', 'doughboss-hybrid' ); ?></p>
		<?php echo do_shortcode( '[doughboss_manoush_hero]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</section>
	<?php echo doughboss_hybrid_plugin_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<section class="db-section db-card" aria-labelledby="choose-shop"><h2 id="choose-shop"><?php esc_html_e( 'Choose your shop', 'doughboss-hybrid' ); ?></h2><?php echo do_shortcode( '[doughboss_shop_picker]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
	<section class="db-section" aria-labelledby="menu"><h2 id="menu"><?php esc_html_e( 'The menu', 'doughboss-hybrid' ); ?></h2><?php echo do_shortcode( '[doughboss_menu]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
</div>
<?php get_footer(); ?>
