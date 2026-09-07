// HOTFIX (2026-09-07): DoughBoss 2.41.0 > Vouchers admin screen fatals because
// admin/class-doughboss-admin.php:2344 calls get_users() with an array `fields`
// (raw stdClass rows) and then user_can() on each row -> "Call to undefined
// method stdClass::has_cap()". Ask WP_User_Query for full WP_User objects on
// that screen only; the template reads ->ID/->display_name/->user_login, which
// WP_User exposes. Remove this snippet once DoughBoss 2.41.1 (repo fix) is deployed.
add_action( 'pre_get_users', function ( $query ) {
    if ( ! is_admin() || ! isset( $_GET['page'] ) || 'doughboss-vouchers' !== $_GET['page'] ) {
        return;
    }
    if ( isset( $query->query_vars['fields'] ) && is_array( $query->query_vars['fields'] ) ) {
        $query->query_vars['fields'] = 'all';
    }
} );
