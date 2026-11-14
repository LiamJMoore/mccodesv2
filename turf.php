<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: gang territory.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$left = turf_week_left();
$mine = (int)$ir['gang'];
echo "<h3>Territory</h3>
<p>Every city is turf. Your gang earns influence in the city you are standing in: <b>1</b> for each successful crime and <b>5</b> for each fight won.
The gang with the most influence in a city holds it, and on Monday each city pays its holder's vault. Then the count starts again.</p>
<div class='season-head'><div><small>This week ends in</small><b>" . intdiv($left, 86400) . 'd ' . intdiv($left % 86400, 3600) . "h</b></div>
<div><small>You are in</small><b>" . e((string)$db->value('SELECT `cityname` FROM `cities` WHERE `cityid` = ?', [(int)$ir['location']], 'nowhere')) . "</b></div>
<div><small>Your gang</small><b>" . ($mine > 0 ? (string)$db->value('SELECT `gangNAME` FROM `gangs` WHERE `gangID` = ?', [$mine], 'None') : 'None') . '</b></div></div>';
if ($mine === 0) {
    echo "<p>You are not in a gang, so nothing you do counts here. <a href='gangcentral.php'>Find a gang</a></p>";
}
echo "<table width='100%' cellspacing='1' class='table'><tr><th>City</th><th>Held by</th><th>Standings this week</th><th>Pays on Monday</th></tr>";
foreach (turf_standings() as $id => $c) {
    $rows = [];
    foreach ($c['top'] as $i => $g) {
        $rows[] = ($g['gang'] === $mine ? '<b>' : '') . ($i + 1) . '. ' . $g['name'] . ': ' . number_format($g['points']) . ($g['gang'] === $mine ? '</b>' : '');
    }
    $yours = $mine > 0 ? (int)$db->value('SELECT `points` FROM `v3_turf` WHERE `city` = ? AND `gang` = ? AND `week` = ?', [$id, $mine, turf_week()], 0) : 0;
    echo '<tr><td><b>' . e($c['name']) . '</b>' . ($id === (int)$ir['location'] ? ' <small>(you are here)</small>' : '') . "<br /><small>Level {$c['min_level']}+</small></td><td>"
        . ($c['holder'] === null ? 'Nobody <small>(needs ' . MCC_TURF_MIN_POINTS . ' influence)</small>' : ($c['holder']['gang'] === $mine ? '<b>Your gang</b>' : $c['holder']['name']))
        . '</td><td>' . ($rows ? implode('<br />', $rows) : 'No influence yet') . ($mine > 0 && $yours > 0 && !in_array($mine, array_column($c['top'], 'gang'), true) ? "<br /><b>Your gang: {$yours}</b>" : '')
        . '</td><td>' . money_formatter($c['payout']['money']) . " + {$c['payout']['crystals']} crystals</td></tr>";
}
echo '</table>';
$wins = $db->all('SELECT `w`.`week`, `w`.`points`, `w`.`money`, `c`.`cityname`, `g`.`gangNAME` FROM `v3_turf_wins` AS `w` LEFT JOIN `cities` AS `c` ON `c`.`cityid` = `w`.`city`
                  LEFT JOIN `gangs` AS `g` ON `g`.`gangID` = `w`.`gang` ORDER BY `w`.`week` DESC, `w`.`money` DESC LIMIT 12');
if ($wins) {
    echo "<h4>Recent payouts</h4><table width='80%' cellspacing='1' class='table'><tr><th>Week</th><th>City</th><th>Held by</th><th>Influence</th><th>Paid</th></tr>";
    foreach ($wins as $w) {
        echo '<tr><td>' . e($w['week']) . '</td><td>' . e((string)$w['cityname']) . '</td><td>' . ($w['gangNAME'] ?? '<i>disbanded</i>') . '</td><td>' . number_format((int)$w['points']) . '</td><td>' . money_formatter((int)$w['money']) . '</td></tr>';
    }
    echo '</table>';
}
$h->endpage();
