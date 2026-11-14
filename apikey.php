<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: a player's API key.
 */
global $db, $ir, $userid, $h, $set;
require_once('globals.php');
echo '<h3>Your API Key</h3>';
$shown = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['create', 'revoke'], '', $_POST);
    if (!verify_csrf_code('apikey', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'create') {
        $shown = api_key_create($userid);
    } elseif ($do === 'revoke') {
        api_key_revoke($userid);
        echo '<p><b>Key revoked.</b> Anything using it has stopped working.</p>';
    }
}
$info = api_key_info($userid);
$token = request_csrf_html('apikey');
$base = 'https://' . determine_game_urlbase() . '/api.php';
echo '<p>A key lets a tool of yours (a stats tracker, a chat bot, a phone widget) read your game data. A key can <b>only read</b>: it can never spend, send or change anything.
Treat it like a password all the same: anyone who has it can see your money and stats.</p>';
if ($shown !== null) {
    echo "<div style='border:1px solid #4caf50;border-radius:8px;padding:12px;margin:10px auto;max-width:560px;'>
    <b>Your new key</b> (copy it now: it is shown once and cannot be recovered)<br />
    <input type='text' readonly='readonly' size='44' value='{$shown}' onclick='this.select()' style='font-family:monospace;margin-top:6px;' /></div>";
}
if ($info === null) {
    echo "<p>You do not have a key.</p>
    <form action='apikey.php' method='post'>{$token}<input type='hidden' name='do' value='create' /><input type='submit' value='Create a key' /></form>";
} else {
    echo "<table cellspacing='1' class='table' width='60%'>
    <tr><th>Created</th><td>" . date('j M Y, H:i', (int)$info['created']) . '</td></tr>
    <tr><th>Last used</th><td>' . ((int)$info['last_used'] > 0 ? date('j M Y, H:i', (int)$info['last_used']) : 'Never') . '</td></tr>
    <tr><th>Requests made</th><td>' . number_format((int)$info['calls']) . "</td></tr></table><br />
    <form action='apikey.php' method='post' style='display:inline;'>{$token}<input type='hidden' name='do' value='create' /><input type='submit' value='Replace with a new key' /></form>
    <form action='apikey.php' method='post' style='display:inline;'>{$token}<input type='hidden' name='do' value='revoke' /><input type='submit' value='Revoke the key' /></form>";
}
echo "<h4>What you can ask for</h4>
<table cellspacing='1' class='table' width='95%' style='text-align:left;'>
<tr><th>Address</th><th>Gives</th><th>Key</th></tr>
<tr><td><code>api.php?do=me</code></td><td>Everything about you: money, bars, stats, progress, achievements</td><td>Yes</td></tr>
<tr><td><code>api.php?do=player&amp;id=12</code></td><td>What another player's profile shows</td><td>Yes</td></tr>
<tr><td><code>api.php?do=top&amp;by=level</code></td><td>A leaderboard. <code>by</code> can be: " . implode(', ', array_keys(MCC_API_TOP)) . "</td><td>No</td></tr>
<tr><td><code>api.php?do=hitlist</code></td><td>Players with a bounty on their head</td><td>No</td></tr>
<tr><td><code>api.php?do=game</code></td><td>The game as a whole: players, who is online, money in play</td><td>No</td></tr>
</table>
<p>Send the key as <code>&amp;key=YOUR_KEY</code>, or better as the request header <code>X-Api-Key: YOUR_KEY</code>. A key may make " . MCC_API_PER_MINUTE . " requests a minute.
Answers are JSON: <code>{\"ok\": true, \"data\": ...}</code> or <code>{\"ok\": false, \"error\": \"...\"}</code>.</p>
<p>Example: <code>curl -H \"X-Api-Key: YOUR_KEY\" \"" . e($base) . "?do=me\"</code></p>
<p>&gt; <a href='record.php'>Your record and the leaderboards</a></p>";
$h->endpage();
