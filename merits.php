<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: merits.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Merits</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_code('merits', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $perk = in_str('perk', 20, '', $_POST);
        $why = merit_buy($userid, $perk);
        echo $why === null ? '<p><b>Upgraded:</b> ' . e(MCC_MERITS[$perk]['name']) . '.</p>' : '<p><b>Not upgraded.</b> ' . e($why) . '</p>';
    }
}
$levels = merit_levels($userid);
$earned = merit_earned($userid);
$free = $earned - merit_spent($levels);
$token = request_csrf_html('merits');
echo "<p>Every <a href='achievements.php'>achievement</a> you unlock is worth one merit. Spend them here on upgrades that last for good. Each level costs one more merit than the last.</p>
<div class='season-head'><div><small>Earned</small><b>{$earned}</b></div><div><small>Spent</small><b>" . merit_spent($levels) . "</b></div><div><small>To spend</small><b>{$free}</b></div></div>
<table width='100%' cellspacing='1' class='table'><tr><th>Upgrade</th><th>What it changes</th><th>Level</th><th>Now</th><th>Next level</th></tr>";
foreach (MCC_MERITS as $key => $m) {
    $lv = $levels[$key] ?? 0;
    echo '<tr><td><b>' . e($m['name']) . '</b></td><td>' . e($m['does']) . "</td><td>{$lv} / " . MCC_MERIT_MAX_LEVEL . '</td><td>' . ($lv ? ($m['step'] * $lv) . e($m['unit']) : 'No change') . '</td><td>';
    if ($lv >= MCC_MERIT_MAX_LEVEL) {
        echo 'At its highest';
    } elseif ($free >= $lv + 1) {
        echo "<form action='merits.php' method='post'>{$token}<input type='hidden' name='perk' value='{$key}' /><input type='submit' value='Upgrade for " . ($lv + 1) . ($lv === 0 ? ' merit' : ' merits') . "' /></form>";
    } else {
        echo 'Needs ' . ($lv + 1) . ($lv === 0 ? ' merit' : ' merits');
    }
    echo '</td></tr>';
}
echo '</table>';
$h->endpage();
