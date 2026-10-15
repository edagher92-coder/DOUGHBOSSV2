<?php
/** Synthetic hook smoke test; no WordPress, network, mail or database. */
define( 'ABSPATH', __DIR__ );
$hooks = array();
$admin = false;
$checks = 0;
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) { global $hooks; $hooks[ $name ] = $callback; }
function add_action( $name, $callback ) { add_filter( $name, $callback ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function is_admin() { global $admin; return $admin; }
function get_post_field( $field, $id ) { return 137 === $id ? 'franchising' : 'wholesale'; }
class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $message, $data ) { $this->code = $code; $this->data = $data; }
}
class Presentation_Request {
	private $route;
	private $method;
	private $type;
	public function __construct( $route, $method, $type ) { $this->route = $route; $this->method = $method; $this->type = $type; }
	public function get_route() { return $this->route; }
	public function get_method() { return $this->method; }
	public function get_param( $name ) { return $this->type; }
}
function check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
require __DIR__ . '/franchising-paused-snippet.php';
$items = array_map( static function ( $slug ) { return (object) array( 'url' => 'https://doughboss.com.au/' . $slug . '/' ); }, array( 'menu', 'franchising', 'wholesale' ) );
$filtered = $hooks['wp_nav_menu_objects']( $items );
check( 2 === count( $filtered ), 'Only the franchising navigation entry is removed.' );
check( '/wholesale/' === parse_url( $filtered[1]->url, PHP_URL_PATH ), 'Wholesale stays available.' );
$draft_items = array(
	(object) array( 'object' => 'page', 'object_id' => 137, 'url' => 'https://doughboss.com.au/?page_id=137' ),
	(object) array( 'object' => 'page', 'object_id' => 137, 'url' => '' ),
	(object) array( 'object' => 'page', 'object_id' => 131, 'url' => 'https://doughboss.com.au/?page_id=131' ),
);
$draft_filtered = $hooks['wp_nav_menu_objects']( $draft_items );
check( 1 === count( $draft_filtered ), 'Draft permalink fallback and empty URLs cannot expose franchising navigation.' );
check( 131 === $draft_filtered[0]->object_id, 'Unrelated draft/page entries are not removed.' );
foreach ( array( 'franchise', 'franchising', 'partner', 'partners', 'partnership', 'partnerships', ' FRANCHISE ' ) as $type ) {
	check( '' === $hooks['pre_do_shortcode_tag']( false, 'doughboss_b2b_form', array( 'type' => $type ) ), 'Franchise form stays suppressed.' );
	foreach ( array( '/doughboss/v1/b2b/enquiry', '/DOUGHBOSS/v1/b2b/enquiry', '/doughboss/v1/B2B/enquiry', '/doughboss/v1/b2b/enquiry/' ) as $route ) {
		$request = new Presentation_Request( $route, 'POST', $type );
		$result = $hooks['rest_pre_dispatch']( null, null, $request );
		check( $result instanceof WP_Error && 'doughboss_franchising_paused' === $result->code && 503 === $result->data['status'], 'Every equivalent franchise route stays paused.' );
	}
}
check( false === $hooks['pre_do_shortcode_tag']( false, 'doughboss_b2b_form', array( 'type' => 'wholesale' ) ), 'Wholesale shortcode unchanged.' );
check( 'upstream' === $hooks['pre_do_shortcode_tag']( 'upstream', 'doughboss_b2b_form', array( 'type' => 'franchise' ) ), 'Keep upstream shortcode decisions.' );
foreach ( array(
	new Presentation_Request( '/doughboss/v1/b2b/enquiry', 'POST', 'wholesale' ),
	new Presentation_Request( '/DOUGHBOSS/v1/B2B/enquiry', 'POST', 'wholesale' ),
	new Presentation_Request( '/another/route', 'POST', 'franchise' ),
	new Presentation_Request( '/doughboss/v1/b2b/enquiry', 'GET', 'franchise' ),
) as $request ) { check( null === $hooks['rest_pre_dispatch']( null, null, $request ), 'Unrelated routes/actions unchanged.' ); }
check( 'upstream' === $hooks['rest_pre_dispatch']( 'upstream', null, new Presentation_Request( '/doughboss/v1/b2b/enquiry', 'POST', 'franchise' ) ), 'Keep upstream REST denial.' );
ob_start(); $hooks['wp_head'](); $css = ob_get_clean();
check( false !== strpos( $css, 'doughboss-franchising-paused' ), 'Existing footer entry hidden.' );
$admin = true; ob_start(); $hooks['wp_head'](); $css = ob_get_clean();
check( '' === $css, 'Editor remains untouched.' );
echo 'PASS ' . $checks . " synthetic franchising pause checks\n";
