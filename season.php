<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the Season Pass.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Season Pass: ' . date('F Y') . '</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['claim', 'buy'], '', $_POST);
    if (!verify_csrf_code('season_' . $do, in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'claim') {
        $got = season_claim_all($userid);
        echo $got['tiers'] > 0
            ? "<p><b>Collected {$got['tiers']} reward" . ($got['tiers'] === 1 ? '' : 's') . '</b>: ' . money_formatter($got['money']) . ($got['crystals'] ? " and {$got['crystals']} crystals" : '') . '.</p>'
            : '<p>There is nothing to collect yet.</p>';
    } elseif ($do === 'buy') {
        $why = season_buy($userid);
        echo $why === null ? '<p><b>Premium unlocked!</b> Every premium reward up to your tier is ready to collect.</p>' : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    }
}
$st = season_status($userid);
$left = season_left();
$pc = $st['tier'] >= MCC_SEASON_TIERS ? 100 : (int)floor($st['into'] / MCC_SEASON_POINTS_PER_TIER * 100);
$waiting = season_waiting($userid);
echo "<p>Everything you do this month earns season points. Each tier pays a reward; the premium track pays a second, bigger one. A new season starts on the 1st.</p>
<div class='season-head'>
  <div><small>Your tier</small><b>{$st['tier']} / " . MCC_SEASON_TIERS . "</b></div>
  <div><small>Season points</small><b>" . number_format($st['xp']) . "</b></div>
  <div><small>Season ends in</small><b>" . intdiv($left, 86400) . 'd ' . intdiv($left % 86400, 3600) . "h</b></div>
  <div><small>Track</small><b>" . ($st['premium'] ? 'Premium' : 'Free') . "</b></div>
</div>
<div class='season-bar'><i style='width:{$pc}%'></i></div>
<p><small>" . ($st['tier'] >= MCC_SEASON_TIERS ? 'Top tier reached.' : number_format($st['into']) . ' / ' . MCC_SEASON_POINTS_PER_TIER . ' points to tier ' . ($st['tier'] + 1)) . '</small></p>';
if ($waiting > 0) {
    echo "<form action='season.php' method='post'>" . request_csrf_html('season_claim') . "<input type='hidden' name='do' value='claim' /><input type='submit' value='Collect {$waiting} reward" . ($waiting === 1 ? '' : 's') . "' /></form>";
}
if (!$st['premium']) {
    $total = ['money' => 0, 'crystals' => 0];
    $now = ['money' => 0, 'crystals' => 0];
    for ($t = 1; $t <= MCC_SEASON_TIERS; $t++) {
        $r = season_reward($t)['premium'];
        $total['money'] += $r['money'];
        $total['crystals'] += $r['crystals'];
        if ($t <= $st['tier']) {
            $now['money'] += $r['money'];
            $now['crystals'] += $r['crystals'];
        }
    }
    echo "<div class='season-offer'><b>Premium track: " . MCC_SEASON_PREMIUM_PRICE . ' crystals.</b> All 30 premium tiers pay ' . money_formatter($total['money']) . " and {$total['crystals']} crystals in total."
        . ($st['tier'] > 0 ? ' At your tier you would collect ' . money_formatter($now['money']) . " and {$now['crystals']} crystals straight away." : '')
        . " You have {$ir['crystals']} crystals.
        <form action='season.php' method='post' style='margin-top:8px;'>" . request_csrf_html('season_buy') . "<input type='hidden' name='do' value='buy' /><input type='submit' value='Unlock premium for " . MCC_SEASON_PREMIUM_PRICE . " crystals' /></form></div>";
}
echo "<table width='90%' cellspacing='1' class='table'><tr><th>Tier</th><th>Free</th><th>Premium</th></tr>";
$cell = function (array $r, bool $reached, bool $claimed, bool $locked): string {
    $text = money_formatter($r['money']) . ($r['crystals'] ? " + {$r['crystals']} crystals" : '');
    return '<td' . ($reached && !$locked ? '' : " style='opacity:.55;'") . ">{$text} " . ($claimed ? '<small>(collected)</small>' : ($locked ? '<small>(premium)</small>' : ($reached ? '<small><b>ready</b></small>' : ''))) . '</td>';
};
for ($t = 1; $t <= MCC_SEASON_TIERS; $t++) {
    $r = season_reward($t);
    $reached = $t <= $st['tier'];
    echo '<tr><td>' . ($reached ? "<b>{$t}</b>" : $t) . '</td>' . $cell($r['free'], $reached, in_array($t, $st['claimed']['free'], true), false)
        . $cell($r['premium'], $reached, in_array($t, $st['claimed']['premium'], true), !$st['premium']) . '</tr>';
}
echo "</table><h4>How points are earned</h4><table width='60%' cellspacing='1' class='table'>";
foreach (MCC_SEASON_POINTS as $stat => $points) {
    echo '<tr><td>' . e(MCC_PROGRESS[$stat]) . "</td><td>{$points} each" . ($stat === 'gym' ? ' (up to ' . MCC_SEASON_GYM_DAILY_CAP . ' a day)' : '') . '</td></tr>';
}
echo '</table>';
$h->endpage();
