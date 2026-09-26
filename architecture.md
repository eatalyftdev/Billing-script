# PHPNuxBill Architecture

> PHPNuxBill (a.k.a. "PHP Mikrotik Billing") is an open‑source billing / hotspot
> management system built for Internet Service Providers that use (but are not
> limited to) **MikroTik** routers. It supports Hotspot and PPPoE, multiple
> payment gateways, vouchers, a customer self‑service portal, an admin panel,
> an optional FreeRADIUS backend (via `radius.php`), a REST JSON API, a plugin
> system, and a one‑click updater.
>
> This document was generated from a static scan of the repository at
> `F:\Git Projects\Billing script` (version `2025.3.20` from `version.json`).
> It captures the **current** state of the codebase so that upcoming changes can
> be understood in context.
>
> **Note:** the filename uses the corrected spelling `architecture.md`
> (the request said `archticyure.md`, which appears to be a typo).

---

## 1. Technology Stack

| Layer | Technology | Notes |
|-------|-----------|-------|
| Language | **PHP 8.2+** | Minimum version per `README.md`. |
| Web | Apache (any PSR‑compatible web server) | Uses query routing. |
| Database | **MySQL 4.1+** | Two connections: billing DB + optional RADIUS DB. |
| ORM | **Idiorm** (vendored in `system/orm.php`) | Single‑class, no config. `ORM::for_table(...)->...`. |
| Templating | **Smarty 4.5.3** (Composer) | `$ui = new Smarty()` in `boot.php`. |
| HTTP | **PHP‑CURL** | Wrapped by the `Http` class. |
| PDF | **mPDF (`^8.1`)** (Composer) | Used by `Invoice` class. |
| MikroTik API | **PEAR2 Net RouterOS** (vendored) | Talk to routers over the Mikrotik API protocol. |
| Image / OCR | PHP‑GD2 | Captcha / QR / image resizing. |
| Dependencies | Composer (`system/vendor`) | `smarty/smarty`, `mpdf/mpdf`, `yosiazwan/php-facedetection`. |
| Task scheduling | **Cron** (`system/cron.php`, `cron_reminder.php`) | Linux cron recommended; Windows possible but harder. |

The project ships a vendored `system/vendor/` (Composer) **and** self‑hosted
libraries under `system/autoload/` (PEAR2 RouterOS, mail libraries). The
composer autoloader at `system/vendor/autoload.php` is included from
`index.php`.

## 2. High-Level Architecture

```
                      ┌──────────────────────────────────────────────┐
                      │              Web Request (HTTP)                 │
                      └──────────────────┬───┬───┬─────────────────────┘
                                         │   │   │
          ┌──────────────────────────────┘   │   └──────────────────────────────┐
          │          Browser / Admin UI        │   Customer portal / API  │
          ▼                                         ▼
   ┌──────────────┐                         ┌──────────────┐
   │  index.php   │ (session + nux-* GET )  │  system/api.php │ (REST JSON)
   └──────┬───────┘                         └──────┬───────┘
          │                                        │
   ┌──────▼──────┐                       ┌─────────▼─────────┐
   │  init.php   │ ◄──── bootstrap & core helpers   │ init.php │ (shared)
   │  (web)      │                       └─────────▼─────────┘
   └──────┬──────┘                                 │
          │                                        │
   ┌──────▼──────┐            Radius/CRON   ┌──────▼──────┐
   │  boot.php   │◄─────Smarty+dispatch      │ radius.php  │◄── FreeRADIUS
   │  (routing   │                           │ system/cron │◄── cron.php
   │  engine)    │                           └─────────────┘
   └──────┬──────┘
          │
   ┌──────▼──────────────────────────┐
   │  system/controllers/<handler>.php  │  (one file per route → "controller")
   └──────┬──────────────────────────┘
          │  uses: autoload lib classes, ORM (billing DB),
          │        Device drivers, Plugins (hooks), Smarty view
          ▼
   ┌──────┴──────┐   ┌──────────────┐   ┌──────────────┐
   │ Smarty View │   │ Device Layer │   │  Radius DB   │ (optional 2nd DB)
   │ (ui/ui/*)   │   │ (devices/*)  │   └──────────────┘
   └─────────────┘   └──────────────┘
```

**Key characteristics**
- A single front controller pattern is used for the **web UI** (`index.php` →
  `boot.php` → `init.php`). The **API** reuses the same `init.php` and
  `system/controllers/` handlers but renders through a lightweight stub object
  instead of Smarty (see §9).
- There is **no separate Model layer directory/file per entity**; persistence
  is done inline via the Idiorm `ORM::for_table('tbl_*')` calls inside
  controllers and the `autoload/` service classes.
- Routing, auth, and view rendering are centralized in `boot.php`, while
  per‑feature logic lives in `system/controllers/<name>.php` "page controller"
  files.
- The codebase mixes **Procedural** and **static OOP** styles. Core services are
  static utility classes (e.g. `Text`, `Lang`, `Http`, `Validator`); persistence
  is ORM‑based.

## 3. Directory Layout

