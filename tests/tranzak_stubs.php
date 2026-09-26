<?php

/**
 * Shared test doubles and fixtures for the Tranzak gateway test suite.
 *
 * Loaded by both tests/tranzak_test.php (the runner) and
 * tests/tranzak_webhook_endpoint.php (the front controller served by the PHP
 * built-in web server), so the stubs exist once.
 *
 * Http, the ORM and Package::rechargeUser are stubbed: the suite needs no
 * network and no database. Tranzak payloads are the verbatim examples from
 * https://docs.developer.tranzak.me
 */


/* ==================================================================== *
 *  Assertions
 * ==================================================================== */

class TzFail extends Exception
{
}

function tz_ok($cond, $msg)
{
    if (!$cond) {
        throw new TzFail($msg);
    }
}

function tz_eq($actual, $expected, $msg)
{
    if ($actual !== $expected) {
        throw new TzFail($msg . ' | expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function tz_contains($haystack, $needle, $msg)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new TzFail($msg . ' | ' . var_export($needle, true) . ' not found in ' . var_export($haystack, true));
    }
}

/**
 * Turn every notice, warning and deprecation into a failure. The production
 * error_reporting level is not what CI runs on, and a notice inside the
 * gateway is a real bug that would otherwise be invisible here.
 */
function tz_strict_errors()
{
    error_reporting(E_ALL);
    set_error_handler(function ($no, $str, $file, $line) {
        if (strpos((string) $file, 'tranzak_stubs.php') !== false || strpos((string) $file, 'tranzak_test.php') !== false) {
            return false;
        }
        throw new TzFail('PHP ' . $no . ': ' . $str . ' in ' . basename($file) . ':' . $line);
    });
}


/* ==================================================================== *
 *  Stub: r2() signal (stands in for the real header()+exit)
 * ==================================================================== */

class TzRedirect extends Exception
{
    public $to;
    public $ntype;
    public $msg;
    public function __construct($to, $ntype, $msg)
    {
        $this->to = $to;
        $this->ntype = $ntype;
        $this->msg = $msg;
        parent::__construct('redirect ' . $to . ' [' . $ntype . '] ' . $msg);
    }
}


/* ==================================================================== *
 *  Stub: fake database
 *
 *  Idiorm models are read and written both ways ($trx['price'] and
 *  $trx->price), so the double implements ArrayAccess as well as __get.
 * ==================================================================== */

class TzRow implements ArrayAccess
{
    private $table;
    private $id;
    private $attrs;

    public function __construct($table, $attrs)
    {
        $this->table = $table;
        $this->attrs = $attrs;
        $this->id = isset($attrs['id']) ? $attrs['id'] : null;
    }
    public function __get($k)
    {
        return array_key_exists($k, $this->attrs) ? $this->attrs[$k] : null;
    }
    public function __set($k, $v)
    {
        $this->attrs[$k] = $v;
    }
    public function __isset($k)
    {
        return isset($this->attrs[$k]);
    }
    public function offsetExists($k)
    {
        return array_key_exists($k, $this->attrs);
    }
    #[\ReturnTypeWillChange]
    public function offsetGet($k)
    {
        return array_key_exists($k, $this->attrs) ? $this->attrs[$k] : null;
    }
    public function offsetSet($k, $v)
    {
        if ($k === null) {
            $this->attrs[] = $v;
        } else {
            $this->attrs[$k] = $v;
        }
    }
    public function offsetUnset($k)
    {
        unset($this->attrs[$k]);
    }
    public function save()
    {
        TzDb::$saves++;
        if ($this->id === null) {
            $this->id = TzDb::$next_id++;
            $this->attrs['id'] = $this->id;
        }
        TzDb::put($this->table, $this->id, $this->attrs);
        return true;
    }
    public function id()
    {
        return $this->id;
    }
    public function to_array()
    {
        return $this->attrs;
    }
}

class TzQuery
{
    private $table;
    private $filters = [];

    public function __construct($table)
    {
        $this->table = $table;
    }
    public function where($col, $val)
    {
        $this->filters[$col] = $val;
        return $this;
    }
    public function whereRaw($sql, $args = [])
    {
        return $this;
    }
    public function find_one($id = null)
    {
        foreach (TzDb::rows($this->table) as $attrs) {
            if ($id !== null && $attrs['id'] != $id) {
                continue;
            }
            $match = true;
            foreach ($this->filters as $c => $v) {
                if (!array_key_exists($c, $attrs) || (string) $attrs[$c] !== (string) $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return new TzRow($this->table, $attrs);
            }
        }
        return false;
    }
    public function create()
    {
        return new TzRow($this->table, ['id' => null]);
    }
}

class TzDb
{
    public static $tables = [];
    public static $next_id = 1;
    public static $saves = 0;

    public static function reset()
    {
        self::$tables = ['tbl_appconfig' => [], 'tbl_payment_gateway' => []];
        self::$next_id = 1;
        self::$saves = 0;
    }
    public static function rows($t)
    {
        if (!isset(self::$tables[$t])) {
            self::$tables[$t] = [];
        }
        return array_values(self::$tables[$t]);
    }
    public static function put($t, $id, $attrs)
    {
        self::$tables[$t][$id] = $attrs;
    }
    /** Insert or update a tbl_appconfig row, mirroring the gateway's upsert. */
    public static function config($key, $value)
    {
        foreach (self::rows('tbl_appconfig') as $r) {
            if ($r['setting'] === $key) {
                self::$tables['tbl_appconfig'][$r['id']]['value'] = $value;
                return;
            }
        }
        $id = self::$next_id;
        self::$next_id++;
        self::$tables['tbl_appconfig'][$id] = ['id' => $id, 'setting' => $key, 'value' => $value];
    }
    public static function config_value($key)
    {
        foreach (self::rows('tbl_appconfig') as $r) {
            if ($r['setting'] === $key) {
                return $r['value'];
            }
        }
        return '';
    }
}

class ORM
{
    public static function for_table($t)
    {
        return new TzQuery($t);
    }
}


/* ==================================================================== *
 *  Stub: HTTP client driven by a scripted response queue
 * ==================================================================== */

class Http
{
    public static $script = [];
    public static $calls = [];

    public static function reset()
    {
        self::$script = [];
        self::$calls = [];
    }
    private static function next($url, $body, $method, $headers = [])
    {
        self::$calls[] = ['url' => $url, 'body' => $body, 'method' => $method, 'headers' => $headers];
        if (empty(self::$script)) {
            return json_encode(['success' => false, 'errorMsg' => 'no scripted response']);
        }
        $item = array_shift(self::$script);
        if (is_callable($item)) {
            return $item($url, $body, $method, $headers);
        }
        return is_string($item) ? $item : json_encode($item);
    }
    public static function getData($url, $headers = [], $connect_timeout = 3000, $wait_timeout = 3000)
    {
        return self::next($url, null, 'GET', $headers);
    }
    public static function postJsonData($url, $array_post, $headers = [], $basic = null, $connect_timeout = 3000, $wait_timeout = 3000)
    {
        return self::next($url, $array_post, 'POST', $headers);
    }
    public static function postData($url, $array_post, $headers = [], $basic = null, $connect_timeout = 3000, $wait_timeout = 3000)
    {
        return self::next($url, $array_post, 'POST', $headers);
    }
}

/** The bearer token that was sent on the nth recorded call, '' when none. */
function tz_bearer($n)
{
    if (!isset(Http::$calls[$n]['headers'])) {
        return '';
    }
    foreach (Http::$calls[$n]['headers'] as $h) {
        if (stripos($h, 'Authorization: Bearer ') === 0) {
            return substr($h, strlen('Authorization: Bearer '));
        }
    }
    return '';
}

/** Recorded calls whose URL contains $url_part. */
function tz_calls_to($url_part)
{
    $out = [];
    foreach (Http::$calls as $c) {
        if (strpos($c['url'], $url_part) !== false) {
            $out[] = $c;
        }
    }
    return $out;
}


/* ==================================================================== *
 *  Stub: framework services
 * ==================================================================== */

class Message
{
    public static $sent = [];
    public static function reset()
    {
        self::$sent = [];
    }
    public static function sendTelegram($txt, $chat_id = null, $topik = '')
    {
        self::$sent[] = $txt;
        return true;
    }
    public static function logMessage($txt)
    {
        return true;
    }
}

class Package
{
    public static $recharges = [];
    public static $recharge_result = true;
    public static function reset()
    {
        self::$recharges = [];
        self::$recharge_result = true;
    }
    public static function rechargeUser($id_customer, $router_name, $plan_id, $gateway, $channel, $note = '')
    {
        self::$recharges[] = [
            'id_customer' => $id_customer,
            'router' => $router_name,
            'plan_id' => $plan_id,
            'gateway' => $gateway,
            'channel' => $channel,
        ];
        return self::$recharge_result;
    }
}

class Lang
{
    public static function T($key)
    {
        return $key;
    }
    public static function moneyFormat($v)
    {
        return (string) $v;
    }
}

/**
 * Mirrors system/autoload/Csrf.php: the token lives in $_SESSION and the check
 * is only enforced when the site has csrf_enabled = yes.
 */
class Csrf
{
    public static $token = 'tztesttoken0000000000000000';
    public static function generateToken($length = 16)
    {
        return self::$token;
    }
    public static function generateAndStoreToken()
    {
        $_SESSION['csrf_token'] = self::$token;
        $_SESSION['csrf_token_time'] = time();
        return self::$token;
    }
    public static function check($token)
    {
        global $config, $isApi;
        if ((isset($config['csrf_enabled']) ? $config['csrf_enabled'] : 'no') !== 'yes' || !empty($isApi)) {
            return true;
        }
        if (isset($_SESSION['csrf_token'], $_SESSION['csrf_token_time'], $token)) {
            return hash_equals($_SESSION['csrf_token'], (string) $token);
        }
        return false;
    }
    public static function clearToken()
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    }
}


/* ==================================================================== *
 *  Stub: global helpers
 * ==================================================================== */

if (!defined('U')) {
    define('U', 'https://billing.test/?_route=');
}

function _post($param, $defvalue = '')
{
    return isset($GLOBALS['tz_post'][$param]) ? $GLOBALS['tz_post'][$param] : $defvalue;
}
function _get($param, $defvalue = '')
{
    return $defvalue;
}
function _log($description, $type = '', $userid = '0')
{
    return true;
}
function r2($to, $ntype = 'e', $msg = '')
{
    throw new TzRedirect($to, $ntype, $msg);
}

class TzUi
{
    public $assigned = [];
    public $displayed = null;
    public function assign($k, $v)
    {
        $this->assigned[$k] = $v;
    }
    public function display($tpl)
    {
        $this->displayed = $tpl;
    }
}


/* ==================================================================== *
 *  Fixtures: verbatim Tranzak payloads
 * ==================================================================== */

function tz_token_ok()
{
    return [
        'data' => [
            'scope' => 'collections',
            'appId' => 'apnz8rfqqsgoai',
            'token' => '50E41R0RDEYK1TPWE0MEWX801HM0T42K1TCEMMC0U2E1HR08',
            'expiresIn' => 7200,
        ],
        'success' => true,
    ];
}

/** REQUEST.COMPLETED webhook, SUCCESSFUL (verbatim from "Webhook Details"). */
function tz_webhook_successful($auth_key = 'jaldfjalsdjkssssssssssssssssssssss', $request_id = 'REQ231121CIPGSDPMO6J')
{
    return [
        'name' => 'Tranzak Payment Notification (TPN)',
        'version' => '1.0',
        'eventType' => 'REQUEST.COMPLETED',
        'merchantId' => null,
        'appId' => 'apnz8rfqqsgoai',
        'resourceId' => $request_id,
        'resource' => [
            'requestId' => $request_id,
            'amount' => 1000,
            'currencyCode' => 'XAF',
            'description' => 'API Demo',
            'mobileWalletNumber' => '237674460261',
            'status' => 'SUCCESSFUL',
            'transactionStatus' => 'SUCCESSFUL',
            'createdAt' => '2023-11-21T03:09:48+00:00',
            'mchTransactionRef' => 'WIFI-1',
            'appId' => 'apnz8rfqqsgoai',
            'transactionId' => 'TX23112186UW8KNF1T07',
            'transactionTime' => '2023-11-21T03:09:51+00:00',
            'payer' => [
                'paymentMethod' => 'MTN Momo',
                'currencyCode' => 'XAF',
                'countryCode' => 'CM',
                'accountId' => '237674460261',
            ],
            'merchant' => [
                'currencyCode' => 'XAF',
                'amount' => 1000,
                'fee' => 20,
                'netAmountReceived' => 980,
            ],
            'links' => ['returnUrl' => 'https://MY_CALLBACK_URL/tranzak_tpn'],
        ],
        'creationDateTime' => '2023-11-21 03:09:52',
        'webhookId' => 'WHM9G4RW7DDXMLIXU6EBZ5',
        'authKey' => $auth_key,
    ];
}

/** REQUEST.COMPLETED webhook, FAILED (verbatim from "Webhook Details"). */
function tz_webhook_failed()
{
    $p = tz_webhook_successful();
    $p['resourceId'] = 'REQ231121BKKJYX5PLZ8';
    $p['resource']['requestId'] = 'REQ231121BKKJYX5PLZ8';
    $p['resource']['mobileWalletNumber'] = '237674000000';
    $p['resource']['status'] = 'FAILED';
    $p['resource']['transactionStatus'] = 'FAILED';
    $p['resource']['errorCode'] = 5002;
    $p['resource']['errorMessage'] = 'SYSTEM_GENERAL_VALIDATION_ERROR';
    unset($p['resource']['transactionId'], $p['resource']['transactionTime'], $p['resource']['payer'], $p['resource']['merchant']);
    return $p;
}

/** GET /request/details, SUCCESSFUL. */
function tz_details_successful($amount = 1000)
{
    return [
        'data' => [
            'requestId' => 'REQ231121CIPGSDPMO6J',
            'amount' => $amount,
            'currencyCode' => 'XAF',
            'description' => 'API Demo',
            'status' => 'SUCCESSFUL',
            'createdAt' => '2023-11-21T03:09:48+00:00',
            'mchTransactionRef' => 'WIFI-1',
            'appId' => 'apnz8rfqqsgoai',
            'transactionId' => 'TX23112186UW8KNF1T07',
            'transactionTime' => '2023-11-21T03:09:51+00:00',
            'fee' => 0,
            'payer' => [
                'name' => '+237680657567',
                'paymentMethod' => 'MTN Momo',
                'currencyCode' => 'XAF',
                'countryCode' => 'CM',
            ],
            'merchant' => [
                'currencyCode' => 'XAF',
                'amount' => $amount,
                'fee' => 0,
                'netAmountReceived' => $amount,
            ],
            'links' => ['returnUrl' => ''],
        ],
        'success' => true,
    ];
}

/** GET /request/details, PENDING. */
function tz_details_pending()
{
    return [
        'data' => [
            'requestId' => 'REQ231121CIPGSDPMO6J',
            'amount' => 1000,
            'currencyCode' => 'XAF',
            'description' => 'API Demo',
            'status' => 'PENDING',
            'creationTime' => '2023-11-21T03:09:48+00:00',
            'mchTransactionRef' => 'WIFI-1',
            'appId' => 'apnz8rfqqsgoai',
            'createdAt' => '2023-11-21T03:09:48+00:00',
            'links' => [
                'returnUrl' => 'https://billing.test/?_route=order/view/1/check',
                'paymentAuthUrl' => 'https://pay.tranzak.me/flow/REQ231121CIPGSDPMO6J',
            ],
        ],
        'success' => true,
    ];
}

function tz_details_redirect_required()
{
    $d = tz_details_pending();
    $d['data']['status'] = 'PAYER_REDIRECT_REQUIRED';
    return $d;
}

function tz_details_status($status, $amount = 1000)
{
    $d = tz_details_successful($amount);
    $d['data']['status'] = $status;
    unset($d['data']['transactionId'], $d['data']['transactionTime']);
    return $d;
}

function tz_create_ok($request_id = 'REQ231121CIPGSDPMO6J', $with_auth_url = false)
{
    $data = [
        'requestId' => $request_id,
        'amount' => 1000,
        'currencyCode' => 'XAF',
        'description' => 'API Demo',
        'status' => 'PENDING',
        'mchTransactionRef' => 'WIFI-1',
        'appId' => 'apnz8rfqqsgoai',
        'createdAt' => '2023-11-21T03:09:48+00:00',
        'links' => ['returnUrl' => 'https://billing.test/?_route=order/view/1/check'],
    ];
    if ($with_auth_url) {
        $data['links']['paymentAuthUrl'] = 'https://pay.tranzak.me/flow/' . $request_id;
    }
    return ['data' => $data, 'success' => true];
}


/* ==================================================================== *
 *  Fixture: configured gateway + one pending order
 * ==================================================================== */

function tz_seed_config($env = 'sandbox')
{
    $key = ($env === 'production')
        ? 'PROD_91AFB18002A5C1B041767BBA4B5D808D91AFB18MJU89'
        : 'SAND_C1B041767BBA4B5D808D91AFB18002A5';
    TzDb::config('csrf_enabled', 'yes');
    TzDb::config('tranzak_env', $env);
    TzDb::config('tranzak_app_id', 'apnz8rfqqsgoai');
    TzDb::config('tranzak_app_key', $key);
    TzDb::config('tranzak_webhook_authkey', 'jaldfjalsdjkssssssssssssssssssssss');
    TzDb::config('tranzak_payment_mode', 'wallet');
    tz_reload_config();
}

/**
 * A gateway that is already authenticated against Tranzak, so a case only has
 * to script the call it actually cares about.
 */
function tz_seed_cached_token($token = 'CACHEDTOKEN0000000000000000', $expires_in = 5000)
{
    TzDb::config('tranzak_token', $token);
    TzDb::config('tranzak_token_expires_at', (string) (time() + $expires_in));
    tz_reload_config();
}

function tz_seed_order($gateway_trx_id = 'REQ231121CIPGSDPMO6J', $status = 1, $price = 1000)
{
    TzDb::put('tbl_payment_gateway', 1, [
        'id' => 1,
        'username' => 'wifiuser',
        'user_id' => 42,
        'gateway' => 'tranzak',
        'gateway_trx_id' => $gateway_trx_id,
        'plan_id' => 7,
        'plan_name' => '1 Hour Voucher',
        'routers_id' => 2,
        'routers' => 'l009-hq',
        'price' => $price,
        'pg_url_payment' => 'https://billing.test/?_route=order/view/1/check',
        'payment_method' => '',
        'payment_channel' => '',
        'pg_request' => '',
        'pg_paid_response' => '',
        'expired_date' => null,
        'created_date' => '2026-09-26 10:00:00',
        'paid_date' => null,
        'status' => $status,
    ]);
    tz_reload_config();
}

function tz_seed_new_order($price = 1000)
{
    $trx = new TzRow('tbl_payment_gateway', [
        'id' => null, 'username' => 'wifiuser', 'user_id' => 42, 'gateway' => 'tranzak',
        'plan_id' => 7, 'plan_name' => '1 Hour Voucher', 'routers_id' => 2,
        'routers' => 'l009-hq', 'price' => $price, 'status' => 1,
        'created_date' => '2026-09-26 10:00:00',
    ]);
    $trx->save();
    $trx['id'] = 1;
    return $trx;
}

/** Mirror init.php: tbl_appconfig rows populate the global $config. */
function tz_reload_config()
{
    global $config;
    $config = [];
    foreach (TzDb::rows('tbl_appconfig') as $r) {
        $config[$r['setting']] = $r['value'];
    }
}

function tz_trx()
{
    $rows = TzDb::rows('tbl_payment_gateway');
    return empty($rows) ? null : $rows[0];
}

function tz_call($url_part)
{
    foreach (Http::$calls as $c) {
        if (strpos($c['url'], $url_part) !== false) {
            return $c;
        }
    }
    return null;
}

function tz_call_count($url_part)
{
    $n = 0;
    foreach (Http::$calls as $c) {
        if (strpos($c['url'], $url_part) !== false) {
            $n++;
        }
    }
    return $n;
}


/* ==================================================================== *
 *  Cross-request state (models a persistent database)
 * ==================================================================== */

function tz_state_load($file)
{
    if (!is_file($file)) {
        return;
    }
    $s = json_decode(file_get_contents($file), true);
    if (!is_array($s)) {
        return;
    }
    TzDb::$tables = $s['tables'] ?? TzDb::$tables;
    TzDb::$next_id = $s['next_id'] ?? 1;
    Package::$recharges = $s['recharges'] ?? [];
    Package::$recharge_result = $s['recharge_result'] ?? true;
    // Appended, never replaced: the same Telegram alert can be re-sent by a
    // duplicate delivery and both copies matter.
    Message::$sent = array_merge(Message::$sent, $s['telegram'] ?? []);
    Http::$calls = array_merge(Http::$calls, $s['http_calls'] ?? []);
}

function tz_state_save($file)
{
    file_put_contents($file, json_encode([
        'tables' => TzDb::$tables,
        'next_id' => TzDb::$next_id,
        'recharges' => Package::$recharges,
        'recharge_result' => Package::$recharge_result,
        'telegram' => Message::$sent,
        'http_calls' => Http::$calls,
    ]));
}

/** Never echo a real credential back out of the harness. */
function tz_redact_config()
{
    $out = [];
    foreach (TzDb::rows('tbl_appconfig') as $r) {
        $secret = in_array($r['setting'], ['tranzak_app_key', 'tranzak_webhook_authkey', 'tranzak_token'], true);
        $out[$r['setting']] = $secret ? '<redacted>' : $r['value'];
    }
    return $out;
}
