<?php require __DIR__ . '/guard.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>PHPNuxBill Installer</title>
    <link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--[if lt IE 9]>
    <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->

    <link type='text/css' href='css/style.css' rel='stylesheet' />
    <link type='text/css' href="css/bootstrap.min.css" rel="stylesheet">
</head>
<?php
// Resolved from __DIR__ rather than $_SERVER['DOCUMENT_ROOT']: on cPanel the
// application usually lives in a subdirectory of the docroot, or the docroot is
// repointed at the application folder, so DOCUMENT_ROOT and the real location of
// this script disagree. When they did, pages_template/ was looked up in the
// wrong tree, the copy threw, and the wizard still reported success - leaving
// the site installed with no pages/ directory at all.
$appRoot = dirname(__DIR__);
$sourceDir = $appRoot . '/pages_template';
$targetDir = $appRoot . '/pages';

function copyDir($src, $dst) {
    $dir = opendir($src);
    if (!$dir) {
        throw new Exception("Cannot open directory: $src");
    }

    if (!file_exists($dst)) {
        if (!mkdir($dst, 0777, true)) {
            throw new Exception("Failed to create directory: $dst");
        }
    }

    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                copyDir($src . '/' . $file, $dst . '/' . $file);
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                    throw new Exception("Failed to copy $src/$file to $dst/$file");
                }
            }
        }
    }
    closedir($dir);
}

function removeDir($dir) {
    if (!is_dir($dir)) return;
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object == '.' || $object == '..') continue;
        if (is_dir($dir . '/' . $object))
            removeDir($dir . '/' . $object);
        else
            if (!unlink($dir . '/' . $object)) {
                throw new Exception("Failed to delete file: $dir/$object");
            }
    }
    if (!rmdir($dir)) {
        throw new Exception("Failed to remove directory: $dir");
    }
}

try {
    if (!file_exists($sourceDir)) {
        throw new Exception("Source directory does not exist.");
    }

    copyDir($sourceDir, $targetDir);
    removeDir($sourceDir);

} catch (Exception $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}

// The database has been imported and config.php written by this point, so the
// installer must never run again. Drop the marker that install/guard.php and
// install/.htaccess both key off, which re-arms the lock with no manual step.
// Writing it even when the template copy above failed is deliberate: the
// destructive part of the wizard has already run either way.
@file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . '.installed', 'Installed ' . date('c') . "\n");
?>
<body style='background-color: #FBFBFB;'>
    <div id='main-container'>
        <img src="img/logo.png" class="img-responsive" alt="Logo" />
        <hr>
        <div class="span12">
            <h4> PHPNuxBill Installer </h4>
            <p>
                <strong>Congratulations!</strong><br>
                You have just install PHPNuxBill !<br><br>
                <span class="text-danger">But wait!!<br>
                    <ol>
                        <li>Don't forget to rename folder <b>pages_example</b> to <b>pages</b>.<br>
                            if it not yet renamed</li>
                        <li>Activate <a href="https://github.com/hotspotbilling/phpnuxbill/wiki/Cron-Jobs" target="_blank">Cronjob</a> for Expired and Reminder.</li>
                        <li>Check <a href="https://github.com/hotspotbilling/phpnuxbill/wiki/How-It-Works---Cara-kerja" target="_blank">how PHPNuxbill Works</a></li>
                        <li><a href="https://github.com/hotspotbilling/phpnuxbill/wiki#login-page-mikrotik" target="_blank">how to link Mikrotik Login to PHPNuxBill</a></li>
                        <li>or use <a href="https://github.com/hotspotbilling/phpnuxbill-mikrotik-login-template" target="_blank">Mikrotik Login Template for PHPNuxBill</a></li>
                    </ol>
                </span><br><br>
                To Login Admin Portal:<br>
                Use this link -
                <?php
                // Same routed-URL stripping as step3: drop the query string
                // first so "?s=5" never leaks into the displayed admin link.
                $cururl = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
                $appurl = str_replace('/install/index.php', '', $cururl);
                $appurl = str_replace('/install', '', $cururl);
                $appurl = str_replace('/system', '', $appurl);
                echo '<a href="' . $appurl . '/admin">' . $appurl . '/admin</a>';
                ?>
                <br>
                Username: admin<br>
                Password: admin<br>
                For security, Delete the <b>install</b> directory inside system folder.
            </p>
        </div>
    </div>
    <div class="footer">Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br /><br /></div>
</body>

</html>