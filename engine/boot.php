<?php
declare(strict_types=1);

/**
 * The one start-up path for every page.
 *
 * v2 had three copies of this (globals.php for players, sglobals.php for staff, globals_nonauth.php for the login
 * and register pages), each a little different, so a fix in one was easily missed in the others. Those three files
 * are now a few lines each: they set $MCC_BOOT and include this.
 *
 *   $MCC_BOOT = 'public'   no login needed: session, config, database, settings
 *   $MCC_BOOT = 'player'   must be logged in: also loads the player ($ir), runs the page header and menu
 *   $MCC_BOOT = 'staff'    as player, and must hold a staff role
 *
 * It is included, not called, because v2 pages expect their working variables in the global scope:
 * $db, $c, $set, and for logged-in pages $userid, $ir, $h, $domain, $fm, $cm, $lv.
 */
if (!isset($MCC_BOOT) || !in_array($MCC_BOOT, ['public', 'player', 'staff'], true)) {
    exit;
}
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Economy.php';
require_once __DIR__ . '/Items.php';
require_once __DIR__ . '/Formula.php';
require_once __DIR__ . '/Combat.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Progress.php';
require_once __DIR__ . '/mods/Daily.php';
require_once __DIR__ . '/mods/Achievements.php';
require_once __DIR__ . '/mods/Missions.php';
require_once __DIR__ . '/mods/Bounties.php';
require_once __DIR__ . '/mods/Crafting.php';
require_once __DIR__ . '/mods/Notify.php';
require_once __DIR__ . '/mods/Api.php';
require_once dirname(__DIR__) . '/lib/session.php';
mcc_session_start();
if (!isset($_SESSION['started'])) {
    session_regenerate_id();
    $_SESSION['started'] = true;
}
ob_start();
require_once dirname(__DIR__) . '/lib/basic_error_handler.php';
set_error_handler('error_php');
require_once dirname(__DIR__) . '/global_func.php';

if ($MCC_BOOT !== 'public') {
    // Not logged in: go to the login page before touching the database.
    $domain = determine_game_urlbase();
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] == 0) {
        header("Location: https://{$domain}/login.php");
        exit;
    }
    $userid = (int)($_SESSION['userid'] ?? 0);
    require_once dirname(__DIR__) . '/header.php';
}

global $_CONFIG;
include dirname(__DIR__) . '/config.php';
if (!defined('MONO_ON')) {
    define('MONO_ON', 1);
}
require_once dirname(__DIR__) . "/class/class_db_{$_CONFIG['driver']}.php";
$db = new database();
$db->configure($_CONFIG['hostname'], $_CONFIG['username'], $_CONFIG['password'], $_CONFIG['database']);
$db->connect();
$c = $db->connection_id;

if ($MCC_BOOT === 'public') {
    // The public pages read settings as plain text, as v2 did.
    $set = [];
    foreach ($db->all('SELECT `conf_name`, `conf_value` FROM `settings`') as $r) {
        $set[$r['conf_name']] = $r['conf_value'];
    }
    return;
}

$set = get_site_settings();
if ((int)($set['engine_schema'] ?? 0) < schema_latest()) {
    schema_upgrade();          // the game's database is behind the engine: bring it up to date, once
}
if ($set['use_timestamps_over_crons']) {
    define('SILENT_CRONS', true);
    try {
        require_once dirname(__DIR__) . '/crons/cronless_crons.php';
    } catch (Exception $e) {
        echo 'An error occurred' . (defined('DEBUG') && DEBUG ? ':<br>' . $e->getMessage() : '');
        exit;
    }
}

// The player, with their job or house joined on when the page asks for it.
global $jobquery, $housequery;
$mcc_join = '';
if (isset($jobquery) && $jobquery) {
    $mcc_select = '`u`.*, `us`.*, `j`.*, `jr`.*';
    $mcc_join   = 'LEFT JOIN `jobs` AS `j` ON `j`.`jID` = `u`.`job` LEFT JOIN `jobranks` AS `jr` ON `jr`.`jrID` = `u`.`jobrank`';
} elseif (isset($housequery) && $housequery) {
    $mcc_select = '`u`.*, `us`.*, `h`.*';
    $mcc_join   = 'LEFT JOIN `houses` AS `h` ON `h`.`hWILL` = `u`.`maxwill`';
} else {
    $mcc_select = '`u`.*, `us`.*';
}
$ir = $db->row(
    "SELECT {$mcc_select}
     FROM `users` AS `u`
     INNER JOIN `userstats` AS `us` ON `u`.`userid` = `us`.`userid`
     {$mcc_join}
     WHERE `u`.`userid` = ?
     LIMIT 1", [$userid]);
unset($mcc_select, $mcc_join);
if ($ir === null) {
    // The account behind this session no longer exists.
    session_unset();
    session_destroy();
    header("Location: https://{$domain}/login.php");
    exit;
}
set_userdata_data_types($ir);
if ($ir['force_logout'] > 0) {
    $db->exec('UPDATE `users` SET `force_logout` = 0 WHERE `userid` = ?', [$userid]);
    session_unset();
    session_destroy();
    header("Location: https://{$domain}/login.php");
    exit;
}

if ($MCC_BOOT === 'staff') {
    if (!is_staff()) {
        echo 'This page cannot be accessed.<br />&gt; <a href="index.php">Go Home</a>';
        die;
    }
} else {
    global $macropage;
    if ($macropage && !$ir['verified'] && $set['validate_on'] == 1) {
        header("Location: https://{$domain}/macro1.php?refer=$macropage");
        exit;
    }
}

check_level();
$h = new headers();
if (!isset($nohdr) || !$nohdr) {
    $h->startheaders();
    $fm = money_formatter($ir['money']);
    $cm = money_formatter($ir['crystals'], '');
    $lv = date('F j, Y, g:i a', $ir['laston']);
    global $atkpage;
    if ($MCC_BOOT === 'staff') {
        $staffpage = 1;
    }
    if ($atkpage) {
        $h->userdata($ir, $lv, $fm, $cm, 0);
    } else {
        $h->userdata($ir, $lv, $fm, $cm);
    }
    if ($MCC_BOOT === 'staff') {
        $h->smenuarea();
    } else {
        global $menuhide;
        if (!$menuhide) {
            $h->menuarea();
        }
    }
}
