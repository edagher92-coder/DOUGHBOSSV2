</main>
<footer class="dbf-footer">
	<div class="dbf-wrap dbf-footer-grid">
		<div class="dbf-footer-brand">
			<a class="dbf-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span>DOUGH BOSS<span class="dbf-brand-dot">.</span></span></a>
			<p><?php esc_html_e( 'A contemporary Lebanese bakery. Stone-baked, fresh to order and serving Sydney since 2009.', 'doughboss-final' ); ?></p>
			<a class="dbf-social" href="https://instagram.com/doughboss" target="_blank" rel="noopener noreferrer" aria-label="Dough Boss on Instagram">
				<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="7" r="1.3" fill="currentColor"/></svg>
				@doughboss
			</a>
		</div>
		<div>
			<h2><?php esc_html_e( 'Visit', 'doughboss-final' ); ?></h2>
			<ul><li>Revesby</li><li>Bankstown</li><li>Roselands Centro</li></ul>
		</div>
		<div>
			<h2><?php esc_html_e( 'Explore', 'doughboss-final' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">About us</a></li>
				<li><a href="<?php echo esc_url( home_url( '/order/' ) ); ?>">Menu</a></li>
				<li><a href="<?php echo esc_url( home_url( '/catering/' ) ); ?>">Catering</a></li>
				<li><a href="<?php echo esc_url( home_url( '/vouchers/' ) ); ?>">Student vouchers</a></li>
				<li><a href="<?php echo esc_url( home_url( '/track-order/' ) ); ?>">Track order</a></li>
				<li><a href="<?php echo esc_url( home_url( '/locations/' ) ); ?>">Locations</a></li>
				<li><a href="<?php echo esc_url( home_url( '/franchising/' ) ); ?>">Partners</a></li>
			</ul>
		</div>
		<div>
			<h2><?php esc_html_e( 'Contact', 'doughboss-final' ); ?></h2>
			<ul>
				<li><a href="tel:+61297742286">(02) 9774 2286</a></li>
				<li><a href="mailto:orders@doughboss.com.au">orders@doughboss.com.au</a></li>
				<li><a href="mailto:catering@doughboss.com.au">catering@doughboss.com.au</a></li>
			</ul>
		</div>
	</div>
	<div class="dbf-wrap dbf-footer-bottom">
		<span>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> Dough Boss.</span>
		<span><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy</a> <span aria-hidden="true">&middot;</span> <a href="<?php echo esc_url( home_url( '/terms-conditions/' ) ); ?>">Terms</a></span>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