```
Billing script/                # web root (== document root)
├── index.php                  # web UI entry point (session + boot)
├── init.php                   # core bootstrap, helpers, DB, config, lang
├── config.sample.php          # template config (copied to config.php at install)
├── radius.php                 # FreeRADIUS authorize/authenticate/accounting endpoint
├── update.php                 # one‑click self‑updater (downloads master.zip)
├── version.json               # current app version
├── composer.json              # root package metadata (no require here)
├── Dockerfile / docker-compose.example.yml   # optional containerization
├── CHANGELOG.md               # release history (date‑based versions)
├── README.md                  # features & requirements
├── .htaccess_firewall         # security headers / hardening
│
├── install/                   # web installer (wizard: step2–step5)
│   ├── index.php              # installer entry
│   ├── phpnuxbill.sql         # billing DB schema
│   ├── radius.sql             # FreeRADIUS DB schema
│
├── qrcode/                    # QR code generation library + cache
├── scan/                      # standalone HTML QR‑code scanner page (index.php)
│
├── system/                    # application heart
│   ├── boot.php               # Smarty setup + routing dispatcher
│   ├── orm.php                # vendored Idiorm ORM
│   ├── cron.php               # main scheduled job (recharges, expiry, cleanup)
│   ├── cron_reminder.php      # reminder notifications scheduler
│   ├── autoload.php           # controller autoloader (web UI AJAX)
│   ├── autoload_user.php      # controller autoloader (customer AJAX)
│   ├── api.php                # API endpoint wrapper
│   ├── updates.json           # update manifest
│   ├── .htaccess              # deny direct access
│   ├── autoload/              # core framework/library classes (static OOP)
│   ├── controllers/           # PAGE CONTROLLERS — one per route
│   ├── devices/               # NETWORK DEVICE DRIVERS (strategy pattern)
│   ├── widgets/               # dashboard widget fragments
│   ├── plugin/                # installed plugins (self-register via Hookers)
│   ├── paymentgateway/        # payment-gateway modules
│   ├── vendor/                # Composer dependencies (smarty, mpdf, …)
│   ├── cache/                 # cache files (plugin_repository.json, installer, …)
│   ├── uploads/               # uploads (logo, invoices, notifications.json)
│   ├── lan/                   # language packs (english.json, arabic.json, …)
│   └── widgets/ui_custom/     # custom template overrides
│
├── ui/                        # user‑interface assets
│   ├── ui/                    # main Smarty template tree (admin/, customer/, sections/)
│   ├── ui_custom/             # CUSTOM templates (override layer — "custom" namespace)
│   ├── themes/                # optional themes (namespace "theme")
│   ├── conf/                  # Smarty config dir
│   └── cache/, compiled/      # Smarty cache & compiled templates
```

## 4. Request Lifecycle

### 4.1 Web UI flow (`index.php`)
1. **`index.php`** — `session_start()`, stashes MikroTik captive‑portal params
   (`nux-mac`, `nux-ip`, `nux-router`, `nux-key`, `nux-hostname`) into the
   session, then includes `system/vendor/autoload.php` (Composer) and
   `system/boot.php`.
2. **`boot.php`** — `require_once 'init.php'`, instantiates Smarty as `$ui`
   with a **multi‑tiered template‑dir chain** (see §7), parses the `_route`
   request URI into `$routes[]` (`$handler = $routes[0]`), resolves the current
   admin via `Admin::_info()`, then `include`s
   `system/controllers/<handler>.php`.
   - Unknown routes → 404 (or redirect to login for document navigations).
   - Top‑level `try/catch` reports errors to `Message::sendTelegram()` and
     renders an admin or customer error template.
3. **`init.php`** (included by `boot.php`) — the real bootstrap:
   - Defines `$root_path`, path constants (`$DEVICE_PATH`, `$UPLOAD_PATH`,
     `$CACHE_PATH`, `$PAGES_PATH`, `$PLUGIN_PATH`, `$WIDGET_PATH`,
     `$PAYMENTGATEWAY_PATH`, `$UI_PATH`), loads `config.php`, includes the
     Idiorm `orm.php`, registers the `PEAR2\Net\RouterOS` autoloader and
     `Hookers.php`.
   - Configures **two ORM connections**: the primary billing DB
     (`mysql:host=$db_host;dbname=$db_name`) and, when RADIUS is enabled, an
     optional second connection keyed `radius`
     (`mysql:host=$radius_host;dbname=$radius_name`).
   - Loads `tbl_appconfig` rows into the global `$config` array.
   - Resolves language (session cookie → user attribute → `english` default),
     loads `system/lan/<lang>.json` into `$_L`, and **writes back auto‑discovered
     keys** (so new keys are persisted automatically).
   - Auto‑includes every `*.php` in `system/plugin/` (plugins self‑register via
     `register_menu()` / `register_hook()`).
   - Defines a rich set of **helper functions** used across controllers:
     `_get/_post/_req` (sanitized input), `_auth()` (customer gate), `_admin()`
     (admin gate), `r2()` (redirect + flash notify), `_alert()` (modal alert),
     `_log()` (audit), `Lang()`, `getUrl()`, `sendTelegram()/sendSMS()/sendWhatsapp()`,
     `showResult()`, `generateUniqueNumericVouchers()`, `isTableExist()`.
   - Initializes an empty user‑agent session log record on first hit.
