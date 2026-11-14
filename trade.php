<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: travel trading.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$city = (int)$ir['location'];
$cityname = (string)$db->value('SELECT `cityname` FROM `cities` WHERE `cityid` = ?', [$city], 'here');
echo '<h3>Trading Post: ' . e($cityname) . '</h3>';
if (!trade_origins()) {
    echo '<p>There is only one city, so there is nowhere to trade with.</p>';
    $h->endpage();
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['buy', 'sell'], '', $_POST);
    $good = in_str('good', 20, '', $_POST);
    $qty = in_int('qty', 0, $_POST);
    if (!verify_csrf_code('trade', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'buy') {
        $why = trade_buy($userid, $good, $qty);
        echo $why === null ? "<p><b>Bought {$qty}.</b> Now take them somewhere else.</p>" : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    } else {
        $r = trade_sell($userid, $good, $qty);
        echo is_string($r) ? '<p><b>Not sold.</b> ' . e($r) . '</p>' : "<p><b>Sold {$qty}</b> for " . money_formatter($r['got']) . '. ' . ($r['profit'] >= 0 ? 'Profit: ' . money_formatter($r['profit']) : 'Loss: ' . money_formatter(-$r['profit'])) . '.</p>';
    }
}
$cap = trade_capacity((int)$ir['level']);
$cargo = trade_cargo($userid);
$carrying = array_sum(array_column($cargo, 'qty'));
$bought = perk_used_today($userid, 'trade_units');
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
$token = request_csrf_html('trade');
$origins = trade_origins();
$names = [];
foreach ($db->all('SELECT `cityid`, `cityname` FROM `cities`') as $c) {
    $names[(int)$c['cityid']] = $c['cityname'];
}
echo '<p>Each city makes a few things that fetch ' . MCC_TRADE_MARKUP_PERCENT . "% more anywhere else. Buy here, <a href='monorail.php'>travel</a>, sell there.</p>
<div class='season-head'><div><small>In your pocket</small><b>" . money_formatter($money) . "</b></div><div><small>Carrying</small><b>{$carrying} / {$cap}</b></div>
<div><small>Bought today</small><b>{$bought} / " . ($cap * MCC_TRADE_LOADS_A_DAY) . "</b></div></div>
<h4>Made here</h4><table width='95%' cellspacing='1' class='table'><tr><th>Goods</th><th>Price here</th><th>Sells elsewhere for</th><th>In stock</th><th></th></tr>";
foreach (trade_stock($city) as $good => $stock) {
    $room = max(0, min($cap - $carrying, $stock, intdiv($money, MCC_TRADE_GOODS[$good][1])));
    echo '<tr><td>' . e(MCC_TRADE_GOODS[$good][0]) . '</td><td>' . money_formatter(MCC_TRADE_GOODS[$good][1]) . '</td><td>' . money_formatter(trade_sell_price($good)) . "</td><td>{$stock}</td><td>"
        . ($stock > 0 ? "<form action='trade.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='do' value='buy' /><input type='hidden' name='good' value='{$good}' /><input type='text' name='qty' size='4' value='{$room}' aria-label='How many' /> <input type='submit' value='Buy' /></form>" : 'Sold out')
        . '</td></tr>';
}
echo "</table><h4>What you are carrying</h4>";
if (!$cargo) {
    echo '<p>Nothing.</p>';
} else {
    echo "<table width='95%' cellspacing='1' class='table'><tr><th>Goods</th><th>Carrying</th><th>From</th><th>Paid</th><th>Worth here</th><th></th></tr>";
    foreach ($cargo as $good => $c) {
        $home = ($origins[$good] ?? 0) === $city;
        $each = $home ? MCC_TRADE_GOODS[$good][1] : trade_sell_price($good);
        echo '<tr><td>' . e(MCC_TRADE_GOODS[$good][0]) . "</td><td>{$c['qty']}</td><td>" . e((string)($names[$origins[$good] ?? 0] ?? '?')) . '</td><td>' . money_formatter($c['paid']) . '</td><td>' . money_formatter($each * $c['qty'])
            . ($home ? ' <small>(no profit at home)</small>' : '') . "</td><td><form action='trade.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='do' value='sell' /><input type='hidden' name='good' value='{$good}' />
            <input type='text' name='qty' size='4' value='{$c['qty']}' aria-label='How many' /> <input type='submit' value='Sell' /></form></td></tr>";
    }
    echo '</table>';
}
echo "<h4>What the other cities make</h4><table width='70%' cellspacing='1' class='table'><tr><th>City</th><th>Makes</th></tr>";
$by = [];
foreach ($origins as $good => $home) {
    $by[$home][] = MCC_TRADE_GOODS[$good][0];
}
foreach ($by as $home => $list) {
    if ($home !== $city) {
        echo '<tr><td>' . e((string)($names[$home] ?? '?')) . '</td><td>' . e(implode(', ', $list)) . '</td></tr>';
    }
}
echo '</table>';
$h->endpage();
