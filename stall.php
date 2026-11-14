<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: market stalls. stall.php is the market; ?u=<id> is one player's stall; ?mine is your own.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
$owner = isset($_GET['mine']) ? $userid : in_int('u', 0, $_GET);
$back = $owner ? "stall.php?u={$owner}" : 'stall.php';
echo '<h3>Market Stalls</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['open', 'rename', 'add', 'remove', 'buy', 'close'], '', $_POST);
    if (!verify_csrf_code('stall', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'open') {
        $why = stall_open($userid, in_str('name', 200, '', $_POST));
        echo $why === null ? '<p><b>Your stall is open.</b> Put something on it.</p>' : '<p><b>Not opened.</b> ' . e($why) . '</p>';
    } elseif ($do === 'rename') {
        echo stall_rename($userid, in_str('name', 200, '', $_POST)) ? '<p><b>Renamed.</b></p>' : '<p>A name is 3 to 40 characters.</p>';
    } elseif ($do === 'add') {
        $why = stall_add($userid, in_int('inv', 0, $_POST), in_int('qty', 0, $_POST), in_int('price', 0, $_POST));
        echo $why === null ? '<p><b>On the stall.</b></p>' : '<p><b>Not added.</b> ' . e($why) . '</p>';
    } elseif ($do === 'remove') {
        echo stall_remove($userid, in_int('line', 0, $_POST)) ? '<p><b>Back in your inventory.</b></p>' : '<p>That is not on your stall.</p>';
    } elseif ($do === 'buy') {
        $why = stall_buy($userid, in_int('line', 0, $_POST), in_int('qty', 0, $_POST), in_int('price', 0, $_POST));
        echo $why === null ? '<p><b>Bought.</b> It is in your inventory.</p>' : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    } else {
        echo stall_close($userid) ? '<p><b>Stall closed.</b></p>' : '<p>Take everything off the stall before you close it.</p>';
    }
}
$token = request_csrf_html('stall');
$mine = stall_of($userid);
echo "<p><a href='stall.php'>The market</a> &middot; " . ($mine === null ? 'You have no stall' : "<a href='stall.php?mine'>Your stall</a>") . '</p>';

