<?php

/**
 * PHPNuxBill installer lock.
 *
 * Why this exists: step4 imports the schema with DROP TABLE IF EXISTS and has no
 * install lock of its own, so an installer left reachable on a live server is a
 * public one-click database wipe. install/.htaccess enforces the same rule at
 * the web-server level, but that layer is not dependable everywhere: nginx
 * ignores .htaccess outright, and <IfFile> support varies between hosts. This
 * guard is the layer that always works, because it is plain PHP.
 *
 * The lock keys off install/.installed rather than config.php, because step4
 * writes config.php at line 86 - before step5 has finished - so a config.php
 * check would lock the operator out of the final page of the wizard they are
 * still running. step5 writes .installed as its last action instead.
 *
 * Each layer is written so that its own failure mode is the safe one:
 *   - .htaccess misbehaving  -> installer is reachable, but this guard refuses
 *   - this guard misbehaving  -> .htaccess still denies, on hosts that honour it
 *
 * To re-run the installer on purpose, delete install/.installed and remove
 * install/.htaccess, and take a database backup first.
 */

$__phpnuxbill_install_lock = __DIR__ . DIRECTORY_SEPARATOR . '.installed';

if (file_exists($__phpnuxbill_install_lock)) {
    if (!headers_sent()) {
        header('Location: ../', true, 302);
    }
    echo 'This application is already installed. Delete install/.installed to run the installer again.';
    exit;
}

unset($__phpnuxbill_install_lock);
