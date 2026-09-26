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
 * This file is the ONLY access control on the installer. It used to be backed
 * by install/.htaccess, which has been removed: <IfFile> and <Files> were both
 * measured to be misapplied by LiteSpeed, which denied the installer's own
 * stylesheet, logo and every step after the first, making the wizard unusable.
 * A rule that a web server may parse into something broader than it says is not
 * a defence, so the lock lives in PHP, where it either runs or does not.
 *
 * To re-run the installer on purpose, delete install/.installed and take a
 * database backup first.
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
