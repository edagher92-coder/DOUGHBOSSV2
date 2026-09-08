<?php
$ordering_open = doughboss_final_ordering_open();
$ordering_status = doughboss_final_shortcode_or_notice( '[doughboss_ordering_status]', __( 'Online ordering is coming soon.', 'doughboss-final' ) );
$has_ordering_status = '' !== trim( $ordering_status );
$counter_class = 'dbf-order-counter';
if ( $ordering_open && ! $has_ordering_status ) {
	$counter_class .= ' dbf-order-counter--shop-only';
} elseif ( ! $ordering_open ) {
	$counter_class .= ' dbf-order-counter--status-only';
}
get_header();
?>
<section class="dbf-page-hero dbf-page-hero--order" aria-labelledby="dbf-order-title">
	<?php echo doughboss_final_asset_image( 'menu/real-v1/sujuk-deluxe.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?>
	<div class="dbf-wrap dbf-page-hero-inner">
		<p class="dbf-eyebrow"><?php echo esc_html( $ordering_open ? 'Fresh from the oven' : 'Browse the complete menu' ); ?></p>
		<h1 id="dbf-order-title" class="dbf-display">Order <em>online.</em></h1>
		<p class="dbf-lede"><?php echo esc_html( $ordering_open ? 'Find your favourites, choose your options and review your order.' : 'Online checkout is coming soon. Browse every category now while the final in-store ordering channels are completed.' ); ?></p>
		<?php if ( ! $ordering_open ) : ?>
			<span class="dbf-coming-soon-badge" role="note"><span aria-hidden="true"></span><?php esc_html_e( 'Checkout coming soon', 'doughboss-final' ); ?></span>
		<?php endif; ?>
	</div>
</section>
<div class="dbf-order-stage">
	<div class="dbf-wrap dbf-order-intro" aria-label="Ordering availability">
		<section class="<?php echo esc_attr( $counter_class ); ?>">
			<?php if ( $ordering_open ) : ?>
				<div class="dbf-order-counter__shop">
					<?php echo doughboss_final_shortcode_or_notice( '[doughboss_shop_picker]', __( 'Shop selection is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
			<?php if ( $has_ordering_status ) : ?>
				<div class="dbf-order-counter__status">
					<?php echo $ordering_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</section>
	</div>
	<?php if ( ! $ordering_open ) : ?>
		<div class="dbf-wrap dbf-order-readiness" aria-label="What to expect when checkout opens">
			<div><span aria-hidden="true">01</span><strong><?php esc_html_e( 'Browse the full menu', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Explore every category, price and option now.', 'doughboss-final' ); ?></small></div>
			<div><span aria-hidden="true">02</span><strong><?php esc_html_e( 'Review before you submit', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Check your order details before you submit it.', 'doughboss-final' ); ?></small></div>
			<div><span aria-hidden="true">03</span><strong><?php esc_html_e( 'Pickup updates', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Follow your order from received to ready.', 'doughboss-final' ); ?></small></div>
		</div>
	<?php endif; ?>
	<div class="dbf-wrap dbf-order-shell">
		<section class="dbf-storefront" aria-label="Dough Boss menu">
			<?php echo doughboss_final_shortcode_or_notice( '[doughboss_menu]', __( 'The menu is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $ordering_open ) : ?>
				<div class="dbf-builder-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_builder]', __( 'The pizza builder is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="dbf-cart-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_cart]', __( 'Checkout is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
		</section>
	</div>
</div>
<?php get_footer(); ?>
