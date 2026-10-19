<?php
/**
 * Square Gift Cards and loyalty information page.
 *
 * @package DoughBoss_Final
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section class="dbf-page-hero dbf-page-hero--compact dbf-page-hero--voucher" aria-labelledby="dbf-voucher-title">
	<div class="dbf-wrap dbf-page-hero-inner">
		<p class="dbf-eyebrow"><?php esc_html_e( 'Square Gift Cards', 'doughboss-final' ); ?></p>
		<h1 id="dbf-voucher-title" class="dbf-display"><?php esc_html_e( 'Gift cards & ', 'doughboss-final' ); ?><em><?php esc_html_e( 'loyalty.', 'doughboss-final' ); ?></em></h1>
		<p class="dbf-lede"><?php esc_html_e( 'Gift cards are handled through Square. We will share a purchase link here once it is available.', 'doughboss-final' ); ?></p>
	</div>
</section>
<section class="dbf-section dbf-section--cream dbf-voucher-section" aria-labelledby="dbf-voucher-guide-title">
	<div class="dbf-wrap">
		<h2 id="dbf-voucher-guide-title" class="dbf-heading"><?php esc_html_e( 'Coming soon.', 'doughboss-final' ); ?></h2>
		<p class="dbf-system-notice" role="status"><?php esc_html_e( 'Square Gift Cards and Dough Boss loyalty are not available on this website yet.', 'doughboss-final' ); ?></p>
		<p><?php esc_html_e( 'Have a question about an existing voucher? Please contact your shop for help.', 'doughboss-final' ); ?></p>
		<a class="dbf-btn" href="<?php echo esc_url( home_url( '/locations/' ) ); ?>"><?php esc_html_e( 'Find your shop', 'doughboss-final' ); ?></a>
	</div>
</section>
<?php get_footer(); ?>
