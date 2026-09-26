<?php

/**
 * Webhook scenarios, shared by the runner and the test front controller so the
 * two cannot drift apart.
 *
 * Each entry names the fake database row the webhook should find and scripts
 * the Tranzak HTTP responses. The payload itself is sent as a real HTTP request
 * body by the runner; see tz_webhook_payloads().
 *
 * A seeded cached token means the only scripted call is the one the scenario is
 * about: a configured gateway is already authenticated, exactly as it is in
 * production after the first checkout.
 *
 * @return array<string, array{
 *     env?:string, request_id?:string, order_status?:int, price?:int,
 *     deliveries?:int, http_script?:array
 * }>
 */
function tz_webhook_cases()
{
    $key = 'jaldfjalsdjkssssssssssssssssssssss';
    $other_event = tz_webhook_successful($key);
    $other_event['eventType'] = 'TRANSFER.COMPLETED';

    return [

        /* ---- charge -> webhook -> server confirm -> activate ---- */
        'webhook_success_activates_voucher_once' => [
            'http_script' => [tz_details_successful()],
        ],

        /* ---- webhooks that must NOT activate ---- */

        // Right body, wrong shared key.
        'webhook_forged_authkey_does_not_activate' => [
            'http_script' => [tz_details_successful()],
        ],

        // Right key, the body claims SUCCESSFUL, Tranzak has not confirmed it.
        'webhook_claiming_success_before_confirmation_does_not_activate' => [
            'http_script' => [tz_details_pending()],
        ],

        // Tranzak confirms SUCCESSFUL but for a different amount.
        'webhook_amount_mismatch_does_not_activate' => [
            'http_script' => [tz_details_successful(100)],
            'price' => 1000,
        ],

        // resourceId matches no order.
        'webhook_unknown_request_id_is_ignored' => [
            'http_script' => [],
        ],

        // A failed payment closes the order, it never activates.
        'webhook_failed_status_closes_order_without_recharge' => [
            'request_id' => 'REQ231121BKKJYX5PLZ8',
            'http_script' => [tz_details_status('FAILED')],
        ],

        // An event type this endpoint does not subscribe to.
        'webhook_ignores_other_event_types' => [
            'http_script' => [tz_details_successful()],
        ],

        // The customer activated it themselves first; the webhook must no-op.
        'webhook_after_manual_activation_is_a_noop' => [
            'order_status' => 2,
            'http_script' => [tz_details_successful()],
        ],

        // Tranzak retries a delivery it did not get an answer to. The second
        // one must be a no-op, not a second voucher.
        'webhook_duplicate_delivery_activates_only_once' => [
            'deliveries' => 2,
            'http_script' => [tz_details_successful()],
        ],
    ];
}

/** How many times the runner delivers a scenario. */
function tz_webhook_deliveries($case)
{
    $s = tz_webhook_cases();
    return isset($s[$case]['deliveries']) ? (int) $s[$case]['deliveries'] : 1;
}

/** Payloads keyed by case, delivered verbatim as the request body. */
function tz_webhook_payloads()
{
    $key = 'jaldfjalsdjkssssssssssssssssssssss';
    $other = tz_webhook_successful($key);
    $other['eventType'] = 'TRANSFER.COMPLETED';

    return [
        'webhook_success_activates_voucher_once' => tz_webhook_successful($key),
        'webhook_forged_authkey_does_not_activate' => tz_webhook_successful('attacker-key'),
        'webhook_claiming_success_before_confirmation_does_not_activate' => tz_webhook_successful($key),
        'webhook_amount_mismatch_does_not_activate' => tz_webhook_successful($key),
        'webhook_unknown_request_id_is_ignored' => tz_webhook_successful($key, 'REQ-UNKNOWN'),
        'webhook_failed_status_closes_order_without_recharge' => tz_webhook_failed(),
        'webhook_ignores_other_event_types' => $other,
        'webhook_after_manual_activation_is_a_noop' => tz_webhook_successful($key),
        'webhook_duplicate_delivery_activates_only_once' => tz_webhook_successful($key),
    ];
}
