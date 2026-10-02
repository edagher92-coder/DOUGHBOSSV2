<?php
/**
 * DoughBoss Growth landing pages: the JSON-LD graph.
 *
 * Builds ONE @graph per companion page from data that has a named source, and refuses to emit a property that
 * has none. The set of types and properties that may appear is the SOURCES table below: each entry says where
 * the value comes from. A property that is not in the table is a violation, and build() returns null (no
 * JSON-LD at all) rather than a partial graph. The forbidden list names the properties that the SEO marketing
 * notes say must stay out until a confirmed claim backs them (geo, sameAs, ratings, price range, menu).
 *
 * The functions here are pure: they take an already resolved context (core location, core packages, ledger
 * claims, page URLs) and return arrays or strings. They read nothing from WordPress and write nothing.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD builder and validator.
 */
final class DoughBoss_Growth_Landing_Schema {

	/**
	 * Currency of catering package prices (core stores AUD, 01 section 3.8).
	 */
	const CURRENCY = 'AUD';

	/**
	 * Region and country printed on a shop address. These two values are constants in core's own SEO class
	 * (class-doughboss-seo.php, schema()) and are not part of a location row, so they are copied from there and
	 * listed in the hand-off as a point for Elie to confirm.
	 */
	const ADDRESS_REGION  = 'NSW';
	const ADDRESS_COUNTRY = 'AU';

	/**
	 * Properties that must never appear until a confirmed claim backs them.
	 */
	const FORBIDDEN = array( 'geo', 'sameAs', 'aggregateRating', 'review', 'reviews', 'rating', 'priceRange', 'hasMenu', 'servesCuisine', 'image', 'logo', 'hasMap', 'currenciesAccepted', 'paymentAccepted', 'slogan', 'description', 'award', 'foundingDate' );

	/**
	 * Every type and property the graph may contain, with where its value comes from. "@id" and "@type" are
	 * structure. Anything not listed is refused by validate().
	 *
	 * @return array Type => property => source text.
	 */
	public static function sources() {
		$core_loc = 'core location row (DoughBoss_Locations), read at render time';
		return array(
			'BreadcrumbList'            => array(
				'@type'           => 'structure',
				'@id'             => 'structure: page permalink + #breadcrumb',
				'itemListElement' => 'WordPress page hierarchy: home URL, the parent page, this page',
			),
			'ListItem'                  => array(
				'@type'    => 'structure',
				'position' => 'structure',
				'name'     => 'parent page title (WordPress) and the page crumb (landing definition, neutral)',
				'item'     => 'permalink (WordPress)',
			),
			'Service'                   => array(
				'@type'      => 'structure',
				'@id'        => 'structure: page permalink + #service',
				'name'       => 'landing definition service_name (neutral, linted)',
				'url'        => 'page permalink (WordPress)',
				'provider'   => 'reference to core organisation id {home}/#organization',
				'areaServed' => 'confirmed ledger claim catering-service-area, its areas list only',
				'offers'     => 'published core catering packages',
			),
			'Place'                     => array(
				'@type' => 'structure',
				'name'  => 'confirmed ledger claim catering-service-area, areas list',
			),
			'Offer'                     => array(
				'@type'         => 'structure',
				'name'          => 'core catering package title',
				'price'         => 'core catering package base price meta, exact',
				'priceCurrency' => 'constant AUD (core stores AUD)',
			),
			'Bakery'                    => array(
				'@type'                     => 'structure',
				'@id'                       => 'core id {home}/#location-{slug} (class-doughboss-seo.php)',
				'name'                      => $core_loc,
				'url'                       => 'page permalink (WordPress)',
				'telephone'                 => $core_loc,
				'address'                   => $core_loc,
				'openingHoursSpecification' => 'DoughBoss_Locations::weekly_hours()',
				'parentOrganization'        => 'reference to core organisation id {home}/#organization',
			),
			'PostalAddress'             => array(
				'@type'           => 'structure',
				'streetAddress'   => $core_loc . ' (first line of the address)',
				'addressLocality' => $core_loc . ' (suburb)',
				'addressRegion'   => 'constant copied from core SEO class (NSW)',
				'addressCountry'  => 'constant copied from core SEO class (AU)',
			),
			'OpeningHoursSpecification' => array(
				'@type'     => 'structure',
				'dayOfWeek' => 'DoughBoss_Locations::weekly_hours()',
				'opens'     => 'DoughBoss_Locations::weekly_hours()',
				'closes'    => 'DoughBoss_Locations::weekly_hours()',
			),
			'FAQPage'                   => array(
				'@type'      => 'structure',
				'@id'        => 'structure: page permalink + #faq',
				'mainEntity' => 'confirmed ledger claims that carry a question',
			),
			'Question'                  => array(
				'@type'          => 'structure',
				'name'           => 'confirmed ledger claim question',
				'acceptedAnswer' => 'confirmed ledger claim text',
			),
			'Answer'                    => array(
				'@type' => 'structure',
				'text'  => 'confirmed ledger claim text',
			),
		);
	}

