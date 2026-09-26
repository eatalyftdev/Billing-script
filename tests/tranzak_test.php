<?php

/**
 * Test suite for the Tranzak payment gateway.
 *
 * PHPNuxBill ships no test framework (no PHPUnit, no composer require-dev, no
 * CI), so this is a self-contained runner that adds no dependencies:
 *
 *   php tests/tranzak_test.php          run everything
 *   php tests/tranzak_test.php --list   list case names
 *
 * Two kinds of case:
 *
 *  - In-process cases (configuration, checkout, check status, pure helpers,
 *    the admin surface) run in a CLI subprocess. That is required because the
 *    gateway ends a request with r2() or header()+exit exactly as it does in
 *    production, and a subprocess is the only honest way to observe "the
 *    script stopped here" without editing the code under test.
 *
 *  - Webhook cases are delivered as real HTTP POSTs to the PHP built-in web
 *    server, because php://input is empty under the CLI SAPI. A state file is
 *    threaded between requests so a repeated delivery is modelled as repeated
 *    requests against one persistent database.
 *
 * Http, the ORM and Package::rechargeUser are stubbed: no network and no
 * database are required.
 */

require __DIR__ . '/tranzak_stubs.php';
require __DIR__ . '/tranzak_cases.php';

$GLOBALS['tz_root'] = dirname(__DIR__);
$GLOBALS['tz_gateway'] = dirname(__DIR__) . '/system/paymentgateway/tranzak.php';


/* ==================================================================== *
 *  Reporting
 * ==================================================================== */

/**
 * Set up the globals a gateway call expects, mirroring init.php, then require
 * the file under test. Shared by the runner and the webhook front controller.
 */
function tz_boot_gateway()
{
    TzDb::reset();
    Message::reset();
    Package::reset();
    Http::reset();
    $GLOBALS['config'] = [];
    $GLOBALS['admin'] = ['id' => 1, 'username' => 'admin'];
    $GLOBALS['_L'] = ['Settings_Saved_Successfully' => 'Saved', 'Save' => 'Save'];
    $GLOBALS['ui'] = new TzUi();
    $GLOBALS['tz_post'] = [];
    $GLOBALS['isApi'] = false;
    $_SESSION = [];
}

function tz_report(array $extra = [])
{
    $out = array_merge([
        'stopped' => 'completed',
        'recharge_count' => count(Package::$recharges),
        'recharges' => Package::$recharges,
        'telegram' => Message::$sent,
        'http_calls' => array_map(function ($c) {
            return $c['method'] . ' ' . $c['url'];
        }, Http::$calls),
        'trx' => tz_trx(),
        'config' => tz_redact_config(),
    ], $extra);
    echo "\n__TZRESULT__" . json_encode($out);
    $GLOBALS['tz_reported'] = true;
}

/**
 * Assertions that have to run after the gateway stopped the request with
 * header(Location:) + exit(). Registered by a case, executed at shutdown.
 */
function tz_on_exit(callable $fn)
{
    $GLOBALS['tz_exit_checks'][] = $fn;
}


/* ==================================================================== *
 *  In-process cases
 * ==================================================================== */

