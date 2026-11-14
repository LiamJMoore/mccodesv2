<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the daily reward.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Daily Reward</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_code('daily_claim', in_str('verf', 64, '', $_POST))) {
    $paid = daily_claim($userid, (int)$ir['level']);
    if ($paid === null) {
        echo "You have already claimed today's reward. Come back tomorrow.<br />";
    } else {
        echo "<p><b>Day {$paid['day']} claimed!</b> You received " . money_formatter($paid['money'])
            . ($paid['crystals'] ? " and {$paid['crystals']} crystals" : '') . '.</p>';
        event_add($userid, "You claimed your day {$paid['day']} daily reward: " . money_formatter($paid['money'])
            . ($paid['crystals'] ? " and {$paid['crystals']} crystals" : '') . '.');
    }
}
$st = daily_status($userid, (int)$ir['level']);
echo "<p>Claim a reward once a day. Claim on days in a row and it grows; miss a day and the streak starts again.</p>
<table width='75%' cellspacing='1' class='table'>
<tr><th>Your streak</th><th>Longest streak</th><th>Rewards claimed</th></tr>
<tr><td>{$st['streak']} day" . ($st['streak'] === 1 ? '' : 's') . "</td><td>{$st['best']} day" . ($st['best'] === 1 ? '' : 's') . "</td><td>{$st['claims']}</td></tr>
</table><br />";
if ($st['claimed']) {
    echo '<p><b>Claimed for today.</b> Tomorrow (day ' . ($st['streak'] + 1) . ') pays ' . money_formatter($st['reward']['money'])
        . ($st['reward']['crystals'] ? " and {$st['reward']['crystals']} crystals" : '') . '.</p>';
} else {
    echo "<form action='daily.php' method='post'>" . request_csrf_html('daily_claim') . "
    <p>Today is <b>day {$st['next']}</b>: " . money_formatter($st['reward']['money'])
        . ($st['reward']['crystals'] ? " and {$st['reward']['crystals']} crystals" : '') . ".</p>
    <input type='submit' value='Claim today&#039;s reward' /></form>";
}
// the week ahead
$from = $st['claimed'] ? $st['streak'] + 1 : $st['next'];
echo "<h4>The next seven days</h4><table width='75%' cellspacing='1' class='table'><tr><th>Day</th><th>Cash</th><th>Crystals</th></tr>";
for ($d = $from; $d < $from + 7; $d++) {
    $r = daily_reward($d, (int)$ir['level']);
    echo "<tr><td>Day {$d}" . ($d % 30 === 0 ? ' (monthly bonus)' : ($d % 7 === 0 ? ' (weekly bonus)' : '')) . '</td><td>' . money_formatter($r['money']) . "</td><td>{$r['crystals']}</td></tr>";
}
echo '</table>';
$h->endpage();
