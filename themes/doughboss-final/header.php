<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="dbf-skip" href="#main-content"><?php esc_html_e( 'Skip to content', 'doughboss-final' ); ?></a>
<?php
$dbf_ordering_open = doughboss_final_ordering_open();
$dbf_order_phone   = doughboss_final_order_phone();
// Both ordering states are rendered; the plugin's live /config read (shop
// status script) reveals the right one even on a cached page.
?>
<div class="dbf-launch-bar" role="region" aria-label="<?php esc_attr_e( 'Ordering status', 'doughboss-final' ); ?>" data-db-when-ordering="paused"<?php echo $dbf_ordering_open ? ' hidden' : ''; ?>>
	<span><?php esc_html_e( 'Online ordering is paused — call to order or visit a shop', 'doughboss-final' ); ?></span>
	<a href="<?php echo esc_attr( 'tel:' . $dbf_order_phone['tel'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: phone number. */ __( 'Call %s', 'doughboss-final' ), $dbf_order_phone['display'] ) ); ?></a>
</div>
<header class="dbf-header" data-dbf-header>
	<div class="dbf-wrap dbf-header-inner">
		<a class="dbf-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Dough Boss home', 'doughboss-final' ); ?>">
			<span>DOUGH BOSS<span class="dbf-brand-dot">.</span></span>
		</a>
		<?php // Icon-only toggle: theme.js keeps aria-expanded and aria-label (Open/Close navigation) in sync. ?>
		<button class="dbf-menu-toggle" type="button" aria-expanded="false" aria-controls="dbf-primary-nav" aria-label="<?php esc_attr_e( 'Open navigation', 'doughboss-final' ); ?>" data-dbf-menu-toggle>
			<svg class="dbf-menu-icon dbf-menu-icon--open" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			<svg class="dbf-menu-icon dbf-menu-icon--close" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
		</button>
		<nav id="dbf-primary-nav" class="dbf-nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'doughboss-final' ); ?>" data-dbf-nav>
			<button class="dbf-nav-close" type="button" aria-label="<?php esc_attr_e( 'Close navigation', 'doughboss-final' ); ?>" data-dbf-menu-close>
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
			<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'dbf-nav-list', 'fallback_cb' => 'doughboss_final_menu_fallback' ) ); ?>
		</nav>
		<?php // The single primary header CTA. Phone ordering lives in the nav and launch bar while paused. ?>
		<a class="dbf-button dbf-button--small dbf-header-cta" href="<?php echo esc_url( home_url( '/order/' ) ); ?>" data-db-ordering-swap data-db-open-label="<?php esc_attr_e( 'Order now', 'doughboss-final' ); ?>" data-db-paused-label="<?php esc_attr_e( 'Browse menu', 'doughboss-final' ); ?>"><?php echo esc_html( $dbf_ordering_open ? __( 'Order now', 'doughboss-final' ) : __( 'Browse menu', 'doughboss-final' ) ); ?></a>
	</div>
	<div class="dbf-wrap dbf-header-shop" data-doughboss-header-shop data-locations-url="<?php echo esc_url( home_url( '/locations/' ) ); ?>">
		<a href="<?php echo esc_url( home_url( '/locations/' ) ); ?>"><?php esc_html_e( 'Check locations and pickup availability', 'doughboss-final' ); ?></a>
	</div>
</header>
<main id="main-content" class="dbf-main">
