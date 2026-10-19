<?php
// Notice and contact details remain owned by the existing catering settings.
$dbf_catering_notice = doughboss_final_catering_notice_line();
$dbf_catering_hero_description = str_replace( array( '"', '[', ']' ), '', 'Office meetings, team lunches and events — share your date, guest count and plans. Our Revesby team will confirm the details. ' . $dbf_catering_notice . '.' );
$dbf_catering_email = class_exists( 'DoughBoss_Settings' ) ? DoughBoss_Settings::catering_email() : 'catering@doughboss.com.au';
get_header();
?>
<section class="dbf-hero">
	<div class="dbf-wrap">
		<h1 class="dbf-sr-only"><?php esc_html_e( 'Corporate and event catering in Sydney', 'doughboss-final' ); ?></h1>
		<?php echo doughboss_final_shortcode_or_notice( '[doughboss_manoush_hero variant="catering" kicker="Corporate & event catering" title="Good food. Great company." description="' . $dbf_catering_hero_description . '" replay_label="Pause photo motion" secondary_url="#catering-enquiry"]', __( 'Catering is being prepared.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
<section class="dbf-section dbf-section--cream" aria-labelledby="dbf-catering-range">
	<div class="dbf-wrap dbf-feature-grid">
		<div data-dbf-reveal>
			<p class="dbf-eyebrow">Made for sharing</p>
			<h2 id="dbf-catering-range" class="dbf-heading">Office catering with a Lebanese heart.</h2>
			<p class="dbf-lede">Bring the team together over manoush, pizzas, pies, wraps and platters. From a meeting table to a celebration, tell us what you have in mind and we’ll help plan the spread.</p>
			<p>All catering enquiries are coordinated by our Revesby team. We’ll confirm the menu, availability, collection arrangements and final pricing before your booking proceeds.</p>
			<div class="dbf-catering-actions">
				<a class="dbf-button" href="#catering-enquiry">Plan your catering</a>
				<a class="dbf-catering-text-link" href="#dbf-catering-repeat">Enquiring again?</a>
			</div>
		</div>
		<div class="dbf-catering-media" data-dbf-reveal data-dbf-reveal-delay="1">
			<?php echo doughboss_final_asset_image( 'doughboss-feast-real-v1.jpg', __( 'Fresh Dough Boss manoush, pizza and pies for sharing', 'doughboss-final' ), array( 'loading' => 'lazy', 'decoding' => 'async' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
</section>
<section class="dbf-section" aria-labelledby="dbf-catering-occasions">
	<div class="dbf-wrap">
		<p class="dbf-eyebrow">Your team. Your occasion.</p>
		<h2 id="dbf-catering-occasions" class="dbf-heading">A spread that fits the gathering.</h2>
		<div class="dbf-card-grid dbf-catering-usecases">
			<article class="dbf-card">
				<h3>Office meetings &amp; team lunches</h3>
				<p>Planning a breakfast meeting, training day or business lunch? Tell us your guest count and serving time so the team can suggest a suitable mix.</p>
			</article>
			<article class="dbf-card">
				<h3>Corporate &amp; professional events</h3>
				<p>For a client gathering or workplace event, share the format, budget and practical details. We’ll check what can be arranged rather than leave you guessing.</p>
			</article>
			<article class="dbf-card">
				<h3>Celebrations &amp; group catch-ups</h3>
				<p>Bring your occasion to us, from family celebrations to team milestones. Choose a starting point below and add the details that matter to your group.</p>
			</article>
		</div>
		<aside class="dbf-catering-planning" aria-labelledby="dbf-catering-plan-title">
			<h3 id="dbf-catering-plan-title">A little planning makes a better spread.</h3>
			<p><?php echo esc_html( $dbf_catering_notice ); ?>. Include your event date, serving time, guest count and budget in your enquiry. The Notes field is a good place for your company, occasion and collection requirements.</p>
			<p>Let us know about dietary requests and allergies. The team must confirm suitable options and preparation requirements with you; an enquiry alone is not an allergen or availability guarantee.</p>
		</aside>
	</div>
</section>
<section id="catering-enquiry" class="dbf-section" tabindex="-1" aria-label="Catering packages and enquiry">
	<div class="dbf-wrap">
		<div class="dbf-catering-app">
			<?php echo doughboss_final_shortcode_or_notice( '[doughboss_catering]', __( 'The catering enquiry form is being prepared. Please email catering@doughboss.com.au.', 'doughboss-final' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<noscript><p class="dbf-system-notice">For catering help without JavaScript, email <a href="<?php echo esc_url( 'mailto:' . $dbf_catering_email ); ?>"><?php echo esc_html( $dbf_catering_email ); ?></a> with your event date and guest count. An enquiry is not a confirmed booking.</p></noscript>
	</div>
</section>
<section id="dbf-catering-repeat" class="dbf-section dbf-section--cream" aria-labelledby="dbf-catering-repeat-title">
	<div class="dbf-wrap dbf-catering-repeat">
		<p class="dbf-eyebrow">Welcome back</p>
		<h2 id="dbf-catering-repeat-title" class="dbf-heading">Another gathering? Let’s plan it together.</h2>
		<p class="dbf-lede">Email the catering team with your previous enquiry reference, new date and updated guest count. We’ll reconfirm the menu, current prices and availability—nothing is reordered or charged automatically.</p>
		<p>Already waiting on an enquiry? Quote the same reference when you contact us rather than submitting it again. Please do not send card details by email.</p>
		<a class="dbf-button" href="<?php echo esc_url( 'mailto:' . $dbf_catering_email ); ?>">Contact the catering team</a>
	</div>
</section>
<?php get_footer(); ?>
