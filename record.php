<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: a player's record (their counters and where they rank), and the leaderboards.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$who = in_int('u', 0, $_GET) ?: $userid;
$p = api_player($who);
if ($p === null) {
    echo 'There is no such player.';
    $h->endpage();
    exit;
}
if ($who === $userid) {
    progress_max($userid, 'level', (int)$ir['level']);
}
$totals = progress_all($who);
$fights = $db->row("SELECT SUM(`attacker` = ? AND `result` = 'won') AS `won`, SUM(`attacker` = ? AND `result` = 'lost') AS `lost`, SUM(`attacked` = ? AND `result` = 'lost') AS `defended`,
                           SUM(`attacked` = ? AND `result` = 'won') AS `beaten`, COALESCE(SUM(IF(`attacker` = ? AND `stole` > 0, `stole`, 0)), 0) AS `mugged`
                    FROM `attacklogs` WHERE `attacker` = ? OR `attacked` = ?", [$who, $who, $who, $who, $who, $who, $who]);
echo '<h3>' . ($who === $userid ? 'Your Record' : 'Record of ' . e($p['name'])) . "</h3>
<table width='80%' cellspacing='1' class='table'><tr><th>What</th><th>Total</th><th>Rank</th></tr>";
foreach (MCC_PROGRESS as $stat => $label) {
    if ($stat === 'level') {
        continue;
    }
    $rank = api_rank($who, $stat);
    echo '<tr><td>' . e($label) . '</td><td>' . ($stat === 'crime_money' ? money_formatter($totals[$stat]) : number_format($totals[$stat])) . '</td><td>' . ($rank === null ? '-' : '#' . number_format($rank)) . '</td></tr>';
}
echo "</table><br />
<table width='80%' cellspacing='1' class='table'><tr><th>Fights started and won</th><th>Fights started and lost</th><th>Attacks fought off</th><th>Times beaten</th><th>Taken in muggings</th></tr>
<tr><td>" . (int)$fights['won'] . '</td><td>' . (int)$fights['lost'] . '</td><td>' . (int)$fights['defended'] . '</td><td>' . (int)$fights['beaten'] . '</td><td>' . money_formatter((int)$fights['mugged']) . "</td></tr></table>
<p><a href='achievements.php?u={$who}'>Achievements ({$p['achievements']})</a>" . ($who === $userid ? " &middot; <a href='apikey.php'>API key</a>" : " &middot; <a href='viewuser.php?u={$who}'>Profile</a>") . '</p>';
$by = in_choice('by', array_keys(MCC_API_TOP), 'level', $_GET);
echo '<h3>Leaderboards</h3><p>';
foreach (MCC_API_TOP as $k => $label) {
    echo $k === $by ? '<b>' . e($label) . '</b> ' : "<a href='record.php?u={$who}&amp;by={$k}'>" . e($label) . '</a> ';
    echo '&middot; ';
}
echo "</p><table width='60%' cellspacing='1' class='table'><tr><th>#</th><th>Player</th><th>" . e(MCC_API_TOP[$by]) . '</th></tr>';
$top = api_top($by, 20);
foreach ($top as $r) {
    echo '<tr' . ($r['id'] === $who ? " style='font-weight:bold;'" : '') . "><td>{$r['rank']}</td><td><a href='viewuser.php?u={$r['id']}'>" . e($r['name']) . '</a></td><td>' . number_format($r['value']) . '</td></tr>';
}
if (!$top) {
    echo "<tr><td colspan='3'>Nobody yet. Be the first.</td></tr>";
}
echo '</table>';
$h->endpage();