	/**
	 * The graph for one page, or null when anything in it is not allowed.
	 *
	 * Context keys:
	 *  - home:        string  home URL ("https://example.test/").
	 *  - url:         string  the page permalink.
	 *  - crumbs:      array   list of array( name, url ) from the home page down to this page.
	 *  - kind:        string  "catering" or "location".
	 *  - service:     array|null  array( name ) for a catering page.
	 *  - packages:    array   list of array( name, price (float) ), core packages with a real price.
	 *  - areas:       array   confirmed service-area names (empty when none is confirmed).
	 *  - location:    array|null  array( slug, name, address, suburb, phone, hours ) for a location page; hours is
	 *                 day key (mon..sun) => list of array( opens, closes ) as "HH:MM".
	 *  - faqs:        array   list of array( question, answer ) from confirmed claims.
	 *
	 * @param array $ctx Context.
	 * @return array|null Graph document (with @context) or null.
	 */
	public static function build( array $ctx ) {
		$home = isset( $ctx['home'] ) && is_string( $ctx['home'] ) ? $ctx['home'] : '';
		$url  = isset( $ctx['url'] ) && is_string( $ctx['url'] ) ? $ctx['url'] : '';
		if ( '' === $home || '' === $url || ! self::is_http_url( $url ) || ! self::is_http_url( $home ) ) {
			return null;
		}
		$org   = array( '@id' => self::organization_id( $home ) );
		$graph = array();

		$crumb_list = self::breadcrumbs( $url, isset( $ctx['crumbs'] ) && is_array( $ctx['crumbs'] ) ? $ctx['crumbs'] : array() );
		if ( null === $crumb_list ) {
			return null;
		}
		$graph[] = $crumb_list;

		$kind = isset( $ctx['kind'] ) ? $ctx['kind'] : '';
		if ( 'catering' === $kind ) {
			$service = self::service( $ctx, $url, $org );
			if ( null === $service ) {
				return null;
			}
			$graph[] = $service;
		} elseif ( 'location' === $kind ) {
			$shop = self::location_node( $ctx, $url, $home, $org );
			if ( null === $shop ) {
				return null;
			}
			$graph[] = $shop;
		} else {
			return null;
		}

		$faqs = isset( $ctx['faqs'] ) && is_array( $ctx['faqs'] ) ? $ctx['faqs'] : array();
		if ( array() !== $faqs ) {
			$faq = self::faq_node( $faqs, $url );
			if ( null !== $faq ) {
				$graph[] = $faq;
			}
		}

		$document = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
		if ( array() !== self::validate( $document ) ) {
			return null;
		}
		return $document;
	}

	/**
	 * The core organisation id.
	 *
	 * @param string $home Home URL.
	 * @return string
	 */
	public static function organization_id( $home ) {
		return rtrim( $home, '/' ) . '/#organization';
	}

	/**
	 * The core location id, the same string core's SEO class prints (class-doughboss-seo.php).
	 *
	 * @param string $home Home URL.
	 * @param string $slug Location slug (already sanitised).
	 * @return string
	 */
	public static function location_id( $home, $slug ) {
		return rtrim( $home, '/' ) . '/#location-' . $slug;
	}