4. **Controller** (`system/controllers/<handler>.php`) — performs auth checks
   (`_admin()` / `_auth()`), reads `$routes`, mutates data via ORM, assigns
   Smarty variables (`$ui->assign(...)`), and `$ui->display('admin/<page>.tpl')`
   (or customer template). Many controllers end with `run_hook('view_…')`.

### 4.2 API flow (`system/api.php`)
1. CORS pre‑flight handled (OPTIONS/HEAD).
2. `$isApi = true`, includes `../init.php` (shared bootstrap).
3. Replaces Smarty with a **tiny stub object** whose `assign()` stores values
   internally and `getAll()` serializes ORM results to arrays →
   `showResult()` JSON (`{success,message,result,meta}`).
4. Token auth: accepts `_req('token')` = `type.uid.time.sha1(uid.time.api_secret)`
   (types `a`=admin, `c`=customer), or a hard API key from config; validates the
   SHA‑1 signature and a 3‑month expiry window.
5. Reuses the **same controller files** in `system/controllers/<handler>.php`;
   controllers detect the API context through the `$isApi` global and emit JSON
   via `showResult()` instead of HTML.

### 4.3 RADIUS flow (`radius.php`)
1. Includes `init.php` (no `$isApi`).
2. Dispatches on `$_SERVER['HTTP_X_FREERADIUS_SECTION']` or `?action=` →
   `authorize`, `authenticate`, `accounting`, `authorize_user_cleanup`, etc.
3. Returns JSON via `show_radius_result()` with FreeRADIUS reply attributes
   (`control:Auth-Type`, `reply:*`).
4. Supports CHAP/PAP, voucher login (username == password hash), data & time
   limits, per‑plan bandwidth rates, and optionally delegates to a device
   driver for live checks.

### 4.4 Cron / scheduled jobs
- **`system/cron.php`** — long‑running batch processor: plan expiry /
  deactivation, auto‑renew using customer balance, voucher stock checks,
  MikroTik sync (`Package`/`Device::sync_customer`), reminder dispatch, RADIUS
  accounting cleanup.
- **`system/cron_reminder.php`** — notification reminders (unpaid, expiring).
- **`system/widgets/cron_monitor.php`** — reports cron health into
  `tbl_widgets_monitor`.
- Designed to run from **Linux cron** (Windows "hard to set cronjob" per README).

## 5. Routing

- Routes are parsed from the request path (or `?_route=`). The first path
  segment is the **controller name** (`system/controllers/<name>.php`); the
  second segment (`$routes[1]`) is the **action/verb**, dispatched internally
  with a `switch` on `$action`/`$do` (e.g. `case 'post'`, `case 'register'`,
  `default:` display).
- Friendly URLs: when `config['url_canonical'] == 'yes'` URLs become
  search‑engine friendly (path based) instead of `?_route=`.
- Routes are exposed to templates (`$ui->assign('_routes', $routes)`) and to
  `Paginator` for link building.
- Controllers that are AJAX/backends (`autoload.php`,
  `autoload_user.php`, `default_info_row.php`, etc.) `die()` after echoing
  JSON/HTML fragments.

## 6. Authentication & Authorization

**Two independent identity stacks** — Admin and Customer — each cookie + session
based:

### Admin (`Admin` class, `system/autoload/Admin.php`)
- `getID()` reads `$_SESSION['aid']` / `$_COOKIE['aid']` where the cookie is a
  signed `id.time.sha1(id.time.$db_pass)` token.
- **Single‑session** support: when `config['single_session'] == 'yes'`, the
  token in `tbl_users.login_token` must match `sha1(cookie)` (forced logout on
  second login). Otherwise any token is accepted.
- **Session timeout**: optional `session_timeout_duration` (minutes); cookie
  validity capped at 7 days.
- `setCookie()` writes `aid`, `login_token`, and sets `aid_expiration`.
- `_admin()` helper gates page controllers.

### Customer (`User` class, `system/autoload/User.php`)
- `getID()` reads `$_SESSION['uid']` / `$_COOKIE['uid']` (same `id.time.sha1`
  scheme, 30‑day cookie lifetime).
- `_auth()` helper gates customer controllers.
- Customer attributes (lang, extra bill fields, etc.) stored in
  `tbl_customers_fields` (EAV) via `Meta`-like helpers on `User`.
- Voucher‑only login supported (username == voucher code).

### Shared
- **CSRF** (`Csrf`): `generateAndStoreToken()` stores a 32‑byte hex token in
  session; `Csrf::check()` validates it on POSTs (30‑minute expiry, toggleable
  via `config['csrf_enabled']`). Applied in `admin.php` login and throughout
  forms.
- **Password hashing**: `sha1()` for stored passwords (legacy), with
  `Password::chap_verify()` for MikroTik CHAP + RADIUS compatibility.
