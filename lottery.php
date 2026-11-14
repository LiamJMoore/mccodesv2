<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the daily lottery.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Daily Lottery</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_code('lottery', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $n = in_int('tickets', 0, $_POST);
        $why = lotto_buy($userid, $n);
        echo $why === null ? '<p><b>Bought ' . $n . ' ticket' . ($n === 1 ? '' : 's') . '.</b> Good luck.</p>' : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    }
}
$t = lotto_today($userid);
$left = (int)strtotime('tomorrow') - time();
$can = MCC_LOTTO_MAX_A_DAY - $t['mine'];
$odds = $t['mine'] > 0 && $t['tickets'] > 0 ? round($t['mine'] / $t['tickets'] * 100, 1) . '%' : '-';
echo '<p>One ticket is drawn at midnight and its owner takes the pot. A ticket costs ' . money_formatter(MCC_LOTTO_TICKET) . '; you can buy up to ' . MCC_LOTTO_MAX_A_DAY . ' for each draw. '
    . MCC_LOTTO_POT_PERCENT . "% of ticket money goes into the pot.</p>
<div class='season-head'><div><small>Tonight's pot</small><b>" . money_formatter(max($t['pot'], MCC_LOTTO_HOUSE_ADDS)) . '</b></div><div><small>Draw in</small><b>' . intdiv($left, 3600) . 'h ' . intdiv($left % 3600, 60) . "m</b></div>
<div><small>Tickets sold</small><b>" . number_format($t['tickets']) . " <small>to {$t['players']} player" . ($t['players'] === 1 ? '' : 's') . "</small></b></div>
<div><small>Your tickets</small><b>{$t['mine']}</b></div><div><small>Your chance</small><b>{$odds}</b></div></div>";
if ($can > 0) {
    echo "<form action='lottery.php' method='post'>" . request_csrf_html('lottery') . "Tickets: <input type='text' name='tickets' value='1' size='4' /> <input type='submit' value='Buy' /> <small>(you can buy {$can} more today)</small></form>";
} else {
    echo "<p>You have all " . MCC_LOTTO_MAX_A_DAY . " of today's tickets.</p>";
}
$draws = $db->all('SELECT `d`.`day`, `d`.`tickets`, `d`.`players`, `d`.`pot`, `d`.`winner`, `u`.`username` FROM `v3_lotto_draws` AS `d` LEFT JOIN `users` AS `u` ON `u`.`userid` = `d`.`winner` ORDER BY `d`.`day` DESC LIMIT 10');
if ($draws) {
    echo "<h4>Recent draws</h4><table width='80%' cellspacing='1' class='table'><tr><th>Draw</th><th>Winner</th><th>Pot</th><th>Tickets</th></tr>";
    foreach ($draws as $d) {
        echo '<tr><td>' . date('j M', strtotime($d['day'])) . "</td><td><a href='viewuser.php?u={$d['winner']}'>" . perk_name((int)$d['winner'], (string)$d['username']) . '</a></td><td>' . money_formatter((int)$d['pot'])
            . '</td><td>' . number_format((int)$d['tickets']) . " from {$d['players']}</td></tr>";
    }
    echo '</table>';
}
$h->endpage();
