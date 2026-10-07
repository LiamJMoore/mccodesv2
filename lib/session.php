<?php
declare(strict_types=1);
/**
 * Session start-up and security headers, shared by every entry point
 * (globals.php, globals_nonauth.php and sglobals.php).
 *
 * The session cookie can't be read by JavaScript, isn't sent on other
 * sites' requests, only travels over HTTPS when the game is served on
 * HTTPS, and PHP refuses session IDs it didn't issue itself.
 */
function mcc_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('MCCSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!headers_sent()) {
        // No framing by other sites (clickjacking), no MIME sniffing, and
        // only the origin goes out as a referrer.
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    session_start();
}

/**
 * Login throttling: at most MCC_LOGIN_MAX failed logins per IP address in
 * MCC_LOGIN_WINDOW seconds. The table creates itself, so existing games
 * need no database change.
 */
const MCC_LOGIN_MAX = 10;
const MCC_LOGIN_WINDOW = 900;

function mcc_login_table($db): void
{
    $db->query(
        "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `ip` VARCHAR(45) NOT NULL,
            `at` INT NOT NULL,
            KEY `ip_at` (`ip`, `at`)
        ) ENGINE=InnoDB");
}

function mcc_login_blocked($db, string $ip): bool
{
    mcc_login_table($db);
    $e_ip = $db->escape($ip);
    $since = time() - MCC_LOGIN_WINDOW;
    $db->query("DELETE FROM `login_attempts` WHERE `at` < {$since}");
    $q = $db->query(
        "SELECT COUNT(*) FROM `login_attempts`
         WHERE `ip` = '{$e_ip}' AND `at` >= {$since}");
    return (int)$db->fetch_single($q) >= MCC_LOGIN_MAX;
}

function mcc_login_failed($db, string $ip): void
{
    $e_ip = $db->escape($ip);
    $db->query("INSERT INTO `login_attempts` (`ip`, `at`) VALUES ('{$e_ip}', " . time() . ")");
}

function mcc_login_succeeded($db, string $ip): void
{
    $e_ip = $db->escape($ip);
    $db->query("DELETE FROM `login_attempts` WHERE `ip` = '{$e_ip}'");
}
