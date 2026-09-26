<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/

//error_reporting (0);
require 'guard.php';
$appurl = $_POST['appurl'];
$db_host = $_POST['dbhost'];
$db_user = $_POST['dbuser'];
$db_pass = $_POST['dbpass'];
$db_name = $_POST['dbname'];
$cn = '0';

// The Application URL is mandatory, and it is now written into config.php as a
// literal. Previously it was recomputed from $_SERVER['SCRIPT_NAME'], which
// resolves to "/install" when the wizard is reached through a subdirectory or
// behind a proxy that rewrites SCRIPT_NAME, producing a config.php whose
// APP_URL points at the installer and breaks every asset URL and redirect.
$appurl = rtrim(trim((string) $appurl), '/');
if ($appurl === '') {
    header('location: step3.php?_error=1');
    exit;
}
$appUrlDefine = 'define("APP_URL", "' . addslashes($appurl) . '");';

try {
    $dbh = new pdo(
        "mysql:host=$db_host;dbname=$db_name",
        "$db_user",
        "$db_pass",
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $cn = '1';
} catch (PDOException $ex) {
    $cn = '0';
}

if ($cn == '1') {
    if (isset($_POST['radius']) && $_POST['radius'] == 'yes') {
        $input = '<?php

' . $appUrlDefine . '

// Live, Dev, Demo
$_app_stage = "Live";

// Database PHPNuxBill
$db_host	    = "' . $db_host . '";
$db_user        = "' . $db_user . '";
$db_pass    	= "' . $db_pass . '";
$db_name	    = "' . $db_name . '";

// Database Radius
$radius_host	    = "' . $db_host . '";
$radius_user        = "' . $db_user . '";
$radius_pass    	= "' . $db_pass . '";
$radius_name	    = "' . $db_name . '";

if($_app_stage!="Live"){
    error_reporting(E_ERROR);
    ini_set("display_errors", 1);
    ini_set("display_startup_errors", 1);
}else{
    error_reporting(E_ERROR);
    ini_set("display_errors", 0);
    ini_set("display_startup_errors", 0);
}';
    } else {
        $input = '<?php
' . $appUrlDefine . '

// Live, Dev, Demo
$_app_stage = "Live";

// Database PHPNuxBill
$db_host	    = "' . $db_host . '";
$db_user        = "' . $db_user . '";
$db_pass	    = "' . $db_pass . '";
$db_name	    = "' . $db_name . '";

if($_app_stage!="Live"){
    error_reporting(E_ERROR);
    ini_set("display_errors", 1);
    ini_set("display_startup_errors", 1);
}else{
    error_reporting(E_ERROR);
    ini_set("display_errors", 0);
    ini_set("display_startup_errors", 0);
}';
    }
    $wConfig = __DIR__ . '/../config.php';
    $fh = fopen($wConfig, 'w') or die("Can't create config file, your server does not support 'fopen' function,
	please create a file named - config.php with following contents- <br/>$input");
    fwrite($fh, $input);
    fclose($fh);
    $sql = file_get_contents(__DIR__ . '/phpnuxbill.sql');
    $qr = $dbh->exec($sql);
    // The schema is removed from disk the moment it has been imported, so a
    // deployed server does not publish its entire database layout at a fixed,
    // well-known URL. install/.htaccess used to hide it, but <Files> was
    // measured to be misapplied by LiteSpeed, so the file is made unreachable
    // by not existing rather than by a rule that may be ignored. Restoring it
    // for a deliberate re-install is `git checkout -- install/phpnuxbill.sql`.
    @unlink(__DIR__ . '/phpnuxbill.sql');
    if (isset($_POST['radius']) && $_POST['radius'] == 'yes') {
        $sql = file_get_contents(__DIR__ . '/radius.sql');
        $qrs = $dbh->exec($sql);
        @unlink(__DIR__ . '/radius.sql');
    }
} else {
    header("location: step3.php?_error=1");
    exit;
}

?>
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

<body style='background-color: #FBFBFB;'>
    <div id='main-container'>
        <img src="img/logo.png" class="img-responsive" alt="Logo" />
        <hr>

        <div class="span12">
            <h4> PHPNuxBill Installer </h4>
            <?php
            if ($cn == '1') {
            ?>
                <p><strong>Config File Created and Database Imported.</strong><br></p>
                <form action="step5.php" method="post">
                    <fieldset>
                        <legend>Click Continue</legend>
                        <button type='submit' class='btn btn-primary'>Continue</button>
                    </fieldset>
                </form>
            <?php
            } elseif ($cn == '2') {
            ?>
                <p> MySQL Connection was successfull. An error occured while adding data on MySQL. Unsuccessfull
                    Installation. Please refer manual installation in the website github.com/ibnux/phpnuxbill/wiki or Contact Telegram @ibnux  for
                    helping on installation</p>
            <?php
            } else {
            ?>
                <p> MySQL Connection Failed.</p>
            <?php
            }
            ?>
        </div>
    </div>

    <div class="footer">Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br /><br /></div>
</body>

</html>