<?php
/**
 * Run with: wp eval-file tests/wp-smoke.php --allow-root
 * Requires Infinity Deploy Bridge to be active in an isolated test installation.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function infinity_smoke_assert( $valid, $message ) {
    if ( ! $valid ) { throw new RuntimeException( $message ); }
}
wp_set_current_user( 1 );
infinity_smoke_assert( current_user_can( 'manage_options' ), 'Admin capabilities missing' );

$routes = rest_get_server()->get_routes();
foreach ( array( '/status', '/transfer/start', '/transfer/status', '/transfer/chunk', '/transfer/complete', '/transfer/cancel' ) as $route ) {
    infinity_smoke_assert( isset( $routes['/infinity-deploy/v1' . $route] ), 'Missing REST route: ' . $route );
}

$status = rest_do_request( new WP_REST_Request( 'GET', '/infinity-deploy/v1/status' ) );
infinity_smoke_assert( 200 === $status->get_status(), 'Status endpoint failed' );
infinity_smoke_assert( '1.0.4' === $status->get_data()['bridge_version'], 'Bridge version mismatch' );

$start = new WP_REST_Request( 'POST', '/infinity-deploy/v1/transfer/start' );
$start->set_body_params( array( 'filename' => 'smoke.zip', 'size' => 4096, 'sha256' => hash( 'sha256', 'smoke' ) ) );
$response = rest_do_request( $start );
infinity_smoke_assert( 200 === $response->get_status(), 'Transfer start failed: ' . wp_json_encode( $response->get_data() ) );
$id = $response->get_data()['id'];
infinity_smoke_assert( preg_match( '/^[a-f0-9]{32}$/', $id ), 'Invalid transfer ID' );

$query = new WP_REST_Request( 'GET', '/infinity-deploy/v1/transfer/status' );
$query->set_param( 'id', $id );
$response = rest_do_request( $query );
infinity_smoke_assert( 200 === $response->get_status() && 0 === $response->get_data()['offset'], 'Transfer status failed' );

$cancel = new WP_REST_Request( 'POST', '/infinity-deploy/v1/transfer/cancel' );
$cancel->set_param( 'id', $id );
$response = rest_do_request( $cancel );
infinity_smoke_assert( 200 === $response->get_status() && true === $response->get_data()['ok'], 'Transfer cancellation failed' );

wp_set_current_user( 0 );
$guest = rest_do_request( new WP_REST_Request( 'GET', '/infinity-deploy/v1/status' ) );
infinity_smoke_assert( 401 === $guest->get_status() || 403 === $guest->get_status(), 'Guest accessed private status' );
echo "WordPress bridge smoke test passed.\n";