function tz_process_cases()
{
    return [

        /* ---------- configuration guards ---------- */

        'config_missing_blocks_checkout' => function () {
            try {
                tranzak_validate_config();
                throw new TzFail('validate_config did not stop an unconfigured gateway');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'not yet setup Tranzak', 'plain message, no codes');
                tz_eq($r->to, U . 'order/package', 'back to the package page');
            }
            tz_ok(count(Message::$sent) > 0, 'the admin is notified');
        },

        'config_env_key_mismatch_is_refused' => function () {
            // Sandbox selected, PROD_ key stored: refuse rather than ship
            // production credentials to the sandbox host.
            tz_seed_config('sandbox');
            TzDb::config('tranzak_app_key', 'PROD_91AFB18002A5C1B041767BBA4B5D808D91AFB18MJU89');
            tz_reload_config();
            try {
                tranzak_validate_config();
                throw new TzFail('a PROD_ key was accepted while in sandbox mode');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'misconfigured', 'customer told to contact the admin');
            }
            tz_eq(tranzak_get_server(), 'https://sandbox.dsapi.tranzak.me/', 'sandbox host');
        },

        'config_env_selects_correct_host' => function () {
            tz_seed_config('production');
            tz_eq(tranzak_get_server(), 'https://dsapi.tranzak.me/', 'production host');
            tz_eq(tranzak_check_env_key(), '', 'PROD_ key valid in production');
            tz_seed_config('sandbox');
            tz_eq(tranzak_get_server(), 'https://sandbox.dsapi.tranzak.me/', 'sandbox host');
            tz_eq(tranzak_check_env_key(), '', 'SAND_ key valid in sandbox');
        },

        /* ---------- checkout ---------- */

        'create_wallet_charge_request_shape' => function () {
            tz_seed_config('sandbox');
            $trx = tz_seed_new_order();
            Http::$script = [tz_token_ok(), tz_create_ok()];

            $redirect = null;
            try {
                tranzak_create_transaction($trx, ['id' => 42, 'phonenumber' => '+237 674 460 261']);
            } catch (TzRedirect $r) {
                $redirect = $r;
            }
            tz_ok($redirect !== null, 'the customer is returned to the order page');
            tz_contains($redirect->to, 'order/view/1', 'redirected to the order');
            tz_contains($redirect->msg, 'approve the payment request', 'told to approve on the phone');

            $post = tz_call('create-mobile-wallet-charge');
            tz_ok($post !== null, 'create-mobile-wallet-charge was called');
            tz_contains($post['url'], 'https://sandbox.dsapi.tranzak.me/xp021/v1/request/create-mobile-wallet-charge', 'sandbox endpoint');
            tz_eq($post['body']['amount'], 1000.0, 'amount charged');
            tz_eq($post['body']['currencyCode'], 'XAF', 'currency is XAF');
            tz_eq($post['body']['description'], '1 Hour Voucher', 'description is the plan name');
            tz_eq($post['body']['mchTransactionRef'], 'WIFI-1', 'mchTransactionRef from the order id');
            tz_eq($post['body']['mobileWalletNumber'], '237674460261', 'MSISDN normalised');
            tz_contains($post['body']['returnUrl'], 'order/view/1/check', 'returnUrl points at the check page');
            tz_contains($post['body']['cancelUrl'], 'order/view/1/cancel', 'cancelUrl present');
            tz_eq(tz_call_count('/request/create'), 1, 'exactly one charge created');

            $saved = $trx->to_array();
            tz_eq($saved['gateway_trx_id'], 'REQ231121CIPGSDPMO6J', 'requestId stored immediately');
            tz_ok(!empty($saved['pg_request']), 'the full response is kept in pg_request');
            tz_contains($saved['pg_url_payment'], 'order/view/1/check', 'pg_url_payment set so order.php does not bounce the check page');
            tz_eq($saved['status'], 1, 'the order is still unpaid at creation');
            tz_eq(count(Package::$recharges), 0, 'no recharge before payment completes');
        },

        'create_redirect_mode_uses_web_flow' => function () {
            tz_seed_config('sandbox');
            TzDb::config('tranzak_payment_mode', 'redirect');
            tz_reload_config();
            $trx = tz_seed_new_order();
            Http::$script = [tz_token_ok(), tz_create_ok('REQWEB1', true)];

            // A paymentAuthUrl came back, so the gateway must leave through
            // header(Location:) + exit. Everything below has to run at
            // shutdown, or it would never be reached.
            tz_on_exit(function () use ($trx) {
                $post = tz_call('/xp021/v1/request/create');
                tz_ok($post !== null, 'the web-redirect endpoint was called');
                tz_eq(tz_call_count('create-mobile-wallet-charge'), 0, 'the wallet endpoint is unused in card mode');
                tz_ok(!isset($post['body']['mobileWalletNumber']), 'no MSISDN needed for cards');
                tz_eq($trx['gateway_trx_id'], 'REQWEB1', 'requestId stored');
                tz_eq($trx['pg_url_payment'], 'https://pay.tranzak.me/flow/REQWEB1', 'paymentAuthUrl stored');
                tz_eq(count(Package::$recharges), 0, 'nothing activated before the card is paid');
            });

            tranzak_create_transaction($trx, ['id' => 42, 'phonenumber' => '674460261']);
            throw new TzFail('the gateway returned instead of redirecting to the payment page');
        },

        'create_without_valid_msisdn_is_refused' => function () {
            tz_seed_config('sandbox');
            $trx = tz_seed_new_order();
            try {
                tranzak_create_transaction($trx, ['id' => 42, 'phonenumber' => '0800']);
                throw new TzFail('an unusable phone number was accepted');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'valid Cameroon mobile number', 'customer asked to fix their profile');
                tz_eq(count(Http::$calls), 0, 'no Tranzak call was made');
            }
            tz_eq($trx['gateway_trx_id'], null, 'no requestId stored');
            tz_ok(count(Message::$sent) > 0, 'the admin is told the profile has no usable number');
        },

        'create_retry_does_not_double_charge' => function () {
            // order.php reuses the unpaid row, so create_transaction can run
            // again. Tranzak keeps mchTransactionRef for 30 days, so a retry
            // must continue the existing request, not open a second charge.
            tz_seed_config('sandbox');
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            Http::$script = [tz_token_ok(), tz_details_pending()];
            try {
                tranzak_create_transaction($trx, ['id' => 42, 'phonenumber' => '674460261']);
                throw new TzFail('expected a redirect to the check page');
            } catch (TzRedirect $r) {
                tz_contains($r->to, '/check', 'the customer is sent to check status');
            }
            tz_eq(tz_call_count('/request/create'), 0, 'no second charge created');
            tz_eq($trx['gateway_trx_id'], 'REQ231121CIPGSDPMO6J', 'the existing requestId is kept');
            tz_eq(count(Package::$recharges), 0, 'still no recharge while pending');
        },

        /* ---------- token caching ---------- */

        'token_is_cached_and_refreshed_at_75_percent' => function () {
            tz_seed_config('sandbox');
            Http::$script = [tz_token_ok()];

            $t1 = tranzak_get_token();
            tz_contains($t1, '50E41R0RDEYK1TPWE0MEWX801HM0T42K1TCEMMC0U2E1HR08', 'token returned');
            $exp = (int) TzDb::config_value('tranzak_token_expires_at');
            tz_ok($exp > time(), 'expiry stored in the future');
            tz_ok($exp <= time() + 5400, 'refreshed at 75% of expiresIn (7200), not at the full lifetime');
            tz_ok($exp >= time() + 5300, 'the refresh window is ~5400s');
            tz_eq(tz_call_count('auth/token'), 1, 'one token request');

            $t2 = tranzak_get_token();
            tz_eq($t2, $t1, 'the cached token is reused');
            tz_eq(tz_call_count('auth/token'), 1, 'still one token request');

            // A second authenticated call in the same request must not mint a
            // second token.
            tz_contains(tranzak_api_headers()[0], $t1, 'the memoized token is what gets sent');
            tz_eq(tz_call_count('auth/token'), 1, 'one request mints at most one token');

            // Expired cache: a new token is fetched.
            tranzak_clear_token();
            Http::$script = [tz_token_ok()];
            $t3 = tranzak_get_token();
            tz_eq(tz_call_count('auth/token'), 2, 'a new token is requested once the cache expired');
            tz_ok($t3 !== '', 'the refreshed token is returned');
        },

        'api_retries_once_when_token_is_rejected' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token('STALE00000000000000000000000');
            Http::$script = [
                ['success' => false, 'errorCode' => 401, 'errorMsg' => 'INVALID_ACCESS_TOKEN'],
                tz_token_ok(),
                tz_details_successful(),
            ];

            $d = tranzak_request_details('REQ231121CIPGSDPMO6J');
            tz_ok(is_array($d), 'details recovered after refreshing the token');
            tz_eq($d['status'], 'SUCCESSFUL', 'confirmed status returned');
            tz_eq(count(tz_calls_to('request/details')), 2, 'exactly one retry');
            tz_eq(tz_bearer(0), 'STALE00000000000000000000000', 'the first attempt used the cached token');
            tz_ok(tz_bearer(2) !== '', 'the retry was authenticated');
            tz_ok(tz_bearer(2) !== 'STALE00000000000000000000000', 'the retry sent a freshly minted token, not the rejected one');
            tz_eq(TzDb::config_value('tranzak_token'), tz_bearer(2), 'the newly minted token is what is cached');
        },

        /* ---------- customer "check status" ---------- */

        'get_status_activates_without_any_webhook' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            Http::$script = [tz_details_successful()];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            $msg = null;
            try {
                tranzak_get_status($trx, ['id' => 42, 'username' => 'wifiuser']);
            } catch (TzRedirect $r) {
                $msg = $r->msg;
            }
            tz_contains($msg, 'successful', 'the customer is told it went through');
            tz_eq(count(Package::$recharges), 1, 'the voucher is activated once');
            $row = tz_trx();
            tz_eq($row['status'], 2, 'the order is marked paid');
            tz_eq($row['payment_method'], 'Tranzak', 'payment method recorded');
            tz_eq($row['payment_channel'], 'MTN Momo', 'channel taken from the confirmed response');
            tz_ok(!empty($row['pg_paid_response']), 'the confirmed payload is stored');
            tz_contains($row['pg_paid_response'], 'TX23112186UW8KNF1T07', 'transactionId retained');
            tz_eq(count(Package::$recharges), 1, 'rechargeUser ran once, not once per field');
        },

        'get_status_is_idempotent_when_already_paid' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 2);
            Http::$script = [tz_details_successful()];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            try {
                tranzak_get_status($trx, ['id' => 42]);
                throw new TzFail('expected a redirect for a paid order');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'has been paid', 'the customer is told it is settled');
            }
            tz_eq(count(Package::$recharges), 0, 'no second recharge');
        },

        'get_status_pending_asks_operator_then_reports_unpaid' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            Http::$script = [tz_details_pending(), tz_details_pending()];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            try {
                tranzak_get_status($trx, ['id' => 42]);
                throw new TzFail('expected a redirect while pending');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'not completed yet', 'a plain pending message');
                tz_ok(stripos($r->msg, 'PENDING') === false, 'no raw status code shown');
            }
            tz_eq(count(Package::$recharges), 0, 'no recharge while pending');
            tz_eq(tz_call_count('refresh-transaction-status'), 1, 'the operator status was refreshed once');
            tz_eq(tz_trx()['status'], 1, 'the order stays unpaid');
        },

        'get_status_pending_but_operator_confirms_activates' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            // Tranzak had not been notified yet; the refresh returns SUCCESSFUL.
            Http::$script = [tz_details_pending(), tz_details_successful()];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            $msg = null;
            try {
                tranzak_get_status($trx, ['id' => 42]);
            } catch (TzRedirect $r) {
                $msg = $r->msg;
            }
            tz_eq(count(Package::$recharges), 1, 'a late confirmation still activates');
            tz_contains($msg, 'successful', 'the customer is informed');
            tz_eq(tz_trx()['status'], 2, 'the order is marked paid');
        },

        'get_status_redirect_required_leaves_for_the_payment_page' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            Http::$script = [tz_details_redirect_required()];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            tz_on_exit(function () use ($trx) {
                tz_eq(count(Package::$recharges), 0, 'nothing activated on a redirect-required state');
                tz_eq($trx['status'], 1, 'the order stays unpaid');
                tz_eq(tz_call_count('refresh-transaction-status'), 0, 'no pointless operator refresh');
            });

            tranzak_get_status($trx, ['id' => 42]);
            throw new TzFail('the customer was not sent back to paymentAuthUrl');
        },

        'get_status_failed_shows_plain_reason' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            tz_seed_order('REQ231121CIPGSDPMO6J', 1);
            Http::$script = [tz_details_status('FAILED')];

            $trx = new TzRow('tbl_payment_gateway', tz_trx());
            try {
                tranzak_get_status($trx, ['id' => 42]);
                throw new TzFail('expected a redirect for a failed payment');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'payment failed', 'a friendly message');
                tz_ok(stripos($r->msg, 'TXN_') === false, 'no raw Tranzak code leaked');
            }
            tz_eq(count(Package::$recharges), 0, 'no recharge on failure');
        },

        /* ---------- pure helpers ---------- */

        'msisdn_normalisation' => function () {
            $cases = [
                '674460261' => '237674460261',
                '+237 674 460 261' => '237674460261',
                '237674460261' => '237674460261',
                '00237674460261' => '237674460261',
                '0674460261' => '237674460261',
                '67 446 026 1' => '237674460261',
                '690000000' => '237690000000',
                '237690000001' => '237690000001',
                '237674000000' => '237674000000',
                '' => '',
                '0800' => '',
                '12345' => '',
                '+1 555 0100' => '',
            ];
            foreach ($cases as $in => $want) {
                tz_eq(tranzak_normalize_msisdn($in), $want, 'normalise "' . $in . '"');
            }
        },

        'customer_messages_never_leak_raw_codes' => function () {
            $codes = [
                'PAYER_INSUFF_BALANCE', 'OPERATOR_PAYER_INSUFF_BALANCE', 'PAYER_INVALID_PIN',
                'TXN_CANCELLED', 'TXN_EXPIRED', 'TXN_LIMIT_EXCEEDED', 'TXN_FAILED_OPERATOR_ERROR',
                'OPERATOR_COMM_ERROR', 'OPERATOR_INVALID_ACCOUNT_HOLDER', 'TXN_INVALID_AMOUNT',
                'SYSTEM_GENERAL_VALIDATION_ERROR', 'BENE_ACCOUNT_INVALID', 'PAYER_INVALID_RNV_KYC',
                'SOMETHING_WE_HAVE_NEVER_SEEN',
            ];
            foreach ($codes as $c) {
                $msg = tranzak_friendly_error($c);
                tz_ok(!empty($msg), 'a message exists for ' . $c);
                tz_ok(stripos($msg, $c) === false, 'the raw code ' . $c . ' is not shown');
                // Raw Tranzak codes are SCREAMING_SNAKE; prose is not.
                tz_ok(strpos($msg, '_') === false, 'no SCREAMING_SNAKE token in "' . $msg . '"');
            }
            tz_contains(tranzak_friendly_error('PAYER_INSUFF_BALANCE'), 'balance', 'insufficient balance is explained');
        },

        'status_messages_cover_every_documented_status' => function () {
            $statuses = [
                'PENDING', 'SUCCESSFUL', 'FAILED', 'CANCELLED', 'CANCELLED_BY_PAYER',
                'PAYMENT_IN_PROGRESS', 'CANCELLED/REFUNDED', 'PAYER_REDIRECT_REQUIRED',
            ];
            foreach ($statuses as $s) {
                $msg = tranzak_status_message($s, '');
                tz_ok(!empty($msg), 'a message exists for ' . $s);
                tz_ok($msg !== $s, 'the raw status is never the message');
                tz_ok(strpos($msg, '_') === false, 'no SCREAMING_SNAKE token in "' . $msg . '"');
            }
            // A recognised error code is more useful than the generic status.
            tz_contains(tranzak_status_message('FAILED', 'PAYER_INSUFF_BALANCE'), 'balance', 'the error code is used when present');
        },

        'status_reader_accepts_both_documented_field_names' => function () {
            // GET /request/details uses "status"; the TPN payloads use
            // "transactionStatus". Both must be understood.
            tz_eq(tranzak_status(['status' => 'SUCCESSFUL']), 'SUCCESSFUL', 'status field');
            tz_eq(tranzak_status(['transactionStatus' => 'SUCCESSFUL']), 'SUCCESSFUL', 'transactionStatus field');
            tz_eq(tranzak_status([]), '', 'empty payload');
            tz_eq(tranzak_status(null), '', 'null payload');
            tz_eq(tranzak_status(['status' => 'successful']), 'SUCCESSFUL', 'case insensitive');
        },

        'mch_transaction_ref_is_deterministic_and_unique_per_order' => function () {
            $a = new TzRow('tbl_payment_gateway', ['id' => 11]);
            $b = new TzRow('tbl_payment_gateway', ['id' => 12]);
            tz_eq(tranzak_mch_ref($a), 'WIFI-11', 'ref for order 11');
            tz_eq(tranzak_mch_ref($b), 'WIFI-12', 'ref for order 12');
            tz_ok(tranzak_mch_ref($a) !== tranzak_mch_ref($b), 'refs differ per order');
            tz_ok(strlen(tranzak_mch_ref($a)) <= 32, 'within the 32 char limit for a mobile wallet charge');
            tz_eq(tranzak_mch_ref($a), tranzak_mch_ref($a), 'stable across calls');
        },

        /* ---------- security / admin surface ---------- */

        'settings_page_never_renders_the_cached_token' => function () {
            $tpl = file_get_contents($GLOBALS['tz_root'] . '/system/paymentgateway/ui/tranzak.tpl');
            tz_ok(strpos($tpl, 'tranzak_token') === false, 'the cached bearer token is not rendered');
            tz_ok(strpos($tpl, 'tranzak_token_expires_at') === false, 'the token cache metadata is not rendered');
            tz_contains($tpl, 'tranzak_app_key', 'the app key field is present');
            tz_contains($tpl, 'tranzak_webhook_authkey', 'the webhook auth key field is present');
            tz_ok(substr_count($tpl, 'type="password"') >= 2, 'both secrets use masked inputs');
        },

        'gateway_file_contains_no_hardcoded_credentials' => function () {
            $src = file_get_contents($GLOBALS['tz_gateway']);
            // SAND_/PROD_ appear only as validation prefixes; a credential
            // would be a prefixed literal with a body.
            tz_ok(preg_match('/[\'"]SAND_[A-Za-z0-9]{8,}/', $src) === 0, 'no sandbox key literal');
            tz_ok(preg_match('/[\'"]PROD_[A-Za-z0-9]{8,}/', $src) === 0, 'no production key literal');
            tz_ok(preg_match('/[\'"]ap[a-z0-9]{8,}[\'"]/', $src) === 0, 'no appId literal');
        },

        'gateway_does_not_leak_credentials_to_telegram' => function () {
            tz_seed_config('sandbox');
            $key = TzDb::config_value('tranzak_app_key');
            // A provider that quotes the submitted key back must not be able to
            // push it into a Telegram chat.
            Http::$script = [['success' => false, 'errorMsg' => 'Invalid appKey ' . $key]];
            tranzak_request_token();
            tz_eq(count(Message::$sent), 1, 'the failure is reported once');
            tz_ok(strpos(Message::$sent[0], $key) === false, 'the app key never reaches Telegram');
            tz_contains(Message::$sent[0], '[redacted]', 'it is reported as redacted instead');
        },

        'csrf_is_enforced_on_the_credential_form' => function () {
            tz_seed_config('sandbox');
            $GLOBALS['tz_post'] = [
                'csrf_token' => 'wrong-token',
                'tranzak_env' => 'production',
                'tranzak_app_id' => 'hacked',
            ];
            try {
                tranzak_save_config();
                throw new TzFail('settings were saved without a valid CSRF token');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'Invalid or expired token', 'the bad token is rejected');
            }
            tz_eq(TzDb::config_value('tranzak_app_id'), 'apnz8rfqqsgoai', 'nothing was written');
            tz_eq(TzDb::config_value('tranzak_env'), 'sandbox', 'not even the environment');
            $GLOBALS['tz_post'] = [];
        },

        'blank_secret_fields_keep_the_stored_credentials' => function () {
            tz_seed_config('sandbox');
            Csrf::generateAndStoreToken();
            $GLOBALS['tz_post'] = [
                'csrf_token' => Csrf::$token,
                'tranzak_env' => 'sandbox',
                'tranzak_app_id' => 'apnz8rfqqsgoai',
                'tranzak_app_key' => '',
                'tranzak_webhook_authkey' => '',
            ];
            try {
                tranzak_save_config();
                throw new TzFail('expected a redirect after saving');
            } catch (TzRedirect $r) {
                tz_contains($r->msg, 'Saved', 'the admin is told it saved');
            }
            tz_eq(TzDb::config_value('tranzak_app_key'), 'SAND_C1B041767BBA4B5D808D91AFB18002A5', 'the stored app key survives an empty field');
            tz_eq(TzDb::config_value('tranzak_webhook_authkey'), 'jaldfjalsdjkssssssssssssssssssssss', 'the stored webhook key survives an empty field');
            $GLOBALS['tz_post'] = [];
        },

        'saving_settings_forces_a_fresh_token' => function () {
            tz_seed_config('sandbox');
            tz_seed_cached_token();
            Csrf::generateAndStoreToken();
            $GLOBALS['tz_post'] = [
                'csrf_token' => Csrf::$token,
                'tranzak_env' => 'sandbox',
                'tranzak_app_id' => 'apnz8rfqqsgoai',
                'tranzak_webhook_authkey' => 'newkey',
                'tranzak_payment_mode' => 'wallet',
            ];
            try {
                tranzak_save_config();
            } catch (TzRedirect $r) {
            }
            tz_eq(TzDb::config_value('tranzak_token'), '', 'the stale token is dropped so the new credentials are used');
            tz_eq(TzDb::config_value('tranzak_webhook_authkey'), 'newkey', 'a new webhook key is stored');
            $GLOBALS['tz_post'] = [];
        },

        'settings_page_renders_and_assigns_a_csrf_token' => function () {
            tranzak_show_config();
            tz_eq($GLOBALS['ui']->displayed, 'tranzak.tpl', 'the gateway template is displayed');
            tz_eq($GLOBALS['ui']->assigned['csrf_token'], Csrf::$token, 'a CSRF token is minted for the form');
            tz_ok(isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === Csrf::$token, 'the token is stored in the session, where the core check will look');
            tz_contains($GLOBALS['ui']->assigned['_title'], 'Tranzak', 'the page title is set');
        },
    ];
}


