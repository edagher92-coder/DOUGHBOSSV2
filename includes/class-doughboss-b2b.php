<?php
/**
 * Wholesale and franchising enquiries: [doughboss_b2b_form] shortcode, server
 * validation and the notification email.
 *
 * The REST route (POST /b2b/enquiry) lives in DoughBoss_REST_Controller so it
 * shares the existing nonce check and rate limiter. Nothing is stored in the
 * database: an enquiry is validated server-side and emailed to the configured
 * B2B inbox with Reply-To set to the enquirer.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wholesale / franchise enquiry form.
 */
class DoughBoss_B2B {

	const TYPE_WHOLESALE = 'wholesale';
	const TYPE_FRANCHISE = 'franchise';

	/** Field length limits (characters). */
	const MAX_NAME     = 100;
	const MAX_BUSINESS = 150;
	const MAX_FOCUS    = 200;
	const MIN_MESSAGE  = 10;
	const MAX_MESSAGE  = 2000;

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'doughboss_b2b_form', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Normalise a form type. "franchising", "franchise" and "partners" all mean
	 * the franchising/partnership stream; anything else is wholesale.
	 *
	 * @param mixed $type Raw type.
	 * @return string
	 */
	public static function normalise_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		return in_array( $type, array( 'franchise', 'franchising', 'partner', 'partners', 'partnership', 'partnerships' ), true )
			? self::TYPE_FRANCHISE
			: self::TYPE_WHOLESALE;
	}

	/**
	 * Single-line text: strip tags and control characters, collapse spaces.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function line( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		return trim( (string) $value );
	}

	/**
	 * Multi-line text: strip tags and control characters except newlines.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function block( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $value );
		$value = preg_replace( "/\n{3,}/", "\n\n", (string) $value );
		return trim( (string) $value );
	}

	/**
	 * Character length that is safe without mbstring.
	 *
	 * @param string $value Text.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Validate and clean a submitted enquiry. Never trusts client validation.
	 *
	 * @param array<string,mixed> $input Raw request fields.
	 * @return array<string,string>|WP_Error Clean fields, or an error whose data
	 *                                       carries status 400 and per-field messages.
	 */
	public static function validate( array $input ) {
		$type     = self::normalise_type( isset( $input['type'] ) ? $input['type'] : '' );
		$name     = self::line( isset( $input['name'] ) ? $input['name'] : '' );
		$business = self::line( isset( $input['business'] ) ? $input['business'] : '' );
		$email    = isset( $input['email'] ) && is_scalar( $input['email'] ) ? trim( (string) $input['email'] ) : '';
		$phone    = self::line( isset( $input['phone'] ) ? $input['phone'] : '' );
		$focus    = self::line( isset( $input['focus'] ) ? $input['focus'] : '' );
		$message  = self::block( isset( $input['message'] ) ? $input['message'] : '' );
		$errors   = array();

		if ( self::length( $name ) < 2 ) {
			$errors['name'] = __( 'Please enter your name.', 'doughboss' );
		} elseif ( self::length( $name ) > self::MAX_NAME ) {
			$errors['name'] = __( 'Please shorten your name.', 'doughboss' );
		}
		if ( self::TYPE_WHOLESALE === $type && self::length( $business ) < 2 ) {
			$errors['business'] = __( 'Please enter your business or venue name.', 'doughboss' );
		} elseif ( self::length( $business ) > self::MAX_BUSINESS ) {
			$errors['business'] = __( 'Please shorten the business name.', 'doughboss' );
		}
		if ( '' === $email || strlen( $email ) > 254 || preg_match( '/[\r\n,;<>"]/', $email ) || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'doughboss' );
		}
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $phone || ! preg_match( '/^\+?[0-9 ()\-]+$/', $phone ) || strlen( (string) $digits ) < 8 || strlen( (string) $digits ) > 15 ) {
			$errors['phone'] = __( 'Please enter a phone number we can call.', 'doughboss' );
		}
		if ( self::length( $focus ) > self::MAX_FOCUS ) {
			$errors['focus'] = __( 'Please shorten this answer.', 'doughboss' );
		}
		if ( self::length( $message ) < self::MIN_MESSAGE ) {
			$errors['message'] = __( 'Please tell us a little more about what you are exploring.', 'doughboss' );
		} elseif ( self::length( $message ) > self::MAX_MESSAGE ) {
			$errors['message'] = __( 'Please keep your message under 2,000 characters.', 'doughboss' );
		}

		if ( $errors ) {
			return new WP_Error(
				'doughboss_b2b_invalid',
				__( 'Please check the highlighted fields.', 'doughboss' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return array(
			'type'     => $type,
			'name'     => $name,
			'business' => $business,
			'email'    => $email,
			'phone'    => $phone,
			'focus'    => $focus,
			'message'  => $message,
		);
	}

	/**
	 * Destination inbox: the B2B setting, else the orders inbox.
	 *
	 * @return string
	 */
	public static function recipient() {
		return DoughBoss_Settings::b2b_email();
	}

	/**
	 * Display name safe for an email header (no CR/LF, quotes or brackets).
	 *
	 * @param string $name Clean name.
	 * @return string
	 */
	public static function header_name( $name ) {
		$name = preg_replace( '/[\x00-\x1F\x7F"<>,;:\\\\]+/u', ' ', (string) $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) );
		return '' === $name ? 'Website enquiry' : $name;
	}

	/**
	 * Build the notification email from clean fields.
	 *
	 * @param array<string,string> $clean Output of validate().
	 * @return array{to:string,subject:string,body:string,headers:string[]}
	 */
	public static function email_parts( array $clean ) {
		$is_franchise = self::TYPE_FRANCHISE === $clean['type'];
		$stream       = $is_franchise ? 'Franchising & partnerships' : 'Wholesale';
		$subject      = sprintf(
			'%1$s enquiry: %2$s',
			$stream,
			'' !== $clean['business'] ? $clean['business'] : $clean['name']
		);
		$subject = trim( (string) preg_replace( '/[\r\n]+/', ' ', $subject ) );

		$lines   = array(
			'A new ' . strtolower( $stream ) . ' enquiry was sent from the website.',
			'',
			'Name: ' . $clean['name'],
			( $is_franchise ? 'Current business / background: ' : 'Business or venue: ' ) . ( '' !== $clean['business'] ? $clean['business'] : 'Not provided' ),
			'Email: ' . $clean['email'],
			'Phone: ' . $clean['phone'],
			( $is_franchise ? 'Preferred area: ' : 'Products and volumes: ' ) . ( '' !== $clean['focus'] ? $clean['focus'] : 'Not provided' ),
			'',
			'Message:',
			$clean['message'],
			'',
			'Reply to this email to respond directly to the enquirer.',
		);

		return array(
			'to'      => self::recipient(),
			'subject' => $subject,
			'body'    => implode( "\n", $lines ) . "\n",
			'headers' => array( 'Reply-To: ' . self::header_name( $clean['name'] ) . ' <' . $clean['email'] . '>' ),
		);
	}

	/**
	 * Send the notification.
	 *
	 * @param array<string,string> $clean Output of validate().
	 * @return bool
	 */
	public static function send( array $clean ) {
		$mail = self::email_parts( $clean );
		if ( ! is_email( $mail['to'] ) ) {
			return false;
		}
		return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
	}

	/**
	 * [doughboss_b2b_form type="wholesale|franchise"].
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'type' => self::TYPE_WHOLESALE ), is_array( $atts ) ? $atts : array(), 'doughboss_b2b_form' );
		$type = self::normalise_type( $atts['type'] );
		$is_franchise = self::TYPE_FRANCHISE === $type;
		$id   = 'db-b2b-' . $type;

		if ( function_exists( 'wp_enqueue_script' ) && defined( 'DOUGHBOSS_PLUGIN_URL' ) ) {
			wp_enqueue_script( 'doughboss-b2b', DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-b2b.js', array(), defined( 'DOUGHBOSS_VERSION' ) ? DOUGHBOSS_VERSION : false, true );
		}
		$rest_url = function_exists( 'rest_url' ) ? rest_url( DOUGHBOSS_REST_NAMESPACE . '/b2b/enquiry' ) : '';
		$nonce    = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$email    = self::recipient();
		$phone    = DoughBoss_Settings::order_phone();

		$labels = $is_franchise
			? array(
				'business'       => __( 'Current business or background (optional)', 'doughboss' ),
				'business_hint'  => __( 'For example: hospitality operator, investor', 'doughboss' ),
				'focus'          => __( 'Preferred Sydney area (optional)', 'doughboss' ),
				'focus_hint'     => __( 'Suburb or region you are considering', 'doughboss' ),
				'message'        => __( 'What would you like to explore?', 'doughboss' ),
				'submit'         => __( 'Send partnership enquiry', 'doughboss' ),
			)
			: array(
				'business'       => __( 'Business or venue name', 'doughboss' ),
				'business_hint'  => __( 'Cafe, grocer, venue or caterer', 'doughboss' ),
				'focus'          => __( 'Products and weekly volumes (optional)', 'doughboss' ),
				'focus_hint'     => __( 'What you would like to stock and roughly how much', 'doughboss' ),
				'message'        => __( 'How can we work together?', 'doughboss' ),
				'submit'         => __( 'Send wholesale enquiry', 'doughboss' ),
			);

		ob_start();
		?>
		<form class="db-b2b-form" id="<?php echo esc_attr( $id ); ?>" data-doughboss-b2b novalidate
			data-endpoint="<?php echo esc_url( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
			<div class="db-b2b-hp" aria-hidden="true">
				<label for="<?php echo esc_attr( $id ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'doughboss' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id ); ?>-website" name="website" tabindex="-1" autocomplete="off">
			</div>
			<p class="db-b2b-required-note"><?php esc_html_e( 'Fields marked * are required.', 'doughboss' ); ?></p>
			<div class="db-b2b-grid">
				<p class="db-b2b-field">
					<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Your name', 'doughboss' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-name" name="name" required maxlength="<?php echo (int) self::MAX_NAME; ?>" autocomplete="name" aria-describedby="<?php echo esc_attr( $id ); ?>-name-error">
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-name-error" data-error-for="name"></span>
				</p>
				<p class="db-b2b-field">
					<label for="<?php echo esc_attr( $id ); ?>-business"><?php echo esc_html( $labels['business'] ); ?><?php if ( ! $is_franchise ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-business" name="business"<?php echo $is_franchise ? '' : ' required'; ?> maxlength="<?php echo (int) self::MAX_BUSINESS; ?>" autocomplete="organization" placeholder="<?php echo esc_attr( $labels['business_hint'] ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-business-error">
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-business-error" data-error-for="business"></span>
				</p>
				<p class="db-b2b-field">
					<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email', 'doughboss' ); ?> <span aria-hidden="true">*</span></label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="email" required maxlength="254" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>-email-error">
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-email-error" data-error-for="email"></span>
				</p>
				<p class="db-b2b-field">
					<label for="<?php echo esc_attr( $id ); ?>-phone"><?php esc_html_e( 'Phone', 'doughboss' ); ?> <span aria-hidden="true">*</span></label>
					<input type="tel" id="<?php echo esc_attr( $id ); ?>-phone" name="phone" required maxlength="20" autocomplete="tel" inputmode="tel" aria-describedby="<?php echo esc_attr( $id ); ?>-phone-error">
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-phone-error" data-error-for="phone"></span>
				</p>
				<p class="db-b2b-field db-b2b-field--wide">
					<label for="<?php echo esc_attr( $id ); ?>-focus"><?php echo esc_html( $labels['focus'] ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-focus" name="focus" maxlength="<?php echo (int) self::MAX_FOCUS; ?>" placeholder="<?php echo esc_attr( $labels['focus_hint'] ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-focus-error">
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-focus-error" data-error-for="focus"></span>
				</p>
				<p class="db-b2b-field db-b2b-field--wide">
					<label for="<?php echo esc_attr( $id ); ?>-message"><?php echo esc_html( $labels['message'] ); ?> <span aria-hidden="true">*</span></label>
					<textarea id="<?php echo esc_attr( $id ); ?>-message" name="message" rows="5" required minlength="<?php echo (int) self::MIN_MESSAGE; ?>" maxlength="<?php echo (int) self::MAX_MESSAGE; ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-message-error"></textarea>
					<span class="db-b2b-error" id="<?php echo esc_attr( $id ); ?>-message-error" data-error-for="message"></span>
				</p>
			</div>
			<div class="db-b2b-status" role="status" aria-live="polite" tabindex="-1" data-b2b-status></div>
			<div class="db-b2b-actions">
				<button type="submit" class="db-b2b-submit"><?php echo esc_html( $labels['submit'] ); ?></button>
				<p class="db-b2b-direct">
					<?php esc_html_e( 'Prefer to talk?', 'doughboss' ); ?>
					<a href="<?php echo esc_attr( 'tel:' . DoughBoss_Settings::phone_tel( $phone ) ); ?>"><?php echo esc_html( DoughBoss_Settings::phone_display( $phone ) ); ?></a>
					<?php esc_html_e( 'or', 'doughboss' ); ?>
					<a href="<?php echo esc_attr( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
				</p>
			</div>
			<noscript><p class="db-b2b-noscript"><?php esc_html_e( 'This form needs JavaScript. Please call or email us instead.', 'doughboss' ); ?></p></noscript>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
