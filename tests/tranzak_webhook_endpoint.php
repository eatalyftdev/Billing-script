<?php

/**
 * Front controller for the Tranzak webhook tests.
 *
 * Served by the PHP built-in web server, not the CLI SAPI, so the webhook runs
 * the way it does in production: a real php://input, real headers, and a real
 * request per delivery. Mirrors system/controllers/callback.php, which
 * includes the gateway and calls tranzak_payment_notification().
 *
 *   php -S 127.0.0.1:<port> tests/tranzak_webhook_endpoint.php
 *
 * Query string:
 *   case=<name>  the scenario from tests/tranzak_cases.php
 *   state=<file> JSON state carried between deliveries, so a second delivery
 *                of the same webhook sees the database the first one wrote
 *
 * The body is the Tranzak payload. The gateway is loaded last, exactly as
 * callback.php loads it.
 */

require __DIR__ . '/tranzak_stubs.php';
require __DIR__ . '/tranzak_cases.php';

tz_strict_errors();

$case = isset($_GET['case']) ? $_GET['case'] : '';
$state_file = isset($_GET['state']) ? $_GET['state'] : '';

$scenarios = tz_webhook_cases();
if (!isset($scenarios[$case])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'unknown case: ' . $case]);
    exit;
}
$scenario = $scenarios[$case];

/* ---- fresh process state, then the fixture, then any previous delivery ---- */

TzDb::reset();
Message::reset();
Package::reset();
Http::reset();

tz_seed_config($scenario['env'] ?? 'sandbox');
tz_seed_cached_token();
tz_seed_order(
    $scenario['request_id'] ?? 'REQ231121CIPGSDPMO6J',
    $scenario['order_status'] ?? 1,
    $scenario['price'] ?? 1000
);

// Loaded last: what a previous delivery persisted wins over the fixture, which
// is what makes a repeated delivery a real retry against a real database.
if ($state_file !== '') {
    tz_state_load($state_file);
}

/* ---- framework globals the gateway reads ---- */

$GLOBALS['config'] = $GLOBALS['config'] ?? [];
$GLOBALS['admin'] = ['id' => 1, 'username' => 'admin'];
$GLOBALS['_L'] = ['Settings_Saved_Successfully' => 'Saved', 'Save' => 'Save'];
$GLOBALS['ui'] = new TzUi();
$GLOBALS['tz_post'] = [];
$_SESSION = [];

Http::$script = $scenario['http_script'] ?? [];

if ($state_file !== '') {
    register_shutdown_function(function () use ($state_file) {
        tz_state_save($state_file);
    });
}

require dirname(__DIR__) . '/system/paymentgateway/tranzak.php';

tranzak_payment_notification();
