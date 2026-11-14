<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: your business.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Your Business</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['buy', 'collect', 'upgrade', 'sell'], '', $_POST);
    if (!verify_csrf_code('business', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'buy') {
        $why = business_buy($userid, in_str('kind', 20, '', $_POST), (int)$ir['level']);
        echo $why === null ? '<p><b>It is yours.</b> The till starts filling now.</p>' : '<p><b>Not bought.</b> ' . e($why) . '</p>';
    } elseif ($do === 'collect') {
        $paid = business_collect($userid);
        echo $paid > 0 ? '<p><b>You collected ' . money_formatter($paid) . '.</b></p>' : '<p>There is nothing in the till yet. It fills by the hour.</p>';
    } elseif ($do === 'upgrade') {
        $why = business_upgrade($userid);
        echo $why === null ? '<p><b>Upgraded.</b></p>' : '<p><b>Not upgraded.</b> ' . e($why) . '</p>';
    } else {
        $paid = business_sell($userid);
        echo $paid > 0 ? '<p><b>Sold.</b> With what was in the till you received ' . money_formatter($paid) . '.</p>' : '<p>You have no business to sell.</p>';
    }
}
$b = business_of($userid);
$token = request_csrf_html('business');
$form = fn (string $do, string $label, string $extra = '', bool $quiet = false) => "<form action='business.php' method='post' style='display:inline'>{$token}<input type='hidden' name='do' value='{$do}' />{$extra}"
    . ($quiet ? "<button type='submit' class='linkish'>{$label}</button>" : "<input type='submit' value='{$label}' />") . '</form>';
if ($b === null) {
    echo '<p>A business earns by the hour. The takings wait in the till, which holds a day\'s worth: collect at least once a day or it stops filling. You can own one.</p>'
        . "<table width='100%' cellspacing='1' class='table'><tr><th>Business</th><th>Price</th><th>Takings</th><th>Needs</th><th></th></tr>";
    foreach (MCC_BUSINESSES as $key => $k) {
        echo '<tr><td><b>' . e($k['name']) . '</b></td><td>' . money_formatter($k['price']) . '</td><td>' . money_formatter($k['hourly']) . ' an hour<br /><small>' . money_formatter($k['hourly'] * 24) . ' a day</small></td><td>Level ' . $k['level'] . '</td><td>'
            . ((int)$ir['level'] >= $k['level'] ? $form('buy', 'Buy', "<input type='hidden' name='kind' value='{$key}' />") : 'Level too low') . '</td></tr>';
    }
    echo '</table>';
} else {
    $k = MCC_BUSINESSES[$b['kind']];
    echo "<div class='season-head'><div><small>You own</small><b>" . e($k['name']) . "</b></div><div><small>Level</small><b>{$b['level']} / " . MCC_BUSINESS_MAX_LEVEL . '</b></div><div><small>Takings</small><b>' . money_formatter($b['hourly'])
        . ' an hour</b></div><div><small>In the till</small><b>' . money_formatter($b['pending']) . "</b></div><div><small>Till</small><b>{$b['hours']} of " . MCC_BUSINESS_TILL_HOURS . ' hours</b></div></div><p>'
        . ($b['hours'] >= 1 ? $form('collect', 'Collect ' . money_formatter($b['pending'])) : 'The till fills by the hour. Come back later.') . '</p>';
    if ($b['level'] < MCC_BUSINESS_MAX_LEVEL) {
        echo '<p>The next level takes ' . money_formatter(business_hourly($b['kind'], $b['level'] + 1)) . ' an hour. ' . $form('upgrade', 'Upgrade for ' . money_formatter(business_upgrade_price($b['kind']))) . '</p>';
    }
    echo '<p><small>Selling pays what is in the till and ' . MCC_BUSINESS_RESALE_PERCENT . '% of the purchase price (' . money_formatter(intdiv($k['price'] * MCC_BUSINESS_RESALE_PERCENT, 100)) . '). Upgrades are not refunded. '
        . $form('sell', 'Sell the business', '', true) . '</small></p>';
}
$h->endpage();
