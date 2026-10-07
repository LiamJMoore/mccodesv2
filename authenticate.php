<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

global $db, $set;
require_once('globals_nonauth.php');
// Check CSRF input
if (!isset($_POST['verf'])
        || !verify_csrf_code('login', stripslashes($_POST['verf'])))
{
    die(
            "<h3>{$set['game_name']} Error</h3>
Your request has expired for security reasons! Please try again.<br />
<a href='login.php'>&gt; Back</a>");
}
// Check username and password input
$username =
        (array_key_exists('username', $_POST) && is_string($_POST['username']))
                ? $_POST['username'] : '';
$password =
        (array_key_exists('password', $_POST) && is_string($_POST['password']))
                ? $_POST['password'] : '';
if (empty($username) || empty($password))
{
    die(
            "<h3>{$set['game_name']} Error</h3>
	You did not fill in the login form!<br />
	<a href='login.php'>&gt; Back</a>");
}
if (mcc_login_blocked($db, (string)$_SERVER['REMOTE_ADDR']))
{
    die(
            "<h3>{$set['game_name']} Error</h3>
	Too many failed logins from your connection. Please wait 15 minutes and try again.<br />
	<a href='login.php'>&gt; Back</a>");
}
$form_username = $db->escape(stripslashes($username));
$raw_password = stripslashes($password);
$uq =
        $db->query(
                "SELECT `userid`, `userpass`, `pass_salt`
                 FROM `users`
                 WHERE `login_name` = '$form_username'");
if ($db->num_rows($uq) == 0)
{
    $db->free_result($uq);
    mcc_login_failed($db, (string)$_SERVER['REMOTE_ADDR']);
    die(
            "<h3>{$set['game_name']} Error</h3>
	Invalid username or password!<br />
	<a href='login.php'>&gt; Back</a>");
}
else
{
    $mem = $db->fetch_row($uq);
    $db->free_result($uq);
    $login_failed = !verify_user_password($raw_password,
            (string)$mem['pass_salt'], (string)$mem['userpass']);
    // Upgrade legacy MD5 (and outdated) hashes to password_hash() the moment
    // the player proves they know the password. No reset needed.
    if (!$login_failed && password_needs_upgrade((string)$mem['userpass']))
    {
        $e_encpsw = $db->escape(encode_password($raw_password));
        $db->query(
                "UPDATE `users`
                 SET `userpass` = '{$e_encpsw}'
                 WHERE `userid` = {$mem['userid']}");
    }
    if ($login_failed)
    {
        mcc_login_failed($db, (string)$_SERVER['REMOTE_ADDR']);
        die(
                "<h3>{$set['game_name']} Error</h3>
		Invalid username or password!<br />
		<a href='login.php'>&gt; Back</a>");
    }
    mcc_login_succeeded($db, (string)$_SERVER['REMOTE_ADDR']);
    session_regenerate_id(true);
    $_SESSION['loggedin'] = 1;
    $_SESSION['userid'] = $mem['userid'];
    $IP = $db->escape($_SERVER['REMOTE_ADDR']);
    $db->query(
            "UPDATE `users`
             SET `lastip_login` = '$IP', `last_login` = "
                    . $_SERVER['REQUEST_TIME']
                    . "
             WHERE `userid` = {$mem['userid']}");
    if ($set['validate_period'] == 'login' && $set['validate_on'])
    {
        $db->query(
                "UPDATE `users`
                 SET `verified` = 0
                 WHERE `userid` = {$mem['userid']}");
    }
    // Relative, so it follows whatever scheme the game is served on (a
    // hard-coded https:// broke logins on plain-http installs).
    header('Location: loggedin.php');
    exit;
}
