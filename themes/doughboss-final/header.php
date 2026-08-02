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
<?php if ( ! doughboss_final_ordering_open() ) : ?>
	<div class="dbf-launch-bar" role="region" aria-label="<?php esc_attr_e( 'Ordering status', 'doughboss-final' ); ?>">
		<span><?php esc_html_e( 'Online ordering is coming soon', 'doughboss-final' ); ?></span>
		<a href="<?php echo esc_url( home_url( '/order/' ) ); ?>"><?php esc_html_e( 'Browse the menu', 'doughboss-final' ); ?></a>
	</div>
<?php endif; ?>
<header class="dbf-header" data-dbf-header>
	<div class="dbf-wrap dbf-header-inner">
		<a class="dbf-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Dough Boss home', 'doughboss-final' ); ?>">
			<span>DOUGH BOSS<span class="dbf-brand-dot">.</span></span>
		</a>
		<button class="dbf-menu-toggle" type="button" aria-expanded="false" aria-controls="dbf-primary-nav" aria-label="<?php esc_attr_e( 'Open navigation', 'doughboss-final' ); ?>" data-dbf-menu-toggle>
			<span class="dbf-menu-toggle-label"><?php esc_html_e( 'Menu', 'doughboss-final' ); ?></span>
			<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
		</button>
		<nav id="dbf-primary-nav" class="dbf-nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'doughboss-final' ); ?>" data-dbf-nav>
			<button class="dbf-nav-close" type="button" aria-label="<?php esc_attr_e( 'Close navigation', 'doughboss-final' ); ?>" data-dbf-menu-close>
				<span aria-hidden="true">&times;</span>
			</button>
			<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'menu_class' => 'dbf-nav-list', 'fallback_cb' => 'doughboss_final_menu_fallback' ) ); ?>
		</nav>
		<a class="dbf-button dbf-button--small dbf-header-cta" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">
			<?php echo esc_html( doughboss_final_ordering_open() ? __( 'Order now', 'doughboss-final' ) : __( 'Browse menu', 'doughboss-final' ) ); ?>
		</a>
	</div>
</header>
<main id="main-content" class="dbf-main">