if ($owner === $userid) {
    // the owner's side of the counter
    if ($mine === null) {
        echo '<p>A stall is your own shop front: name it, stock it from your inventory at your own prices, and the money comes straight to you. A pitch costs ' . money_formatter(MCC_STALL_FEE) . ", once.</p>
        <form action='stall.php?mine' method='post'>{$token}<input type='hidden' name='do' value='open' />Name: <input type='text' name='name' maxlength='40' size='30' /> <input type='submit' value='Open a stall' /></form>";
        $h->endpage();
        exit;
    }
    echo '<h4>' . e($mine['name']) . "</h4><form action='stall.php?mine' method='post'>{$token}<input type='hidden' name='do' value='rename' /><input type='text' name='name' maxlength='40' size='30' value='" . e($mine['name']) . "' aria-label='Stall name' /> <input type='submit' value='Rename' /></form>
    <table width='100%' cellspacing='1' class='table'><tr><th>Item</th><th>On the stall</th><th>Price each</th><th></th></tr>";
    $stock = stall_stock($userid);
    foreach ($stock as $k) {
        echo '<tr><td>' . e($k['itmname']) . "</td><td>{$k['qty']}</td><td>" . money_formatter((int)$k['price']) . "</td><td><form action='stall.php?mine' method='post'>{$token}<input type='hidden' name='do' value='remove' /><input type='hidden' name='line' value='{$k['id']}' /><button type='submit' class='linkish'>Take off</button></form></td></tr>";
    }
    echo ($stock ? '' : "<tr><td colspan='4'>Your stall is empty.</td></tr>") . '</table><h4>Stock the stall</h4>';
    $inv = $db->all('SELECT `iv`.`inv_id`, `iv`.`inv_qty`, `i`.`itmname` FROM `inventory` AS `iv` INNER JOIN `items` AS `i` ON `i`.`itmid` = `iv`.`inv_itemid` WHERE `iv`.`inv_userid` = ? AND `iv`.`inv_qty` > 0 ORDER BY `i`.`itmname`', [$userid]);
    if (!$inv) {
        echo '<p>You have nothing in your inventory to sell.</p>';
    } else {
        echo "<form action='stall.php?mine' method='post'>{$token}<input type='hidden' name='do' value='add' /><select name='inv' aria-label='Item'>";
        foreach ($inv as $i) {
            echo "<option value='{$i['inv_id']}'>" . e($i['itmname']) . " (you have {$i['inv_qty']})</option>";
        }
        echo "</select> How many: <input type='text' name='qty' size='4' value='1' /> Price each: \$<input type='text' name='price' size='9' /> <input type='submit' value='Put on the stall' /></form>
        <p><small>Adding more of something already on the stall sets its price to the new one.</small></p>";
    }
    if (!$stock) {
        echo "<form action='stall.php?mine' method='post'>{$token}<input type='hidden' name='do' value='close' /><button type='submit' class='linkish'>Close the stall</button> <small>(the pitch fee is not returned)</small></form>";
    }
} elseif ($owner > 0) {
    $s = stall_of($owner);
    if ($s === null) {
        echo '<p>That player has no stall.</p>';
    } else {
        $money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
        echo '<h4>' . e($s['name']) . "</h4><p>Run by <a href='viewuser.php?u={$owner}'>" . e((string)$s['username']) . '</a>. You have ' . money_formatter($money) . ".</p>
        <table width='100%' cellspacing='1' class='table'><tr><th>Item</th><th>Left</th><th>Price each</th><th>Buy</th></tr>";
        $stock = stall_stock($owner);
        foreach ($stock as $k) {
            echo "<tr><td><a href='iteminfo.php?ID={$k['item']}'>" . e($k['itmname']) . "</a></td><td>{$k['qty']}</td><td>" . money_formatter((int)$k['price']) . "</td><td><form action='stall.php?u={$owner}' method='post'>{$token}<input type='hidden' name='do' value='buy' />
            <input type='hidden' name='line' value='{$k['id']}' /><input type='hidden' name='price' value='{$k['price']}' /><input type='text' name='qty' size='4' value='1' aria-label='How many' /> <input type='submit' value='Buy' /></form></td></tr>";
        }
        echo ($stock ? '' : "<tr><td colspan='4'>Nothing on the stall today.</td></tr>") . '</table>';
    }
} else {
    $find = in_str('find', 60, '', $_GET);
    echo "<form action='stall.php' method='get'>Looking for something? <input type='text' name='find' maxlength='60' value='" . e($find) . "' aria-label='Item name' /> <input type='submit' value='Find' /></form>";
    if ($find !== '') {
        $hits = stall_find($find);
        echo "<table width='100%' cellspacing='1' class='table'><tr><th>Item</th><th>Price each</th><th>Left</th><th>Stall</th></tr>";
        foreach ($hits as $k) {
            echo '<tr><td>' . e($k['itmname']) . '</td><td>' . money_formatter((int)$k['price']) . "</td><td>{$k['qty']}</td><td><a href='stall.php?u={$k['userid']}'>" . e($k['name']) . '</a></td></tr>';
        }
        echo ($hits ? '' : "<tr><td colspan='4'>No stall has that.</td></tr>") . '</table>';
    }
    $all = stall_directory();
    echo "<h4>Open stalls</h4><table width='100%' cellspacing='1' class='table'><tr><th>Stall</th><th>Run by</th><th>Different items</th><th>Items in all</th></tr>";
    foreach ($all as $s) {
        echo "<tr><td><a href='stall.php?u={$s['userid']}'><b>" . e($s['name']) . "</b></a></td><td><a href='viewuser.php?u={$s['userid']}'>" . e((string)$s['username']) . "</a></td><td>{$s['lines']}</td><td>" . number_format((int)$s['items']) . '</td></tr>';
    }
    echo ($all ? '' : "<tr><td colspan='4'>No stall has anything for sale yet.</td></tr>") . '</table>';
}
$h->endpage();
