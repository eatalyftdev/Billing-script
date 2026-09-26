<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * Payment Gateway — Tranzak (https://tranzak.net)
 * Docs: https://docs.developer.tranzak.me
 *
 * Cameroon mobile money collections (MTN MoMo / Orange Money) plus an
 * optional card / web-redirect flow. Currency: XAF only.
 *
 * Environment: https://sandbox.dsapi.tranzak.me | https://dsapi.tranzak.me
 *
 * Entry points (all called by the core, see system/controllers/order.php
 * and system/controllers/callback.php — none of those files are modified):
 *   tranzak_validate_config()        order.php, before every customer action
 *   tranzak_create_transaction()     order.php, on checkout
 *   tranzak_get_status()             order.php, on /order/view/{id}/check
 *   tranzak_payment_notification()   callback.php, public webhook
 *   tranzak_show_config()            paymentgateway.php, admin GET
 *   tranzak_save_config()            paymentgateway.php, admin POST
 */


/* ------------------------------------------------------------------ *
 *  Configuration
 * ------------------------------------------------------------------ */

/**
 * Read a gateway setting from tbl_appconfig ($config) without notices.
 */
function tranzak_setting($key, $default = '')
{
    global $config;
    if (!isset($config[$key]) || $config[$key] === '') {
        return $default;
    }
    return $config[$key];
}

/**
 * Upsert a single setting row. Same pattern as the other gateways.
 */
function tranzak_save_setting($key, $value)
{
    global $config;

    $d = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
    if ($d) {
        $d->value = $value;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = $key;
        $d->value = $value;
        $d->save();
    }

    /*
     * $config is a snapshot taken at bootstrap and is not reloaded per query.
     * Without this, a value written earlier in the same request would be read
     * back stale by tranzak_setting(): a cleared token would look valid, and a
     * freshly saved one would look missing.
     */
    $config[$key] = $value;
}

/**
 * 'sandbox' | 'production'
 */
function tranzak_get_env()
{
    return (tranzak_setting('tranzak_env', 'sandbox') === 'production') ? 'production' : 'sandbox';
}

/**
 * Base API URL for the selected environment. Never mix environments:
 * the key prefix is validated against this in tranzak_check_env_key().
 */
function tranzak_get_server()
{
    return (tranzak_get_env() === 'production')
        ? 'https://dsapi.tranzak.me/'
        : 'https://sandbox.dsapi.tranzak.me/';
}

/**
 * Tranzak issues SAND_* keys for sandbox and PROD_* for production. Serving a
 * sandbox key against the production host (or the reverse) would leak
 * credentials and create unmatched transactions, so refuse the combination.
 *
 * @return string '' when consistent, otherwise a human readable reason.
 */
function tranzak_check_env_key()
{
    $env = tranzak_get_env();
    $app_key = (string) tranzak_setting('tranzak_app_key');
    if ($app_key === '') {
        return '';
    }
    $expected = ($env === 'production') ? 'PROD_' : 'SAND_';
    if (strpos(strtoupper($app_key), $expected) !== 0) {
        return 'The saved App Key is not a ' . $expected . '* key, but the gateway is set to ' . $env . '.';
    }
    return '';
}

/**
 * Called by order.php before any customer-facing gateway action.
 */
function tranzak_validate_config()
{
    global $config;
    if (empty($config['tranzak_app_id']) || empty($config['tranzak_app_key']) || empty($config['tranzak_env'])) {
        Message::sendTelegram('tranzak payment gateway not configured');
        r2(U . 'order/package', 'w', Lang::T('Admin has not yet setup Tranzak payment gateway, please tell admin'));
    }
    $mismatch = tranzak_check_env_key();
    if ($mismatch !== '') {
        Message::sendTelegram('Tranzak configuration error: ' . $mismatch);
        r2(U . 'order/package', 'w', Lang::T('Tranzak payment gateway is misconfigured, please tell admin'));
    }
}


/* ------------------------------------------------------------------ *
 *  HTTP
 * ------------------------------------------------------------------ */

/**
 * Authenticated POST. Tranzak wraps every payload in
 * { data: {...}, success: bool, errorMsg, errorCode }.
 *
 * Explicit timeouts are passed on purpose: Http's defaults are 3000 *seconds*,
 * which is far too long for a customer-facing checkout or a webhook reply.
 *
 * @return array|null decoded payload, or null on transport/parse failure.
 */
function tranzak_api_post($path, $body, $connect_timeout = 10, $wait_timeout = 20)
{
    $raw = Http::postJsonData(
        tranzak_get_server() . ltrim($path, '/'),
        $body,
        tranzak_api_headers(),
        null,
        $connect_timeout,
        $wait_timeout
    );
    return tranzak_decode($raw);
}

/**
 * Authenticated GET.
 *
 * @return array|null decoded payload, or null on transport/parse failure.
 */
function tranzak_api_get($path, $connect_timeout = 10, $wait_timeout = 20)
{
    $raw = Http::getData(
        tranzak_get_server() . ltrim($path, '/'),
        tranzak_api_headers(),
        $connect_timeout,
        $wait_timeout
    );
    return tranzak_decode($raw);
}

function tranzak_api_headers()
{
    return [
        'Authorization: Bearer ' . tranzak_get_token(),
        'X-App-ID: ' . tranzak_setting('tranzak_app_id'),
        'Accept: application/json',
        'Cache-Control: no-cache',
    ];
}

/**
 * json_decode() that tolerates the junk Http returns on a cURL error and
 * never emits warnings on an empty body.
 */
function tranzak_decode($raw)
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/**
 * @return array|null $json['data'] when the call succeeded, else null.
 */
function tranzak_data($json)
{
    if (!is_array($json) || empty($json['success'])) {
        return null;
    }
    return isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
}

/**
 * Best available human readable reason out of a Tranzak response.
 */
function tranzak_error_of($json)
{
    if (!is_array($json)) {
        return 'no response from payment provider';
    }
    if (!empty($json['errorMsg'])) {
        return $json['errorMsg'];
    }
    if (!empty($json['errorCode'])) {
        return 'error code ' . $json['errorCode'];
    }
    return 'unknown error';
}


/* ------------------------------------------------------------------ *
 *  Token (cached in tbl_appconfig, refreshed at 75% of expiresIn)
 * ------------------------------------------------------------------ */

/**
 * Per-request memo for the bearer token.
 *
 * Held in a single static that tranzak_clear_token() can reach by reference.
 * A function-local static would survive tranzak_clear_token(), and the retry
 * inside tranzak_request_details() would then re-send the token the provider
 * had just rejected instead of minting a new one.
 *
 * @return string|null
 */
function &tranzak_token_memo()
{
    static $memo = null;
    return $memo;
}

/**
 * Returns a bearer token, reusing the cached one while it is still fresh.
 *
 * Two rows are used: tranzak_token and tranzak_token_expires_at. They are
 * internal cache and are deliberately never rendered in the settings UI.
 */
function tranzak_get_token()
{
    $memo = &tranzak_token_memo();
    if ($memo !== null) {
        return $memo;
    }

    $token = tranzak_setting('tranzak_token');
    $expires_at = (int) tranzak_setting('tranzak_token_expires_at');
    if (!empty($token) && $expires_at > time()) {
        $memo = $token;
        return $memo;
    }

    // tranzak_request_token() fills the memo on both paths.
    return tranzak_request_token();
}

/**
 * POST /auth/token. Also used to force a refresh when the cached token was
 * rejected (401 INVALID_ACCESS_TOKEN).
 */
function tranzak_request_token()
{
    $json = tranzak_decode(Http::postJsonData(
        tranzak_get_server() . 'auth/token',
        [
            'appId' => tranzak_setting('tranzak_app_id'),
            'appKey' => tranzak_setting('tranzak_app_key'),
        ],
        ['Accept: application/json'],
        null,
        10,
        20
    ));

    $data = tranzak_data($json);
    if (empty($data['token'])) {
        $memo = &tranzak_token_memo();
        $memo = '';
        Message::sendTelegram('Tranzak: failed to obtain API token -> ' . tranzak_redact(tranzak_error_of($json)));
        return '';
    }

    // Tranzak recommends caching for at least 3/4 of the token lifetime.
    $expires_in = isset($data['expiresIn']) ? (int) $data['expiresIn'] : 7200;
    $lifetime = ($expires_in > 0) ? (int) floor($expires_in * 0.75) : 5400;

    tranzak_save_setting('tranzak_token', $data['token']);
    tranzak_save_setting('tranzak_token_expires_at', (string) (time() + $lifetime));

    $memo = &tranzak_token_memo();
    $memo = $data['token'];

    return $data['token'];
}

/**
 * Drop the cached token so the next call re-authenticates. The per-request
 * memo is dropped with it, otherwise the retry below would reuse it.
 */
function tranzak_clear_token()
{
    $memo = &tranzak_token_memo();
    $memo = null;
    tranzak_save_setting('tranzak_token', '');
    tranzak_save_setting('tranzak_token_expires_at', '0');
}

/**
 * Strip credentials out of anything bound for a log or a Telegram message.
 * Provider error text is echoed verbatim, and a provider that quotes back the
 * submitted key must not be able to push it into a chat.
 */
function tranzak_redact($text)
{
    $text = (string) $text;
    foreach (['tranzak_app_key', 'tranzak_webhook_authkey', 'tranzak_token'] as $key) {
        $secret = (string) tranzak_setting($key);
        if (strlen($secret) >= 8) {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }
    return $text;
}

/**
 * GET /xp021/v1/request/details — the authoritative status of a request.
 * Retries once with a fresh token when the cached one was rejected.
 */
function tranzak_request_details($request_id)
{
    if (empty($request_id)) {
        return null;
    }
    $data = tranzak_data(tranzak_api_get('xp021/v1/request/details?requestId=' . rawurlencode($request_id)));
    if ($data === null) {
        tranzak_clear_token();
        $data = tranzak_data(tranzak_api_get('xp021/v1/request/details?requestId=' . rawurlencode($request_id)));
    }
    return $data;
}

/**
 * POST /xp021/v1/request/refresh-transaction-status — asks the operator for a
 * fresh status. Used when a request is still PENDING/PAYMENT_IN_PROGRESS and
 * Tranzak may not have been notified yet.
 */
function tranzak_refresh_status($request_id)
{
    if (empty($request_id)) {
        return null;
    }
    $data = tranzak_data(tranzak_api_post('xp021/v1/request/refresh-transaction-status', [
        'requestId' => $request_id,
    ]));
    if ($data === null) {
        tranzak_clear_token();
        $data = tranzak_data(tranzak_api_post('xp021/v1/request/refresh-transaction-status', [
            'requestId' => $request_id,
        ]));
    }
    return $data;
}


/* ------------------------------------------------------------------ *
 *  Helpers
 * ------------------------------------------------------------------ */

/**
 * Normalise a customer phone number to the 237XXXXXXXXX form Tranzak requires
 * for a direct mobile-wallet charge.
 *
 * @return string '' when the number cannot be used.
 */
function tranzak_normalize_msisdn($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return '';
    }
    // +2376XXXXXXXX / 2376XXXXXXXX
    if (strpos($digits, '237') === 0) {
        $digits = substr($digits, 3);
    }
    // 002376XXXXXXXX
    if (strpos($digits, '00237') === 0) {
        $digits = substr($digits, 5);
    }
    // Local 06XXXXXXXX / 2XXXXXXXX -> national 6XXXXXXXX / 2XXXXXXXX
    if (strlen($digits) == 10 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    } elseif (strlen($digits) == 9 && ($digits[0] === '6' || $digits[0] === '2')) {
        // already national format
    } elseif (strlen($digits) == 8 && $digits[0] === '6') {
        $digits = '6' . $digits; // tolerate a dropped leading 6
    }
    // Cameroon mobile numbers are 9 digits starting with 6 (MTN/Orange).
    if (!preg_match('/^[62][0-9]{8}$/', $digits)) {
        return '';
    }
    return '237' . $digits;
}

/**
 * Map Tranzak TXN_, PAYER_, OPERATOR_ and SYSTEM_ error codes to plain
 * language. Never show a raw code to the customer.
 */
function tranzak_friendly_error($code)
{
    $code = strtoupper(trim((string) $code));
    $map = [
        'PAYER_INSUFF_BALANCE' => 'You do not have enough balance in your mobile money account.',
        'OPERATOR_PAYER_INSUFF_BALANCE' => 'You do not have enough balance in your mobile money account.',
        'PAYER_PAYMENT_METHOD_ERROR' => 'This payment method is not valid. Please choose MTN Mobile Money or Orange Money.',
        'PAYER_PAYMENT_METHOD_UNSPECIFIED' => 'Please choose a payment method.',
        'PAYER_AUTHORIZATION_REQUIRED' => 'Your payment needs to be authorised. Please check your mobile money account.',
        'PAYER_INVALID_PIN' => 'The PIN you entered is not valid.',
        'PAYER_INVALID_RNV_KYC' => 'Your mobile money account is not fully registered yet.',
        'PAYER_SETTLEMENT_AMOUNT_VIOLATION' => 'This amount is outside the allowed limits for your account.',
        'BENE_UNDEFINED_ERROR' => 'The recipient account is not valid.',
        'BENE_ACCOUNT_INVALID' => 'The recipient account is not valid.',
        'BENE_ACCOUNT_NAME_MISMATCH' => 'The account holder name does not match.',
        'TXN_GENERAL_FAILURE' => 'The payment could not be completed. Please try again.',
        'TXN_FAILED_OPERATOR_ERROR' => 'Your mobile money operator reported a problem. Please try again.',
        'TXN_PIN_AUTHORIZATION_REQUIRED' => 'Your payment needs to be authorised. Please check your mobile money account.',
        'TXN_FAILED_INTERNAL_ERROR' => 'The payment failed. Please try again.',
        'TXN_EXPIRED' => 'This payment request has expired. Please start again.',
        'TXN_UNSUPPORTED_CURRENCY' => 'This payment currency is not supported.',
        'TXN_INVALID_AMOUNT' => 'This amount is not within the accepted limits.',
        'TXN_CANCELLED' => 'The payment was cancelled.',
        'TXN_INVALID_AUTHORIZATION_CODE' => 'The authorisation code entered is not valid.',
        'TXN_APPROVAL_REQUIRED' => 'This payment is waiting for approval.',
        'TXN_PAYMENT_METHOD_UNSUPPORTED' => 'This payment method is not supported.',
        'TXN_CROSS_BORDER_PAYMENT_UNSUPPORTED' => 'Cross border payment is not available for this account.',
        'TXN_CROSS_BORDER_PAYMENT_UNSUPPORTED_CURRENCY' => 'This currency cannot be used for a cross border payment.',
        'TXN_LIMIT_EXCEEDED' => 'This amount exceeds the transaction limit of your account.',
        'OPERATOR_COMM_ERROR' => 'The network of your mobile money operator is unstable. Please try again shortly.',
        'OPERATOR_INVALID_ACCOUNT_HOLDER' => 'Your mobile money account is not valid or is restricted.',
        'OPERATOR_TXN_FAILED' => 'Your mobile money operator could not process the payment.',
        'OPERATOR_INTERNAL_TXN_FAILURE' => 'Your mobile money operator reported an internal error.',
        'OPERATOR_SYSTEM_INTERNAL_ERROR' => 'Your mobile money operator reported an internal error.',
        'OPERATOR_INVALID_SERVICE_PROVIDER' => 'Your mobile money operator is temporarily unavailable.',
        'SYSTEM_PARSE_ERROR' => 'The payment data could not be read. Please try again.',
        'SYSTEM_GENERAL_VALIDATION_ERROR' => 'The payment details were rejected. Please try again.',
        'SYSTEM_EXCEPTION' => 'An internal error occurred. Please try again later.',
        'SYSTEM_ADMIN_RESTRICTED' => 'This account is restricted. Please contact support.',
        'SYSTEM_ADMIN_POLICY_VIOLATION' => 'This payment cannot be processed under our policy.',
        'SYSTEM_INTERNAL_ERROR' => 'A temporary internal error occurred. Please try again.',
        'AUTH_INVALID_CREDENTIALS' => 'Tranzak rejected the configured credentials. Please tell admin.',
        'INVALID_ACCESS_TOKEN' => 'Tranzak rejected the configured credentials. Please tell admin.',
    ];
    if (isset($map[$code])) {
        return $map[$code];
    }
    if ($code === '') {
        return 'The payment could not be completed. Please try again.';
    }
    // Unknown code: keep it out of the customer message, but still useful.
    return 'The payment could not be completed. Please try again or contact support.';
}

/**
 * Plain-language message for a non-successful request status.
 */
function tranzak_status_message($status, $error_code = '')
{
    $status = strtoupper((string) $status);
    if ($error_code !== '') {
        $friendly = tranzak_friendly_error($error_code);
        if ($friendly !== 'The payment could not be completed. Please try again.') {
            return $friendly;
        }
    }
    switch ($status) {
        case 'PENDING':
            return 'Your payment is not completed yet. Please approve the payment request on your phone, then try again.';
        case 'PAYMENT_IN_PROGRESS':
            return 'Your payment is still processing. Please wait a moment and check again.';
        case 'CANCELLED':
            return 'The payment was cancelled.';
        case 'CANCELLED_BY_PAYER':
            return 'You cancelled the payment.';
        case 'CANCELLED/REFUNDED':
            return 'This payment was cancelled or refunded.';
        case 'FAILED':
            return 'The payment failed. Please try again.';
        case 'PAYER_REDIRECT_REQUIRED':
            return 'Your payment needs an extra step. Please continue the payment.';
        default:
            return 'The payment could not be completed. Please try again.';
    }
}

/**
 * 'wallet' (MTN/Orange direct charge, default) or 'redirect' (card / web).
 */
function tranzak_get_mode()
{
    return (tranzak_setting('tranzak_payment_mode', 'wallet') === 'redirect') ? 'redirect' : 'wallet';
}

/**
 * Deterministic, unique-per-order merchant reference. Tranzak keeps this for
 * 30 days, so a row that already has a request is never charged twice.
 */
function tranzak_mch_ref($trx)
{
    return 'WIFI-' . $trx['id'];
}

function tranzak_return_url($trx)
{
    return U . 'order/view/' . $trx['id'] . '/check';
}

function tranzak_cancel_url($trx)
{
    return U . 'order/view/' . $trx['id'] . '/cancel';
}


/* ------------------------------------------------------------------ *
 *  Activation (shared by get_status and the webhook)
 * ------------------------------------------------------------------ */

/**
 * Server-confirmed activation. Called only with a status re-read from
 * Tranzak, never from a webhook body.
 *
 * Idempotent: the row is re-read from the database, and status 2 short
 * circuits, so duplicate webhooks or a customer clicking "check status" at
 * the same moment cannot recharge twice.
 *
 * @return string '' on success/already-done, otherwise a customer message.
 */
function tranzak_activate($trx, $details)
{
    global $config;

    // Re-read: the row we were handed may be stale.
    $row = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if (empty($row)) {
        return 'Transaction not found';
    }
    if ($row['status'] == 2) {
        return ''; // already activated, safe no-op
    }
    if (isset($row['status']) && in_array((int) $row['status'], [3, 4])) {
        return 'This transaction was already closed';
    }

    // Cross-check the confirmed request against the order before activating.
    $reason = tranzak_verify_details($row, $details);
    if ($reason !== '') {
        Message::sendTelegram('Tranzak activation blocked for #' . $row['id'] . ': ' . $reason);
        return 'The payment could not be verified. Please contact support with transaction #' . $row['id'] . '.';
    }

    $channel = 'Tranzak';
    if (!empty($details['payer']['paymentMethod'])) {
        $channel = $details['payer']['paymentMethod'];
    }

    $paid_date = date('Y-m-d H:i:s');
    if (!empty($details['transactionTime'])) {
        $ts = strtotime($details['transactionTime']);
        if ($ts) {
            $paid_date = date('Y-m-d H:i:s', $ts);
        }
    }

    if (!Package::rechargeUser($row['user_id'], $row['routers'], $row['plan_id'], $row['gateway'], $channel)) {
        return 'Failed to activate your Package, please try again later.';
    }

    $row->pg_paid_response = json_encode($details);
    $row->payment_method = 'Tranzak';
    $row->payment_channel = $channel;
    $row->paid_date = $paid_date;
    $row->status = 2;
    $row->save();

    return '';
}

/**
 * Confirm a server-fetched request really belongs to this order.
 *
 * @return string '' when it matches, otherwise the mismatch reason.
 */
function tranzak_verify_details($row, $details)
{
    if (empty($details['requestId'])) {
        return 'response without requestId';
    }
    if (!empty($row['gateway_trx_id']) && $details['requestId'] !== $row['gateway_trx_id']) {
        return 'requestId mismatch';
    }
    if (!empty($details['mchTransactionRef']) && $details['mchTransactionRef'] !== tranzak_mch_ref($row)) {
        return 'mchTransactionRef mismatch (' . $details['mchTransactionRef'] . ')';
    }
    if (empty($details['amount'])) {
        return 'response without amount';
    }
    if (isset($details['currencyCode']) && $details['currencyCode'] !== 'XAF') {
        return 'unexpected currency ' . $details['currencyCode'];
    }
    if (number_format((float) $details['amount'], 2, '.', '') !== number_format((float) $row['price'], 2, '.', '')) {
        return 'amount mismatch, charged ' . $details['amount'] . ' expected ' . $row['price'];
    }
    return '';
}


/* ------------------------------------------------------------------ *
 *  Admin settings
 * ------------------------------------------------------------------ */

function tranzak_show_config()
{
    global $ui;
    $ui->assign('_title', 'Tranzak - Payment Gateway');
    // paymentgateway.php does not emit a CSRF token, so mint one here and
    // verify it in tranzak_save_config(). This form writes credentials.
    $ui->assign('csrf_token', Csrf::generateAndStoreToken());
    $ui->display('tranzak.tpl');
}

function tranzak_save_config()
{
    global $admin, $_L;

    if (!Csrf::check(_post('csrf_token'))) {
        r2(U . 'paymentgateway/tranzak', 'w', Lang::T('Invalid or expired token, please try again'));
    }

    tranzak_save_setting('tranzak_env', (_post('tranzak_env') === 'production') ? 'production' : 'sandbox');
    tranzak_save_setting('tranzak_app_id', trim((string) _post('tranzak_app_id')));
    tranzak_save_setting('tranzak_payment_mode', (_post('tranzak_payment_mode') === 'redirect') ? 'redirect' : 'wallet');

    /*
     * Both secrets are write-only in the UI ("leave blank to keep the stored
     * value"), so an empty field must not wipe the stored credential. Clearing
     * one is a deliberate act on the database, not a side effect of saving the
     * rest of the form.
     */
    $app_key = trim((string) _post('tranzak_app_key'));
    if ($app_key !== '') {
        tranzak_save_setting('tranzak_app_key', $app_key);
    }
    $webhook_key = trim((string) _post('tranzak_webhook_authkey'));
    if ($webhook_key !== '') {
        tranzak_save_setting('tranzak_webhook_authkey', $webhook_key);
    }

    // Credentials or environment may have changed: force a fresh token.
    tranzak_clear_token();

    _log('[' . $admin['username'] . ']: Tranzak ' . $_L['Settings_Saved_Successfully'], 'Admin', $admin['id']);

    r2(U . 'paymentgateway/tranzak', 's', $_L['Settings_Saved_Successfully']);
}


/* ------------------------------------------------------------------ *
 *  Checkout
 * ------------------------------------------------------------------ */

function tranzak_create_transaction($trx, $user)
{
    global $config;

    tranzak_validate_config();

    /*
     * order.php reuses an existing unpaid row for the same gateway, so this
     * can run again for a retry. Tranzak keeps mchTransactionRef for 30 days
     * and rejects duplicates, so an already-charged row is continued instead
     * of charged twice.
     */
    if (!empty($trx['gateway_trx_id'])) {
        $existing = tranzak_request_details($trx['gateway_trx_id']);
        if (!empty($existing)) {
            $status = strtoupper((string) tranzak_status($existing));
            if ($status === 'SUCCESSFUL') {
                $msg = tranzak_activate($trx, $existing);
                if ($msg !== '') {
                    r2(U . 'order/view/' . $trx['id'], 'd', Lang::T($msg));
                }
                r2(U . 'order/view/' . $trx['id'], 's', Lang::T('Transaction successful.'));
            }
            if ($status === 'PAYER_REDIRECT_REQUIRED' && !empty($existing['links']['paymentAuthUrl'])) {
                header('Location: ' . $existing['links']['paymentAuthUrl']);
                exit();
            }
            r2(U . 'order/view/' . $trx['id'] . '/check', 'w', Lang::T('Transaction still unpaid.'));
        }
        // The stored request is gone on Tranzak's side: start over cleanly.
        $trx->gateway_trx_id = '';
        $trx->save();
    }

    $body = [
        'amount' => (float) $trx['price'],
        'currencyCode' => 'XAF',
        'description' => substr((string) $trx['plan_name'], 0, 255),
        'mchTransactionRef' => tranzak_mch_ref($trx),
        'returnUrl' => tranzak_return_url($trx),
        'cancelUrl' => tranzak_cancel_url($trx),
    ];

    if (tranzak_get_mode() === 'redirect') {
        // Card / web redirect flow.
        $json = tranzak_api_post('xp021/v1/request/create', $body);
    } else {
        // Preferred: push the USSD prompt straight to the customer's wallet.
        $msisdn = tranzak_normalize_msisdn(isset($user['phonenumber']) ? $user['phonenumber'] : '');
        if ($msisdn === '') {
            Message::sendTelegram('Tranzak: #' . $trx['id'] . ' has no usable Cameroon mobile number, cannot charge wallet');
            r2(
                U . 'order/package',
                'w',
                Lang::T('Please add a valid Cameroon mobile number (MTN or Orange) to your profile before paying by mobile money')
            );
        }
        $body['mobileWalletNumber'] = $msisdn;
        $json = tranzak_api_post('xp021/v1/request/create-mobile-wallet-charge', $body);
    }

    $data = tranzak_data($json);
    if (empty($data['requestId'])) {
        Message::sendTelegram(tranzak_redact("Tranzak payment failed\n\n" . json_encode($json, JSON_PRETTY_PRINT)));
        $friendly = tranzak_friendly_error(isset($json['errorMsg']) ? $json['errorMsg'] : '');
        if ($friendly === 'The payment could not be completed. Please try again.') {
            $friendly = 'Failed to start the payment. Please try again or contact support.';
        }
        r2(U . 'order/package', 'e', Lang::T($friendly));
    }

    /*
     * Persist the requestId immediately, before any wait on completion, so a
     * webhook that arrives mid-flight can already be matched to this order.
     */
    $auth_url = isset($data['links']['paymentAuthUrl']) ? $data['links']['paymentAuthUrl'] : '';
    $trx->gateway_trx_id = $data['requestId'];
    $trx->pg_request = json_encode($json);
    /*
     * order.php bounces /order/view/{id}/check back to the buy page while
     * pg_url_payment is empty, which would make the "check status" return
     * flow unreachable for a direct wallet charge. Store the page the
     * customer has to come back to when there is no paymentAuthUrl.
     */
    $trx->pg_url_payment = ($auth_url !== '') ? $auth_url : tranzak_return_url($trx);
    $trx->expired_date = date('Y-m-d H:i:s', strtotime('+ 1 DAY'));
    $trx->save();

    if ($auth_url !== '') {
        header('Location: ' . $auth_url);
        exit();
    }

    // Direct wallet charge: the USSD prompt is already on the customer's
    // phone, send them to the order page to watch it settle.
    r2(U . 'order/view/' . $trx['id'], 's', Lang::T('Please approve the payment request on your mobile phone.'));
}


/* ------------------------------------------------------------------ *
 *  Customer "check status"
 * ------------------------------------------------------------------ */

function tranzak_get_status($trx, $user)
{
    global $config;

    $request_id = $trx['gateway_trx_id'];
    if (empty($request_id)) {
        r2(U . 'order/package', 'd', Lang::T('Transaction expired.'));
    }

    $details = tranzak_request_details($request_id);
    if (empty($details)) {
        Message::sendTelegram('Tranzak_get_status: could not read request ' . $request_id . ' for #' . $trx['id']);
        r2(U . 'order/view/' . $trx['id'], 'w', Lang::T('Transaction still unpaid.'));
    }

    $status = tranzak_status($details);

    if ($status === 'SUCCESSFUL') {
        if ($trx['status'] == 2) {
            r2(U . 'order/view/' . $trx['id'], 'd', Lang::T('Transaction has been paid..'));
        }
        $err = tranzak_activate($trx, $details);
        if ($err !== '') {
            r2(U . 'order/view/' . $trx['id'], 'd', Lang::T($err));
        }
        r2(U . 'order/view/' . $trx['id'], 's', Lang::T('Transaction successful.'));
    }

    if ($trx['status'] == 2) {
        r2(U . 'order/view/' . $trx['id'], 'd', Lang::T('Transaction has been paid..'));
    }

    // Still open: ask the operator directly, mobile money providers do not
    // always notify in time.
    if ($status === 'PENDING' || $status === 'PAYMENT_IN_PROGRESS') {
        $refreshed = tranzak_refresh_status($request_id);
        if (!empty($refreshed) && tranzak_status($refreshed) === 'SUCCESSFUL') {
            $err = tranzak_activate($trx, $refreshed);
            if ($err !== '') {
                r2(U . 'order/view/' . $trx['id'], 'd', Lang::T($err));
            }
            r2(U . 'order/view/' . $trx['id'], 's', Lang::T('Transaction successful.'));
        }
    }

    if ($status === 'PAYER_REDIRECT_REQUIRED' && !empty($details['links']['paymentAuthUrl'])) {
        header('Location: ' . $details['links']['paymentAuthUrl']);
        exit();
    }

    $error_code = '';
    if (!empty($details['errorMessage'])) {
        $error_code = $details['errorMessage'];
    } elseif (!empty($details['errorCode'])) {
        $error_code = $details['errorCode'];
    }

    r2(U . 'order/view/' . $trx['id'], 'w', Lang::T(tranzak_status_message($status, $error_code)));
}

/**
 * GET /request/details returns `status`; the TPN family uses
 * `transactionStatus`. Accept either so both payloads are understood.
 */
function tranzak_status($details)
{
    if (!is_array($details)) {
        return '';
    }
    if (!empty($details['status'])) {
        return strtoupper((string) $details['status']);
    }
    if (!empty($details['transactionStatus'])) {
        return strtoupper((string) $details['transactionStatus']);
    }
    return '';
}


/* ------------------------------------------------------------------ *
 *  Webhook  ->  {site}/?_route=callback/tranzak
 * ------------------------------------------------------------------ */

/**
 * Public, unauthenticated. Routed by system/controllers/callback.php, which
 * includes this file and calls tranzak_payment_notification(). That
 * controller needs no change.
 *
 * The payload's authKey is a static shared value, not an HMAC, so it is only
 * a sanity check. The status that decides activation is always re-read from
 * Tranzak over the authenticated API.
 */
function tranzak_payment_notification()
{
    $ack = ['success' => false, 'message' => 'ignored'];

    $raw = file_get_contents('php://input');
    $payload = tranzak_decode($raw);

    if (empty($payload) || empty($payload['eventType']) || $payload['eventType'] !== 'REQUEST.COMPLETED') {
        tranzak_ack($ack);
    }

    // 1. sanity check the shared key when one is configured.
    $expected = (string) tranzak_setting('tranzak_webhook_authkey');
    if ($expected !== '') {
        $given = isset($payload['authKey']) ? (string) $payload['authKey'] : '';
        if ($given === '' || !hash_equals($expected, $given)) {
            Message::sendTelegram('Tranzak webhook rejected, authKey mismatch for resource ' . tranzak_redact(isset($payload['resourceId']) ? $payload['resourceId'] : 'unknown'));
            tranzak_ack(['success' => false, 'message' => 'authKey mismatch']);
        }
    } else {
        Message::sendTelegram('Tranzak webhook received but no authKey is configured. Set one in the gateway settings.');
    }

    // 2. locate the order. resourceId is the Tranzak requestId we stored.
    $resource_id = isset($payload['resourceId']) ? (string) $payload['resourceId'] : '';
    if ($resource_id === '') {
        tranzak_ack(['success' => false, 'message' => 'missing resourceId']);
    }

    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway_trx_id', $resource_id)
        ->where('gateway', 'tranzak')
        ->find_one();

    if (empty($trx)) {
        Message::sendTelegram('Tranzak webhook: no order matches request ' . tranzak_redact($resource_id));
        tranzak_ack(['success' => false, 'message' => 'unknown requestId']);
    }

    // 3. authoritative status, fetched server side. Ignore the body.
    $details = tranzak_request_details($resource_id);
    if (empty($details)) {
        tranzak_ack(['success' => false, 'message' => 'could not confirm status']);
    }

    $status = tranzak_status($details);

    if ($status === 'SUCCESSFUL') {
        $err = tranzak_activate($trx, $details);
        if ($err !== '') {
            tranzak_ack(['success' => false, 'message' => $err]);
        }
        tranzak_ack([
            'success' => true,
            'message' => ($trx['status'] == 2) ? 'already activated' : 'activated',
            'requestId' => $resource_id,
        ]);
    }

    // Terminal failures close the order so the customer is not left with a
    // permanently "unpaid" transaction.
    if (in_array($status, ['FAILED', 'CANCELLED', 'CANCELLED_BY_PAYER', 'CANCELLED/REFUNDED'])) {
        tranzak_close_failed($trx, $details, $status);
        tranzak_ack(['success' => true, 'message' => 'closed as ' . $status, 'requestId' => $resource_id]);
    }

    // PENDING / PAYMENT_IN_PROGRESS / PAYER_REDIRECT_REQUIRED: nothing to do.
    tranzak_ack(['success' => true, 'message' => 'status ' . ($status !== '' ? $status : 'unknown'), 'requestId' => $resource_id]);
}

/**
 * Mark a definitively failed/cancelled request as failed (3) so the customer
 * can start a new order. Never touches an already paid row.
 */
function tranzak_close_failed($trx, $details, $status)
{
    $row = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if (empty($row) || $row['status'] == 2 || $row['status'] == 3) {
        return;
    }
    $row->pg_paid_response = json_encode($details);
    $row->payment_method = 'Tranzak';
    $row->status = 3;
    $row->save();
}

/**
 * Always answer with JSON and stop: callback.php die()s after this returns.
 */
function tranzak_ack($ack)
{
    header('Content-Type: application/json');
    echo json_encode($ack);
    die();
}