/* ==================================================================== *
 *  Webhook assertions, applied to the state after real HTTP deliveries
 * ==================================================================== */

function tz_assert_webhook($case, array $st)
{
    $recharged = $st['recharges'];
    $status = $st['trx'] === null ? null : $st['trx']['status'];
    $acks = $st['acks'];
    $telegram = implode(' | ', $st['telegram']);
    $last_ack = isset($acks[count($acks) - 1]) ? trim($acks[count($acks) - 1]) : '';
    $confirmations = $st['confirmations'];

    switch ($case) {

        case 'webhook_success_activates_voucher_once':
            tz_eq(count($recharged), 1, 'the voucher is activated exactly once');
            tz_eq($status, 2, 'the order is marked paid');
            tz_eq($st['trx']['payment_method'], 'Tranzak', 'payment method recorded');
            tz_eq($st['trx']['payment_channel'], 'MTN Momo', 'channel from the confirmed response');
            tz_eq($st['trx']['gateway_trx_id'], 'REQ231121CIPGSDPMO6J', 'requestId kept');
            tz_contains($st['trx']['pg_paid_response'], 'TX23112186UW8KNF1T07', 'the confirmed payload is stored');
            tz_contains($last_ack, '"success":true', 'the gateway acknowledged');
            tz_eq($recharged[0]['channel'], 'MTN Momo', 'rechargeUser got the provider payment method');
            tz_eq($recharged[0]['id_customer'], 42, 'rechargeUser got the customer');
            tz_eq($recharged[0]['plan_id'], 7, 'rechargeUser got the plan');
            tz_eq($recharged[0]['router'], 'l009-hq', 'rechargeUser got the router');
            break;

        case 'webhook_forged_authkey_does_not_activate':
            tz_eq(count($recharged), 0, 'a forged authKey must not activate anything');
            tz_eq($status, 1, 'the order stays unpaid');
            tz_contains($last_ack, 'authKey mismatch', 'rejected explicitly');
            tz_contains($telegram, 'authKey mismatch', 'the admin is alerted');
            tz_eq(count($confirmations), 0, 'not even a confirmation call for an unauthenticated payload');
            break;

        case 'webhook_claiming_success_before_confirmation_does_not_activate':
            tz_eq(count($recharged), 0, 'the body status alone must not activate');
            tz_eq($status, 1, 'the order stays unpaid');
            tz_eq(count($confirmations), 1, 'the server was asked exactly once');
            tz_contains($last_ack, 'PENDING', 'the ack reports the server-confirmed status, not the body');
            break;

        case 'webhook_amount_mismatch_does_not_activate':
            tz_eq(count($recharged), 0, 'a different amount must not activate');
            tz_eq($status, 1, 'the order stays unpaid');
            tz_contains($telegram, 'amount mismatch', 'the mismatch is reported to the admin');
            tz_ok(strpos($last_ack, '"success":true') === false, 'the failure is not acknowledged as a success');
            break;

        case 'webhook_unknown_request_id_is_ignored':
            tz_eq(count($recharged), 0, 'an unknown requestId activates nothing');
            tz_eq($status, 1, 'the order stays unpaid');
            tz_contains($last_ack, 'unknown requestId', 'the ack says so');
            tz_eq(count($confirmations), 0, 'no confirmation call for an unknown order');
            break;

        case 'webhook_failed_status_closes_order_without_recharge':
            tz_eq(count($recharged), 0, 'a failed payment never activates');
            tz_eq($status, 3, 'the order is closed as failed so the customer can retry');
            tz_contains($last_ack, 'closed as FAILED', 'the ack reports the confirmed status');
            break;

        case 'webhook_ignores_other_event_types':
            tz_eq(count($recharged), 0, 'an event this endpoint does not subscribe to is ignored');
            tz_eq($status, 1, 'the order stays unpaid');
            tz_contains($last_ack, '"message":"ignored"', 'the ack says it was ignored');
            tz_eq(count($confirmations), 0, 'no confirmation call for an event we do not handle');
            break;

        case 'webhook_after_manual_activation_is_a_noop':
            tz_eq(count($recharged), 0, 'an already-paid order is not recharged');
            tz_eq($status, 2, 'it stays paid');
            tz_contains($last_ack, 'already activated', 'the ack says it was a no-op');
            break;

        case 'webhook_duplicate_delivery_activates_only_once':
            tz_eq(count($acks), 2, 'both deliveries were answered');
            tz_eq(count($recharged), 1, 'rechargeUser ran exactly once across both deliveries');
            tz_eq($status, 2, 'the order is paid');
            tz_contains($acks[0], '"message":"activated"', 'the first delivery activates');
            tz_contains($acks[1], '"message":"already activated"', 'the second delivery is a safe no-op');
            break;
    }
}


