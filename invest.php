<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: fixed-term investments.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Investment Bank</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['open', 'close'], '', $_POST);
    if (!verify_csrf_code('invest', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'open') {
        $why = invest_open($userid, in_int('amount', 0, $_POST), in_int('days', 0, $_POST), (int)$ir['level']);
        echo $why === null ? '<p><b>Invested.</b> Come back when it matures.</p>' : '<p><b>Not invested.</b> ' . e($why) . '</p>';
    } else {
        $r = invest_close($userid);
        echo $r === null ? '<p>You have no investment.</p>' : '<p><b>Paid out ' . money_formatter($r['paid']) . '.</b> ' . ($r['interest'] > 0 ? 'That includes ' . money_formatter($r['interest']) . ' interest.' : 'It was taken out early, so there was no interest.') . '</p>';
    }
}
$inv = invest_of($userid);
$token = request_csrf_html('invest');
echo '<p>Lock money away and get it back with interest. Take it out early and you get back what you put in, with no interest. One investment at a time.</p>';
if ($inv !== null) {
    $left = max(0, $inv['matures'] - time());
    echo "<div class='season-head'><div><small>Invested</small><b>" . money_formatter($inv['amount']) . '</b></div><div><small>Interest at the end</small><b>' . money_formatter($inv['interest']) . ' <small>(' . ($inv['rate'] / 10)
        . '%)</small></b></div><div><small>' . ($inv['ready'] ? 'Status' : 'Matures in') . '</small><b>' . ($inv['ready'] ? 'Ready' : intdiv($left, 86400) . 'd ' . intdiv($left % 86400, 3600) . 'h') . "</b></div></div>
    <form action='invest.php' method='post'>{$token}<input type='hidden' name='do' value='close' /><input type='submit' value='" . ($inv['ready'] ? 'Collect with interest' : 'Take it out early (no interest)') . "' /></form>";
} else {
    $money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
    echo "<form action='invest.php' method='post'>{$token}<input type='hidden' name='do' value='open' /><table cellspacing='1' class='table'>
    <tr><td>Amount</td><td>\$<input type='text' name='amount' value='" . min($money, invest_max((int)$ir['level'])) . "' size='12' /> (up to " . money_formatter(invest_max((int)$ir['level'])) . " at your level)</td></tr><tr><td>Term</td><td>";
    foreach (MCC_INVEST_TERMS as $days => $rate) {
        echo "<label style='display:block;'><input type='radio' name='days' value='{$days}'" . ($days === 7 ? " checked='checked'" : '') . " /> {$days} days, pays " . ($rate / 10) . '%</label>';
    }
    echo "</td></tr><tr><td colspan='2' align='center'><input type='submit' value='Invest' /></td></tr></table></form>";
}
$h->endpage();