- **Authorization** matrix: user types `SuperAdmin`, `Admin`, `Report`,
  `Agent`, `Sales` — checked via `in_array($admin['user_type'], [...])` and the
  `auth` field on registered menus.

## 7. Templating (Smarty)

`boot.php` builds `$ui` with a **template fallback chain**:

1. `custom` → `ui/ui_custom/` (user overrides; safe from updates).
2. `theme` → `ui/themes/<config['theme']>/` (themed overrides, if a non‑default
   theme is set; otherwise omitted).
3. `default` → `ui/ui/` (the shipped templates).

Additional template namespaces:
- `pg` → `system/paymentgateway/ui/` (payment gateway templates).
- `plugin` → `system/plugin/ui/` (plugin templates).

Views are split by **audience**:
- `ui/ui/admin/` — admin backend (subfolders mirror controllers:
  `customers/`, `settings/`, `reports/`, `vpn/`, …).
- `ui/ui/customer/` — customer self‑service portal
  (`login.tpl`, `register.tpl`, `dashboard.tpl`, `orderPlan.tpl`, …).
- `ui/ui/sections/` — shared `header.tpl` / `footer.tpl` (admin & public
  variants), `user-header.tpl` / `user-footer.tpl`.
- `ui/ui/user-ui/` — alternate customer header/footer used by some views.

Common helper templates: `admin/alert.tpl` (modal redirect), `admin/error.tpl`,
`customer/error.tpl`, `admin/maintenance.tpl`, pagination via `Paginator`.

## 8. Data Layer

- **ORM**: The vendored **Idiorm** (`system/orm.php`) provides a fluent,
  single‑table ActiveRecord‑style API:
  ```php
  ORM::for_table('tbl_customers')->where('status','Active')->find_one();
  ORM::for_table('tbl_plans')->find_many();
  ->select(...)->left_outer_join(...)->where(...)->find_many();
  ```
  `create()` / `save()` / `delete()` mutate records; `find_array()` returns
  raw rows. `ORM::raw_execute()` runs arbitrary SQL.
- **Two DB connections**: primary billing DB + optional `radius` connection
  (configured only when `$radius_user` & `$config['radius_enable']` are set).
- **Schema** lives in `install/phpnuxbill.sql` (billing) and
  `install/radius.sql` (FreeRADIUS). Tables are prefixed `tbl_` (billing) and
  use the FreeRADIUS convention for the radius DB.
- **Custom fields / extensions**: `tbl_meta` is an EAV table accessed through
  the `Meta` class — lets plugins add data to any row without schema changes.
- **Custom customer fields** are persisted as a JSON manifest
  (`system/uploads/customer_field.json`) and rendered through
  `User::setFormCustomField()` / `getFormCustomField()`.
- Connections default to **utf8mb4 / utf8** charset; the billing connection is
  set at bootstrap via `ORM::configure('connection_string', ...)`.

## 9. Device / Router Drivers

`system/devices/*.php` implement a tiny **strategy interface**. A plan's
`device` column names the driver class; `Package::getDevice($plan)` resolves
the file (defaulting `MikrotikHotspot` for Hotspot, `MikrotikPppoe` for
PPPoE, or `Radius`/`RadiusRest` for the RADIUS path). Drivers implement
methods consumed by `Package::rechargeUser()` and the dashboard/AJAX live‑status
checks:

- `add_customer($customer, $plan)`
- `sync_customer($customer, $plan)`
- `remove_customer($customer, $plan)`
- `online_customer($customer, $router)` → bool (presence check)

Drivers talk to routers either over the **MikroTik API** (`PEAR2\Net\RouterOS`
Client via `Mikrotik::getClient()`) or over **FreeRADIUS REST** (`RadiusRest`).
`$_app_stage == 'demo'`/`'Demo'` short‑circuits live device calls (safe
demo mode).

### 9.1 Core autoload service classes

The 15 framework classes in `system/autoload/` each own a narrow responsibility.

`App.php` — token/voucher helpers, `_run()` stub and a bootstrap for
plugin‑style “App” modules.

`Admin.php` — admin session + signed-cookie auth (`setCookie`,
`getID`), single‑session enforcement, CSRF‑aware login flow.

`User.php` — customer auth (same signed‑cookie scheme, 30‑day cookie),
self‑service attribute helpers and EAV custom‑field rendering.

`File.php` — cross‑platform path/file helpers (`pathFixer` resolves Windows
backslashes to web‑safe forward slashes and normalizes the root path).

`Http.php` — cURL wrapper for GET/POST/JSON outbound requests, with proxy and
timeout handling (used by payment gateways, GeoIP, notifications).

`Lang.php` — i18n: loads `system/lan/<lang>.json` into `$_L`, exposes
`Lang::T()` / `Lang::dateTimeFormat()` / `Lang::moneyFormat()` /
`Lang::getNotifText()`, and lazily persists newly‑discovered keys back to the
JSON file (with optional Google‑Translate fallback).

`Text.php` — URL builder / sanitizer / formatter: `getUrl()`, `r2()` route
builder, input sanitization for search/select2 lookups.

`Validator.php` — static input validation helpers (email, url, ip, phone,
number, date, older‑than, image‑size).