/* ==================================================================== *
 *  Runner plumbing
 * ==================================================================== */

/** The null device for the current platform, to silence a child process. */
function tz_null_device()
{
    return (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
}

/** Run one in-process case in a CLI subprocess. */
function tz_run_process($case)
{
    // The array form is deliberate: with a command string proc_open goes
    // through cmd.exe on Windows, so a stray grandchild would outlive us.
    $cmd = [PHP_BINARY, __FILE__, '--case', $case];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['stopped' => 'error', 'error' => 'could not spawn php'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $pos = strrpos($stdout, '__TZRESULT__');
    if ($pos === false) {
        return [
            'stopped' => 'error',
            'error' => 'no result marker' . ($stderr !== '' ? ' (stderr: ' . trim($stderr) . ')' : ''),
            'raw_output' => trim($stdout),
        ];
    }
    $res = json_decode(substr($stdout, $pos + strlen('__TZRESULT__')), true);
    if (!is_array($res)) {
        return ['stopped' => 'error', 'error' => 'unparseable result', 'raw_output' => trim($stdout)];
    }
    $res['raw_output'] = $stdout;
    return $res;
}

/**
 * Start the built-in web server used for the webhook deliveries.
 *
 * The array command form matters: with a string, proc_open launches cmd.exe on
 * Windows and proc_terminate() kills cmd.exe instead of the server, which
 * leaves a listening process behind after the suite has finished.
 */
function tz_server_start()
{
    $router = __DIR__ . '/tranzak_webhook_endpoint.php';
    $docroot = __DIR__;
    $null = tz_null_device();

    for ($port = 8731; $port < 8800; $port++) {
        $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($probe) {
            fclose($probe);
            continue;
        }
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router];
        $desc = [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $docroot);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        for ($t = 0; $t < 50; $t++) {
            usleep(100000);
            $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($probe) {
                fclose($probe);
                return [
                    'proc' => $proc,
                    'port' => $port,
                    'base' => 'http://127.0.0.1:' . $port . '/tranzak_webhook_endpoint.php',
                ];
            }
            if (!proc_get_status($proc)['running']) {
                break;
            }
        }
        proc_terminate($proc);
        proc_close($proc);
    }
    return null;
}

function tz_server_stop($server)
{
    if ($server && is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
}

/** POST a JSON body to the webhook endpoint, the way Tranzak would. */
function tz_post_webhook($server, $case, $payload, $state_file)
{
    $url = $server['base'] . '?case=' . rawurlencode($case) . '&state=' . rawurlencode($state_file);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => is_string($payload) ? $payload : json_encode($payload),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => (string) $body];
}


/* ==================================================================== *
 *  Entry point
 * ==================================================================== */

/* ---- child: run exactly one in-process case ---- */
if (($argv[1] ?? null) === '--case') {
    $case = $argv[2] ?? '';
    $cases = tz_process_cases();
    if (!isset($cases[$case])) {
        fwrite(STDERR, 'unknown case: ' . $case . "\n");
        exit(2);
    }

    tz_strict_errors();
    tz_boot_gateway();
    $GLOBALS['tz_exit_checks'] = [];
    require $GLOBALS['tz_gateway'];

    // Runs for a normal return and for header()+exit(), so a case can assert
    // on state the gateway only leaves behind by terminating the request.
    register_shutdown_function(function () {
        if (isset($GLOBALS['tz_reported'])) {
            return;
        }
        $stopped = 'completed';
        foreach ($GLOBALS['tz_exit_checks'] as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                $stopped = 'assertion-failed';
                tz_report(['stopped' => $stopped, 'error' => $e->getMessage()]);
                return;
            }
        }
        tz_report();
    });

    try {
        $cases[$case]();
    } catch (TzFail $e) {
        if (!isset($GLOBALS['tz_reported'])) {
            tz_report(['stopped' => 'assertion-failed', 'error' => $e->getMessage()]);
        }
    } catch (Throwable $e) {
        if (!isset($GLOBALS['tz_reported'])) {
            tz_report(['stopped' => 'error', 'error' => get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine()]);
        }
    }
    exit(0);
}

