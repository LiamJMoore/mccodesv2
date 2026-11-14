<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the garage.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Garage</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['buy', 'sell'], '', $_POST);
    if (!verify_csrf_code('garage', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'buy') {
        $key = in_str('vehicle', 20, '', $_POST);
        $why = vehicle_buy($userid, $key);
        echo $why === null ? '<p><b>The keys are yours:</b> ' . e(MCC_VEHICLES[$key]['name']) . '.</p>' : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    } else {
        $paid = vehicle_sell($userid);
        echo $paid > 0 ? '<p><b>Sold for ' . money_formatter($paid) . '.</b></p>' : '<p>You have nothing to sell.</p>';
    }
}
$mine = vehicle_of($userid);
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
$token = request_csrf_html('garage');
$credit = $mine === null ? 0 : vehicle_resale($mine);
echo '<p>Your own vehicle means no monorail ticket (' . money_formatter(MCC_TRAVEL_FARE) . ' a journey) and less time on the road. The monorail takes ' . travel_clock(MCC_TRAVEL_SECONDS)
    . '. You can own one vehicle; buying another trades yours in at ' . MCC_VEHICLE_RESALE_PERCENT . '% of its price. You have ' . money_formatter($money) . '.</p>';
echo "<table width='100%' cellspacing='1' class='table'><tr><th>Vehicle</th><th>Journey time</th><th>Price</th><th></th></tr>";
foreach (MCC_VEHICLES as $key => $v) {
    echo '<tr><td><b>' . e($v['name']) . '</b></td><td>' . travel_clock($v['seconds']) . '</td><td>' . money_formatter($v['price']) . '</td><td>';
    if ($key === $mine) {
        echo "<b>Yours</b> <form action='vehicles.php' method='post' style='display:inline'>{$token}<input type='hidden' name='do' value='sell' /><button type='submit' class='linkish'>Sell for " . money_formatter($credit) . '</button></form>';
    } else {
        $net = $v['price'] - $credit;
        echo "<form action='vehicles.php' method='post'>{$token}<input type='hidden' name='do' value='buy' /><input type='hidden' name='vehicle' value='{$key}' /><input type='submit' value='"
            . ($net >= 0 ? 'Buy for ' . money_formatter($net) : 'Swap and get ' . money_formatter(-$net) . ' back') . "' /></form>";
    }
    echo '</td></tr>';
}
echo '</table>';
$h->endpage();
