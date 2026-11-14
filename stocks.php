<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the stock market.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
stocks_tick();
echo '<h3>Stock Market</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['buy', 'sell'], '', $_POST);
    if (!verify_csrf_code('stocks', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $r = $do === 'buy' ? stock_buy($userid, in_int('stock', 0, $_POST), in_int('shares', 0, $_POST), (int)$ir['level']) : stock_sell($userid, in_int('stock', 0, $_POST), in_int('shares', 0, $_POST));
        if (is_string($r)) {
            echo '<p><b>No trade.</b> ' . e($r) . '</p>';
        } elseif ($do === 'buy') {
            echo '<p><b>Bought</b> ' . number_format($r['shares']) . ' shares at ' . money_formatter($r['price']) . ' for ' . money_formatter($r['paid']) . ' (fee ' . money_formatter($r['fee']) . ').</p>';
        } else {
            echo '<p><b>Sold</b> ' . number_format($r['shares']) . ' shares at ' . money_formatter($r['price']) . ' for ' . money_formatter($r['got']) . ' (fee ' . money_formatter($r['fee']) . '). '
                . ($r['profit'] >= 0 ? 'Profit: ' . money_formatter($r['profit']) : 'Loss: ' . money_formatter(-$r['profit'])) . '.</p>';
        }
    }
}
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
$list = stocks_list($userid);
$hist = stocks_history(24);
$token = request_csrf_html('stocks');
$next = MCC_STOCK_TICK - (time() % MCC_STOCK_TICK);
$worth = 0;
$paid = 0;
foreach ($list as $s) {
    $worth += (int)$s['shares'] * (int)$s['price'];
    $paid += (int)$s['cost'];
}
echo '<p>Prices move every ' . intdiv(MCC_STOCK_TICK, 60) . ' minutes; the next move is in ' . intdiv($next, 60) . 'm ' . ($next % 60) . 's. Every trade pays a ' . (MCC_STOCK_FEE_PERMILLE / 10)
    . '% fee. At your level you can hold up to ' . number_format(stock_cap((int)$ir['level'])) . " shares of each company.</p>
<div class='season-head'><div><small>In your pocket</small><b>" . money_formatter($money) . '</b></div><div><small>Your shares are worth</small><b>' . money_formatter($worth)
    . '</b></div><div><small>You paid</small><b>' . money_formatter($paid) . '</b></div><div><small>' . ($worth >= $paid ? 'Up' : 'Down') . '</small><b>' . money_formatter(abs($worth - $paid)) . "</b></div></div>
<table width='100%' cellspacing='1' class='table'><tr><th>Company</th><th>Price</th><th>Last move</th><th>Last day</th><th>You hold</th><th>Trade</th></tr>";
foreach ($list as $s) {
    $id = (int)$s['id'];
    $price = (int)$s['price'];
    $move = $price - (int)$s['prev'];
    $pc = (int)$s['prev'] > 0 ? round($move / (int)$s['prev'] * 100, 1) : 0;
    $day = $hist[$id] ?? [];
    $dayup = $day ? $price >= $day[0] : $move >= 0;
    $daypc = $day && $day[0] > 0 ? round(($price - $day[0]) / $day[0] * 100, 1) : 0;
    $held = (int)$s['shares'];
    echo '<tr><td><b>' . e($s['symbol']) . '</b><br /><small>' . e($s['name']) . '</small></td><td><b>' . money_formatter($price) . '</b></td><td>'
        . ($move > 0 ? "<span style='color:#34D399;'>&#9650; up {$pc}%</span>" : ($move < 0 ? "<span style='color:#FB7185;'>&#9660; down " . abs($pc) . '%</span>' : 'no change')) . '</td><td>'
        . stock_spark($day, $dayup) . '<br /><small>' . ($daypc >= 0 ? 'up ' : 'down ') . abs($daypc) . '%</small></td><td>'
        . ($held > 0 ? number_format($held) . ' worth ' . money_formatter($held * $price) . '<br /><small>' . ($held * $price >= (int)$s['cost'] ? 'up ' : 'down ') . money_formatter(abs($held * $price - (int)$s['cost'])) . '</small>' : '-')
        . "</td><td><form action='stocks.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='stock' value='{$id}' /><input type='text' name='shares' size='5' value='" . ($held > 0 ? $held : 10) . "' aria-label='Shares of " . e($s['symbol']) . "' />
        <button type='submit' name='do' value='buy'>Buy</button> <button type='submit' name='do' value='sell'" . ($held > 0 ? '' : ' disabled') . '>Sell</button></form></td></tr>';
}
echo '</table>';
$h->endpage();
