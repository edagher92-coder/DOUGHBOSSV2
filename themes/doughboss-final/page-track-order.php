<?php get_header(); ?>
<section class="dbf-page-hero"><?php echo doughboss_final_asset_image( 'menu/real-v1/zaatar-cheese.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow">Order updates</p><h1 class="dbf-display">Track your <em>order.</em></h1><p class="dbf-lede">Use your order number and the same email address used at checkout.</p></div></section>
<div class="dbf-wrap dbf-page-content"><div class="dbf-catering-app"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_order_tracking]', __( 'Order tracking is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
<?php get_footer(); ?>