/* ---- parent: run the suite ---- */

$arg = $argv[1] ?? null;
$process_cases = array_keys(tz_process_cases());
$webhook_cases = array_keys(tz_webhook_cases());

if ($arg === '--list') {
    foreach (array_merge($process_cases, $webhook_cases) as $n) {
        echo $n . "\n";
    }
    exit(0);
}

$only = $arg;

echo "Tranzak payment gateway - test suite\n";
echo str_repeat('=', 66) . "\n\n";

$pass = 0;
$fail = 0;
$failed = [];

/* ---------- in-process cases ---------- */
foreach ($process_cases as $name) {
    if ($only !== null && $only !== $name) {
        continue;
    }
    $res = tz_run_process($name);
    $ok = is_array($res) && !in_array($res['stopped'], ['error', 'assertion-failed'], true);
    if ($ok) {
        echo '  ok     ' . $name . "\n";
        $pass++;
        continue;
    }
    echo '  FAIL   ' . $name . "\n";
    echo '         ' . ($res['error'] ?? 'stopped: ' . ($res['stopped'] ?? '?')) . "\n";
    if (!empty($res['http_calls'])) {
        echo '         http: ' . implode(', ', array_unique($res['http_calls'])) . "\n";
    }
    if (!empty($res['telegram'])) {
        echo '         telegram: ' . implode(' | ', $res['telegram']) . "\n";
    }
    $fail++;
    $failed[] = $name;
}

