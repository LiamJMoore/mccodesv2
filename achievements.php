<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: achievements. Shows your own, or another player's with ?u=<id>.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$who = in_int('u', 0, $_GET) ?: $userid;
$name = $who === $userid ? null : $db->value('SELECT `username` FROM `users` WHERE `userid` = ?', [$who]);
if ($who !== $userid && $name === null) {
    echo 'There is no such player.';
    $h->endpage();
    exit;
}
if ($who === $userid) {
    progress_max($userid, 'level', (int)$ir['level']);       // players from before v3 get credit for the level they are on
}
$earned = achievements_earned($who);
$totals = progress_all($who);
echo '<h3>' . ($name === null ? 'Your Achievements' : 'Achievements of ' . e((string)$name)) . '</h3>
<p><b>' . count(array_intersect_key($earned, MCC_ACHIEVEMENTS)) . '</b> of ' . count(MCC_ACHIEVEMENTS) . " unlocked." . ($name === null ? " Each is worth a merit: <a href='merits.php'>spend your merits</a>." : '') . "</p>
<table width='90%' cellspacing='1' class='table'><tr><th>Achievement</th><th>How</th><th>Progress</th><th>Reward</th></tr>";
foreach (MCC_ACHIEVEMENTS as $key => $a) {
    $have = isset($earned[$key]);
    $now = min($a['target'], $totals[$a['stat']] ?? 0);
    $pc = (int)floor($now / $a['target'] * 100);
    echo '<tr' . ($have ? '' : " style='opacity:0.6;'") . '><td><b>' . e($a['name']) . '</b></td><td>' . e(achievement_goal($a)) . '</td><td>'
        . ($have ? 'Unlocked ' . date('j M Y', $earned[$key])
                 : "<div style='background:#555;border-radius:4px;width:100px;height:8px;display:inline-block;vertical-align:middle;'><div style='background:#4caf50;border-radius:4px;height:8px;width:{$pc}%;'></div></div> "
                   . number_format($now) . ' / ' . number_format($a['target']))
        . '</td><td>' . money_formatter($a['money']) . ($a['crystals'] ? " + {$a['crystals']} crystals" : '') . '</td></tr>';
}
echo '</table>';
$h->endpage();