`Csrf.php` — CSRF token generation (`generateAndStoreToken`) and
`check()` (30‑min expiry; toggleable via `config['csrf_enabled']`).

`Password.php` — `sha1()` hashing/verification plus `chap_verify()` for
MikroTik CHAP and RADIUS compatibility.

`Hookers.php` — `register_menu()` (admin/customer positions),
`register_hook()` / `run_hook()` for the plugin hook bus.

`Mikrotik.php` — low‑level RouterOS connection helpers: `getClient()`,
`info()` (router record lookup), and shared add/remove/unsync helpers used by
the device drivers.

`Message.php` — notification dispatcher: `sendTelegram()`, `sendSMS()`,
`sendWhatsapp()`, `sendEmail()`, `logMessage()`, and customer‑facing helpers
such as `sendBalanceNotification()` / `sendOrderNotification()`.

`Meta.php` — EAV helper around `tbl_meta` for ad‑hoc extension data on any
record.

`Log.php` — audit‑log writer (`_log()` helper) into `tbl_logs`.

`Widget.php` — (stub) dashboard‑widget helper hooks.

`Balance.php` — customer balance ledger: credit/debit and
`transfer($fromId,$username,$amount)`.

`Invoice.php` — mPDF invoice PDF generation plus email/SMS notification and
PDF caching under `system/uploads/`.

`Package.php` — the reconciliation heart: `getDevice($plan)`,
`rechargeUser($customer,$plan,$pay,$order_row)`, plan lookup, expired‑plan
fallback, and live device sync.

`Paginator.php` — server‑side pagination: reads the `p` query param, builds
`LIMIT/OFFSET`, and renders HTML `<nav>` link markup.

`Timezone.php` — timezone selection list for forms.

`Text.php` — URL builder / sanitizer / formatter (dedup note above).

## 10. FreeRADIUS Integration

Two coexistence modes:

1. **Local DB mode** — MikroTik talks directly to PHPNuxBill (`index.php` with
   `nux-mac/ip/router/key/hostname` captive‑portal params); PHPNuxBill applies
   the rate plan to the router and records accounting in `tbl_user_recharges`.
2. **FreeRADIUS mode** — `radius.php` is the RADIUS authorize/authenticate/
   accounting endpoint (pointed at by `authorize`/`authenticate` site
   configs in `mods-available/`). It validates credentials (incl. CHAP &
   voucher login), computes bandwidth/time/data limits per plan, and returns
   RADIUS reply attributes. An optional **second DB connection** (`radius`)
   lets PHPNuxBill query the same `radcheck`/`radacct` tables.

The CHANGELOG notes RADIUS REST support was added to enable quota‑based and
shared‑user checks against the RADIUS DB.

## 11. Plugin & Payment‑Gateway Extensibility

### Plugin system
- **Registration**: files in `system/plugin/*.php` are `include`d during
  `init.php` startup; each self‑registers using `Hookers.php`:
  - `register_menu($name, $admin, $function, $position, $icon, $label, $color, $auth)`
  - `register_hook($action, $function)` / `run_hook($action, $args)`
- **Hook points** sprinkled in controllers, e.g. `admin_login`,
  `view_login`, `view_dashboard`, `send_telegram`, `send_sms`, etc.
- **Menus** are rendered in `boot.php` based on `$menu_registered`, honoring
  the `admin` flag + `auth` roles, and producing `_MENU_<POSITION>` template
  variables (positions like `AFTER_DASHBOARD`, `CUSTOMERS`, `SETTINGS`, …;
  customer positions `ORDER`, `HISTORY`, `ACCOUNTS`).
- **Templates** served from the `plugin` Smarty namespace
  (`system/plugin/ui/`).

### Payment gateways
- Modules live in `system/paymentgateway/` (and `system/plugin/`) with their
  own `ui/` templates under the `pg` namespace.
- Installed/managed by `pluginmanager.php` controller — downloads ZIPs from the
  GitHub plugin repository (`hotspotbilling.github.io/Plugin-Repository/`),
  extracts via `ZipArchive`, and copies `plugin`/`paymentgateway`/`theme`/
  `device` sub‑folders to the right destinations.
- Gateways record transactions in `tbl_payment_gateway` and call into
  `Package::rechargeUser()` on success. Payment audit logs are kept
  (`Message::logMessage` / `tbl_message_logs`).

#### Tranzak (Cameroon mobile money + card), installed as `system/paymentgateway/tranzak.php`
- Self‑contained: no existing gateway, core file or device driver was touched.
  `callback.php` already `include`s `system/paymentgateway/{action}.php` and
  calls `{action}_payment_notification()`, so the webhook needs no routing
  change, and `paymentgateway.php` discovers the module automatically.
- Admin page `paymentgateway/tranzak` (Smarty `pg` namespace,
  `system/paymentgateway/ui/tranzak.tpl`) writes to `tbl_appconfig`:
  `tranzak_env` (`sandbox`/`production`), `tranzak_app_id`, `tranzak_app_key`,
  `tranzak_webhook_authkey`, `tranzak_payment_mode` (`wallet`/`redirect`), plus
  the internal cache rows `tranzak_token` / `tranzak_token_expires_at`. The
  cache rows are never rendered; both secrets are write‑only fields (empty
  keeps the stored value) and the form is CSRF‑checked.
