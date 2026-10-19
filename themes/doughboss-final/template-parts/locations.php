<?php
/**
 * Store cards: address, hours, phone and a live Open now / Closed badge
 * (Sydney time). The badge is rendered server-side and refreshed every
 * minute by theme.js from the same weekly hours, so cached pages stay right.
 *
 * @package DoughBossFinal
 */

$dbf_stores = doughboss_final_store_cards();
?>
<div class="dbf-locations-grid">
	<?php foreach ( $dbf_stores as $dbf_index => $dbf_store ) : ?>
		<article class="dbf-location-card<?php echo $dbf_store['featured'] ? ' is-orderable' : ''; ?>" data-dbf-reveal<?php echo $dbf_index ? ' data-dbf-reveal-delay="' . (int) $dbf_index . '"' : ''; ?> data-dbf-store="<?php echo esc_attr( $dbf_store['slug'] ); ?>" data-dbf-hours="<?php echo esc_attr( $dbf_store['hours_json'] ); ?>">
			<div class="dbf-location-head">
				<div>
					<span class="dbf-location-status"><?php echo esc_html( $dbf_store['eyebrow'] ); ?></span>
					<h3><?php echo esc_html( $dbf_store['name'] ); ?></h3>
				</div>
				<span class="dbf-open-badge<?php echo $dbf_store['badge_state'] ? ' is-' . esc_attr( $dbf_store['badge_state'] ) : ''; ?>" data-dbf-open-badge role="status"<?php echo '' === $dbf_store['badge'] ? ' hidden' : ''; ?>><?php echo esc_html( $dbf_store['badge'] ); ?></span>
			</div>
			<address><?php echo esc_html( $dbf_store['address'][0] ); ?><br><?php echo esc_html( $dbf_store['address'][1] ); ?></address>
			<dl class="dbf-location-rows">
				<div class="dbf-location-row">
					<dt><?php esc_html_e( 'Hours', 'doughboss-final' ); ?></dt>
					<dd><?php echo implode( '<br>', array_map( 'esc_html', (array) $dbf_store['hours_label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line escaped. ?></dd>
				</div>
				<div class="dbf-location-row">
					<dt><?php esc_html_e( 'Phone', 'doughboss-final' ); ?></dt>
					<dd><a href="<?php echo esc_attr( 'tel:' . $dbf_store['tel'] ); ?>"><?php echo esc_html( $dbf_store['phone'] ); ?></a></dd>
				</div>
				<div class="dbf-location-row">
					<dt><?php esc_html_e( 'Ordering', 'doughboss-final' ); ?></dt>
					<dd><?php echo esc_html( $dbf_store['note'] ); ?></dd>
				</div>
			</dl>
			<div class="dbf-location-actions">
				<a class="dbf-location-action dbf-location-action--call" href="<?php echo esc_attr( 'tel:' . $dbf_store['tel'] ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
					<span><?php echo esc_html( sprintf( /* translators: %s: shop name. */ __( 'Call %s', 'doughboss-final' ), $dbf_store['name'] ) ); ?></span>
				</a>
				<a class="dbf-location-action dbf-location-action--maps" href="<?php echo esc_url( $dbf_store['maps'] ); ?>" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="9.5" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/></svg>
					<span><?php esc_html_e( 'Google Maps', 'doughboss-final' ); ?></span>
					<span class="dbf-sr-only"><?php esc_html_e( '(opens in a new tab)', 'doughboss-final' ); ?></span>
				</a>
			</div>
		</article>
	<?php endforeach; ?>
</div>
