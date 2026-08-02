<?php get_header(); ?>

<section class="dbf-hero" aria-label="Dough Boss introduction">
	<div class="dbf-wrap">
		<h1 class="dbf-sr-only"><?php esc_html_e( 'Dough Boss — a contemporary Lebanese bakery', 'doughboss-final' ); ?></h1>
		<?php
		echo doughboss_final_shortcode_or_notice(
			'[doughboss_manoush_hero variant="bites" kicker="In the industry since 2009" title="A taste of the Mediterranean." description="Authentic manoush, pizza and pies, stone-baked fresh to order with a modern twist." replay_label="Replay the food build"]',
			__( 'The DoughBoss menu experience is being prepared.', 'doughboss-final' )
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="dbf-hero-stats" aria-label="Dough Boss at a glance">
			<div class="dbf-hero-stat"><strong>Since 2009</strong><span>In the industry</span></div>
			<div class="dbf-hero-stat"><strong>Fresh</strong><span>Baked to order</span></div>
			<div class="dbf-hero-stat"><strong>3 shops</strong><span>Now baking across Sydney</span></div>
		</div>
	</div>
</section>

<section class="dbf-section dbf-section--dark">
	<div class="dbf-wrap dbf-story-grid">
		<div class="dbf-story-copy" data-dbf-reveal>
			<p class="dbf-eyebrow">Who we are</p>
			<h2 class="dbf-heading">Tradition through generations.<br><span style="color:var(--dbf-ember)">Made for today.</span></h2>
			<p>Dough Boss is a contemporary Lebanese bakery preserving traditional dough recipes while embracing a modern twist. We use quality ingredients to craft simple, generous manoush, pizza and pies — a taste of the Mediterranean in every bite.</p>
			<a class="dbf-button" href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">Our story</a>
		</div>
		<div class="dbf-story-media" data-dbf-reveal data-dbf-reveal-delay="1">
			<img src="<?php echo esc_url( doughboss_final_asset_url( 'menu/dough-boss-special.webp' ) ); ?>" width="900" height="720" alt="Freshly baked Dough Boss manoush" loading="lazy" decoding="async">
		</div>
	</div>
</section>

<section class="dbf-section dbf-section--cream">
	<div class="dbf-wrap">
		<header class="dbf-section-head" data-dbf-reveal>
			<div><p class="dbf-eyebrow">What we do</p><h2 class="dbf-heading">Three ways we feed you</h2></div>
			<p>Fresh dough, bold Mediterranean flavour and food made when you order it.</p>
		</header>
		<div class="dbf-card-grid">
			<article class="dbf-card" data-dbf-reveal><span class="dbf-card-number">01</span><h3>Manoush</h3><p>Stone-baked flatbreads with zaatar, cheese, meat and more, served flat or folded.</p><a class="dbf-card-link" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">Browse the menu &rarr;</a></article>
			<article class="dbf-card" data-dbf-reveal data-dbf-reveal-delay="1"><span class="dbf-card-number">02</span><h3>Pizza, pies &amp; wraps</h3><p>Stone-baked pizzas, golden pies and fresh-rolled wraps made to order.</p><a class="dbf-card-link" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">See what's baking &rarr;</a></article>
			<article class="dbf-card" data-dbf-reveal data-dbf-reveal-delay="2"><span class="dbf-card-number">03</span><h3>Catering</h3><p>Office runs, footy nights and functions. Tell us what you need and we'll help plan the spread.</p><a class="dbf-card-link" href="<?php echo esc_url( home_url( '/catering/' ) ); ?>">Plan catering &rarr;</a></article>
		</div>
	</div>
</section>

<section class="dbf-section">
	<div class="dbf-wrap">
		<header class="dbf-section-head" data-dbf-reveal>
			<div><p class="dbf-eyebrow">From the oven</p><h2 class="dbf-heading">Find your favourite</h2></div>
			<a class="dbf-button dbf-button--outline" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">Browse the full menu</a>
		</header>
		<div class="dbf-food-grid">
			<a class="dbf-food-card" href="<?php echo esc_url( home_url( '/order/#manoush' ) ); ?>" data-dbf-reveal><img src="<?php echo esc_url( doughboss_final_asset_url( 'menu/zaatar-cheese.webp' ) ); ?>" alt="Zaatar and cheese manoush" loading="lazy"><span class="dbf-food-card-copy"><span>Stone-baked</span><strong>Manoush</strong></span></a>
			<a class="dbf-food-card" href="<?php echo esc_url( home_url( '/order/#pizza' ) ); ?>" data-dbf-reveal data-dbf-reveal-delay="1"><img src="<?php echo esc_url( doughboss_final_asset_url( 'menu/dough-boss-special.webp' ) ); ?>" alt="Dough Boss special pizza" loading="lazy"><span class="dbf-food-card-copy"><span>Fresh to order</span><strong>Pizza</strong></span></a>
			<a class="dbf-food-card" href="<?php echo esc_url( home_url( '/order/#pies' ) ); ?>" data-dbf-reveal data-dbf-reveal-delay="2"><img src="<?php echo esc_url( doughboss_final_asset_url( 'menu/spinach-cheese-pie.webp' ) ); ?>" alt="Spinach and cheese pie" loading="lazy"><span class="dbf-food-card-copy"><span>Golden and warm</span><strong>Pies</strong></span></a>
		</div>
	</div>
</section>

<section class="dbf-section dbf-section--cream">
	<div class="dbf-wrap dbf-catering-panel" data-dbf-reveal>
		<div class="dbf-catering-copy">
			<p class="dbf-eyebrow">Catering, made fresh</p>
			<h2 class="dbf-heading">Feed the whole table.</h2>
			<p>Mini manoush, pizzas, pies, wraps and generous platters for work, family and celebrations. Contact our catering team and we'll help build the right menu.</p>
			<a class="dbf-button" href="<?php echo esc_url( home_url( '/catering/' ) ); ?>">Plan your catering</a>
		</div>
		<div class="dbf-catering-media"><img src="<?php echo esc_url( doughboss_final_asset_url( 'catering-menu-platter-v3.webp' ) ); ?>" alt="Dough Boss catering platter" loading="lazy"></div>
	</div>
</section>

<section class="dbf-section">
	<div class="dbf-wrap">
		<header class="dbf-section-head" data-dbf-reveal><div><p class="dbf-eyebrow">Visit us</p><h2 class="dbf-heading">Three shops baking daily</h2></div><a class="dbf-button dbf-button--outline" href="<?php echo esc_url( home_url( '/locations/' ) ); ?>">View locations</a></header>
		<?php get_template_part( 'template-parts/locations' ); ?>
	</div>
</section>

<section class="dbf-section dbf-section--dark">
	<div class="dbf-wrap dbf-review-panel" data-dbf-reveal>
		<div><p class="dbf-eyebrow" style="color:#fff">Your experience matters</p><h2 class="dbf-heading">Been to Dough Boss?</h2><p>Tell us about your genuine experience. Your feedback helps our team improve and helps local customers know what to expect.</p></div>
		<a class="dbf-button dbf-button--light" href="<?php echo esc_url( (string) doughboss_final_setting( 'google_review_url', 'https://www.google.com/maps/search/?api=1&query=Dough+Boss+Revesby' ) ); ?>" target="_blank" rel="noopener noreferrer">Leave a Google review</a>
	</div>
</section>

<?php get_footer(); ?>