/* ---------- webhook cases, delivered over real HTTP ---------- */
$selected = array_values(array_filter($webhook_cases, function ($n) use ($only) {
    return $only === null || $only === $n;
}));

if (!empty($selected)) {
    echo "\n" . str_repeat('-', 66) . "\n";
    echo "webhook cases (real HTTP POST to the PHP built-in server)\n\n";

    $server = tz_server_start();
    if ($server === null) {
        echo "  ERROR  could not start the PHP built-in web server\n";
        exit(2);
    }

    try {
        foreach ($selected as $name) {
            $payloads = tz_webhook_payloads();
            $state = tempnam(sys_get_temp_dir(), 'tzstate');
            $acks = [];
            $err = null;

            for ($i = 0; $i < tz_webhook_deliveries($name); $i++) {
                $r = tz_post_webhook($server, $name, $payloads[$name], $state);
                $acks[] = trim($r['body']);
                if ($r['status'] !== 200) {
                    $err = 'HTTP ' . $r['status'] . ': ' . substr($r['body'], 0, 300);
                    break;
                }
            }

            $snap = json_decode((string) @file_get_contents($state), true);
            if (is_file($state)) {
                unlink($state);
            }

            if ($err === null && !is_array($snap)) {
                $err = 'the endpoint persisted no state';
            }
            if ($err === null) {
                $rows = array_values($snap['tables']['tbl_payment_gateway'] ?? []);
                $confirmations = [];
                foreach (($snap['http_calls'] ?? []) as $c) {
                    if (strpos($c['url'], 'request/details') !== false) {
                        $confirmations[] = $c;
                    }
                }
                $st = [
                    'recharges' => $snap['recharges'] ?? [],
                    'trx' => $rows ? $rows[0] : null,
                    'acks' => $acks,
                    'telegram' => $snap['telegram'] ?? [],
                    'confirmations' => $confirmations,
                ];
                try {
                    tz_assert_webhook($name, $st);
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                }
            }

            if ($err !== null) {
                echo '  FAIL   ' . $name . "\n";
                echo '         ' . $err . "\n";
                if (!empty($acks)) {
                    echo '         ack: ' . implode(' || ', $acks) . "\n";
                }
                $fail++;
                $failed[] = $name;
                continue;
            }
            echo '  ok     ' . $name . "\n";
            $pass++;
        }
    } finally {
        tz_server_stop($server);
    }
}

echo "\n" . str_repeat('=', 66) . "\n";
echo 'passed: ' . $pass . '   failed: ' . $fail . "\n";
if ($fail > 0) {
    echo "failing:\n  - " . implode("\n  - ", $failed) . "\n";
    exit(1);
}
echo "all green\n";
exit(0);
