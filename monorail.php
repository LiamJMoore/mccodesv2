<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: travel. The journey is in engine/mods/Travel.php.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Travel Agency</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_code('travel', in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $why = travel_start($userid, in_int('to', 0, $_POST));
        echo $why === null ? '' : '<p><b>You are not going anywhere.</b> ' . e($why) . '</p>';
    }
}
$trip = travel_of($userid);
if ($trip !== null) {
    echo "<div class='season-head'><div><small>On your way to</small><b>" . e($trip['cityname']) . '</b></div><div><small>Arriving in</small><b>' . travel_clock($trip['left']) . "</b></div></div>
    <p>You can read your mail, events and the forums on the way. Everything in the city waits until you arrive.</p><p>&gt; <a href='monorail.php'>Check again</a></p>";
    $h->endpage();
    exit;
}
$vehicle = vehicle_of($userid);
$here = (int)$db->value('SELECT `location` FROM `users` WHERE `userid` = ?', [$userid]);
echo '<p>' . ($vehicle === null
    ? 'A monorail ticket costs ' . money_formatter(MCC_TRAVEL_FARE) . ' and the journey takes ' . travel_clock(MCC_TRAVEL_SECONDS) . ". <a href='vehicles.php'>Your own vehicle</a> is quicker and needs no ticket."
    : 'You travel in your ' . e(MCC_VEHICLES[$vehicle]['name']) . ': no ticket, and ' . travel_clock(MCC_VEHICLES[$vehicle]['seconds']) . " a journey. <a href='vehicles.php'>Garage</a>") . '</p>';
$token = request_csrf_html('travel');
echo "<table width='100%' cellspacing='1' class='table'><tr><th>City</th><th>Description</th><th>Min level</th><th></th></tr>";
foreach ($db->all('SELECT `cityid`, `cityname`, `citydesc`, `cityminlevel` FROM `cities` WHERE `cityid` <> ? ORDER BY `cityminlevel`, `cityid`', [$here]) as $c) {
    echo '<tr><td><b>' . e($c['cityname']) . '</b></td><td>' . e($c['citydesc']) . "</td><td>{$c['cityminlevel']}</td><td>" . ((int)$c['cityminlevel'] <= (int)$ir['level']
        ? "<form action='monorail.php' method='post'>{$token}<input type='hidden' name='to' value='{$c['cityid']}' /><input type='submit' value='Go' /></form>" : 'Level too low') . '</td></tr>';
}
echo '</table>';
$h->endpage();
