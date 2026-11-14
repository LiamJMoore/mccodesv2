<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the player API. Read-only JSON.
 *
 *   api.php?do=game                          the game as a whole              (no key needed)
 *   api.php?do=top&by=level&limit=10         a leaderboard                    (no key needed)
 *   api.php?do=hitlist                       players with a bounty on them    (no key needed)
 *   api.php?do=player&id=12&key=...          what a profile page shows
 *   api.php?do=me&key=...                    everything about yourself
 *   api.php?do=notifications                 your unread events and mail      (logged-in browser only)
 *   api.php?do=live                          the sidebar's figures and the bell (logged-in browser only)
 *
 * A key comes from apikey.php. It may also be sent as the header  X-Api-Key: ...  which keeps it out of server logs.
 * Every answer is {"ok": true, "data": ...} or {"ok": false, "error": "..."}.
 */
$MCC_BOOT = 'public';
require __DIR__ . '/engine/boot.php';
global $db, $set;

function api_send(int $status, array $body): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // the game's text is ISO-8859-1; JSON must be UTF-8
    array_walk_recursive($body, function (&$v) {
        if (is_string($v) && preg_match('//u', $v) !== 1) {
            $v = function_exists('mb_convert_encoding') ? mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1') : (string)iconv('ISO-8859-1', 'UTF-8//IGNORE', $v);
        }
    });
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
if ((int)($set['engine_schema'] ?? 0) < schema_latest()) {
    schema_upgrade();
}
$do = in_choice('do', ['game', 'top', 'hitlist', 'player', 'me', 'notifications', 'live'], '', $_GET);
if ($do === '') {
    api_send(400, ['ok' => false, 'error' => 'Say what you want with ?do= : game, top, hitlist, player, me or notifications.']);
}
// who is asking: a key, or a logged-in browser
$key = (string)($_SERVER['HTTP_X_API_KEY'] ?? in_str('key', 64, '', $_GET));
$me = 0;
if ($key !== '') {
    $me = api_key_use($key);
    if ($me === 0) {
        api_send(401, ['ok' => false, 'error' => 'That key is not valid. Make one at apikey.php.']);
    }
    if ($me === -1) {
        header('Retry-After: 60');
        api_send(429, ['ok' => false, 'error' => 'Too many requests: a key may make ' . MCC_API_PER_MINUTE . ' a minute.']);
    }
} elseif (!empty($_SESSION['loggedin']) && !empty($_SESSION['userid'])) {
    $me = (int)$_SESSION['userid'];
}
session_write_close();          // nothing below changes the session: let the player's other requests through
if ($me > 0 && (int)$db->value('SELECT `fedjail` FROM `users` WHERE `userid` = ?', [$me], 1) > 0) {
    api_send(403, ['ok' => false, 'error' => 'This account cannot use the API.']);
}
switch ($do) {
    case 'game':
        api_send(200, ['ok' => true, 'data' => api_game()]);
    case 'top':
        $by = in_choice('by', array_keys(MCC_API_TOP), 'level', $_GET);
        api_send(200, ['ok' => true, 'data' => ['by' => $by, 'title' => MCC_API_TOP[$by], 'top' => api_top($by, in_int('limit', 10, $_GET))]]);
    case 'hitlist':
        $list = array_map(fn ($r) => ['id' => (int)$r['target'], 'name' => $r['username'], 'level' => (int)$r['level'], 'total' => (int)$r['total'], 'bounties' => (int)$r['bounties']], bounty_hitlist(50));
        api_send(200, ['ok' => true, 'data' => $list]);
}
if ($me <= 0) {
    api_send(401, ['ok' => false, 'error' => 'This needs a key (apikey.php), sent as ?key= or as the X-Api-Key header.']);
}
switch ($do) {
    case 'me':
        api_send(200, ['ok' => true, 'data' => api_me($me)]);
    case 'player':
        $p = api_player(in_int('id', 0, $_GET));
        api_send($p === null ? 404 : 200, $p === null ? ['ok' => false, 'error' => 'There is no such player.'] : ['ok' => true, 'data' => $p]);
    case 'live':
        // what the page's sidebar shows, for js/live.js to keep fresh; logged-in browser only
        if ($key !== '') {
            api_send(403, ['ok' => false, 'error' => 'This is for the logged-in game page only; use do=me.']);
        }
        if (!empty(get_site_settings()['use_timestamps_over_crons'])) {
            // a player watching one page still gets their energy back: the timed updates run from here too
            define('SILENT_CRONS', true);
            try {
                require_once __DIR__ . '/crons/cronless_crons.php';
            } catch (Throwable) {
            }
        }
        api_send(200, ['ok' => true, 'data' => api_live($me)]);
    case 'notifications':
        if ($key !== '') {
            api_send(403, ['ok' => false, 'error' => 'Notifications are for the logged-in game page only; use do=me for the unread counts.']);
        }
        api_send(200, ['ok' => true, 'data' => notify_summary($me)]);
}