- Key prefixes are validated against the selected environment (`SAND_` /
  `PROD_`) and the bearer token is cached until 75% of its `expiresIn`, with a
  per‑request memo and one retry with a fresh token on a rejected one. Because
  `$config` is a bootstrap snapshot, `tranzak_save_setting()` also updates it,
  so a value written earlier in a request is never read back stale.
- Data flow: checkout posts `amount`/`currencyCode` (`XAF`)/
  `mchTransactionRef` (`WIFI-{order_id}`) to
  `xp021/v1/request/create-mobile-wallet-charge` (or `/create` in redirect
  mode) and persists `requestId` into `gateway_trx_id` immediately, so a
  webhook arriving mid‑flight already matches. `pg_url_payment` is set to the
  check page for a direct wallet charge, because `order.php` bounces
  `/order/view/{id}/check` back to the buy page while it is empty.
- Status: `tranzak_get_status()` re‑reads `xp021/v1/request/details`, and
  `refresh-transaction-status` when still `PENDING`/`PAYMENT_IN_PROGRESS`
  (mobile money operators do not always notify in time). A
  `PAYER_REDIRECT_REQUIRED` answer sends the customer back to `paymentAuthUrl`.
  `FAILED`/`CANCELLED*` close the order (status 3) so they can retry.
- Webhook `REQUEST.COMPLETED` at `{site}/?_route=callback/tranzak`: the static
  `authKey` is a sanity check only, and the deciding status is always re‑fetched
  server side. Activation re‑reads the row, short‑circuits on status 2, and
  verifies `requestId`, `mchTransactionRef`, amount and currency before
  `Package::rechargeUser()`, so duplicate deliveries, a customer clicking
  "check status" at the same moment, and a forged/early payload cannot produce
  two vouchers. Provider text is mapped to plain language before it reaches the
  customer, and credentials are stripped from everything sent to Telegram.
- Tests: `tests/tranzak_test.php` (36 cases, no dependencies). In‑process cases
  run in a CLI subprocess because the gateway ends requests with `r2()` or
  `header()+exit`; webhooks are delivered as real HTTP POSTs to
  `php -S`, because `php://input` is empty under the CLI SAPI, with a state file
  standing in for the database. `Http`, the ORM and `Package::rechargeUser` are
  stubbed, so no network and no database are needed.
- Not implemented (deliberately): refunds/payouts. Tranzak settles payouts in
  multiples of 10 XAF, which a future payout module would have to respect.

### One‑click updater
- `update.php` downloads the master branch ZIP, extracts to
  `system/cache/`, and merges files into the web root with a multi‑step flow
  (`?step=`). Gated on write permissions for `system/cache/` and the web root
  and on admin session.

## 12. Cross‑Cutting Concerns

| Concern | Where | Notes |
|---------|-------|-------|
| **i18n** | `Lang` + `system/lan/*.json` | Keys look up `$_L`; unknown keys are auto‑written back to the JSON file (lazy translation) with an optional Google Translate fallback for non‑English. |
| **Notifications** | `Message` | Telegram (`telegram_bot`/`telegram_target_id`), SMS (URL template or MikroTik `/tool sms send`), WhatsApp (`WhatsApp-Gateway-...`), E‑mail (PHPMailer). Errors reported to Telegram. |
| **Security** | `Csrf`, `Admin`/`User`, `.htaccess_firewall` | CSRF tokens (30‑min), signed `sha1` auth cookies, single‑session, HttpOnly+SameSite cookie flags, session timeout config, `.htaccess` deny‑by‑default in `system/` sub‑folders. |
| **Audit/logging** | `_log()` / `tbl_logs`, `Log`, `Message::logMessage` | Logs description, user type, user id, client IP (incl. Cloudflare/XFF/forwarded headers). |
| **Error handling** | `boot.php` try/catch | Catches in web, `api.php`, `radius.php`; non‑fatal plugin errors are swallowed at load time. |
| **Validation** | `Validator` | Reusable static checks (email, url, ip, phone, number, date, older‑than, image size). |
| **Pagination** | `Paginator` | Reads `p` query param; outputs HTML `<nav>` links; also used in API mode to return arrays. |

## 13. Key Request/Response Contracts

- **Web UI**: `GET /?_route=<controller>/<action>&<params>`
  (canonical if `url_canonical=yes`). Controllers `r2()`‑redirect with a
  session flash (`ntype` + `notify`) shown via notification templates.
- **API**: `GET|POST /system/api.php?r=<controller>/<action>&token=…`
  → JSON `{success, message, result, meta}`. API reuses web controllers; the
  `$isApi` flag swaps HTML output for `showResult()` JSON.
- **RADIUS**: POST/GET to `radius.php?action=<section>` with
  `HTTP_X_FREERADIUS_SECTION` header → JSON radius attributes
  (`show_radius_result()`).
