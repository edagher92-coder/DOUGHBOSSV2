<?php get_header(); ?>
<section class="dbf-page-hero">
	<img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'doughboss-hero-premium-v1.webp' ) ); ?>" alt="" width="1600" height="900" fetchpriority="high">
	<div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow">Order online</p><h1 class="dbf-display"><?php echo doughboss_final_ordering_open() ? 'Build your <em>order.</em>' : 'Ordering <em>coming soon.</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1><p class="dbf-lede"><?php echo esc_html( doughboss_final_ordering_open() ? 'Choose your favourites, customise them and order for pickup from Revesby.' : 'The full menu is ready to browse. Checkout will open after the kitchen hardware and backend channels complete their final approval.' ); ?></p></div>
</section>
<div class="dbf-wrap dbf-order-shell">
	<div class="dbf-order-status"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_ordering_status]', __( 'Online ordering is coming soon.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="dbf-order-location"><div><strong>Revesby</strong><span>Shop 12/25 Selems Parade, Revesby NSW 2212</span></div><span><?php echo doughboss_final_ordering_open() ? 'Pickup ordering available' : 'Browse-only preview'; ?></span></div>
	<section class="dbf-storefront" aria-label="Dough Boss menu">
		<?php echo doughboss_final_shortcode_or_notice( '[doughboss_shop_picker]', __( 'Shop selection is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo doughboss_final_shortcode_or_notice( '[doughboss_menu]', __( 'The menu is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( doughboss_final_ordering_open() ) : ?>
			<div class="dbf-builder-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_builder]', __( 'The pizza builder is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="dbf-cart-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_cart]', __( 'Checkout is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
	</section>
</div>
<?php get_footer(); ?>
