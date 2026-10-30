<?php
/**
 * Manager-facing, read-only view of canonical catering enquiries.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_CRM {
	const PER_PAGE = 20;
	const MAX_PAGE = 10000;
	const MAX_SEARCH_BYTES = 200;

	/** Existing managers intentionally have the existing all-store authority. */
	public static function can_access() {
		return current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Validate GET filters before querying the existing enquiry model.
	 *
	 * @param array $request Request values; only the three CRM keys are used.
	 * @return array|WP_Error
	 */
	public static function inbox( array $request = array() ) {
		if ( ! self::can_access() ) {
			return new WP_Error( 'doughboss_crm_forbidden', __( 'Management access is required.', 'doughboss' ), array( 'status' => 403 ) );
		}
		$status = array_key_exists( 'crm_status', $request ) ? $request['crm_status'] : '';
		$search = array_key_exists( 'crm_search', $request ) ? $request['crm_search'] : '';
		$page   = array_key_exists( 'crm_page', $request ) ? $request['crm_page'] : 1;
		if ( ! is_string( $status ) || ( '' !== $status && ! DoughBoss_Catering::is_valid_status( $status ) ) || ! is_string( $search ) || strlen( $search ) > self::MAX_SEARCH_BYTES ) {
			return self::filter_error();
		}
		if ( ! is_int( $page ) && ( ! is_string( $page ) || ! preg_match( '/^[1-9][0-9]{0,4}$/D', $page ) ) ) {
			return self::filter_error();
		}
		$page = (int) $page;
		if ( $page < 1 || $page > self::MAX_PAGE ) {
			return self::filter_error();
		}
		$filters = array( 'status' => $status, 'search' => sanitize_text_field( wp_unslash( $search ) ), 'page' => $page );
		$result  = DoughBoss_Catering::query( array_merge( $filters, array( 'per_page' => self::PER_PAGE ) ) );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) || ! isset( $result['items'], $result['total'] ) || ! is_array( $result['items'] ) ) {
			return new WP_Error( 'doughboss_crm_unavailable', __( 'Enquiries could not be loaded. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}
		$total = max( 0, (int) $result['total'] );
		return array( 'filters' => $filters, 'items' => $result['items'], 'total' => $total, 'total_pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
	}

	/** @return WP_Error */
	private static function filter_error() {
		return new WP_Error( 'doughboss_crm_filter', __( 'Choose a valid status, search of up to 200 bytes and page from 1 to 10000.', 'doughboss' ), array( 'status' => 400 ) );
	}

	/** @return string */
	private static function page_url( array $filters, $page ) {
		return home_url( '/management/' ) . '?' . http_build_query( array( 'section' => 'crm', 'crm_status' => $filters['status'], 'crm_search' => $filters['search'], 'crm_page' => (int) $page ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Render without introducing another public API or financial action path.
	 *
	 * @param array $request Request filters.
	 * @return void
	 */
	public static function render( array $request = array() ) {
		$view = self::inbox( $request );
		?>
		<section class="db-crm" aria-labelledby="db-crm-heading">
			<h1 id="db-crm-heading"><?php esc_html_e( 'Catering CRM', 'doughboss' ); ?></h1>
			<p class="db-crm-intro"><?php esc_html_e( 'Find catering enquiries and continue their existing quote workflow.', 'doughboss' ); ?></p>
			<?php if ( is_wp_error( $view ) ) : ?>
				<p class="db-crm-error" role="alert"><?php echo esc_html( $view->get_error_message() ); ?></p>
				<a class="button" href="<?php echo esc_url( home_url( '/management/?section=crm' ) ); ?>"><?php esc_html_e( 'Reset filters', 'doughboss' ); ?></a>
			<?php else : ?>
				<form class="db-crm-filters" method="get" action="<?php echo esc_url( home_url( '/management/' ) ); ?>">
					<input type="hidden" name="section" value="crm">
					<label><?php esc_html_e( 'Enquiry status', 'doughboss' ); ?>
						<select name="crm_status"><option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
							<?php foreach ( DoughBoss_Catering::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $view['filters']['status'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Name, email or enquiry number', 'doughboss' ); ?>
						<input type="search" name="crm_search" maxlength="200" value="<?php echo esc_attr( $view['filters']['search'] ); ?>">
					</label>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Filter enquiries', 'doughboss' ); ?></button>
					<a class="button" href="<?php echo esc_url( home_url( '/management/?section=crm' ) ); ?>"><?php esc_html_e( 'Clear', 'doughboss' ); ?></a>
				</form>
				<p class="db-crm-summary" role="status"><?php echo esc_html( sprintf( __( '%1$d enquiries. Page %2$d of %3$d.', 'doughboss' ), $view['total'], $view['filters']['page'], $view['total_pages'] ) ); ?></p>
				<p class="db-crm-notice"><?php esc_html_e( 'Amounts below are enquiry quotes. Review invoices and settled payments in the existing catering workflow.', 'doughboss' ); ?></p>
				<?php if ( ! $view['items'] ) : ?>
					<p class="db-crm-notice"><?php esc_html_e( 'No enquiries on this page. Adjust the filters or return to the first page.', 'doughboss' ); ?></p>
					<a class="button" href="<?php echo esc_url( self::page_url( $view['filters'], 1 ) ); ?>"><?php esc_html_e( 'First page', 'doughboss' ); ?></a>
				<?php else : ?>
					<ul class="db-crm-list">
						<?php foreach ( $view['items'] as $enquiry ) :
							$number   = (string) $enquiry['enquiry_number'];
							$statuses = DoughBoss_Catering::statuses();
							$status   = isset( $statuses[ $enquiry['status'] ] ) ? $statuses[ $enquiry['status'] ] : __( 'Unknown status', 'doughboss' );
							$currency = isset( $enquiry['currency'] ) && preg_match( '/^[A-Z]{3}$/D', (string) $enquiry['currency'] ) ? (string) $enquiry['currency'] : '';
							$amount   = isset( $enquiry['quote_total'] ) && is_numeric( $enquiry['quote_total'] ) && (float) $enquiry['quote_total'] > 0 && '' !== $currency ? number_format( (float) $enquiry['quote_total'], 2, '.', ',' ) . ' ' . $currency : __( 'Awaiting quote', 'doughboss' );
							$open_url = admin_url( 'admin.php' ) . '?' . http_build_query( array( 'page' => 'doughboss-catering', 's' => $number ), '', '&', PHP_QUERY_RFC3986 );
							?>
							<li class="db-crm-card">
								<h2><?php echo esc_html( $number ); ?></h2>
								<span class="db-crm-status"><?php echo esc_html( $status ); ?></span>
								<div class="db-crm-contact"><p><?php echo esc_html( $enquiry['customer_name'] ); ?></p><p><?php echo esc_html( $enquiry['customer_email'] ); ?></p></div>
								<dl class="db-crm-details">
									<div><dt><?php esc_html_e( 'Event', 'doughboss' ); ?></dt><dd><?php echo esc_html( trim( $enquiry['event_date'] . ' ' . $enquiry['event_time'] ) ); ?></dd></div>
									<div><dt><?php esc_html_e( 'Guests', 'doughboss' ); ?></dt><dd><?php echo esc_html( (string) max( 0, (int) $enquiry['guest_count'] ) ); ?></dd></div>
									<div><dt><?php esc_html_e( 'Quote value', 'doughboss' ); ?></dt><dd><?php echo esc_html( $amount ); ?></dd></div>
								</dl>
								<div class="db-crm-actions"><a class="button" href="<?php echo esc_url( $open_url ); ?>"><?php echo esc_html( sprintf( __( 'Open enquiry %s', 'doughboss' ), $number ) ); ?></a></div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<nav class="db-crm-pagination" aria-label="<?php esc_attr_e( 'Enquiry pages', 'doughboss' ); ?>">
					<?php if ( $view['filters']['page'] > 1 ) : ?><a class="button" rel="prev" href="<?php echo esc_url( self::page_url( $view['filters'], $view['filters']['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'doughboss' ); ?></a><?php endif; ?>
					<?php if ( $view['filters']['page'] < min( self::MAX_PAGE, $view['total_pages'] ) ) : ?><a class="button" rel="next" href="<?php echo esc_url( self::page_url( $view['filters'], $view['filters']['page'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'doughboss' ); ?></a><?php endif; ?>
				</nav>
			<?php endif; ?>
		</section>
		<?php
	}
}