- **MikroTik captive portal**: GET `index.php?nux-mac=…&nux-ip=…&nux-router=…`
  seeds the session so the customer can connect through `home.php` without
  re‑entering MAC/IP.

## 14. Operational / Deployment Notes

- **Config**: copy `config.sample.php` → `config.php`; set DB credentials,
  `api_secret`, payment gateway settings, etc. (wizard writes this in
  `install/step5.php`).
- **Permissions**: `system/cache/`, `system/uploads/`, web root, and
  `ui/ui/compiled/`, `ui/ui/cache/` must be web‑writable (checked by
  `pluginmanager.php` installer and `update.php`).
- **Cron** (Linux): `*/5 * * * * php /path/system/cron.php` and a reminder job.
- **Docker**: `Dockerfile` + `docker-compose.example.yml` provided.
- **Demo mode**: `$_app_stage == 'Demo'`/`'demo'` disables live MikroTik and
  device writes across `Mikrotik`, `MikrotikHotspot`, `Message`, and the
  device drivers.
- **Maintenance mode**: `config['maintenance_mode']` +
  `maintenance_date`; `displayMaintenanceMessage()` serves a 503 page (with
  optional forced customer logout).

### 14.1 Install once, then deploy with git

The installer is a one‑time bootstrap, not an update path. After the first
install every server is updated with `git pull` plus, if the schema changed, an
explicit migration.

- `config.php` and the database are per‑server and gitignored. Create them once
  per server, never commit them.
- Do **not** use the root `update.php` on a git deployment. It downloads the
  upstream `master` ZIP and merges it, which silently overwrites local work.
  `install/update.php` is a hardcoded legacy upgrader, not a general migration
  tool.
- The web installer must be unreachable once installed; see §14.2.

### 14.2 Installer hazards (verified on PHP 8.0.30 / MySQL, Sept 2026)

`install/` shipped with four defects. All four are now fixed in this repository;
they are documented because the fixes are local patches to vendor files and will
conflict on the next upstream merge.

1. **`step4.php` had no lock and is destructive.** It imports
   `install/phpnuxbill.sql`, which opens with `DROP TABLE IF EXISTS` for all 21
   tables, so anyone who could reach the installer could wipe the database —
   after which `step5.php` hands them a fresh administrator account. Now locked
   by `install/guard.php` plus `install/.htaccess`; see 14.5.
2. **`step4.php` ignored the Application URL field.** It wrote
   `define("APP_URL", $protocol . $host . $baseDir)` computed from its *own*
   request path, so `APP_URL` always came out ending in `/install`. Since
   `init.php` derives `U` from `APP_URL` and every asset URL and redirect uses
   it, the result was a broken app. It now writes the URL submitted in step 3 as
   a literal, and step 3 marks the field `required` so a blank value is rejected
   rather than silently producing a request-derived URL.
3. **`step5.php` deletes `pages_template/`.** It copies the directory to
   `pages/` and then calls `removeDir($sourceDir)`. `pages_template` is tracked
   in git, so committing straight after an install deletes it from the
   repository and the next fresh clone has nothing to install from. Restore it
   with `git checkout -- pages_template`.
4. **Working-directory-relative paths.** `step4.php` wrote `../config.php` and
   read `phpnuxbill.sql`, and `step5.php` resolved the `pages` copy through
   `$_SERVER['DOCUMENT_ROOT']`. All of these now use `__DIR__`. The
   `DOCUMENT_ROOT` case was the dangerous one: on cPanel the app normally lives
   outside the docroot, or the docroot is repointed at the app, so the wizard
   looked for `pages_template` in the wrong tree, threw, printed the error
   inline — and still reported success, leaving the site installed with no
   `pages/` directory at all.

`APP_URL` must also be a literal rather than `dirname($_SERVER['SCRIPT_NAME'])`
whenever the app is not at the domain root: the value is also used for
`system/api.php`, whose `SCRIPT_NAME` differs from the UI's. A request-derived
`APP_URL` resolves to `.../system` for API requests, producing a doubled
`/system/system/api.php` in `U`.

### 14.5 The installer lock, and why it is two layers

The installer is reachable exactly once: before `config.php` exists, and never
again. Two independent mechanisms enforce that, and each is written so that its
own failure mode is the safe one.

| Layer | Mechanism | Fails by |
| --- | --- | --- |
| `install/guard.php` | `file_exists(__DIR__ . '/.installed')`, then redirect and `exit` | staying reachable — inert, not destructive |
| `install/.htaccess` | `<IfFile ".installed">` → `Require all denied` | nothing; PHP still holds the lock |

`install/step5.php` writes `.installed` as its final action, which re-arms the
lock automatically. **There is no manual step after installing** — no file to
rename, nothing to remember, and no window in which a finished install is left
exposed.

The key detail: the marker is *not* `config.php`. `step4.php` writes
`config.php` at line 86, before `step5.php` has run, so a `config.php`-based
lock would lock the operator out of the final page of the wizard they are still
running.

