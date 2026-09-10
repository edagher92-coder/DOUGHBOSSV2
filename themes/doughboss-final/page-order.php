<?php get_header(); ?>
<section class="dbf-page-hero dbf-page-hero--order" aria-labelledby="dbf-order-title">
	<img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'menu/real-v1/sujuk-deluxe.jpg' ) ); ?>" alt="" width="900" height="720" fetchpriority="high">
	<div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow"><?php echo esc_html( doughboss_final_ordering_open() ? 'Pickup from Revesby' : 'Browse the complete menu' ); ?></p><h1 id="dbf-order-title" class="dbf-display"><?php if ( doughboss_final_ordering_open() ) : ?>Order <em>online.</em><?php else : ?>Browse the <em>menu.</em><?php endif; ?></h1><p class="dbf-lede"><?php echo esc_html( doughboss_final_ordering_open() ? 'Choose your favourites, customise them and order for pickup from Revesby.' : 'Online checkout is currently unavailable. Browse the menu or call Revesby to place an order.' ); ?></p><?php if ( ! doughboss_final_ordering_open() ) : ?><span class="dbf-coming-soon-badge" role="note"><span aria-hidden="true"></span><?php esc_html_e( 'Checkout coming soon', 'doughboss-final' ); ?></span><?php endif; ?></div>
</section>
<div class="dbf-order-stage">
	<?php if ( doughboss_final_ordering_open() ) : ?>
	<div class="dbf-wrap dbf-order-intro" aria-label="Ordering availability">
		<section class="dbf-order-location" aria-labelledby="dbf-order-location-title">
			<div><strong id="dbf-order-location-title">Revesby</strong><span>Shop 12/25 Selems Parade, Revesby NSW 2212</span></div>
			<span><?php esc_html_e( 'Pickup ordering available', 'doughboss-final' ); ?></span>
		</section>
		<div class="dbf-order-status"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_ordering_status]', __( 'Online ordering is coming soon.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	</div>
	<?php else : ?>
		<section class="dbf-wrap dbf-order-browse-brief" aria-label="Order by phone">
			<div><strong id="dbf-order-location-title"><?php esc_html_e( 'Revesby bakery', 'doughboss-final' ); ?></strong><span><?php esc_html_e( 'Shop 12/25 Selems Parade, Revesby NSW 2212 · online checkout coming soon.', 'doughboss-final' ); ?></span></div>
			<a class="dbf-button dbf-button--small" href="tel:+61297742286"><?php esc_html_e( 'Call (02) 9774 2286', 'doughboss-final' ); ?></a>
		</section>
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
