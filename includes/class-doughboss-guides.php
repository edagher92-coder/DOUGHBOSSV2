<?php
/**
 * Interactive staff and management operating guides.
 *
 * These guides are deliberately rendered by the DoughBoss plugin rather than
 * the public theme. That keeps the operational instructions available on a
 * kitchen tablet or phone even when the marketing site changes.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Guides {

	/**
	 * Render a focused operating guide.
	 *
	 * @param string $audience staff|management.
	 * @return void
	 */
	public static function render( $audience ) {
		$is_management = 'management' === $audience;
		$workspace_url = $is_management ? home_url( '/management/' ) : home_url( '/staff-clock/' );
		$workspace_label = $is_management ? __( 'Open management', 'doughboss' ) : __( 'Open staff clock', 'doughboss' );
		$signin_url = $is_management ? wp_login_url( home_url( '/management-guide/' ) ) : home_url( '/staff-clock/' );
		$sections = $is_management ? self::management_sections() : self::staff_sections();
		?>
		<a class="db-guide-skip" href="#db-guide-main"><?php esc_html_e( 'Skip to guide', 'doughboss' ); ?></a>
		<header class="db-guide-header">
			<a class="db-guide-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Dough Boss home', 'doughboss' ); ?>">DOUGH BOSS<span>.</span></a>
			<div class="db-guide-header-actions">
				<span class="db-guide-audience"><?php echo esc_html( $is_management ? __( 'Management playbook', 'doughboss' ) : __( 'Staff playbook', 'doughboss' ) ); ?></span>
				<a class="db-guide-quiet-link" href="<?php echo esc_url( $signin_url ); ?>"><?php echo esc_html( $is_management ? __( 'Manager sign in', 'doughboss' ) : __( 'Staff sign in', 'doughboss' ) ); ?></a>
				<a class="db-guide-header-cta" href="<?php echo esc_url( $workspace_url ); ?>"><?php echo esc_html( $workspace_label ); ?></a>
			</div>
		</header>

		<div class="db-guide-layout">
			<aside class="db-guide-sidebar" aria-label="<?php esc_attr_e( 'Guide sections', 'doughboss' ); ?>">
				<p class="db-guide-sidebar-kicker"><?php esc_html_e( 'Today’s guide', 'doughboss' ); ?></p>
				<nav class="db-guide-nav">
					<a class="is-active" href="#welcome"><?php esc_html_e( 'Start here', 'doughboss' ); ?></a>
					<?php foreach ( $sections as $index => $section ) : ?>
						<a href="#<?php echo esc_attr( $section['id'] ); ?>"><span><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span><?php echo esc_html( $section['title'] ); ?></a>
					<?php endforeach; ?>
					<a href="#quick-links"><?php esc_html_e( 'Quick links', 'doughboss' ); ?></a>
				</nav>
				<div class="db-guide-progress-card">
					<span><?php esc_html_e( 'Your guide progress', 'doughboss' ); ?></span>
					<strong data-guide-progress-label>0%</strong>
					<div aria-hidden="true"><i data-guide-progress-bar></i></div>
					<button type="button" data-guide-reset><?php esc_html_e( 'Reset this device', 'doughboss' ); ?></button>
				</div>
			</aside>

			<main class="db-guide-main" id="db-guide-main">
				<section class="db-guide-hero" id="welcome">
					<div>
						<p class="db-guide-eyebrow"><?php echo esc_html( $is_management ? __( 'OPERATIONS, CLEARLY EXPLAINED', 'doughboss' ) : __( 'YOUR SHIFT, STEP BY STEP', 'doughboss' ) ); ?></p>
						<h1><?php echo esc_html( $is_management ? __( 'Run the day with confidence.', 'doughboss' ) : __( 'Clock in. Make great food. Clock out.', 'doughboss' ) ); ?></h1>
						<p><?php echo esc_html( $is_management ? __( 'A practical control room for orders, staff, catering and the hand-over between every screen.', 'doughboss' ) : __( 'A simple guide for your shared kitchen screen. Your QR badge and PIN record only your own shift.', 'doughboss' ) ); ?></p>
						<div class="db-guide-hero-actions">
							<a class="db-guide-primary" href="<?php echo esc_url( $workspace_url ); ?>"><?php echo esc_html( $workspace_label ); ?></a>
							<button class="db-guide-secondary" type="button" data-guide-copy-url><?php esc_html_e( 'Copy this guide link', 'doughboss' ); ?></button>
						</div>
					</div>
					<div class="db-guide-visual" aria-label="<?php esc_attr_e( 'Dough Boss operating flow', 'doughboss' ); ?>">
						<div class="db-guide-visual-orbit"></div>
						<div class="db-guide-visual-screen">
							<div class="db-guide-screen-top"><b>DOUGH BOSS.</b><span></span><span></span></div>
							<div class="db-guide-screen-card is-hot"><i></i><strong><?php echo esc_html( $is_management ? __( 'Live operations', 'doughboss' ) : __( 'Scan badge', 'doughboss' ) ); ?></strong><small><?php echo esc_html( $is_management ? __( 'Orders & staff', 'doughboss' ) : __( 'Then enter PIN', 'doughboss' ) ); ?></small></div>
							<div class="db-guide-screen-card"><i></i><strong><?php echo esc_html( $is_management ? __( 'Make → Pass', 'doughboss' ) : __( 'Start shift', 'doughboss' ) ); ?></strong><small><?php echo esc_html( $is_management ? __( 'One live flow', 'doughboss' ) : __( 'One clear action', 'doughboss' ) ); ?></small></div>
						</div>
						<p><?php esc_html_e( 'Designed for touch screens, phones and desktop.', 'doughboss' ); ?></p>
					</div>
				</section>

				<section class="db-guide-flow" aria-labelledby="db-guide-flow-title">
					<div class="db-guide-section-heading"><p><?php esc_html_e( 'THE LOOP', 'doughboss' ); ?></p><h2 id="db-guide-flow-title"><?php echo esc_html( $is_management ? __( 'See it. Decide it. Hand it over.', 'doughboss' ) : __( 'One person. One badge. One clear record.', 'doughboss' ) ); ?></h2></div>
					<div class="db-guide-flow-track">
						<?php foreach ( self::flow_items( $is_management ) as $index => $flow ) : ?>
							<article><span><?php echo esc_html( sprintf( '0%d', $index + 1 ) ); ?></span><i aria-hidden="true"><?php echo esc_html( $flow['icon'] ); ?></i><strong><?php echo esc_html( $flow['title'] ); ?></strong><small><?php echo esc_html( $flow['copy'] ); ?></small></article>
						<?php endforeach; ?>
					</div>
				</section>

				<?php foreach ( $sections as $index => $section ) : ?>
					<section class="db-guide-step" id="<?php echo esc_attr( $section['id'] ); ?>" data-guide-step="<?php echo esc_attr( $section['id'] ); ?>">
						<div class="db-guide-step-index"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></div>
						<div class="db-guide-step-copy">
							<p class="db-guide-eyebrow"><?php echo esc_html( $section['eyebrow'] ); ?></p>
							<h2><?php echo esc_html( $section['title'] ); ?></h2>
							<p><?php echo esc_html( $section['copy'] ); ?></p>
							<ul>
								<?php foreach ( $section['points'] as $point ) : ?><li><?php echo esc_html( $point ); ?></li><?php endforeach; ?>
							</ul>
							<?php if ( ! empty( $section['notice'] ) ) : ?><div class="db-guide-notice"><b><?php esc_html_e( 'Important', 'doughboss' ); ?></b><span><?php echo esc_html( $section['notice'] ); ?></span></div><?php endif; ?>
							<div class="db-guide-step-actions">
								<?php if ( ! empty( $section['url'] ) ) : ?><a class="db-guide-primary" href="<?php echo esc_url( $section['url'] ); ?>"><?php echo esc_html( $section['action'] ); ?></a><?php endif; ?>
								<button type="button" class="db-guide-complete" data-guide-complete="<?php echo esc_attr( $section['id'] ); ?>"><span aria-hidden="true">✓</span><?php esc_html_e( 'Mark understood', 'doughboss' ); ?></button>
							</div>
						</div>
						<div class="db-guide-step-graphic" aria-hidden="true"><span class="db-guide-step-glyph"><?php echo esc_html( $section['icon'] ); ?></span><i></i><b><?php echo esc_html( $section['graphic'] ); ?></b></div>
					</section>
				<?php endforeach; ?>

				<section class="db-guide-links" id="quick-links">
					<div class="db-guide-section-heading"><p><?php esc_html_e( 'OPEN THE RIGHT SCREEN', 'doughboss' ); ?></p><h2><?php esc_html_e( 'Quick links for the job in front of you.', 'doughboss' ); ?></h2></div>
					<div class="db-guide-link-grid">
						<?php foreach ( self::quick_links( $is_management ) as $link ) : ?>
							<a href="<?php echo esc_url( $link['url'] ); ?>"><span><?php echo esc_html( $link['icon'] ); ?></span><strong><?php echo esc_html( $link['title'] ); ?></strong><small><?php echo esc_html( $link['copy'] ); ?></small><b>↗</b></a>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="db-guide-faq" aria-labelledby="db-guide-faq-title">
					<div class="db-guide-section-heading"><p><?php esc_html_e( 'GOOD HABITS', 'doughboss' ); ?></p><h2 id="db-guide-faq-title"><?php esc_html_e( 'Short answers to the things that matter.', 'doughboss' ); ?></h2></div>
					<?php foreach ( self::faq( $is_management ) as $item ) : ?>
						<details><summary><?php echo esc_html( $item['question'] ); ?><span>+</span></summary><p><?php echo esc_html( $item['answer'] ); ?></p></details>
					<?php endforeach; ?>
				</section>
			</main>
		</div>
		<?php
	}

	/** @return array<int,array<string,mixed>> */
	private static function staff_sections() {
		return array(
			array( 'id' => 'get-ready', 'eyebrow' => __( 'BEFORE YOUR FIRST SHIFT', 'doughboss' ), 'title' => __( 'Get your own QR badge and PIN.', 'doughboss' ), 'copy' => __( 'Your manager issues one personal QR badge and a private PIN. Keep both private: they prove that a shift is yours.', 'doughboss' ), 'points' => array( __( 'Ask your manager to assign you to the correct shop.', 'doughboss' ), __( 'Receive the QR badge and PIN separately.', 'doughboss' ), __( 'Do not share, photograph or lend your badge.', 'doughboss' ) ), 'notice' => __( 'A badge is not a shared kitchen login. It only opens the clock screen for two minutes while you enter your PIN.', 'doughboss' ), 'url' => home_url( '/staff-clock/' ), 'action' => __( 'Open staff clock', 'doughboss' ), 'icon' => '▣', 'graphic' => __( 'YOUR BADGE', 'doughboss' ) ),
			array( 'id' => 'start-shift', 'eyebrow' => __( 'WHEN YOU ARRIVE', 'doughboss' ), 'title' => __( 'Scan, enter PIN, then clock in.', 'doughboss' ), 'copy' => __( 'Use the scanner or tap the scan box, scan your QR, enter your PIN on the large keypad, then choose Clock in.', 'doughboss' ), 'points' => array( __( 'Check the shop name before you tap Clock in.', 'doughboss' ), __( 'Only tap once and wait for the confirmation.', 'doughboss' ), __( 'If the screen says a shift is already open, ask a manager before trying again.', 'doughboss' ) ), 'notice' => __( 'Clocking in records the actual time and the assigned shop. It does not collect GPS or browser location.', 'doughboss' ), 'url' => home_url( '/staff-clock/' ), 'action' => __( 'Clock in now', 'doughboss' ), 'icon' => '→', 'graphic' => __( 'START SHIFT', 'doughboss' ) ),
			array( 'id' => 'take-break', 'eyebrow' => __( 'WHEN YOU TAKE A BREAK', 'doughboss' ), 'title' => __( 'Record the break you actually take.', 'doughboss' ), 'copy' => __( 'Scan again, enter your PIN, choose Start break, and repeat when you return with End break.', 'doughboss' ), 'points' => array( __( 'Use Start break when you leave work, not before.', 'doughboss' ), __( 'Use End break as soon as you return.', 'doughboss' ), __( 'Worked time subtracts only breaks that were actually recorded.', 'doughboss' ) ), 'notice' => __( 'The system does not assume a break or make a payroll decision. Ask your manager about the paid-break and overtime policy.', 'doughboss' ), 'url' => home_url( '/staff-clock/' ), 'action' => __( 'Record a break', 'doughboss' ), 'icon' => 'Ⅱ', 'graphic' => __( 'REAL BREAKS', 'doughboss' ) ),
			array( 'id' => 'finish-shift', 'eyebrow' => __( 'WHEN YOU FINISH', 'doughboss' ), 'title' => __( 'Clock out and read the confirmation.', 'doughboss' ), 'copy' => __( 'Scan, enter your PIN and choose Clock out. The shared screen clears your badge session after the action.', 'doughboss' ), 'points' => array( __( 'Clock out only when your work has finished.', 'doughboss' ), __( 'Check the green confirmation before walking away.', 'doughboss' ), __( 'If anything is wrong, tell a manager promptly so it can be corrected with an audit trail.', 'doughboss' ) ), 'notice' => __( 'Never clock in or out for another person.', 'doughboss' ), 'url' => home_url( '/staff-clock/' ), 'action' => __( 'Open clock out', 'doughboss' ), 'icon' => '✓', 'graphic' => __( 'SHIFT COMPLETE', 'doughboss' ) ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function management_sections() {
		return array(
			array( 'id' => 'open-day', 'eyebrow' => __( 'OPEN THE DAY', 'doughboss' ), 'title' => __( 'Check the control centre before service.', 'doughboss' ), 'copy' => __( 'Use the management workspace to confirm the right shop, staff coverage, order state and service settings before the first customer order.', 'doughboss' ), 'points' => array( __( 'Open the management overview and check for operational notices.', 'doughboss' ), __( 'Confirm kitchen and catering screens are signed in to the correct role.', 'doughboss' ), __( 'Keep customer ordering and payment switches under management control.', 'doughboss' ) ), 'notice' => __( 'A screen being reachable is not permission to enable live orders or payments. Follow the approved go-live checklist.', 'doughboss' ), 'url' => home_url( '/management/' ), 'action' => __( 'Open management', 'doughboss' ), 'icon' => '◫', 'graphic' => __( 'CONTROL CENTRE', 'doughboss' ) ),
			array( 'id' => 'staff-access', 'eyebrow' => __( 'SET UP STAFF', 'doughboss' ), 'title' => __( 'Issue badges only after the shop assignment is correct.', 'doughboss' ), 'copy' => __( 'Create individual staff accounts, assign their active shop and roster settings, then issue a QR badge and private PIN.', 'doughboss' ), 'points' => array( __( 'One employee means one account and one active badge.', 'doughboss' ), __( 'Reissue a lost badge instead of reusing somebody else’s.', 'doughboss' ), __( 'Use the timesheet for corrections so the original record stays auditable.', 'doughboss' ) ), 'notice' => __( 'Never use a shared WordPress account or a shared PIN for time records.', 'doughboss' ), 'url' => admin_url( 'admin.php?page=doughboss-staff-badges' ), 'action' => __( 'Manage QR badges', 'doughboss' ), 'icon' => '▣', 'graphic' => __( 'STAFF ACCESS', 'doughboss' ) ),
			array( 'id' => 'run-service', 'eyebrow' => __( 'RUN SERVICE', 'doughboss' ), 'title' => __( 'Keep MAKE, PASS and catering in one flow.', 'doughboss' ), 'copy' => __( 'The kitchen station can switch between Make, Pass and Catering tabs. Use the live counters to see work waiting in each part of the flow.', 'doughboss' ), 'points' => array( __( 'MAKE owns incoming preparation and oven flow.', 'doughboss' ), __( 'PASS owns ready orders, collection and hand-over.', 'doughboss' ), __( 'CATERING uses its separate production view for scheduled work.', 'doughboss' ) ), 'notice' => __( 'Use the 24-inch touch screen at 100% display scaling and test touch, sound and network before a busy service.', 'doughboss' ), 'url' => home_url( '/kitchen/?screen=make' ), 'action' => __( 'Open kitchen MAKE', 'doughboss' ), 'icon' => '↔', 'graphic' => __( 'ONE LIVE FLOW', 'doughboss' ) ),
			array( 'id' => 'close-loop', 'eyebrow' => __( 'CLOSE THE LOOP', 'doughboss' ), 'title' => __( 'Use evidence, not assumptions.', 'doughboss' ), 'copy' => __( 'At hand-over, review the order board, exceptions, staff records and any customer reports. Keep payment and order changes deliberate and reversible where possible.', 'doughboss' ), 'points' => array( __( 'Review pending orders before close.', 'doughboss' ), __( 'Resolve open staff shifts and recorded-break issues with a manager correction.', 'doughboss' ), __( 'Record any outage, voucher or payment exception for the next manager.', 'doughboss' ) ), 'notice' => __( 'Protect customer data and never place payment keys, passwords or staff PINs in a shared note or chat.', 'doughboss' ), 'url' => admin_url( 'admin.php?page=doughboss-timeclock' ), 'action' => __( 'Open staff timesheet', 'doughboss' ), 'icon' => '✓', 'graphic' => __( 'HAND-OVER READY', 'doughboss' ) ),
		);
	}

	/** @return array<int,array<string,string>> */
	private static function flow_items( $is_management ) {
		return $is_management ? array(
			array( 'icon' => '◫', 'title' => __( 'See', 'doughboss' ), 'copy' => __( 'Open the live picture.', 'doughboss' ) ),
			array( 'icon' => '→', 'title' => __( 'Decide', 'doughboss' ), 'copy' => __( 'Use the approved controls.', 'doughboss' ) ),
			array( 'icon' => '↔', 'title' => __( 'Hand over', 'doughboss' ), 'copy' => __( 'Keep every team in sync.', 'doughboss' ) ),
			array( 'icon' => '✓', 'title' => __( 'Record', 'doughboss' ), 'copy' => __( 'Leave a clear trail.', 'doughboss' ) ),
		) : array(
			array( 'icon' => '▣', 'title' => __( 'Scan', 'doughboss' ), 'copy' => __( 'Use your badge.', 'doughboss' ) ),
			array( 'icon' => '•', 'title' => __( 'PIN', 'doughboss' ), 'copy' => __( 'Enter it privately.', 'doughboss' ) ),
			array( 'icon' => '→', 'title' => __( 'Record', 'doughboss' ), 'copy' => __( 'Choose one action.', 'doughboss' ) ),
			array( 'icon' => '✓', 'title' => __( 'Confirm', 'doughboss' ), 'copy' => __( 'Read the result.', 'doughboss' ) ),
		);
	}

	/** @return array<int,array<string,string>> */
	private static function quick_links( $is_management ) {
		$links = array(
			array( 'icon' => '◫', 'title' => __( 'Kitchen MAKE', 'doughboss' ), 'copy' => __( 'Incoming work and prep flow.', 'doughboss' ), 'url' => home_url( '/kitchen/?screen=make' ) ),
			array( 'icon' => '↔', 'title' => __( 'Kitchen PASS', 'doughboss' ), 'copy' => __( 'Ready orders and collection.', 'doughboss' ), 'url' => home_url( '/kitchen/?screen=pass' ) ),
			array( 'icon' => '◌', 'title' => __( 'Catering', 'doughboss' ), 'copy' => __( 'Scheduled catering production.', 'doughboss' ), 'url' => home_url( '/catering-kitchen/' ) ),
			array( 'icon' => '▣', 'title' => __( 'Staff clock', 'doughboss' ), 'copy' => __( 'QR badge and PIN attendance.', 'doughboss' ), 'url' => home_url( '/staff-clock/' ) ),
		);
		if ( $is_management ) {
			array_unshift( $links, array( 'icon' => '◆', 'title' => __( 'Management', 'doughboss' ), 'copy' => __( 'Orders, reports and settings.', 'doughboss' ), 'url' => home_url( '/management/' ) ) );
		}
		return $links;
	}

	/** @return array<int,array<string,string>> */
	private static function faq( $is_management ) {
		return $is_management ? array(
			array( 'question' => __( 'Can everyone use the management link?', 'doughboss' ), 'answer' => __( 'No. The guide and management workspace require a DoughBoss Manager or administrator account. Kitchen and staff roles should use their own screens.', 'doughboss' ) ),
			array( 'question' => __( 'How should a lost QR badge be handled?', 'doughboss' ), 'answer' => __( 'Revoke it and issue a new badge. Do not reuse a badge, PIN or WordPress account belonging to another employee.', 'doughboss' ) ),
			array( 'question' => __( 'Does the clock make payroll decisions?', 'doughboss' ), 'answer' => __( 'No. It records actual clock and break events, roster-lateness snapshots and manager corrections. Your approved payroll policy still determines paid breaks and overtime.', 'doughboss' ) ),
		) : array(
			array( 'question' => __( 'What if my badge does not scan?', 'doughboss' ), 'answer' => __( 'Try the scan box and press Enter. If it still fails, ask a manager to check whether your badge is active. Do not use another person’s badge.', 'doughboss' ) ),
			array( 'question' => __( 'Can I use the kitchen login instead?', 'doughboss' ), 'answer' => __( 'Only use your personal staff account where a manager has instructed you to. The QR clock is designed so the shared screen does not stay logged in as you.', 'doughboss' ) ),
			array( 'question' => __( 'What if I forgot to clock out?', 'doughboss' ), 'answer' => __( 'Tell a manager promptly. They can make an audited correction; do not create a second shift to try to fix it.', 'doughboss' ) ),
		);
	}
}
