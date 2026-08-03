<?php
/**
 * Student voucher claim page.
 *
 * @package DoughBoss_Final
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This template renders the shortcode itself, so opt into the plugin assets
// before wp_head() runs even when the page editor body is empty.
add_filter( 'doughboss_load_assets', '__return_true' );
add_filter( 'doughboss_load_voucher_assets', '__return_true' );

get_header();
?>
<section class="dbf-page-hero dbf-page-hero--compact dbf-page-hero--voucher" aria-labelledby="dbf-voucher-title">
	<div class="dbf-wrap dbf-page-hero-inner">
		<p class="dbf-eyebrow"><?php esc_html_e( 'Student offer', 'doughboss-final' ); ?></p>
		<h1 id="dbf-voucher-title" class="dbf-display"><?php esc_html_e( 'Claim your ', 'doughboss-final' ); ?><em><?php esc_html_e( '$5 voucher.', 'doughboss-final' ); ?></em></h1>
		<p class="dbf-lede"><?php esc_html_e( 'Eligible students can claim one single-use voucher each day while the daily allocation lasts.', 'doughboss-final' ); ?></p>
	</div>
</section>

<section class="dbf-section dbf-section--cream dbf-voucher-section">
	<div class="dbf-wrap dbf-voucher-layout">
		<aside class="dbf-voucher-guide" aria-labelledby="dbf-voucher-guide-title">
			<p class="dbf-eyebrow"><?php esc_html_e( 'Before you begin', 'doughboss-final' ); ?></p>
			<h2 id="dbf-voucher-guide-title" class="dbf-heading"><?php esc_html_e( 'Your student email is required.', 'doughboss-final' ); ?></h2>
			<p><?php esc_html_e( 'Use an education email ending in .edu or .edu.au. You will enter it twice so your voucher is allocated to the right student.', 'doughboss-final' ); ?></p>
			<ol class="dbf-voucher-steps">
				<li><span>1</span><div><strong><?php esc_html_e( 'Choose the offer', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Select the available student voucher.', 'doughboss-final' ); ?></small></div></li>
				<li><span>2</span><div><strong><?php esc_html_e( 'Confirm your details', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Enter your mobile and matching student email twice.', 'doughboss-final' ); ?></small></div></li>
				<li><span>3</span><div><strong><?php esc_html_e( 'Use your single-use code', 'doughboss-final' ); ?></strong><small><?php esc_html_e( 'Show the QR code at the till or apply the code at checkout.', 'doughboss-final' ); ?></small></div></li>
			</ol>
			<p class="dbf-voucher-fineprint"><?php esc_html_e( 'One voucher per eligible student email per day, subject to the daily allocation and campaign terms.', 'doughboss-final' ); ?></p>
		</aside>
		<div class="dbf-voucher-widget" aria-label="Student voucher claim form">
			<?php if ( shortcode_exists( 'doughboss_voucher_claim' ) ) : ?>
				<?php echo do_shortcode( '[doughboss_voucher_claim]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<p class="dbf-system-notice" role="status"><?php esc_html_e( 'Voucher claiming is temporarily unavailable. Please contact the shop.', 'doughboss-final' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php get_footer(); ?>