	/**
	 * Whether a string is an absolute http(s) URL with a host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_http_url( $url ) {
		$parts = parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * A BreadcrumbList node.
	 *
	 * @param string $url    Page permalink.
	 * @param array  $crumbs List of array( name, url ).
	 * @return array|null
	 */
	private static function breadcrumbs( $url, array $crumbs ) {
		$items    = array();
		$position = 0;
		foreach ( $crumbs as $crumb ) {
			if ( ! is_array( $crumb ) || ! isset( $crumb[0], $crumb[1] ) || ! is_string( $crumb[0] ) || ! is_string( $crumb[1] ) || '' === trim( $crumb[0] ) || ! self::is_http_url( $crumb[1] ) ) {
				return null;
			}
			++$position;
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $crumb[0],
				'item'     => $crumb[1],
			);
		}
		if ( $position < 2 ) {
			return null;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * A Service node with an Offer for each core package that has a real price.
	 *
	 * @param array  $ctx Context.
	 * @param string $url Page permalink.
	 * @param array  $org Organisation reference.
	 * @return array|null
	 */
	private static function service( array $ctx, $url, array $org ) {
		$name = ( isset( $ctx['service'] ) && is_array( $ctx['service'] ) && isset( $ctx['service']['name'] ) && is_string( $ctx['service']['name'] ) ) ? trim( $ctx['service']['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		$node = array(
			'@type'    => 'Service',
			'@id'      => $url . '#service',
			'name'     => $name,
			'url'      => $url,
			'provider' => $org,
		);
		$areas = array();
		if ( isset( $ctx['areas'] ) && is_array( $ctx['areas'] ) ) {
			foreach ( $ctx['areas'] as $area ) {
				if ( is_string( $area ) && '' !== trim( $area ) ) {
					$areas[] = array(
						'@type' => 'Place',
						'name'  => trim( $area ),
					);
				}
			}
		}
		if ( array() !== $areas ) {
			$node['areaServed'] = $areas;
		}
		$offers = array();
		if ( isset( $ctx['packages'] ) && is_array( $ctx['packages'] ) ) {
			foreach ( $ctx['packages'] as $package ) {
				if ( ! is_array( $package ) || ! isset( $package['name'], $package['price'] ) || ! is_string( $package['name'] ) || '' === trim( $package['name'] ) || ! ( is_int( $package['price'] ) || is_float( $package['price'] ) ) || $package['price'] <= 0 ) {
					continue; // No price in core, so no Offer.
				}
				$offers[] = array(
					'@type'         => 'Offer',
					'name'          => $package['name'],
					'price'         => self::price_string( $package['price'] ),
					'priceCurrency' => self::CURRENCY,
				);
			}
		}
		if ( array() !== $offers ) {
			$node['offers'] = $offers;
		}
		return $node;
	}

	/**
	 * A price exactly as core stores it, two decimals, no thousands separator.
	 *
	 * @param float|int $price Price.
	 * @return string
	 */
	public static function price_string( $price ) {
		return number_format( round( (float) $price, 2 ), 2, '.', '' );
	}

	/**
	 * The shop node, reusing core's @id so search engines see one entity.
	 *
	 * @param array  $ctx  Context.
	 * @param string $url  Page permalink.
	 * @param string $home Home URL.
	 * @param array  $org  Organisation reference.
	 * @return array|null
	 */
	private static function location_node( array $ctx, $url, $home, array $org ) {
		$loc = ( isset( $ctx['location'] ) && is_array( $ctx['location'] ) ) ? $ctx['location'] : null;
		if ( null === $loc || ! isset( $loc['slug'], $loc['name'], $loc['address'], $loc['suburb'] ) || ! is_string( $loc['slug'] ) || ! is_string( $loc['name'] ) || '' === trim( $loc['name'] ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $loc['slug'] ) ) {
			return null;
		}
		$street = self::first_line( (string) $loc['address'] );
		if ( '' === $street ) {
			return null;
		}
		$address = array(
			'@type'          => 'PostalAddress',
			'streetAddress'  => $street,
			'addressRegion'  => self::ADDRESS_REGION,
			'addressCountry' => self::ADDRESS_COUNTRY,
		);
		if ( '' !== trim( (string) $loc['suburb'] ) ) {
			$address['addressLocality'] = trim( (string) $loc['suburb'] );
		}
		$node = array(
			'@type'              => array( 'Bakery', 'Restaurant' ),
			'@id'                => self::location_id( $home, $loc['slug'] ),
			'name'               => trim( $loc['name'] ),
			'url'                => $url,
			'address'            => $address,
			'parentOrganization' => $org,
		);
		$phone = isset( $loc['phone'] ) && is_string( $loc['phone'] ) ? trim( $loc['phone'] ) : '';
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}
		$hours = self::hours_specification( isset( $loc['hours'] ) && is_array( $loc['hours'] ) ? $loc['hours'] : array() );
		if ( array() !== $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}
		return $node;
	}

	/**
	 * OpeningHoursSpecification list, one entry per range, in Monday to Sunday order (as core prints it).
	 *
	 * @param array $hours Day key => list of array( opens, closes ).
	 * @return array
	 */
	private static function hours_specification( array $hours ) {
		$days = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		$out  = array();
		foreach ( $days as $key => $label ) {
			if ( empty( $hours[ $key ] ) || ! is_array( $hours[ $key ] ) ) {
				continue;
			}
			foreach ( $hours[ $key ] as $range ) {
				if ( ! is_array( $range ) || ! isset( $range[0], $range[1] ) || 1 !== preg_match( '/^[0-2][0-9]:[0-5][0-9]$/D', (string) $range[0] ) || 1 !== preg_match( '/^[0-2][0-9]:[0-5][0-9]$/D', (string) $range[1] ) ) {
					continue;
				}
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $label,
					'opens'     => (string) $range[0],
					'closes'    => (string) $range[1],
				);
			}
		}
		return $out;
	}

