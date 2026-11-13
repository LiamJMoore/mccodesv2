<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: daily missions.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Daily Missions</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slot = in_int('slot', 0, $_POST);
    if (!verify_csrf_code('mission_claim', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $paid = mission_claim($userid, $slot);
        if ($paid === null) {
            echo '<p>That mission is not finished, or its reward has already been collected.</p>';
        } else {
            echo '<p><b>Mission complete!</b> You received ' . money_formatter($paid['money']) . '.'
                . ($paid['crystals'] ? " That was all of today's missions: a bonus of {$paid['crystals']} crystals is yours." : '') . '</p>';
        }
    }
}
$missions = missions_today($userid, (int)$ir['level']);
$token = request_csrf_html('mission_claim');
echo '<p>Three jobs a day. Each pays when you finish it; finish all three for a bonus of ' . MCC_MISSION_ALL_CRYSTALS . " crystals. New missions arrive at midnight.</p>
<table width='90%' cellspacing='1' class='table'><tr><th>Mission</th><th>Progress</th><th>Reward</th><th></th></tr>";
$collected = 0;
foreach ($missions as $m) {
    $done = (int)$m['progress'] >= (int)$m['target'];
    $pc = (int)min(100, floor((int)$m['progress'] / max(1, (int)$m['target']) * 100));
    echo '<tr><td>' . e(mission_text($m)) . "</td>
    <td><div style='background:#555;border-radius:4px;width:140px;height:10px;display:inline-block;vertical-align:middle;'><div style='background:#4caf50;border-radius:4px;height:10px;width:{$pc}%;'></div></div>
        {$m['progress']} / {$m['target']}</td>
    <td>" . money_formatter((int)$m['reward']) . '</td><td>';
    if ((int)$m['claimed'] > 0) {
        echo 'Collected';
        $collected++;
    } elseif ($done) {
        echo "<form action='missions.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='slot' value='{$m['slot']}' /><input type='submit' value='Collect' /></form>";
    } else {
        echo 'In progress';
    }
    echo '</td></tr>';
}
echo '</table>';
if ($collected >= MCC_MISSIONS_A_DAY) {
    echo "<p><b>All done for today.</b> Come back tomorrow for three more.</p>";
}
echo "<p>Missions completed in total: <b>" . number_format(progress_get($userid, 'missions')) . "</b>. <a href='achievements.php'>Achievements</a></p>";
$h->endpage();