`<IfFile>` was measured to be a **silent no-op** on Apache 2.4 with
`AllowOverride All` — a bare relative filename is not resolved to the `.htaccess`
directory the way `<FilesMatch>` patterns are. It is retained only for hosts
where it does work, and must never be treated as the lock. The PHP guard is the
lock.

`install/.htaccess` additionally denies `*.sql`, `*.md`, `*.ini`, `*.log` and
`update.php` **unconditionally**, with no dependence on `<IfFile>`. The wizard
reads the `.sql` files from disk via `file_get_contents()`, which `.htaccess`
does not affect, so this costs the installer nothing — and without it a deployed
server publishes its entire database schema at a fixed, well-known path.

To deliberately re-run the installer: take a database backup, then delete
`install/.installed` and remove `install/.htaccess`. Restore both afterwards.


### 14.3 Access‑control files must be in git

`.htaccess` is gitignored, which silently strips access control from any server
set up with `git clone`. Three files are therefore un‑ignored on purpose:

| File | Why it must ship |
|------|------------------|
| `install/.htaccess` | Denies the installer; without it the database can be wiped (§14.2). |
| `system/.htaccess` | Denies direct web access to `system/*.php`, with explicit exceptions for `api.php`, `cron.php`, `cron_reminder.php`. |
| `pages/.htaccess` | CORS headers the page builder depends on. |

`pages/` stays ignored, so `pages/.htaccess` needs the three‑line idiom in
`.gitignore` (`!pages/`, then `pages/*`, then `!pages/.htaccess`) — git never
descends into an excluded directory, so a bare `!pages/.htaccess` is ignored.

These rules are Apache‑only. On nginx the equivalent `location` blocks have to
be written by hand.

The same "git never descends into an excluded directory" trap applies to
`system/uploads/`. `system/uploads/**` silently excluded the subdirectories
themselves, which made the existing `!system/uploads/sms/index.html` negations
no‑ops, and six required files were missing from every clone:

| File | Consequence of omitting it |
|------|----------------------------|
| `notifications.default.json` | **Total outage.** `init.php:63` hard‑fails without it, so every page 500s, not just admin. |
| `user.default.jpg` | Broken avatar on every customer without a photo (`onerror` fallback in 6 templates). |
| `index.html` (4 dirs) | Directory listings become browsable. |

`.gitignore` now re‑includes the subdirectories explicitly before negating the
files inside them, and the required defaults are allow‑listed. The fix is
verified with `git add --dry-run`; runtime artefacts (`sms/send.log`,
`_sysfrm_tmp_/tmp1`, `cache/*`, uploaded invoices) remain ignored.

**The general lesson:** in this repository, any file the application *reads* must
be verified as tracked. `git ls-files --others --ignored --exclude-standard`
lists everything that exists locally but would not ship — cross-reference it
against the paths in the code before deploying to a new server. A missing
default asset is a live outage and a missing `.htaccess` is a security hole, and
both fail silently at runtime rather than at deploy time.

`config.php` is ignored by design: it holds per‑server credentials and must never
be committed. Each server gets its own, written by the installer or by hand.

### 14.4 Local development note (XAMPP)

If another project already claims `ServerName localhost` in
`apache/conf/extra/httpd-vhosts.conf`, it overrides the default document root
and every path under it, so a junction inside `htdocs` is never reached. PHPNuxBill
is therefore served by its own vhost on port 8080 with the project folder as the
document root, which needs no `hosts` file edit (editing
`C:\Windows\System32\drivers\etc\hosts` requires elevation). `APP_URL` must be
`http://localhost:8080` to match.

`system/lan/english.json` is a tracked file that the application **rewrites at
runtime** to add missing translation keys, so it shows up as modified after any
page render. That is expected, not a local edit.

## 15. File‑by‑File Reference of Core Entry Points

| File | Role |
|------|------|
| `index.php` | Web UI entry: session + nux params → Composer autoload → `boot.php`. |
| `init.php` | Bootstrap: paths, config, Idiorm (2 DBs), plugins, language, helpers. |
| `system/boot.php` | Smarty init (template chain) + routing dispatcher + menu build + error handler. |
| `system/controllers/<name>.php` | Page controllers (≈40 files, one per route). |
| `system/api.php` | JSON API front controller (stub `$ui`, token auth). |
| `radius.php` | FreeRADIUS authorize/authenticate/accounting endpoint. |
| `update.php` | One‑click updater (multi‑step ZIP download/extract/merge). |
| `config.php` | Generated user config (DB creds, secrets, feature flags). |
| `system/autoload/*.php` | Framework service classes + helpers. |
| `system/devices/*.php` | Router/device driver strategies. |
| `system/widgets/*.php` | Dashboard widget fragments. |
| `system/cron.php` | Scheduled reconciliation & expiry jobs. |
| `install/*` | Web installer + SQL schemas. |

---

*This document is intended as a living reference. Where the codebase is large
and fast‑moving (controllers like `customers.php`, `plan.php`, `services.php`,
`settings.php` are 15–55 KB each), consult the source directly for exact
parameter names and DB column usage. Sections map to the numbered headers
(§1–§15); the `autoload/` service‑class inventory lives under §9.1.*

