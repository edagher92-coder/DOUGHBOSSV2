<?php get_header(); ?>
<section class="dbf-page-hero">
	<?php echo doughboss_final_asset_image( 'doughboss-feast-real-v1.jpg', '', array( 'class' => 'dbf-page-hero-bg', 'decoding' => 'async', 'fetchpriority' => 'high' ) ); ?>
	<div class="dbf-wrap dbf-page-hero-inner"><p class="dbf-eyebrow">Our story</p><h1 class="dbf-display">Mediterranean roots.<br><em>Sydney energy.</em></h1><p class="dbf-lede">A contemporary Lebanese bakery built on recipes passed down through generations and food made fresh for the people around us.</p></div>
</section>
<section class="dbf-section dbf-section--dark"><div class="dbf-wrap dbf-story-grid">
	<div class="dbf-story-copy" data-dbf-reveal><p class="dbf-eyebrow">Since 2009</p><h2 class="dbf-heading">Dough is where our story begins.</h2><p>We keep the things that matter: traditional dough, natural ingredients, generous flavour and the care that comes from making food to order. Then we bring a modern approach to the way customers discover the menu, order and stay connected.</p><p>From zaatar and cheese manoush to pizza, pies and wraps, the goal stays simple — serve food that feels familiar, fresh and worth coming back for.</p></div>
	<div class="dbf-story-media" data-dbf-reveal data-dbf-reveal-delay="1"><?php echo doughboss_final_asset_image( 'menu/real-v1/meat-cheese.jpg', 'Real Dough Boss meat and cheese manoush', array( 'loading' => 'lazy', 'decoding' => 'async' ) ); ?></div>
</div></section>
<section class="dbf-section dbf-section--cream"><div class="dbf-wrap"><header class="dbf-section-head"><div><p class="dbf-eyebrow">What guides us</p><h2 class="dbf-heading">Fresh, straightforward, generous</h2></div></header><div class="dbf-card-grid">
	<article class="dbf-card" data-dbf-reveal><span class="dbf-card-number">01</span><h3>Made fresh</h3><p>Dough prepared with care and food baked to order throughout the day.</p></article>
	<article class="dbf-card" data-dbf-reveal data-dbf-reveal-delay="1"><span class="dbf-card-number">02</span><h3>Built on tradition</h3><p>Lebanese bakery flavours and recipes carried forward with a modern twist.</p></article>
	<article class="dbf-card" data-dbf-reveal data-dbf-reveal-delay="2"><span class="dbf-card-number">03</span><h3>Made for community</h3><p>Three Sydney shops serving breakfast, lunch, families, teams and celebrations.</p></article>
</div></div></section>
<section class="dbf-section"><div class="dbf-wrap dbf-review-panel"><div><p class="dbf-eyebrow" style="color:#fff">Hungry?</p><h2 class="dbf-heading">See what's baking.</h2><p>Browse the complete menu now. Online checkout will open after the final in-store setup is approved.</p></div><a class="dbf-button dbf-button--light" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">Browse the menu</a></div></section>
<?php get_footer(); ?>
