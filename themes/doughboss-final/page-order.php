<?php
$ordering_open = doughboss_final_ordering_open();
$ordering_status = doughboss_final_shortcode_or_notice(
	'[doughboss_ordering_status]',
	$ordering_open
		? __( 'Ordering availability is temporarily unavailable. Check with your selected shop.', 'doughboss-final' )
		: __( 'Online ordering is paused. You can still browse the menu and contact a shop.', 'doughboss-final' )
);
$has_ordering_status = '' !== trim( $ordering_status );
$counter_class = 'dbf-order-counter';
if ( ! $has_ordering_status ) {
	$counter_class .= ' dbf-order-counter--shop-only';
}
get_header();
?>
<section class="dbf-page-hero dbf-page-hero--order" aria-labelledby="dbf-order-title">
	<?php echo doughboss_final_asset_image( 'menu/real-v1/sujuk-deluxe.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?>
	<div class="dbf-wrap dbf-page-hero-inner">
		<h1 id="dbf-order-title" class="dbf-display">
			<?php if ( $ordering_open ) : ?>
				<?php esc_html_e( 'Order', 'doughboss-final' ); ?> <em><?php esc_html_e( 'online.', 'doughboss-final' ); ?></em>
			<?php else : ?>
				<?php esc_html_e( 'Browse', 'doughboss-final' ); ?> <em><?php esc_html_e( 'the menu.', 'doughboss-final' ); ?></em>
			<?php endif; ?>
		</h1>
		<p class="dbf-lede"><?php echo esc_html( $ordering_open ? __( 'Choose your favourites and review your order.', 'doughboss-final' ) : __( 'Online ordering is paused. Browse the menu and check your shop.', 'doughboss-final' ) ); ?></p>
	</div>
</section>
<div class="dbf-order-stage">
	<div class="dbf-wrap dbf-order-intro" aria-label="<?php esc_attr_e( 'Shop and ordering availability', 'doughboss-final' ); ?>">
		<section class="<?php echo esc_attr( $counter_class ); ?>">
			<div class="dbf-order-counter__shop">
				<?php echo doughboss_final_shortcode_or_notice( '[doughboss_shop_picker]', __( 'Shop selection is temporarily unavailable. View locations before visiting.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $has_ordering_status ) : ?>
				<div class="dbf-order-counter__status">
					<?php echo $ordering_status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</section>
	</div>
	<div class="dbf-wrap dbf-order-shell">
		<section class="dbf-storefront" aria-label="Dough Boss menu">
			<?php echo doughboss_final_shortcode_or_notice( '[doughboss_menu]', __( 'The menu is temporarily unavailable. Please try again shortly.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $ordering_open ) : ?>
				<div class="dbf-builder-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_builder]', __( 'Customization is temporarily unavailable.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="dbf-cart-wrap"><?php echo doughboss_final_shortcode_or_notice( '[doughboss_cart]', __( 'Checkout is temporarily unavailable.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
		</section>
	</div>
</div>
<?php get_footer(); ?>