	/**
	 * The first non-empty line of an address, whitespace collapsed.
	 *
	 * @param string $address Address text.
	 * @return string
	 */
	public static function first_line( $address ) {
		foreach ( preg_split( '/\r\n|\r|\n/', $address ) as $line ) {
			$line = preg_replace( '/\s+/', ' ', trim( $line ) );
			if ( is_string( $line ) && '' !== $line ) {
				return $line;
			}
		}
		return '';
	}

	/**
	 * A FAQPage node from confirmed claims.
	 *
	 * @param array  $faqs List of array( question, answer ).
	 * @param string $url  Page permalink.
	 * @return array|null
	 */
	private static function faq_node( array $faqs, $url ) {
		$entities = array();
		foreach ( $faqs as $faq ) {
			if ( ! is_array( $faq ) || ! isset( $faq[0], $faq[1] ) || ! is_string( $faq[0] ) || ! is_string( $faq[1] ) || '' === trim( $faq[0] ) || '' === trim( $faq[1] ) ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => trim( $faq[0] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => trim( $faq[1] ),
				),
			);
		}
		if ( array() === $entities ) {
			return null;
		}
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Every violation in a document: a type or property with no entry in sources(), or a forbidden property.
	 * An empty array means every property has a source.
	 *
	 * @param mixed $document A decoded JSON-LD document (array).
	 * @return string[]
	 */
	public static function validate( $document ) {
		if ( ! is_array( $document ) || ! isset( $document['@context'] ) || 'https://schema.org' !== $document['@context'] || ! isset( $document['@graph'] ) || ! is_array( $document['@graph'] ) ) {
			return array( 'document: not a schema.org @graph' );
		}
		$problems = array();
		foreach ( $document['@graph'] as $index => $node ) {
			self::validate_node( $node, '@graph[' . $index . ']', $problems );
		}
		return $problems;
	}

	/**
	 * Check one node (and its children) against sources().
	 *
	 * @param mixed  $node     Node.
	 * @param string $path     Location text for messages.
	 * @param array  $problems Collected problems (by reference).
	 * @return void
	 */
	private static function validate_node( $node, $path, array &$problems ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( array() !== $node && array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
			foreach ( $node as $i => $child ) {
				self::validate_node( $child, $path . '[' . $i . ']', $problems );
			}
			return;
		}
		if ( array( '@id' ) === array_keys( $node ) ) {
			return; // A reference to a node defined elsewhere (core's organisation).
		}
		if ( ! isset( $node['@type'] ) ) {
			$problems[] = $path . ': node has no @type';
			return;
		}
		$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				$problems[] = $path . ': @type must be a string';
				return;
			}
		}
		$allowed = self::allowed_properties( $types );
		if ( null === $allowed ) {
			$problems[] = $path . ': type "' . implode( ',', $types ) . '" has no source entry';
			return;
		}
		foreach ( $node as $property => $value ) {
			if ( in_array( $property, self::FORBIDDEN, true ) ) {
				$problems[] = $path . ': property "' . $property . '" is forbidden without a confirmed claim';
				continue;
			}
			if ( ! isset( $allowed[ $property ] ) ) {
				$problems[] = $path . ': property "' . $property . '" has no source';
				continue;
			}
			if ( is_array( $value ) ) {
				self::validate_node( $value, $path . '.' . $property, $problems );
			}
		}
	}

	/**
	 * The allowed properties of a node whose @type is one string or the Bakery plus Restaurant pair.
	 *
	 * @param array $types @type values.
	 * @return array|null Property => source, or null when the type is not allowed.
	 */
	private static function allowed_properties( array $types ) {
		$sources = self::sources();
		sort( $types );
		if ( array( 'Bakery', 'Restaurant' ) === $types ) {
			return $sources['Bakery'];
		}
		if ( 1 === count( $types ) && isset( $sources[ $types[0] ] ) ) {
			return $sources[ $types[0] ];
		}
		return null;
	}

	/**
	 * Encode a document for a script tag. Tag, ampersand and quote characters are escaped so a value can never
	 * close the script element or break out of it.
	 *
	 * @param array $document Graph document.
	 * @return string Empty string when encoding fails.
	 */
	public static function encode( array $document ) {
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return is_string( $json ) ? $json : '';
	}
}
