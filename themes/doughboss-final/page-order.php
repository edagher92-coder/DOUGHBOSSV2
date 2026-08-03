<?php get_header(); ?>
<section class="dbf-page-hero dbf-page-hero--order" aria-labelledby="dbf-order-title">
	<img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'doughboss-hero-premium-v1.webp' ) ); ?>" alt="" width="1600" height="900" fetchpriority="high">
	<div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow"><?php echo esc_html( doughboss_final_ordering_open() ? 'Pickup from Revesby' : 'Browse the complete menu' ); ?></p><h1 id="dbf-order-title" class="dbf-display">Order <em>online.</em></h1><p class="dbf-lede"><?php echo esc_html( doughboss_final_ordering_open() ? 'Choose your favourites, customise them and order for pickup from Revesby.' : 'Online checkout is coming soon. Browse every category now while the final in-store ordering channels are completed.' ); ?></p><?php if ( ! doughboss_final_ordering_open() ) : ?><span class="dbf-coming-soon-badge" role="note"><span aria-hidden="true"></span><?php esc_html_e( 'Checkout coming soon', 'doughboss-final' ); ?></span><?php endif; ?></div>
</section>
<div class="dbf-order-stage">
	<div class="dbf-wrap dbf-order-intro" aria-label="Ordering availability">
		<section class="dbf-order-location" aria-labelledby="dbf-order-location-title">
			<div><strong id="dbf-order-location-title">Revesby</strong><span>Shop 12/25 Selems Parade, Revesby NSW 2212</span></div>
			<span><?php echo esc_html( doughboss_final_ordering_open() ? 'Pickup ordering available' : 'Browse-only preview' ); ?></span>
		</section>
		<div class="dbf-order-status"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_ordering_status]', __( 'Online ordering is coming soon.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	</div>
	<?php if ( ! doughboss_final_ordering_open() ) : ?>
		<div class="dbf-wrap dbf-order-readiness" aria-label="What to expect when checkout opens">
			<div><span aria-hidden="true">01</span><strong><?php esc_html_e( 'Browse the full menu', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Explore every category, price and option now.', 'doughboss-final' ); ?></small></div>
			<div><span aria-hidden="true">02</span><strong><?php esc_html_e( 'Fast, secure checkout', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Card and eligible digital wallets at launch.', 'doughboss-final' ); ?></small></div>
			<div><span aria-hidden="true">03</span><strong><?php esc_html_e( 'Revesby pickup updates', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Follow your order from received to ready.', 'doughboss-final' ); ?></small></div>
		</div>
	<?php endif; ?>
	<div class="dbf-wrap dbf-order-shell">
		<section class="dbf-storefront" aria-label="Dough Boss menu">
		<?php if ( doughboss_final_ordering_open() ) : ?><?php echo doughboss_final_shortcode_or_notice( '[doughboss_shop_picker]', __( 'Shop selection is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
		<?php echo doughboss_final_shortcode_or_notice( '[doughboss_menu]', __( 'The menu is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( doughboss_final_ordering_open() ) : ?>
			<div class="dbf-builder-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_builder]', __( 'The pizza builder is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="dbf-cart-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_cart]', __( 'Checkout is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
		</section>
	</div>
</div>
<?php get_footer(); ?>
