<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the auction house.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
auction_settle();
echo '<h3>Auction House</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['bid', 'list', 'cancel'], '', $_POST);
    if (!verify_csrf_code('auction', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'bid') {
        $why = auction_bid($userid, in_int('id', 0, $_POST), in_int('amount', 0, $_POST));
        echo $why === null ? '<p><b>You are the highest bidder.</b> Your bid is held until you win or are outbid.</p>' : '<p><b>Bid not taken.</b> ' . e($why) . '</p>';
    } elseif ($do === 'list') {
        $r = auction_list($userid, in_int('inv', 0, $_POST), in_int('qty', 0, $_POST), in_int('start', 0, $_POST), in_int('hours', 0, $_POST));
        echo is_int($r) ? '<p><b>Your auction is open.</b></p>' : '<p><b>Not listed.</b> ' . e($r) . '</p>';
    } else {
        echo auction_cancel($userid, in_int('id', 0, $_POST)) ? '<p><b>Auction taken down.</b> The items are back in your inventory.</p>' : '<p>That auction cannot be taken down: someone has bid, or it has closed.</p>';
    }
}
$token = request_csrf_html('auction');
$left = function (int $ends): string {
    $s = max(0, $ends - time());
    return $s >= 86400 ? intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h' : ($s >= 3600 ? intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm' : intdiv($s, 60) . 'm ' . ($s % 60) . 's');
};
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
echo '<p>Bid on what other players are selling. A bid is taken from your pocket when you make it and returned the moment you are outbid. The house keeps ' . MCC_AUCTION_CUT_PERCENT . '% of each sale. You have ' . money_formatter($money) . '.</p>';
$open = auction_open();
echo "<table width='100%' cellspacing='1' class='table'><tr><th>Lot</th><th>Seller</th><th>Current bid</th><th>Closes in</th><th></th></tr>";
foreach ($open as $a) {
    $min = auction_min_bid($a);
    echo "<tr><td><b>{$a['qty']} x <a href='iteminfo.php?ID={$a['item']}'>" . e((string)$a['itmname']) . "</a></b></td><td><a href='viewuser.php?u={$a['seller']}'>" . perk_name((int)$a['seller'], (string)$a['sname']) . '</a></td><td>'
        . ((int)$a['bidder'] > 0 ? '<b>' . money_formatter((int)$a['bid']) . '</b><br /><small>by ' . ((int)$a['bidder'] === $userid ? 'you' : e((string)$a['bname'])) . '</small>' : 'No bids<br /><small>starts at ' . money_formatter((int)$a['start_bid']) . '</small>')
        . '</td><td>' . $left((int)$a['ends']) . '</td><td>';
    if ((int)$a['seller'] === $userid) {
        echo (int)$a['bidder'] === 0 ? "<form action='auction.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='do' value='cancel' /><input type='hidden' name='id' value='{$a['id']}' /><input type='submit' value='Take down' /></form>" : 'Yours';
    } elseif ((int)$a['bidder'] === $userid) {
        echo 'You are winning';
    } else {
        echo "<form action='auction.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='do' value='bid' /><input type='hidden' name='id' value='{$a['id']}' />\$<input type='text' name='amount' size='9' value='{$min}' aria-label='Your bid' /> <input type='submit' value='Bid' /></form>";
    }
    echo '</td></tr>';
}
if (!$open) {
    echo "<tr><td colspan='5'>Nothing is up for auction.</td></tr>";
}
echo '</table><h4>Sell something</h4>';
$inv = $db->all('SELECT `iv`.`inv_id`, `iv`.`inv_qty`, `i`.`itmname` FROM `inventory` AS `iv` INNER JOIN `items` AS `i` ON `i`.`itmid` = `iv`.`inv_itemid` WHERE `iv`.`inv_userid` = ? AND `iv`.`inv_qty` > 0 ORDER BY `i`.`itmname`', [$userid]);
if (!$inv) {
    echo '<p>You have nothing in your inventory to sell.</p>';
} else {
    echo "<form action='auction.php' method='post'>{$token}<input type='hidden' name='do' value='list' /><table cellspacing='1' class='table'><tr><td>Item</td><td><select name='inv'>";
    foreach ($inv as $i) {
        echo "<option value='{$i['inv_id']}'>" . e($i['itmname']) . " (you have {$i['inv_qty']})</option>";
    }
    echo "</select></td></tr><tr><td>How many</td><td><input type='text' name='qty' value='1' size='5' /></td></tr><tr><td>Starting price</td><td>\$<input type='text' name='start' value='100' size='10' /></td></tr><tr><td>Closes in</td><td><select name='hours'>";
    foreach (MCC_AUCTION_HOURS as $hrs) {
        echo "<option value='{$hrs}'" . ($hrs === 24 ? " selected='selected'" : '') . '>' . ($hrs < 24 ? "{$hrs} hour" . ($hrs === 1 ? '' : 's') : intdiv($hrs, 24) . ' day' . ($hrs === 24 ? '' : 's')) . '</option>';
    }
    echo "</select></td></tr><tr><td colspan='2' align='center'><input type='submit' value='Open the auction' /></td></tr></table></form>";
}
$h->endpage();
