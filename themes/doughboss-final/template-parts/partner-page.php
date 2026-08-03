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
?>
<section class="dbf-page-hero"><img class="dbf-page-hero-bg" src="<?php echo esc_url( doughboss_final_asset_url( 'menu/zaatar.webp' ) ); ?>" alt=""><div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow"><?php echo esc_html( $eyebrow ); ?></p><h1 class="dbf-display"><?php echo esc_html( $headline ); ?></h1><p class="dbf-lede"><?php echo esc_html( $lede ); ?></p></div></section>
<section class="dbf-page-content dbf-page-content--partner"><div class="dbf-wrap dbf-partner-grid<?php echo $has_partner_content ? '' : ' dbf-partner-grid--single'; ?>"><aside class="dbf-partner-aside"><p class="dbf-eyebrow">Start a conversation</p><h2 class="dbf-heading">Let's talk.</h2><p>Tell us who you are, what you are exploring and the best way to contact you.</p><a class="dbf-button" href="mailto:orders@doughboss.com.au">Contact Dough Boss</a></aside><?php if ( $has_partner_content ) : ?><article class="dbf-prose"><?php echo $partner_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content filters have already run. ?></article><?php endif; ?></div></section>
