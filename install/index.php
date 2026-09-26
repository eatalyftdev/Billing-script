<?php
/**
 * PHPNuxBill installer front controller.
 *
 * WHY THIS EXISTS (cPanel / no-terminal deploys):
 * The post-install hardening file `.htaccess_firewall` (renamed to `.htaccess`
 * on the live server) contains:
 *
 *     <Files *.php> Deny from all </Files>
 *     <Files index.php> Allow from all </Files>
 *     ...
 *
 * `<Files index.php>` matches the BASENAME in EVERY directory, so
 * `/install/index.php` is allowed while `/install/step2.php`, `step3.php`,
 * `step4.php`, `step5.php`, `update.php` are all denied with 403. There is
 * deliberately no `install/.htaccess` to override it (see ARCHITECTURE.md
 * §14.5 -- LiteSpeed was measured to misapply <Files>/<IfFile> rules to files
 * they did not name, failing closed and taking the wizard down).
 *
 * This file is therefore the ONLY installer URL the wizard needs. Every step
 * is reachable as `install/index.php?s=N` (N = 2,3,4,5 or `update`), which
 * executes the corresponding `stepN.php`/`update.php` IN-PROCESS via include,
 * so the browser never requests a denied basename. Direct `step*.php` URLs
 * still work on hosts without the firewall file and are kept for backwards
 * compatibility.
 *
 * Deploy model: pure tracked PHP, no .htaccess change, no terminal needed --
 * `git push` + cPanel "Git Version Control > Pull/Deploy" is enough.
 */
require __DIR__ . '/guard.php';

$allowed = [
    '2'      => 'step2.php',
    '3'      => 'step3.php',
    '4'      => 'step4.php',
    '5'      => 'step5.php',
    'update' => 'update.php',
];

$step = isset($_GET['s']) ? strtolower(trim((string) $_GET['s'])) : '';
// Backwards-compatible alias: ?step=2 works exactly like ?s=2.
if ($step === '' && isset($_GET['step'])) {
    $step = strtolower(trim((string) $_GET['step']));
}

if ($step !== '' && isset($allowed[$step])) {
    // Execute the step in-process so the URL stays on index.php (allowed).
    // Each step file re-requires guard.php itself; the double-require is
    // harmless (guard is idempotent) and keeps direct-URL access protected.
    require __DIR__ . '/' . $allowed[$step];
    exit;
}

if ($step !== '') {
    // Unknown ?s= value -- fail closed back to the welcome screen rather than
    // including an arbitrary file (path traversal guard: whitelist above is
    // the only include source).
    header('Location: index.php', true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>PHPNuxBill Installer</title>
    <link rel="shortcut icon" type="image/x-icon" href="img/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--[if lt IE 9]>
    <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->

    <link type='text/css' href='css/style.css' rel='stylesheet' />
    <link href="css/bootstrap.min.css" rel="stylesheet">
</head>

<body style='background-color: #FBFBFB;'>
    <div id='main-container'>
        <img src="img/logo.png" class="img-responsive" alt="Logo" />
        <hr>
        <!--  contents area start  -->
        <div class="row">
            <div class="col-md-12">
                <h4> PHPNuxBill Installer </h4>
                <h5>Please Read Before Continue</h5>
                <p><strong>Informasi Aplikasi</strong><br>
                    Application Name: PHPNuxBill <br>
                    Release Date: 30/10/2015<br>
                    By: PHPNuxBill [ <a href="https://github.com/hotspotbilling/phpnuxbill" target="_blank">https://github.com/hotspotbilling/phpnuxbill</a> ]<br>
                    Donasi Paypal: <b>me@ibnux.et</b><br>
                    <br>
                    <strong>Syarat Penggunaan:</strong><br>
                    Syarat Penggunaan ini berlaku untuk semua versi.<br><br>
                <ul>
                    <li>Silahkan Anda menggunakan aplikasi ini dengan bijak, Anda dapat mendesain ulang script maupun tampilan pada
                        aplikasi ini sesuai dengan kebutuhan anda, memperbayak jumlah copy atau mendistribusikan aplikasi ini.
                        Dengan catatan tidak menghapus link developer.</li>
                    <li>Tidak ada garansi dari kami jika anda mengalami error atau merasa rugi ketika menggunakan aplikasi ini,
                        Anda hanya dapat memberikan feedback yang berisi laporan error, dengan syarat dan ketentuan yang berlaku.</li>
                    <li>Semua yang terkait biaya atau donasi apapun versi-nya, Anda dapat update seumur hidup atau selama aplikasi
                        ini masih dikembangkan. Mohon jangan salah pengertian bahwa kami tim pengembang mengkomersilkan produk ini
                        dan anda membeli produk kami.</li>
                    <li>Aplikasi ini bersifat sosial untuk dapat dikembangkan bersama. Karena itu kami juga mengundang relawan-relawan
                        yang mau menjadi pengembangkan aplikasi ini.</li>
                    <li>Penulis berhak setiap saat untuk mengubah ketentuan Syarat Penggunaan tanpa pemberitahuan sebelumnya.</li>
                </ul>
            </div>
            <div class="col-md-12"><br>
                <!-- Routed through index.php (the only basename the
                     .htaccess_firewall allows): ?s=2 dispatches to step2.php
                     in-process. Direct step2.php is 403 once .htaccess is live. -->
                <a href="index.php?s=2" class="btn btn-primary">Accept &amp; Continue</a>
            </div>
        </div>
        <!--  contents area end  -->
    </div>
    <div class="footer">Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br /><br /></div>
</body>

</html>