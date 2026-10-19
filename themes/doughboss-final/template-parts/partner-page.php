<?php
$is_wholesale = is_page( 'wholesale' );
$eyebrow = $is_wholesale ? __( 'Wholesale', 'doughboss-final' ) : __( 'Partnerships', 'doughboss-final' );
$headline = $is_wholesale ? __( 'Bring Dough Boss to your customers.', 'doughboss-final' ) : __( 'Grow with a bakery people remember.', 'doughboss-final' );
$lede = $is_wholesale ? __( 'Talk to us about wholesale supply, product fit and operational requirements.', 'doughboss-final' ) : __( 'Learn about the brand, operating model and what a Dough Boss partnership could look like.', 'doughboss-final' );
$partner_content = '';
while ( have_posts() ) {
	the_post();
	ob_start();
	the_content();
	$partner_content .= (string) ob_get_clean();
}
$has_partner_content = '' !== trim( wp_strip_all_tags( $partner_content ) );
// The enquiry form ships with these templates. If an editor already pasted
// [doughboss_b2b_form] into the page content, do not render a second copy.
$has_partner_form = false !== strpos( $partner_content, 'data-doughboss-b2b' );
$partner_phone    = doughboss_final_order_phone();
$partner_form     = $has_partner_form ? '' : doughboss_final_shortcode_or_notice(
	'[doughboss_b2b_form type="' . ( $is_wholesale ? 'wholesale' : 'franchise' ) . '"]',
	sprintf(
		/* translators: 1: phone number. */
		__( 'Our enquiry form is unavailable right now. Please email info@doughboss.com.au or call %1$s.', 'doughboss-final' ),
		$partner_phone['display']
	)
);
?>
<section class="dbf-page-hero"><?php echo doughboss_final_asset_image( 'doughboss-feast-real-v1.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow"><?php echo esc_html( $eyebrow ); ?></p><h1 class="dbf-display"><?php echo esc_html( $headline ); ?></h1><p class="dbf-lede"><?php echo esc_html( $lede ); ?></p></div></section>
<section class="dbf-page-content dbf-page-content--partner"><div class="dbf-wrap dbf-partner-grid">
	<aside class="dbf-partner-aside"><p class="dbf-eyebrow"><?php esc_html_e( 'Start a conversation', 'doughboss-final' ); ?></p><h2 class="dbf-heading"><?php esc_html_e( "Let's talk.", 'doughboss-final' ); ?></h2><p><?php esc_html_e( 'Tell us who you are, what you are exploring and the best way to contact you. The form goes straight to our team, and we reply by email or phone.', 'doughboss-final' ); ?></p></aside>
	<div class="dbf-partner-main">
		<?php if ( $has_partner_content ) : ?><article class="dbf-prose"><?php echo $partner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content filters have already run. ?></article><?php endif; ?>
		<?php if ( '' !== $partner_form ) : ?>
			<div class="dbf-partner-form" id="enquiry">
				<h2 class="dbf-partner-form-title"><?php echo esc_html( $is_wholesale ? __( 'Wholesale enquiry', 'doughboss-final' ) : __( 'Franchising & partnership enquiry', 'doughboss-final' ) ); ?></h2>
				<?php echo $partner_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output escapes itself; the notice is escaped. ?>
			</div>
		<?php endif; ?>
	</div>
</div></section>
